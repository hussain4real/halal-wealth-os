<?php

use App\Domain\Imports\ImportRejected;
use App\Domain\Imports\StatementImports;
use App\Domain\Ledger\PostingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FxFixtures;
use Tests\Support\InvestmentFixtures as I;
use Tests\Support\PortfolioFixtures as P;
use Tests\Support\StatementFixtures as S;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->bank = FxFixtures::account($this->owner);
    $this->imports = app(StatementImports::class);
});

test('statement preview never posts and selected approval reuploads by provider and fingerprint exactly once', function () {
    $rows = [['provider_id' => 'PROVIDER-A'], ['description' => 'Synthetic no-ID expense', 'type' => 'expense', 'amount' => '2.01']];
    $s = S::preview($this->owner, $this->bank, $rows);
    $view = $this->imports->show($this->owner, (string) $s->id);
    expect(array_column($view['rows'], 'ready'))->toBe([true, true]);
    $this->assertDatabaseCount('journal_entries', 0);
    $r = $this->imports->approve($this->owner, (string) $s->id, 'synthetic-statement-approval', ['1']);
    expect($this->imports->approve($this->owner, (string) $s->id, 'synthetic-statement-approval', ['1'])->id)->toBe($r->id);
    $again = S::preview($this->owner, $this->bank, array_reverse($rows));
    $r2 = $this->imports->approve($this->owner, (string) $again->id, 'synthetic-reordered-approval', ['1', '2']);
    expect(array_column($this->imports->receipt($this->owner, (string) $r2->id)['outcomes'], 'type'))->toBe(['created', 'duplicate']);
    $this->assertDatabaseCount('journal_entries', 2);
    $this->imports->approve($this->owner, (string) $again->id, 'synthetic-third-approval', ['1', '2']);
    $this->assertDatabaseCount('journal_entries', 2);
    expect(DB::table('postings')->select('journal_id')->groupBy('journal_id')->havingRaw('sum(amount_minor)<>0')->exists())->toBeFalse();
});

test('unresolved repeated rows require explicit stable occurrences and byte replay cannot relabel a committed row', function () {
    $s = S::preview($this->owner, $this->bank, [[], []]);
    expect($this->imports->show($this->owner, (string) $s->id)['rows'][0]['errors'][0])->toContain('Indistinguishable');
    expect(fn () => $this->imports->approve($this->owner, (string) $s->id, 'synthetic-ambiguous-approval', ['1']))->toThrow(ImportRejected::class);
    $resolved = $this->imports->revise($this->owner, (string) $s->id, ['1' => ['occurrence' => 'first-recorded-occurrence'], '2' => ['occurrence' => 'second-recorded-occurrence']]);
    $this->imports->approve($this->owner, (string) $resolved->id, 'synthetic-resolved-approval', ['1', '2']);
    $this->assertDatabaseCount('journal_entries', 2);
    $original = $this->imports->show($this->owner, (string) $s->id);
    expect(array_column($original['rows'], 'ready'))->toBe([false, false])->and($original['rows'][0]['existing'])->not->toBeNull();
    $renamed = $this->imports->revise($this->owner, (string) $s->id, ['1' => ['occurrence' => 'new-label'], '2' => ['occurrence' => 'another-label']]);
    $this->imports->approve($this->owner, (string) $renamed->id, 'synthetic-relabeled-replay', ['1', '2']);
    $this->assertDatabaseCount('journal_entries', 2);
});

test('stable ID and same-source reinterpretation conflicts never create a guessed replacement', function () {
    $s = S::preview($this->owner, $this->bank, [['provider_id' => 'STABLE-A']]);
    $this->imports->approve($this->owner, (string) $s->id, 'synthetic-stable-first', ['1']);
    $changed = S::preview($this->owner, $this->bank, [['provider_id' => 'STABLE-A', 'amount' => '13.34']]);
    expect($this->imports->show($this->owner, (string) $changed->id)['rows'][0]['errors'][0])->toContain('different facts');
    expect(fn () => $this->imports->approve($this->owner, (string) $changed->id, 'synthetic-stable-conflict', ['1']))->toThrow(ImportRejected::class);
    $reinterpreted = $this->imports->revise($this->owner, (string) $s->id, ['1' => ['type' => 'expense']]);
    expect($this->imports->show($this->owner, (string) $reinterpreted->id)['rows'][0]['ready'])->toBeFalse();
    $this->assertDatabaseCount('journal_entries', 1);
});

test('a later real investment failure rolls back earlier posting and all batch evidence then retries consistently', function () {
    $account = P::account($this->owner);
    $portfolio = P::link($this->owner, $account);
    $asset = P::asset($this->owner);
    $s = S::preview($this->owner, $account, [['amount' => '10', 'provider_id' => 'FUNDING'],
        ['type' => 'buy', 'amount' => '56', 'quantity' => '8', 'asset_id' => (string) $asset->id, 'operation_reference' => 'SYNTHETIC-IMPORT-BUY', 'provider_id' => 'TRADE']]);
    expect(fn () => $this->imports->approve($this->owner, (string) $s->id, 'synthetic-atomic-import', ['1', '2']))->toThrow(ImportRejected::class);
    foreach (['journal_entries', 'postings', 'investment_events', 'statement_claims', 'statement_receipts'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    I::fund($this->owner, $account, '100');
    $r = $this->imports->approve($this->owner, (string) $s->id, 'synthetic-atomic-import', ['1', '2']);
    expect($this->imports->approve($this->owner, (string) $s->id, 'synthetic-atomic-import', ['2', '1'])->id)->toBe($r->id)
        ->and(I::replay($this->owner, $portfolio)['rows'][0]['quantity'])->toBe('8');
    $this->assertDatabaseCount('investment_events', 1);
    $this->assertDatabaseCount('statement_receipts', 1);
});

test('explicit transfer legs across accounts create one journal and exact existing matches add no cash', function () {
    $other = FxFixtures::account($this->owner);
    $out = S::preview($this->owner, $this->bank, [['type' => 'transfer_out', 'amount' => '4.57', 'counterparty_account_id' => (string) $other->id, 'operation_reference' => 'SYNTHETIC-TRANSFER', 'provider_id' => 'LEG-A']]);
    $r = $this->imports->approve($this->owner, (string) $out->id, 'synthetic-transfer-out', ['1']);
    $journal = $this->imports->receipt($this->owner, (string) $r->id)['outcomes'][0]['target']['journal_id'];
    $in = S::preview($this->owner, $other, [['type' => 'transfer_in', 'amount' => '4.57', 'counterparty_account_id' => (string) $this->bank->id, 'operation_reference' => 'SYNTHETIC-TRANSFER', 'provider_id' => 'LEG-B']]);
    $this->imports->approve($this->owner, (string) $in->id, 'synthetic-transfer-in', ['1']);
    $match = S::preview($this->owner, $other, [['type' => 'match_transfer', 'amount' => '4.57', 'match_id' => $journal, 'direction' => 'in']]);
    $m = $this->imports->approve($this->owner, (string) $match->id, 'synthetic-transfer-match', ['1']);
    expect($this->imports->receipt($this->owner, (string) $m->id)['outcomes'][0]['type'])->toBe('matched');
    expect(fn () => $this->imports->correct($this->owner, (string) $m->id, '1', 'synthetic-match-reversal', 'No implicit reversal authority'))->toThrow(ImportRejected::class);
    $this->assertDatabaseCount('journal_entries', 1);
    $this->assertDatabaseCount('postings', 2);
    $bad = S::preview($this->owner, $other, [['type' => 'match_transfer', 'amount' => '4.58', 'match_id' => $journal, 'direction' => 'in']]);
    expect($this->imports->show($this->owner, (string) $bad->id)['rows'][0]['errors'][0])->toContain('exact account');
});

test('new trade and exact event cash match retain one position and one investment cash journal', function () {
    $account = P::account($this->owner);
    $p = P::link($this->owner, $account);
    $asset = P::asset($this->owner);
    I::fund($this->owner, $account);
    $rows = [['type' => 'buy', 'amount' => '56', 'fee' => '0.08', 'cash_amount' => '-56.08', 'quantity' => '8', 'asset_id' => (string) $asset->id, 'operation_reference' => 'SYNTHETIC-TRADE', 'provider_id' => 'BROKER-TRADE']];
    $s = S::preview($this->owner, $account, $rows);
    $r = $this->imports->approve($this->owner, (string) $s->id, 'synthetic-trade-import', ['1']);
    $event = $this->imports->receipt($this->owner, (string) $r->id)['outcomes'][0]['target']['event_id'];
    $match = S::preview($this->owner, $account, [['type' => 'match_investment', 'amount' => '56.08', 'direction' => 'out', 'match_id' => $event, 'provider_id' => 'BROKER-CASH-LEG']]);
    $this->imports->approve($this->owner, (string) $match->id, 'synthetic-trade-match', ['1']);
    $this->imports->approve($this->owner, (string) $s->id, 'synthetic-trade-reupload', ['1']);
    expect(I::replay($this->owner, $p)['rows'][0]['quantity'])->toBe('8');
    $this->assertDatabaseCount('investment_events', 1);
    $this->assertDatabaseCount('journal_entries', 2);
    $bad = S::preview($this->owner, $account, [[...$rows[0], 'cash_amount' => '-56']]);
    expect($this->imports->show($this->owner, (string) $bad->id)['rows'][0]['errors'][0])->toContain('signed cash');
});

test('linked cash replacement cancellation and old source replay preserve nonforking provenance', function () {
    $s = S::preview($this->owner, $this->bank, [['provider_id' => 'CORRECTED-ID']]);
    $r = $this->imports->approve($this->owner, (string) $s->id, 'synthetic-original-import', ['1']);
    $replacement = S::preview($this->owner, $this->bank, [['provider_id' => 'CORRECTED-ID', 'amount' => '14.56']]);
    $corrected = $this->imports->correct($this->owner, (string) $r->id, '1', 'synthetic-replacement-import', 'Synthetic corrected source amount', (string) $replacement->id, '1');
    expect($this->imports->correct($this->owner, (string) $r->id, '1', 'synthetic-replacement-import', 'Synthetic corrected source amount', (string) $replacement->id, '1')->id)->toBe($corrected->id);
    expect(fn () => $this->imports->correct($this->owner, (string) $r->id, '1', 'synthetic-fork-import', 'Forbidden correction fork'))->toThrow(ImportRejected::class);
    $cancelled = $this->imports->correct($this->owner, (string) $corrected->id, '1', 'synthetic-cancel-import', 'Synthetic linked cancellation');
    expect($this->imports->show($this->owner, (string) $s->id)['rows'][0]['existing']['type'])->toBe('cancelled')
        ->and($this->imports->show($this->owner, (string) $replacement->id)['rows'][0]['existing']['type'])->toBe('cancelled');
    $this->imports->approve($this->owner, (string) $s->id, 'synthetic-cancelled-reupload', ['1']);
    $this->assertDatabaseCount('journal_entries', 4);
    expect(DB::table('postings')->sum('amount_minor'))->toBe(0)
        ->and(DB::table('journal_entries')->whereNotNull('reverses_journal_id')->count())->toBe(2)
        ->and($this->imports->receipt($this->owner, (string) $cancelled->id)['corrects_receipt_id'])->toBe((string) $corrected->id);
    expect(DB::table('statement_sources')->where('id', $s->source_id)->value('source_base64'))->toBe(base64_encode(S::bytes([['provider_id' => 'CORRECTED-ID']])));
});

test('committed receipt recovery precedes archived eligibility and changed selections are refused', function () {
    $s = S::preview($this->owner, $this->bank, [[]]);
    $r = $this->imports->approve($this->owner, (string) $s->id, 'synthetic-response-loss', ['1']);
    DB::table('accounts')->where('id', $this->bank->id)->update(['archived_at' => now()]);
    expect($this->imports->approve($this->owner, (string) $s->id, 'synthetic-response-loss', ['1'])->id)->toBe($r->id);
    expect(fn () => $this->imports->approve($this->owner, (string) $s->id, 'synthetic-response-loss', ['2']))->toThrow(ImportRejected::class);
    $this->assertDatabaseCount('journal_entries', 1);
});

test('contradictory supplied cash direction and unused financial relationships are row errors', function () {
    foreach ([['cash_amount' => '-12.34'], ['direction' => 'out'], ['match_id' => '123']] as $contradiction) {
        $s = S::preview($this->owner, $this->bank, [$contradiction]);
        expect($this->imports->show($this->owner, (string) $s->id)['rows'][0]['errors'])->not->toBeEmpty();
    }
    $this->assertDatabaseCount('journal_entries', 0);
});

test('explicit matches reject conflicting supplied counterparty and investment quantities', function () {
    $other = FxFixtures::account($this->owner);
    $wrong = FxFixtures::account($this->owner);
    $journal = app(PostingService::class)->record($this->owner, 'synthetic-match-source-transfer', ['kind' => 'transfer', 'account_id' => (string) $this->bank->id, 'destination_account_id' => (string) $other->id, 'amount' => '4.57', 'effective_date' => now('UTC')->toDateString(), 'description' => 'Synthetic transfer']);
    $s = S::preview($this->owner, $this->bank, [['type' => 'match_transfer', 'amount' => '4.57', 'direction' => 'out', 'match_id' => (string) $journal->id, 'counterparty_account_id' => (string) $wrong->id]]);
    expect($this->imports->show($this->owner, (string) $s->id)['rows'][0]['errors'])->not->toBeEmpty();
    $account = P::account($this->owner);
    $p = P::link($this->owner, $account);
    $asset = P::asset($this->owner);
    I::fund($this->owner, $account);
    $event = I::event($this->owner, $p, $asset);
    $s = S::preview($this->owner, $account, [['type' => 'match_investment', 'amount' => '56.08', 'direction' => 'out', 'match_id' => (string) $event->id, 'quantity' => '999']]);
    expect($this->imports->show($this->owner, (string) $s->id)['rows'][0]['errors'])->not->toBeEmpty();
});

test('provider availability mode switches fail closed while distinct provider IDs remain legitimate equal facts', function (bool $startsWithProvider) {
    $first = $startsWithProvider ? ['provider_id' => 'ORIGINAL-ID'] : [];
    $second = $startsWithProvider ? [] : ['provider_id' => 'ENRICHED-ID'];
    $s = S::preview($this->owner, $this->bank, [$first]);
    $this->imports->approve($this->owner, (string) $s->id, 'synthetic-mode-first', ['1']);
    $changed = S::preview($this->owner, $this->bank, [$second]);
    expect($this->imports->show($this->owner, (string) $changed->id)['rows'][0]['errors'])->not->toBeEmpty();
    expect(fn () => $this->imports->approve($this->owner, (string) $changed->id, 'synthetic-mode-second', ['1']))->toThrow(ImportRejected::class);
    $this->assertDatabaseCount('journal_entries', 1);
})->with([true, false]);

test('corrected transfer source and opposite leg replay recognize authorized hash history only', function () {
    $other = FxFixtures::account($this->owner);
    $out = ['type' => 'transfer_out', 'amount' => '4.57', 'counterparty_account_id' => (string) $other->id, 'operation_reference' => 'SYNTHETIC-CORRECTED-TRANSFER', 'provider_id' => 'OUTGOING'];
    $in = ['type' => 'transfer_in', 'amount' => '4.57', 'counterparty_account_id' => (string) $this->bank->id, 'operation_reference' => 'SYNTHETIC-CORRECTED-TRANSFER', 'provider_id' => 'INCOMING'];
    $s = S::preview($this->owner, $this->bank, [$out]);
    $r = $this->imports->approve($this->owner, (string) $s->id, 'synthetic-corrected-transfer-first', ['1']);
    $leg = S::preview($this->owner, $other, [$in]);
    $this->imports->approve($this->owner, (string) $leg->id, 'synthetic-corrected-transfer-leg', ['1']);
    $replacement = S::preview($this->owner, $this->bank, [[...$out, 'amount' => '5.67']]);
    $corrected = $this->imports->correct($this->owner, (string) $r->id, '1', 'synthetic-transfer-replacement', 'Synthetic transfer amount correction', (string) $replacement->id, '1');
    expect($this->imports->show($this->owner, (string) $replacement->id)['rows'][0]['existing']['receipt_id'] ?? null)->toBe((string) $corrected->id);
    $newLeg = S::preview($this->owner, $other, [[...$in, 'amount' => '5.67']]);
    expect($this->imports->show($this->owner, (string) $newLeg->id)['rows'][0]['existing']['receipt_id'] ?? null)->toBe((string) $corrected->id);
    $this->imports->approve($this->owner, (string) $newLeg->id, 'synthetic-corrected-leg-replay', ['1']);
    $conflict = S::preview($this->owner, $other, [[...$in, 'amount' => '99.99']]);
    expect($this->imports->show($this->owner, (string) $conflict->id)['rows'][0]['errors'])->not->toBeEmpty();
    $this->assertDatabaseCount('journal_entries', 3);
});

test('distinct provider IDs and separate account namespaces preserve legitimate equal transactions', function () {
    $s = S::preview($this->owner, $this->bank, [['provider_id' => 'LEGITIMATE-A'], ['provider_id' => 'LEGITIMATE-B']]);
    $this->imports->approve($this->owner, (string) $s->id, 'synthetic-distinct-real-events', ['1', '2']);
    $other = FxFixtures::account($this->owner);
    $separate = S::preview($this->owner, $other, [[]]);
    expect($this->imports->show($this->owner, (string) $separate->id)['rows'][0]['ready'])->toBeTrue();
    $this->imports->approve($this->owner, (string) $separate->id, 'synthetic-distinct-real-account', ['1']);
    $this->assertDatabaseCount('journal_entries', 3);
});

test('investment replacement and root reversal preserve exact units cash links and original source identity', function () {
    $account = P::account($this->owner);
    $p = P::link($this->owner, $account);
    $asset = P::asset($this->owner);
    I::fund($this->owner, $account);
    $row = ['type' => 'buy', 'amount' => '56', 'quantity' => '8', 'unit_price' => '7', 'fee' => '0.08', 'asset_id' => (string) $asset->id, 'operation_reference' => 'SYNTHETIC-CORRECTABLE-TRADE', 'provider_id' => 'CORRECTABLE-TRADE'];
    $s = S::preview($this->owner, $account, [$row]);
    $r = $this->imports->approve($this->owner, (string) $s->id, 'synthetic-trade-to-correct', ['1']);
    $replacement = S::preview($this->owner, $account, [[...$row, 'amount' => '42', 'quantity' => '6']]);
    $corrected = $this->imports->correct($this->owner, (string) $r->id, '1', 'synthetic-trade-corrected', 'Synthetic corrected trade source', (string) $replacement->id, '1');
    expect(I::replay($this->owner, $p)['rows'][0]['quantity'])->toBe('6');
    $this->imports->correct($this->owner, (string) $corrected->id, '1', 'synthetic-trade-cancelled', 'Synthetic complete trade reversal');
    expect(I::replay($this->owner, $p)['rows'])->toBeEmpty();
    $this->imports->approve($this->owner, (string) $s->id, 'synthetic-trade-old-source-replay', ['1']);
    $this->assertDatabaseCount('investment_events', 4);
    $this->assertDatabaseCount('investment_event_journals', 4);
    expect(DB::table('investment_events')->whereNotNull('reverses_id')->count())->toBe(2)
        ->and($this->imports->show($this->owner, (string) $s->id)['rows'][0]['existing']['type'])->toBe('cancelled');
});

test('standard investment CSV kinds preserve net cash fees tax and no implicit units', function () {
    $account = P::account($this->owner);
    $p = P::link($this->owner, $account);
    $asset = P::asset($this->owner);
    I::fund($this->owner, $account);
    $rows = [
        ['type' => 'buy', 'amount' => '56', 'quantity' => '8', 'unit_price' => '7', 'fee' => '0.08', 'cash_amount' => '-56.08', 'direction' => 'out', 'operation_reference' => 'SYNTHETIC-KINDS-BUY'],
        ['type' => 'sell', 'amount' => '14', 'quantity' => '2', 'fee' => '0.02', 'cash_amount' => '13.98', 'direction' => 'in', 'operation_reference' => 'SYNTHETIC-KINDS-SELL'],
        ['type' => 'dividend', 'amount' => '10', 'fee' => '0.10', 'tax' => '0.20', 'cash_amount' => '9.70', 'operation_reference' => 'SYNTHETIC-KINDS-DIVIDEND'],
        ['type' => 'fee', 'amount' => '1', 'cash_amount' => '-1', 'operation_reference' => 'SYNTHETIC-KINDS-FEE'],
    ];
    $s = S::preview($this->owner, $account, array_map(fn (array $r): array => [...$r, 'asset_id' => (string) $asset->id], $rows));
    $this->imports->approve($this->owner, (string) $s->id, 'synthetic-standard-investment-kinds', ['1', '2', '3', '4']);
    expect(I::replay($this->owner, $p)['rows'][0]['quantity'])->toBe('6')->and(DB::table('postings')->where('account_id', $account->id)->selectRaw('sum(amount_minor)::text AS amount')->value('amount'))->toBe('196660');
    $this->assertDatabaseCount('investment_events', 4);
});

test('correction cannot bypass repeated-row or cross-identity-mode ambiguity warnings', function () {
    $original = S::preview($this->owner, $this->bank, [['amount' => '12.34']]);
    $r = $this->imports->approve($this->owner, (string) $original->id, 'synthetic-ambiguity-root', ['1']);
    $repeated = S::preview($this->owner, $this->bank, [['amount' => '14.56'], ['amount' => '14.56']]);
    expect($this->imports->show($this->owner, (string) $repeated->id)['rows'][0]['errors'])->not->toBeEmpty();
    expect(fn () => $this->imports->correct($this->owner, (string) $r->id, '1', 'synthetic-ambiguous-replacement', 'No inferred occurrence', (string) $repeated->id, '1'))->toThrow(ImportRejected::class);
    $other = S::preview($this->owner, $this->bank, [['amount' => '15.67', 'provider_id' => 'SYNTHETIC-OTHER-OPERATION']]);
    $this->imports->approve($this->owner, (string) $other->id, 'synthetic-ambiguity-other', ['1']);
    $crossMode = S::preview($this->owner, $this->bank, [['amount' => '15.67']]);
    expect(fn () => $this->imports->correct($this->owner, (string) $r->id, '1', 'synthetic-cross-mode-replacement', 'No inferred identity', (string) $crossMode->id, '1'))->toThrow(ImportRejected::class);
    $this->assertDatabaseCount('journal_entries', 2);
    $this->assertDatabaseCount('statement_receipts', 2);
});

test('preview flags conflicting candidate provider facts before any partial selection can commit', function () {
    $s = S::preview($this->owner, $this->bank, [['provider_id' => 'SAME-PROVIDER-ID', 'amount' => '12.34'], ['provider_id' => 'SAME-PROVIDER-ID', 'amount' => '14.56']]);
    $view = $this->imports->show($this->owner, (string) $s->id);
    expect(array_column($view['rows'], 'ready'))->toBe([false, false]);
    expect(fn () => $this->imports->approve($this->owner, (string) $s->id, 'synthetic-conflicting-partial', ['1']))->toThrow(ImportRejected::class);
    $this->assertDatabaseCount('journal_entries', 0);
});

test('same-root provider conflict cannot mask independent cross-mode replacement ambiguity', function () {
    $s = S::preview($this->owner, $this->bank, [['provider_id' => 'SAME-ROOT-PROVIDER', 'amount' => '12.34']]);
    $r = $this->imports->approve($this->owner, (string) $s->id, 'synthetic-intersection-root', ['1']);
    $other = S::preview($this->owner, $this->bank, [['amount' => '14.56']]);
    $this->imports->approve($this->owner, (string) $other->id, 'synthetic-intersection-other', ['1']);
    $replacement = S::preview($this->owner, $this->bank, [['provider_id' => 'SAME-ROOT-PROVIDER', 'amount' => '14.56']]);
    expect(fn () => $this->imports->correct($this->owner, (string) $r->id, '1', 'synthetic-intersection-correction', 'Ambiguity remains independent', (string) $replacement->id, '1'))->toThrow(ImportRejected::class);
    $this->assertDatabaseCount('journal_entries', 2);
});

test('replaying a known unlabeled source row cannot claim its still pending equal occurrence', function () {
    $s = S::preview($this->owner, $this->bank, [[], []]);
    $resolved = $this->imports->revise($this->owner, (string) $s->id, ['1' => ['occurrence' => 'first'], '2' => ['occurrence' => 'second']]);
    $r = $this->imports->approve($this->owner, (string) $resolved->id, 'synthetic-partial-occurrence', ['1']);
    $this->imports->approve($this->owner, (string) $s->id, 'synthetic-known-unlabeled-replay', ['1']);
    expect($this->imports->show($this->owner, (string) $s->id)['rows'][1]['resolution_status'])->toBe('ambiguous');
    $this->imports->correct($this->owner, (string) $r->id, '1', 'synthetic-unlabeled-correction', 'Retain explicit occurrence identity', (string) $s->id, '1');
    expect($this->imports->show($this->owner, (string) $s->id)['rows'][1]['resolution_status'])->toBe('ambiguous');
    expect(fn () => $this->imports->approve($this->owner, (string) $s->id, 'synthetic-no-inferred-second', ['2']))->toThrow(ImportRejected::class);
    $this->imports->approve($this->owner, (string) $resolved->id, 'synthetic-explicit-second', ['2']);
    expect(DB::table('postings')->where('account_id', $this->bank->id)->sum('amount_minor'))->toBe('2468');
});

test('an overlapping statement cannot collapse repeated unlabeled occurrences through one old fallback fingerprint', function () {
    $single = S::preview($this->owner, $this->bank, [[]]);
    $this->imports->approve($this->owner, (string) $single->id, 'synthetic-single-old-occurrence', ['1']);
    $overlap = S::preview($this->owner, $this->bank, [[], []]);
    expect(array_column($this->imports->show($this->owner, (string) $overlap->id)['rows'], 'resolution_status'))->toBe(['ambiguous', 'ambiguous']);
    expect(fn () => $this->imports->approve($this->owner, (string) $overlap->id, 'synthetic-no-collapse-overlap', ['1', '2']))->toThrow(ImportRejected::class);
    $resolved = $this->imports->revise($this->owner, (string) $overlap->id, ['2' => ['occurrence' => 'confirmed-separate-occurrence']]);
    $this->imports->approve($this->owner, (string) $resolved->id, 'synthetic-confirmed-separate-overlap', ['2']);
    expect(DB::table('postings')->where('account_id', $this->bank->id)->sum('amount_minor'))->toBe('2468');
});
