<?php

namespace App\Http\Controllers;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Shariah\AssessmentRead;
use App\Domain\Shariah\PurificationRead;
use App\Domain\Shariah\PurificationService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as Page;
use stdClass;

class PurificationController extends Controller
{
    public function index(Request $request): Page
    {
        return Inertia::render('purification/Index', app(PurificationRead::class)->index($this->owner($request), $this->after($request)));
    }

    public function form(Request $request, ?string $obligation = null): Page
    {
        return PortfolioRead::coherent(function () use ($request, $obligation): Page {
            $owner = $this->owner($request);
            $prior = $obligation === null ? null : LedgerInput::owned('purification_obligations', $owner, $obligation);
            $distributionId = $request->query('distribution');
            if ($prior !== null) {
                $distributionId = (string) (DB::table('investment_events as d')->where('d.owner_id', $owner->id)->where('d.sort_id', $prior->distribution_lineage)->where('d.kind', 'dividend')
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('investment_events as n')->whereColumn('n.reverses_id', 'd.id'))->orderByDesc('d.id')->value('id') ?? $prior->distribution_id);
            }
            if ($distributionId === null) {
                $q = DB::table('investment_events as d')->where('d.owner_id', $owner->id)->where('d.kind', 'dividend')
                    ->whereNotExists(fn ($n) => $n->selectRaw('1')->from('investment_events as n')->whereColumn('n.reverses_id', 'd.id'));
                if (($after = $this->after($request)) !== null) {
                    $q->where('d.id', '<', $after);
                }
                $rows = $q->orderByDesc('d.id')->limit(21)->get();

                return Inertia::render('purification/Choose', ['distributions' => $rows->take(20)->map(fn ($d): array => app(PurificationRead::class)->row($d))->all(), 'next' => $rows->count() > 20 ? (string) $rows[19]->id : null]);
            }
            $d = LedgerInput::owned('investment_events', $owner, LedgerInput::identifier($distributionId));
            abort_unless($d->kind === 'dividend', 404);
            $changed = DB::table('investment_events')->where('reverses_id', $d->id)->exists();
            $assessments = DB::table('shariah_assessments')->where('owner_id', $owner->id)->where('asset_id', $d->asset_id)->where('provenance', 'source_claim')->orderByDesc('id')->get();

            return Inertia::render('purification/Form', ['distribution' => app(PurificationRead::class)->row($d), 'prior' => $prior === null ? null : app(PurificationRead::class)->row($prior),
                'context' => app(PurificationService::class)->context($owner, $d), 'source_changed' => $changed,
                'stale' => $prior !== null && DB::table('purification_obligations')->where('supersedes_id', $prior->id)->exists(),
                'assessments' => $assessments->map(fn ($a): array => app(AssessmentRead::class)->row($a, now('UTC')->toDateString()))->all()]);
        });
    }

    public function show(Request $request, string $obligation): Page
    {
        return Inertia::render('purification/Record', app(PurificationRead::class)->detail($this->owner($request), $obligation, $this->after($request)));
    }

    public function paymentForm(Request $request): Page
    {
        $owner = $this->owner($request);

        return Inertia::render('purification/Payment', ['accounts' => DB::table('accounts')->where('owner_id', $owner->id)->whereNull('system_role')->whereNull('archived_at')->orderBy('id')->get(['id', 'name', 'currency_code'])->map(fn ($a): array => ['id' => (string) $a->id, 'name' => $a->name, 'currency' => $a->currency_code])->all(), 'today' => now('UTC')->toDateString()]);
    }

    public function obligationStore(Request $request): JsonResponse|RedirectResponse
    {
        $row = app(PurificationService::class)->obligation($this->owner($request), $this->key($request), $request->except('idempotency_key'));

        return $this->receipt($request, $row, '/purification/obligations/'.$row->id);
    }

    public function paymentStore(Request $request): JsonResponse|RedirectResponse
    {
        $row = app(PurificationService::class)->payment($this->owner($request), $this->key($request), $request->except('idempotency_key'));

        return $this->receipt($request, $row, '/purification/payments/'.$row->id);
    }

    public function paymentShow(Request $request, string $payment): Page
    {
        return PortfolioRead::coherent(function () use ($request, $payment): Page {
            $owner = $this->owner($request);
            $p = LedgerInput::owned('purification_payments', $owner, $payment);

            return Inertia::render('purification/PaymentRecord', ['payment' => app(PurificationRead::class)->payment($owner, $p)]);
        });
    }

    public function allocate(Request $request): JsonResponse|RedirectResponse
    {
        $owner = $this->owner($request);
        $row = app(PurificationService::class)->allocate($owner, $this->key($request), $request->except('idempotency_key'));

        return $this->receipt($request, $row, '/purification/obligations/'.$row->obligation_id);
    }

    public function release(Request $request, string $allocation): JsonResponse|RedirectResponse
    {
        $owner = $this->owner($request);
        $row = app(PurificationService::class)->release($owner, $allocation, $this->key($request), $request->except('idempotency_key'));
        $a = LedgerInput::owned('purification_allocations', $owner, $allocation);

        return $this->receipt($request, $row, '/purification/obligations/'.$a->obligation_id);
    }

    public function export(Request $request): Response
    {
        return response(json_encode(app(PurificationRead::class)->export($this->owner($request)), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), 200, ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="purification.json"', 'Cache-Control' => 'no-store, private']);
    }

    private function key(Request $request): string
    {
        $key = $request->input('idempotency_key');
        if (! is_string($key)) {
            LedgerInput::fail('idempotency_key', 'Use a valid request identity.');
        }

        return LedgerInput::key($key);
    }

    private function after(Request $request): ?string
    {
        $value = $request->query('after');

        return $value === null ? null : LedgerInput::identifier($value);
    }

    private function receipt(Request $request, stdClass $row, string $url): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json(['id' => (string) $row->id, 'reference' => $row->reference, 'url' => $url], 201) : redirect($url, 303);
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 403);

        return $owner;
    }
}
