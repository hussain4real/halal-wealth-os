<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Imports\CsvStatement;
use App\Domain\Imports\ImportRejected;
use App\Domain\Imports\StatementCsv;
use App\Domain\Imports\StatementImports;
use App\Domain\Ledger\LedgerInput;
use App\Models\Account;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as Page;
use stdClass;

final class StatementImportController extends Controller
{
    public function index(Request $request): Page
    {
        $owner = $this->owner($request);

        return Inertia::render('imports/Index', [...$this->choices($owner), 'fields' => CsvStatement::FIELDS, 'kinds' => CsvStatement::KINDS,
            'adapters' => DB::table('statement_adapters')->where('owner_id', $owner->id)->orderByDesc('id')->limit(100)->get()->map(fn (stdClass $a): array => ['id' => (string) $a->id, 'name' => $a->name, 'config' => json_decode($a->config, true, flags: JSON_THROW_ON_ERROR)])->all(),
            'previews' => DB::table('statement_snapshots as p')->join('statement_sources as s', 's.id', '=', 'p.source_id')->where('p.owner_id', $owner->id)->orderByDesc('p.id')->limit(20)->get(['p.id', 'p.reference', 's.namespace'])->map(fn (stdClass $p): array => ['id' => (string) $p->id, 'reference' => $p->reference, 'namespace' => $p->namespace])->all()]);
    }

    public function inspect(Request $request): JsonResponse
    {
        return $this->post($request, ['file' => ['required', 'file', 'max:256'], 'delimiter' => ['required', 'string', 'regex:/^(?:,|;|tab)$/D']], function (array $data) use ($request): JsonResponse {
            $csv = app(CsvStatement::class)->read($this->bytes($request), $data['delimiter'] === 'tab' ? "\t" : $data['delimiter']);

            return response()->json(['headers' => $csv['headers'], 'row_count' => count($csv['rows'])]);
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->post($request, ['file' => ['required', 'file', 'max:256'], 'namespace' => ['required', 'string', 'max:180'], 'account_id' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'adapter_name' => ['required', 'string', 'max:180'], 'mapping' => ['required', 'string', 'max:32768']], function (array $data) use ($request): JsonResponse {
                $mapping = json_decode($data['mapping'], true);
                if (! is_array($mapping)) {
                    LedgerInput::fail('mapping', 'Use a supported mapping object.');
                }
                $snapshot = app(StatementImports::class)->preview($this->owner($request), $this->bytes($request), $data['namespace'], $data['account_id'], $data['adapter_name'], $mapping);

                return response()->json(['id' => (string) $snapshot->id, 'url' => '/imports/'.$snapshot->id], 201);
            }, false);
    }

    public function show(Request $request, string $snapshot): Page
    {
        $owner = $this->owner($request);

        return Inertia::render('imports/Show', ['preview' => app(StatementImports::class)->show($owner, $snapshot), ...$this->choices($owner), 'kinds' => CsvStatement::KINDS]);
    }

    public function revise(Request $request, string $snapshot): JsonResponse
    {
        return $this->post($request, ['resolutions' => ['present', 'array', 'max:1000']], function (array $data) use ($request, $snapshot): JsonResponse {
            $row = app(StatementImports::class)->revise($this->owner($request), $snapshot, $data['resolutions']);

            return response()->json(['id' => (string) $row->id, 'url' => '/imports/'.$row->id], 201);
        }, false);
    }

    public function approve(Request $request, string $snapshot): JsonResponse
    {
        return $this->post($request, ['idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,120}$/D'], 'selection' => ['required', 'array', 'min:1', 'max:1000'], 'selection.*' => ['required', 'string']],
            fn (array $data): JsonResponse => $this->saved(app(StatementImports::class)->approve($this->owner($request), $snapshot, $data['idempotency_key'], $data['selection'])));
    }

    public function receipt(Request $request, string $receipt): Page
    {
        $owner = $this->owner($request);

        return Inertia::render('imports/Receipt', ['receipt' => app(StatementImports::class)->receipt($owner, $receipt), 'previews' => DB::table('statement_snapshots')->where('owner_id', $owner->id)->orderByDesc('id')->limit(100)->get(['id', 'reference'])->map(fn (stdClass $s): array => ['id' => (string) $s->id, 'reference' => $s->reference])->all()]);
    }

    public function status(Request $request, string $key): JsonResponse
    {
        LedgerInput::key($key);
        $row = DB::table('statement_receipts')->where('owner_id', $this->owner($request)->id)->where('idempotency_key', $key)->first();

        return $row === null ? response()->json(['committed' => false], 404) : $this->saved($row)->setStatusCode(200);
    }

    public function correct(Request $request, string $receipt, string $token): JsonResponse
    {
        return $this->post($request, ['idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,120}$/D'], 'reason' => ['required', 'string', 'max:180'],
            'replacement_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'], 'replacement_row' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,3}$/D']],
            fn (array $data): JsonResponse => $this->saved(app(StatementImports::class)->correct($this->owner($request), $receipt, $token, $data['idempotency_key'], $data['reason'], $data['replacement_id'] ?? null, $data['replacement_row'] ?? null)));
    }

    public function export(Request $request, string $snapshot): Response
    {
        abort_unless($request->query() === [], 400);

        return app(StatementCsv::class)->download($this->owner($request), $snapshot);
    }

    /** Always JSON: validation never flashes private request input into a redirect session. */
    private function post(Request $request, array $rules, Closure $work, bool $financial = true): JsonResponse
    {
        $entered = false;
        try {
            if ($request->query() !== []) {
                LedgerInput::fail('query', 'Query selectors cannot be submitted.');
            }
            $unknown = array_diff(array_keys($request->all()), [...array_keys($rules), '_token']);
            if ($unknown !== []) {
                throw ValidationException::withMessages(array_fill_keys($unknown, 'This field cannot be submitted.'));
            }
            $data = $request->validate($rules);
            $entered = true;

            return $work($data);
        } catch (ImportRejected $error) {
            return response()->json(['message' => $error->getMessage(), 'errors' => $error->errors, 'definitive_no_write' => true], 422);
        } catch (ValidationException $error) {
            return response()->json(['message' => 'Review the import validation reasons.', 'errors' => $error->errors(), 'definitive_no_write' => ! $financial || ! $entered], 422);
        }
    }

    private function bytes(Request $request): string
    {
        $file = $request->file('file');
        if ($file === null || ! $file->isValid() || strtolower($file->getClientOriginalExtension()) !== 'csv' || ! in_array($file->getMimeType(), ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'], true)) {
            LedgerInput::fail('file', 'Choose a UTF8 .csv text file. Documents, markup and executable files are unsupported.');
        }

        return $file->get();
    }

    private function saved(stdClass $receipt): JsonResponse
    {
        return response()->json(['id' => (string) $receipt->id, 'reference' => $receipt->reference, 'url' => '/imports/receipts/'.$receipt->id, 'committed' => true], 201);
    }

    /** @return array<string,mixed> */
    private function choices(User $owner): array
    {
        return ['accounts' => Account::query()->ownedBy($owner)->whereNull('system_role')->whereNull('archived_at')->orderBy('name')->limit(1000)->get()->map(fn (Account $a): array => ['id' => (string) $a->id, 'name' => $a->name, 'currency' => $a->currency_code->value])->all(),
            'assets' => DB::table('portfolio_assets')->where('owner_id', $owner->id)->orderBy('name')->limit(1000)->get(['id', 'name', 'currency'])->map(fn (stdClass $a): array => ['id' => (string) $a->id, 'name' => $a->name, 'currency' => $a->currency])->all(),
            'transfers' => DB::table('journal_entries')->where('owner_id', $owner->id)->where('kind', 'transfer')->where('status', 'posted')->orderByDesc('id')->limit(100)->get(['id', 'reference', 'description', 'effective_date'])->map(fn (stdClass $r): array => ['id' => (string) $r->id, 'label' => $r->effective_date.' · '.$r->description.' · '.$r->reference])->all(),
            'events' => DB::table('investment_events as e')->where('e.owner_id', $owner->id)->where('e.kind', '<>', 'reversal')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('investment_events as r')->whereColumn('r.reverses_id', 'e.id'))->orderByDesc('e.id')->limit(100)->get(['e.id', 'e.kind', 'e.effective_date', 'e.action_reference'])->map(fn (stdClass $e): array => ['id' => (string) $e->id, 'label' => $e->effective_date.' · '.$e->kind.' · '.$e->action_reference])->all()];
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 403);

        return $owner;
    }
}
