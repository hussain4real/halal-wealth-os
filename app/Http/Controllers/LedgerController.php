<?php

namespace App\Http\Controllers;

use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\FxObservationService;
use App\Domain\Ledger\FxService;
use App\Domain\Ledger\LedgerInput;
use App\Domain\Ledger\ReconciliationService;
use App\Domain\Reporting\NetWorthSnapshot;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Http\Requests\LedgerRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

class LedgerController extends Controller
{
    public function fxIndex(Request $request): Response
    {
        $items = DB::table('fx_operations')->where('owner_id', $this->owner($request)->id)->orderByDesc('id')->limit(30)->get()->map(fn (stdClass $row): array => ['id' => (string) $row->id, 'reference' => $row->reference, 'status' => $row->status, 'description' => $row->description,
            'sent' => Money::fromMinor(Currency::from($row->source_currency), (string) $row->source_minor)->jsonSerialize(),
            'received' => Money::fromMinor(Currency::from($row->destination_currency), (string) $row->destination_minor)->jsonSerialize(), 'effective_date' => $row->effective_date])->all();

        return Inertia::render('fx/Index', ['operations' => $items]);
    }

    public function fxCreate(Request $request): Response
    {
        return $this->fxForm($request);
    }

    public function fxCorrection(Request $request, string $operation): Response
    {
        $original = app(FxService::class)->receipt($this->owner($request), $operation);
        abort_unless($original['status'] === 'posted', 404);

        return $this->fxForm($request, $original);
    }

    public function fxShow(Request $request, string $operation): Response
    {
        return Inertia::render('fx/Show', ['operation' => app(FxService::class)->receipt($this->owner($request), $operation)]);
    }

    public function fxStore(LedgerRequest $request, ?string $operation = null): JsonResponse|RedirectResponse
    {
        $row = app(FxService::class)->record($this->owner($request), $request->string('idempotency_key')->toString(), $request->safe()->except('idempotency_key'), $operation);

        return $this->saved($request, $row, '/fx/'.$row->id);
    }

    public function fxReverse(LedgerRequest $request, string $operation): JsonResponse|RedirectResponse
    {
        $row = app(FxService::class)->reverse($this->owner($request), $operation, $request->string('idempotency_key')->toString(), $request->string('reason')->toString());

        return $this->saved($request, $row, '/fx/'.$row->id);
    }

    public function rates(Request $request): Response
    {
        $owner = $this->owner($request);
        $prior = $request->query('supersedes') === null ? null : LedgerInput::owned('fx_observations', $owner, LedgerInput::identifier($request->query('supersedes')));
        abort_if($prior !== null && $prior->purpose !== 'reporting', 404);
        $items = DB::table('fx_observations')->where('owner_id', $owner->id)->where('purpose', 'reporting')->orderByDesc('id')->limit(30)->get()->map(fn (stdClass $row): array => $this->ratePayload($row))->all();

        return Inertia::render('ledger/Rates', ['observations' => $items, 'prior' => $prior === null ? null : $this->ratePayload($prior), 'currencies' => $this->currencies(), 'now_utc' => now('UTC')->format('Y-m-d\TH:i:s\Z')]);
    }

    public function rateStore(LedgerRequest $request): JsonResponse|RedirectResponse
    {
        $row = app(FxObservationService::class)->record($this->owner($request), $request->string('idempotency_key')->toString(), $request->safe()->except('idempotency_key'));

        return $this->saved($request, $row, '/rates');
    }

    public function reports(Request $request): Response
    {
        $base = $this->currency($request->query('base_currency', 'QAR'));
        $cutoff = LedgerInput::instant($request->query('cutoff', now('UTC')->format('Y-m-d\TH:i:s\Z')), 'cutoff');

        return $this->reportPage($request, app(NetWorthSnapshot::class)->capture($this->owner($request), $base, $cutoff));
    }

    public function reportShow(Request $request, string $report): Response
    {
        $row = LedgerInput::owned('valuation_reports', $this->owner($request), $report);

        return $this->reportPage($request, json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR), ['id' => (string) $row->id, 'reference' => $row->reference, 'recorded_at' => LedgerInput::utc($row->created_at)]);
    }

    public function reportStore(LedgerRequest $request): JsonResponse|RedirectResponse
    {
        $row = app(NetWorthSnapshot::class)->save($this->owner($request), $request->string('idempotency_key')->toString(), $this->currency($request->validated('base_currency')), $request->string('cutoff')->toString());

        return $this->saved($request, $row, '/reports/'.$row->id);
    }

    public function reconciliations(Request $request): Response
    {
        $owner = $this->owner($request);
        $prior = $request->query('supersedes') === null ? null : LedgerInput::owned('reconciliation_sessions', $owner, LedgerInput::identifier($request->query('supersedes')));
        $items = DB::table('reconciliation_sessions')->where('owner_id', $owner->id)->orderByDesc('id')->limit(30)->get()->map(fn (stdClass $row): array => $this->reconciliationPayload($row))->all();

        return Inertia::render('ledger/Reconciliations', ['sessions' => $items, 'prior' => $prior === null ? null : $this->reconciliationPayload($prior), 'accounts' => $this->accounts($owner), 'now_utc' => now('UTC')->format('Y-m-d\TH:i:s\Z')]);
    }

    public function reconciliationShow(Request $request, string $session): Response
    {
        $owner = $this->owner($request);
        $row = LedgerInput::owned('reconciliation_sessions', $owner, $session);
        $adjustment = DB::table('reconciliation_adjustments')->where('owner_id', $owner->id)->where(fn ($query) => $query->where('original_session_id', $row->id)->orWhere('result_session_id', $row->id))->first();
        $linked = null;
        if ($adjustment !== null) {
            $journal = LedgerInput::owned('journal_entries', $owner, (string) $adjustment->journal_id);
            $linked = ['original_id' => (string) $adjustment->original_session_id, 'result_id' => (string) $adjustment->result_session_id, 'journal_id' => (string) $journal->id, 'current_status' => $journal->status, 'reason' => $adjustment->reason];
        }

        return Inertia::render('ledger/Reconciliation', ['session' => $this->reconciliationPayload($row), 'evidence' => json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR), 'adjustment' => $linked]);
    }

    public function reconciliationStore(LedgerRequest $request): JsonResponse|RedirectResponse
    {
        $row = app(ReconciliationService::class)->record($this->owner($request), $request->string('idempotency_key')->toString(), $request->string('account_id')->toString(), $request->validated('statement_amount'), $request->string('cutoff')->toString(), $request->validated('supersedes_id'));

        return $this->saved($request, $row, '/reconciliations/'.$row->id);
    }

    public function reconciliationAdjust(LedgerRequest $request, string $session): JsonResponse|RedirectResponse
    {
        $row = app(ReconciliationService::class)->adjust($this->owner($request), $session, $request->string('idempotency_key')->toString(), $request->string('offset')->toString(), $request->string('reason')->toString());

        return $this->saved($request, $row, '/reconciliations/'.$row->id);
    }

    /** @param array<string, mixed>|null $original */
    private function fxForm(Request $request, ?array $original = null): Response
    {
        return Inertia::render('fx/Create', ['accounts' => array_values(array_filter($this->accounts($this->owner($request)), fn (array $account): bool => ! $account['archived'])),
            'original' => $original, 'today' => now('UTC')->toDateString(), 'now_utc' => now('UTC')->format('Y-m-d\TH:i:s\Z')]);
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 403);

        return $owner;
    }

    private function currency(mixed $value): Currency
    {
        $currency = is_string($value) ? Currency::tryFrom($value) : null;
        if ($currency === null) {
            LedgerInput::fail('base_currency', 'Choose a supported currency.');
        }

        return $currency;
    }

    /** @return list<string> */
    private function currencies(): array
    {
        return array_map(fn (Currency $currency): string => $currency->value, Currency::cases());
    }

    /** @return array<int, array<string, mixed>> */
    private function accounts(User $owner): array
    {
        return DB::table('accounts')->where('owner_id', $owner->id)->whereNull('system_role')->orderBy('id')->get()->filter(fn (stdClass $row): bool => AccountType::from($row->type)->contributesToNetWorth())->map(fn (stdClass $row): array => ['id' => (string) $row->id, 'name' => $row->name, 'currency' => $row->currency_code, 'exponent' => Currency::from($row->currency_code)->exponent(), 'archived' => $row->archived_at !== null])->values()->all();
    }

    /** @return array<string, mixed> */
    private function ratePayload(stdClass $row): array
    {
        return ['id' => (string) $row->id, 'base_currency' => $row->base_currency, 'quote_currency' => $row->quote_currency, 'value' => $row->value, 'version' => (string) $row->version,
            'observed_at' => LedgerInput::utc($row->observed_at), 'effective_at' => LedgerInput::utc($row->effective_at), 'source' => $row->source,
            'supersedes_id' => $row->supersedes_id === null ? null : (string) $row->supersedes_id, 'correction_reason' => $row->correction_reason];
    }

    /** @return array<string, mixed> */
    private function reconciliationPayload(stdClass $row): array
    {
        $currency = Currency::from($row->currency);

        return ['id' => (string) $row->id, 'reference' => $row->reference, 'account_id' => (string) $row->account_id, 'status' => $row->status, 'currency' => $row->currency,
            'statement' => Money::fromMinor($currency, (string) $row->statement_minor)->jsonSerialize(), 'ledger' => $row->ledger_minor === null ? null : Money::fromMinor($currency, (string) $row->ledger_minor)->jsonSerialize(),
            'difference_minor' => $row->difference === null ? null : (string) $row->difference,
            'difference_amount' => $row->difference === null ? null : bcdiv((string) $row->difference, bcpow('10', (string) $currency->exponent(), 0), $currency->exponent()),
            'cutoff' => LedgerInput::utc($row->cutoff), 'recorded_at' => LedgerInput::utc($row->created_at), 'supersedes_id' => $row->supersedes_id === null ? null : (string) $row->supersedes_id];
    }

    /**
     * @param array<string, mixed> $evidence
     * @param array<string, string>|null $saved */
    private function reportPage(Request $request, array $evidence, ?array $saved = null): Response
    {
        $history = DB::table('valuation_reports')->where('owner_id', $this->owner($request)->id)->orderByDesc('id')->limit(20)->get()->map(fn (stdClass $row): array => ['id' => (string) $row->id, 'reference' => $row->reference, 'cutoff' => LedgerInput::utc($row->cutoff), 'base_currency' => $row->base_currency])->all();

        return Inertia::render('ledger/Reports', ['evidence' => $evidence, 'saved' => $saved, 'reports' => $history, 'currencies' => $this->currencies()]);
    }

    private function saved(Request $request, stdClass $row, string $url): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['id' => (string) $row->id, 'reference' => $row->reference ?? null, 'status' => $row->status ?? 'saved', 'url' => $url], 201);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. Your durable record is ready.']);

        return redirect($url);
    }
}
