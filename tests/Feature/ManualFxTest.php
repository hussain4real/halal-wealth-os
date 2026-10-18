<?php

use App\Domain\Ledger\AccountBalanceReader;
use App\Domain\Ledger\FinancialSnapshot;
use App\Domain\Ledger\FxObservationService;
use App\Domain\Ledger\FxService;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\ReconciliationService;
use App\Domain\Shared\Money\Currency;
use App\Models\JournalEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\FxFixtures as Fx;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('paired FX preserves both principal settlements and counts an explicit fee once', function (string $side) {
    $owner = User::factory()->create();
    $source = Fx::account($owner);
    $destination = Fx::account($owner, Currency::NGN);
    $input = Fx::input($source, $destination);
    $input['fee_side'] = $side;
    $operation = app(FxService::class)->record($owner, 'fx-fixture-0001', $input);
    expect($operation->status)->toBe('posted')->and((string) $operation->source_minor)->toBe('7325')->and((string) $operation->destination_minor)->toBe('2966625');
    expect(app(AccountBalanceReader::class)->forAccount($source)->money?->minor)->toBe($side === 'source' ? '-7575' : '-7325');
    expect(app(AccountBalanceReader::class)->forAccount($destination)->money?->minor)->toBe($side === 'source' ? '2966625' : '2966375');
    expect(DB::table('postings')->selectRaw('journal_id,sum(amount_minor::numeric) AS total')->groupBy('journal_id')->pluck('total')->all())->toBe(['0', '0', '0']);
    expect(DB::table('journal_entries')->where('kind', 'income')->count())->toBe(0)->and(DB::table('journal_entries')->where('kind', 'expense')->count())->toBe(1);
    expect(DB::table('accounts')->where('system_role', 'clearing')->count())->toBe(2);
    $rate = DB::table('fx_observations')->find($operation->actual_rate_id);
    expect($rate->base_currency)->toBe('QAR')->and($rate->quote_currency)->toBe('NGN')->and($rate->value)->toBe('405.000000000000')->and($rate->purpose)->toBe('transaction');
    expect(app(FinancialSnapshot::class)->capture($owner, Currency::QAR, now('UTC')->format('Y-m-d\TH:i:s\Z'))['components'])->toHaveCount(2);
})->with(['source', 'destination']);

test('FX root retries preserve identity and reject consequential changes', function (string $field, string $replacement) {
    $owner = User::factory()->create();
    $source = Fx::account($owner);
    $destination = Fx::account($owner, Currency::NGN);
    $input = Fx::input($source, $destination);
    $service = app(FxService::class);
    $first = $service->record($owner, 'fx-root-repeat', $input);
    expect($service->record($owner, 'fx-root-repeat', $input)->id)->toBe($first->id);
    $input[$field] = $replacement;
    expect(fn () => $service->record($owner, 'fx-root-repeat', $input))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('fx_operations', 1);
    $this->assertDatabaseCount('journal_entries', 3);
})->with([['source_amount', '74.25'], ['fee_amount', '3.50'], ['rate_value', '406'], ['rate_source', 'Changed synthetic source'], ['description', 'Changed description']]);

test('FX failure at each write checkpoint rolls back every journal rate clearing account and successful key', function (string $checkpoint) {
    $owner = User::factory()->create();
    $source = Fx::account($owner);
    $destination = Fx::account($owner, Currency::NGN);
    $input = Fx::input($source, $destination);
    $enabled = true;
    $members = 0;
    DB::listen(function (QueryExecuted $query) use ($checkpoint, &$enabled, &$members): void {
        if (! $enabled) {
            return;
        }
        if (str_contains($query->sql, 'insert into "fx_operation_journals"')) {
            $members++;
            if ((string) $members === $checkpoint) {
                throw new RuntimeException('Injected FX rollback');
            }
        }
        if ($checkpoint === 'completion' && str_contains($query->sql, 'update "fx_operations"')) {
            throw new RuntimeException('Injected FX rollback');
        }
    });
    expect(fn () => app(FxService::class)->record($owner, 'fx-failure-retry', $input))->toThrow(RuntimeException::class, 'Injected FX rollback');
    $enabled = false;
    foreach (['fx_operations', 'fx_observations', 'fx_operation_journals', 'journal_entries', 'postings'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    expect(DB::table('accounts')->whereNotNull('system_role')->count())->toBe(0);
    expect(app(FxService::class)->record($owner, 'fx-failure-retry', $input)->status)->toBe('posted');
})->with(['1', '2', '3', 'completion']);

test('group reversal includes the fee and generic constituent correction paths are denied', function () {
    $owner = User::factory()->create();
    $source = Fx::account($owner);
    $destination = Fx::account($owner, Currency::NGN);
    $input = Fx::input($source, $destination);
    $service = app(FxService::class);
    $operation = $service->record($owner, 'fx-group-original', $input);
    foreach (DB::table('fx_operation_journals')->where('operation_id', $operation->id)->get() as $member) {
        expect(fn () => app(PostingService::class)->reverse($owner, (string) $member->journal_id, 'generic-reversal', 'Do not split FX'))->toThrow(ValidationException::class);
        expect(fn () => app(PostingService::class)->correct($owner, (string) $member->journal_id, 'generic-correction', []))->toThrow(ValidationException::class);
    }
    $this->assertDatabaseCount('journal_entries', 3);
    $reversed = $service->reverse($owner, (string) $operation->id, 'fx-group-reversal', 'Synthetic full correction');
    expect($reversed->status)->toBe('reversed')->and($service->reverse($owner, (string) $operation->id, 'fx-group-reversal', 'Synthetic full correction')->id)->toBe($operation->id);
    expect(app(AccountBalanceReader::class)->forAccount($source)->money?->minor)->toBe('0')->and(app(AccountBalanceReader::class)->forAccount($destination)->money?->minor)->toBe('0');
    expect(JournalEntry::query()->where('status', 'reversed')->count())->toBe(3);
    $this->assertDatabaseCount('journal_entries', 6);
    expect(fn () => $service->reverse($owner, (string) $operation->id, 'fx-another-key', 'Synthetic full correction'))->toThrow(ValidationException::class);
});

test('FX replacement reverses all prior members and retains separate immutable rate evidence', function () {
    $owner = User::factory()->create();
    $source = Fx::account($owner);
    $destination = Fx::account($owner, Currency::NGN);
    $input = Fx::input($source, $destination);
    $service = app(FxService::class);
    $original = $service->record($owner, 'fx-before-correction', $input);
    $input['destination_amount'] = '29700.00';
    $input['rate_value'] = '405.5';
    $replacement = $service->record($owner, 'fx-after-correction', $input, (string) $original->id);
    expect($service->owned($owner, (string) $original->id)->status)->toBe('reversed')->and($replacement->replaces_id)->toBe($original->id)
        ->and($replacement->actual_rate_id)->not->toBe($original->actual_rate_id);
    expect(app(AccountBalanceReader::class)->forAccount($source)->money?->minor)->toBe('-7575')->and(app(AccountBalanceReader::class)->forAccount($destination)->money?->minor)->toBe('2970000');
});

test('reporting uses only eligible direct observations and preserves saved version evidence', function () {
    $owner = User::factory()->create();
    $account = Fx::account($owner, Currency::NGN);
    app(PostingService::class)->record($owner, 'fx-report-income', ['kind' => 'income', 'account_id' => (string) $account->id, 'amount' => '100.00', 'effective_date' => now()->toDateString(), 'description' => 'Synthetic reporting balance']);
    $cutoff = now('UTC')->format('Y-m-d\TH:i:s\Z');
    $snapshots = app(FinancialSnapshot::class);
    expect($snapshots->capture($owner, Currency::QAR, $cutoff)['state'])->toBe('missing')->and($snapshots->capture($owner, Currency::QAR, $cutoff)['total'])->toBeNull();
    $quote = app(FxObservationService::class)->record($owner, 'report-version-one', Fx::quote(value: '2'));
    $saved = $snapshots->save($owner, 'saved-report-one', Currency::QAR, $cutoff);
    $bytes = $saved->evidence;
    $input = Fx::quote(value: '3') + ['supersedes_id' => (string) $quote->id, 'correction_reason' => 'Synthetic revised evidence'];
    $revision = app(FxObservationService::class)->record($owner, 'report-version-two', $input);
    $fresh = $snapshots->capture($owner, Currency::QAR, $cutoff);
    expect($fresh['total']['minor'])->toBe('30000')->and($fresh['components'][0]['rate']['id'])->toBe((string) $revision->id);
    expect(DB::table('valuation_reports')->find($saved->id)->evidence)->toBe($bytes)->and($snapshots->save($owner, 'saved-report-one', Currency::QAR, $cutoff)->id)->toBe($saved->id);
    expect(json_decode($bytes, true)['total']['minor'])->toBe('20000');
});

test('future observations and effective dates are excluded from earlier reporting cutoffs', function (string $field) {
    $owner = User::factory()->create();
    Fx::account($owner, Currency::NGN);
    $input = Fx::quote();
    $cutoff = now('UTC')->subDays(2)->format('Y-m-d\TH:i:s\Z');
    $input['observed_at'] = now('UTC')->subDays(3)->format('Y-m-d\TH:i:s\Z');
    $input['effective_at'] = $input['observed_at'];
    $input[$field] = now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z');
    app(FxObservationService::class)->record($owner, 'future-cutoff-quote', $input);
    $snapshot = app(FinancialSnapshot::class)->capture($owner, Currency::QAR, $cutoff);
    expect($snapshot['state'])->toBe('missing')->and($snapshot['components'][0]['rate'])->toBeNull()->and($snapshot['components'][0]['converted'])->toBeNull();
})->with(['observed_at', 'effective_at']);

test('staleness boundary is explicit and native amounts survive missing partial conversion', function (int $seconds, string $state) {
    $owner = User::factory()->create();
    Fx::account($owner, Currency::NGN);
    $cutoff = now('UTC')->format('Y-m-d\TH:i:s\Z');
    $time = CarbonImmutable::parse($cutoff)->subSeconds($seconds)->format('Y-m-d\TH:i:s\Z');
    $input = Fx::quote();
    $input['observed_at'] = $time;
    $input['effective_at'] = $time;
    app(FxObservationService::class)->record($owner, 'stale-boundary-quote', $input);
    $snapshot = app(FinancialSnapshot::class)->capture($owner, Currency::QAR, $cutoff);
    expect($snapshot['state'])->toBe($state)->and($snapshot['components'][0]['native']['minor'])->toBe('0');
    Fx::account($owner);
    $partial = app(FinancialSnapshot::class)->capture($owner, Currency::QAR, $cutoff);
    expect($partial['state'])->toBe($state === 'stale' ? 'partial' : 'complete');
    if ($state === 'stale') {
        expect($partial['total'])->toBeNull()->and($partial['covered_subtotal']['minor'])->toBe('0');
    }
})->with([[604799, 'complete'], [604800, 'complete'], [604801, 'stale']]);

test('fee-free exchanges use only two principal journals and reject over-withheld destination cash', function () {
    $owner = User::factory()->create();
    $input = Fx::input(Fx::account($owner), Fx::account($owner, Currency::NGN));
    $input['fee_amount'] = '0';
    app(FxService::class)->record($owner, 'fx-without-fee', $input);
    $this->assertDatabaseCount('journal_entries', 2);
    expect(DB::table('journal_entries')->where('kind', 'expense')->count())->toBe(0);
    expect(fn () => app(FxService::class)->record($owner, 'fx-excess-fee', [...$input, 'fee_side' => 'destination', 'fee_amount' => '29666.26']))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('fx_operations', 1);
});

test('archived-account receipt replay and group reversal remain available while new exchange is denied', function () {
    $owner = User::factory()->create();
    $source = Fx::account($owner);
    $destination = Fx::account($owner, Currency::NGN);
    $input = Fx::input($source, $destination);
    $service = app(FxService::class);
    $first = $service->record($owner, 'fx-archive-stable', $input);
    DB::table('accounts')->where('id', $source->id)->update(['archived_at' => now()]);
    expect($service->record($owner, 'fx-archive-stable', $input)->id)->toBe($first->id);
    expect(fn () => $service->record($owner, 'fx-archive-newkey', $input))->toThrow(ValidationException::class);
    expect($service->reverse($owner, (string) $first->id, 'fx-archive-reverse', 'Synthetic archive reversal')->status)->toBe('reversed');
});

test('observations reports and reconciliation comparisons reject direct mutation and deletion', function (string $table) {
    $owner = User::factory()->create();
    $account = Fx::account($owner);
    $row = match ($table) {
        'fx_observations' => app(FxObservationService::class)->record($owner, 'immutable-rate-0001', Fx::quote()),
        'valuation_reports' => app(FinancialSnapshot::class)->save($owner, 'immutable-report-0001', Currency::QAR, now('UTC')->format('Y-m-d\TH:i:s\Z')),
        default => app(ReconciliationService::class)->record($owner, 'immutable-compare-001', (string) $account->id, '0', now('UTC')->format('Y-m-d\TH:i:s\Z')),
    };
    expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $row->id)->update(['created_at' => now()->subDay()])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $row->id)->delete()))->toThrow(QueryException::class);
    expect(DB::table($table)->where('id', $row->id)->exists())->toBeTrue();
})->with(['fx_observations', 'valuation_reports', 'reconciliation_sessions']);

test('quote revisions cannot change owned pair purpose or fork an already revised version', function () {
    $owner = User::factory()->create();
    $service = app(FxObservationService::class);
    $first = $service->record($owner, 'rate-version-base', Fx::quote());
    $revision = [...Fx::quote(), 'supersedes_id' => (string) $first->id, 'correction_reason' => 'Synthetic revision'];
    expect(fn () => $service->record($owner, 'rate-wrong-pair', [...$revision, 'quote_currency' => 'USD']))->toThrow(ValidationException::class);
    expect(fn () => $service->record($owner, 'rate-wrong-purpose', $revision, 'transaction'))->toThrow(ValidationException::class);
    $second = $service->record($owner, 'rate-version-second', [...$revision, 'value' => '0.003']);
    expect(fn () => $service->record($owner, 'rate-version-fork', $revision))->toThrow(ValidationException::class);
    expect($service->record($owner, 'rate-version-second', [...$revision, 'value' => '0.003'])->id)->toBe($second->id);
});
