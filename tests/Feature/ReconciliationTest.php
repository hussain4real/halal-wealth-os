<?php

use App\Domain\Ledger\AccountBalanceReader;
use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Ledger\FinancialSnapshot;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\ReconciliationService;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\FxFixtures as Fx;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('a saved reconciliation comparison is immutable and an explicit adjustment produces a linked zero difference', function () {
    $owner = User::factory()->create();
    $account = Fx::account($owner);
    $cutoff = now('UTC')->format('Y-m-d\TH:i:s\Z');
    $service = app(ReconciliationService::class);
    $comparison = $service->record($owner, 'reconcile-before', (string) $account->id, '19.10', $cutoff);
    expect((string) $comparison->difference)->toBe('1910')->and($comparison->status)->toBe('difference');
    $bytes = $comparison->evidence;
    $result = $service->adjust($owner, (string) $comparison->id, 'reconcile-adjust', 'equity', 'Synthetic statement correction');
    expect($result->status)->toBe('matched')->and((string) $result->difference)->toBe('0')->and($result->supersedes_id)->toBe($comparison->id);
    expect(DB::table('reconciliation_sessions')->find($comparison->id)->evidence)->toBe($bytes);
    expect($service->adjust($owner, (string) $comparison->id, 'reconcile-adjust', 'equity', 'Synthetic statement correction')->id)->toBe($result->id);
    expect(fn () => $service->adjust($owner, (string) $comparison->id, 'reconcile-other', 'equity', 'Synthetic statement correction'))->toThrow(ValidationException::class);
    expect(DB::table('journal_entries')->whereIn('kind', ['income', 'expense'])->count())->toBe(0);
    $journal = DB::table('journal_entries')->find($result->adjustment_journal_id);
    expect($journal->description)->toBe('Synthetic statement correction')->and($journal->effective_date)->toBe(substr($cutoff, 0, 10));
    app(PostingService::class)->reverse($owner, (string) $journal->id, 'reverse-statement-adjust', 'Synthetic adjustment reversal');
    $next = $service->record($owner, 'reconcile-after-reverse', (string) $account->id, '19.10', $cutoff, (string) $result->id);
    expect((string) $next->difference)->toBe('1910')->and(app(AccountBalanceReader::class)->forAccount($account)->money?->minor)->toBe('0');
});

test('stale reconciliation refuses even a net zero change in journal evidence', function () {
    $owner = User::factory()->create();
    $account = Fx::account($owner);
    $service = app(ReconciliationService::class);
    $comparison = $service->record($owner, 'reconcile-stale', (string) $account->id, '12.00', now('UTC')->format('Y-m-d\TH:i:s\Z'));
    $posting = app(PostingService::class);
    $journal = $posting->record($owner, 'stale-extra-income', ['kind' => 'income', 'account_id' => (string) $account->id, 'amount' => '1.00', 'effective_date' => now()->toDateString(), 'description' => 'Synthetic extra evidence']);
    $posting->reverse($owner, (string) $journal->id, 'stale-extra-reverse', 'Synthetic net-zero change');
    expect(app(AccountBalanceReader::class)->forAccount($account)->money?->minor)->toBe('0');
    expect(fn () => $service->adjust($owner, (string) $comparison->id, 'stale-adjustment', 'equity', 'Reject stale evidence'))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('reconciliation_adjustments', 0);
});

test('historical cutoffs retain originals before later reversals and saved evidence never changes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
    $owner = User::factory()->create();
    $account = Fx::account($owner);
    $journal = app(PostingService::class)->record($owner, 'historical-income', ['kind' => 'income', 'account_id' => (string) $account->id, 'amount' => '12.34', 'effective_date' => '2026-10-15', 'description' => 'Synthetic historical receipt']);
    $cutoff = '2026-10-15T12:00:00Z';
    $saved = app(ReconciliationService::class)->record($owner, 'historical-compare', (string) $account->id, '12.34', $cutoff);
    $bytes = $saved->evidence;
    $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00Z'));
    app(PostingService::class)->reverse($owner, (string) $journal->id, 'historical-reverse', 'Synthetic next-day reversal');
    $old = app(FinancialSnapshot::class)->capture($owner, Currency::QAR, $cutoff);
    $new = app(FinancialSnapshot::class)->capture($owner, Currency::QAR, '2026-10-16T12:00:00Z');
    expect($old['components'][0]['native']['minor'])->toBe('1234')->and($new['components'][0]['native']['minor'])->toBe('0');
    expect(DB::table('reconciliation_sessions')->find($saved->id)->evidence)->toBe($bytes);
});

test('later opening consumption is retrospective without rewriting saved unknown evidence or consuming again', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
    $owner = User::factory()->create();
    $account = app(CreateAccount::class)->create($owner, 'Synthetic historical opening', AccountType::Bank, Currency::QAR, Money::fromMinor(Currency::QAR, '500'));
    $snapshots = app(FinancialSnapshot::class);
    $saved = app(ReconciliationService::class)->record($owner, 'opening-before-consume', (string) $account->id, '5.00', '2026-10-15T12:00:00Z');
    expect($saved->status)->toBe('unavailable')->and($saved->difference)->toBeNull();
    $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00Z'));
    $posting = app(PostingService::class);
    $opening = $posting->postOpening($owner, (string) $account->id, 'historical-opening-post');
    expect($snapshots->capture($owner, Currency::QAR, '2026-10-15T12:00:00Z')['components'][0]['native']['minor'])->toBe('500');
    expect($snapshots->capture($owner, Currency::QAR, '2026-10-14T12:00:00Z')['components'][0]['native_reason'])->toBe('opening_after_cutoff');
    $posting->reverse($owner, (string) $opening->id, 'historical-opening-reverse', 'Synthetic opening correction');
    $old = $snapshots->capture($owner, Currency::QAR, '2026-10-15T12:00:00Z');
    $current = $snapshots->capture($owner, Currency::QAR, '2026-10-16T12:00:00Z');
    expect($old['components'][0]['opening']['state'])->toBe('posted')->and($old['components'][0]['native']['minor'])->toBe('500')
        ->and($current['components'][0]['opening']['state'])->toBe('reversed')->and($current['components'][0]['native']['minor'])->toBe('0');
    expect(fn () => $posting->postOpening($owner, (string) $account->id, 'historical-opening-again'))->toThrow(ValidationException::class);
    expect(DB::table('reconciliation_sessions')->find($saved->id)->difference)->toBeNull();
});

test('MIN reconciliation adjustments and their split inverses preserve the complete Money range', function () {
    $owner = User::factory()->create();
    $account = Fx::account($owner);
    $service = app(ReconciliationService::class);
    $saved = $service->record($owner, 'minimum-comparison', (string) $account->id, Money::fromMinor(Currency::QAR, Money::MIN_MINOR)->amount(), now('UTC')->format('Y-m-d\TH:i:s\Z'));
    $result = $service->adjust($owner, (string) $saved->id, 'minimum-adjustment', 'equity', 'Synthetic minimum correction');
    expect($result->status)->toBe('matched')->and((string) $result->ledger_minor)->toBe(Money::MIN_MINOR);
    app(PostingService::class)->reverse($owner, (string) $result->adjustment_journal_id, 'minimum-adjustment-reverse', 'Synthetic minimum reversal');
    expect(app(AccountBalanceReader::class)->forAccount($account)->money?->minor)->toBe('0');
});

test('unbounded statement differences remain exact while unsupported single corrections are refused', function () {
    $owner = User::factory()->create();
    $account = app(CreateAccount::class)->create($owner, 'Synthetic difference bound', AccountType::Bank, Currency::QAR, Money::fromMinor(Currency::QAR, Money::MIN_MINOR));
    app(PostingService::class)->postOpening($owner, (string) $account->id, 'difference-bound-opening');
    $service = app(ReconciliationService::class);
    $saved = $service->record($owner, 'large-difference-comparison', (string) $account->id, Money::fromMinor(Currency::QAR, Money::MAX_MINOR)->amount(), now('UTC')->format('Y-m-d\TH:i:s\Z'));
    expect((string) $saved->difference)->toBe('18446744073709551615');
    expect(fn () => $service->adjust($owner, (string) $saved->id, 'large-difference-adjustment', 'equity', 'Review unsupported single amount'))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('reconciliation_adjustments', 0);
});
