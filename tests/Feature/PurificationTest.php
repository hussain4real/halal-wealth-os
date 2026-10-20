<?php

use App\Domain\Ledger\PostingService;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shariah\PurificationRead;
use App\Domain\Shariah\PurificationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\AssessmentFixtures as A;
use Tests\Support\InvestmentFixtures as I;
use Tests\Support\PurificationFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->f = F::distribution($this->owner);
    $this->service = app(PurificationService::class);
    $this->read = app(PurificationRead::class);
});

test('F12 sourced gross distribution produces NGN2 without using net income or market value and cash debits once on actual payment', function () {
    $tables = ['journal_entries', 'postings', 'investment_events', 'portfolio_opening_rows', 'investment_valuation_runs'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    $o = F::obligation($this->owner, $this->f);
    expect((string) $o->base_minor)->toBe('10000')->and((string) $o->due_minor)->toBe('200')->and((string) $o->assessment_id)->toBe((string) $this->f['assessment']->id);
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($before[$table]);
    }
    $input = F::paymentInput($this->f);
    $cash = (string) DB::table('postings')->where('account_id', $this->f['account']->id)->sum('amount_minor');
    $p = $this->service->payment($this->owner, 'synthetic-f12-payment', $input);
    $after = (string) DB::table('postings')->where('account_id', $this->f['account']->id)->sum('amount_minor');
    expect(bcsub($cash, $after, 0))->toBe('200');
    expect($this->service->payment($this->owner, 'synthetic-f12-payment', $input)->id)->toBe($p->id);
    $a = F::allocate($this->owner, $o, $p, '2', 'synthetic-f12-allocation');
    expect((string) DB::table('postings')->where('account_id', $this->f['account']->id)->sum('amount_minor'))->toBe($after);
    $view = $this->read->detail($this->owner, (string) $o->id);
    expect($view['obligation']['status'])->toBe('settled')->and($view['obligation']['paid']['minor'])->toBe('200')->and($view['obligation']['outstanding']['minor'])->toBe('0')
        ->and($view['allocations'][0]['id'])->toBe((string) $a->id);
});
test('explicit net and separately sourced custom bases and fixed amounts remain explainable', function () {
    $net = F::obligation($this->owner, $this->f, ['base_kind' => 'net']);
    expect((string) $net->base_minor)->toBe('9400')->and((string) $net->due_minor)->toBe('188');
    $custom = F::obligation($this->owner, $this->f, ['method_reference' => 'SYNTHETIC-CUSTOM', 'base_kind' => 'custom', 'custom_base' => '40', 'base_explanation' => 'Synthetic source applicable income portion']);
    expect((string) $custom->due_minor)->toBe('80');
    $fixed = F::obligation($this->owner, $this->f, ['method_reference' => 'SYNTHETIC-FIXED', 'calculation' => 'fixed', 'rate_percent' => '', 'fixed_amount' => '1.25']);
    expect((string) $fixed->due_minor)->toBe('125');
});
test('F15 missing instructions preserve pending uncertainty rather than zero due', function (array $changes) {
    $o = F::obligation($this->owner, $this->f, $changes);
    $view = $this->read->obligation($this->owner, $o);
    expect($o->due_minor)->toBeNull()->and($view['status'])->toBe('pending')->and($view['outstanding'])->toBeNull()->and($view['pending_explanation'])->not->toBeNull();
    $p = F::payment($this->owner, $this->f);
    expect(fn () => F::allocate($this->owner, $o, $p))->toThrow(ValidationException::class);
})->with(array_map(fn ($x) => [$x], [['rate_percent' => ''], ['base_kind' => 'missing'], ['assessment_id' => ''], ['source_instruction' => ''], ['method_version' => ''], ['calculation' => 'missing', 'rate_percent' => ''], ['base_kind' => 'custom', 'custom_base' => ''], ['calculation' => 'fixed', 'rate_percent' => '', 'fixed_amount' => '']]));
test('a specifically sourced zero is known zero and is distinct from pending', function () {
    $o = F::obligation($this->owner, $this->f, ['rate_percent' => '0']);
    expect((string) $o->due_minor)->toBe('0')->and($this->read->obligation($this->owner, $o)['status'])->toBe('settled');
});
test('partial allocations reconcile and cannot exceed payment capacity across multiple obligations', function () {
    $o = F::obligation($this->owner, $this->f);
    $other = F::obligation($this->owner, $this->f, ['method_reference' => 'SYNTHETIC-OTHER']);
    $p = F::payment($this->owner, $this->f);
    F::allocate($this->owner, $o, $p, '0.75');
    expect($this->read->obligation($this->owner, $o)['status'])->toBe('partially_paid')->and($this->read->obligation($this->owner, $o)['outstanding']['minor'])->toBe('125');
    expect(fn () => F::allocate($this->owner, $other, $p, '1.26'))->toThrow(ValidationException::class);
    F::allocate($this->owner, $other, $p, '1.25');
    expect($this->read->payment($this->owner, $p)['available']['minor'])->toBe('0');
    expect(fn () => F::allocate($this->owner, $o, $p, '0.01'))->toThrow(ValidationException::class);
});
test('over-allocation to an obligation is refused even when actual payment has spare capacity', function () {
    $o = F::obligation($this->owner, $this->f);
    $p = F::payment($this->owner, $this->f, ['amount' => '5']);
    expect(fn () => F::allocate($this->owner, $o, $p, '2.01'))->toThrow(ValidationException::class);
    F::allocate($this->owner, $o, $p);
    expect($this->read->payment($this->owner, $p)['available']['minor'])->toBe('300');
});
test('exact obligation retry recovers after later revisions while changed retry and duplicate business identity fail', function () {
    $input = F::input($this->owner, $this->f);
    $first = $this->service->obligation($this->owner, 'synthetic-obligation-retry', $input);
    $next = F::obligation($this->owner, $this->f, ['supersedes_id' => (string) $first->id, 'rate_percent' => '3', 'method_version' => 'Synthetic v2']);
    expect($this->service->obligation($this->owner, 'synthetic-obligation-retry', $input)->id)->toBe($first->id);
    expect(fn () => $this->service->obligation($this->owner, 'synthetic-obligation-retry', [...$input, 'rate_percent' => '4']))->toThrow(ValidationException::class);
    expect(fn () => F::obligation($this->owner, $this->f))->toThrow(ValidationException::class);
    expect($this->read->detail($this->owner, (string) $first->id)['obligation']['id'])->toBe((string) $next->id);
});
test('upward and downward corrections retain version attribution payments and visible excess without moving cash', function () {
    $first = F::obligation($this->owner, $this->f);
    $p = F::payment($this->owner, $this->f);
    $a = F::allocate($this->owner, $first, $p);
    $journal = DB::table('postings')->orderBy('id')->get()->toJson();
    $up = F::obligation($this->owner, $this->f, ['supersedes_id' => (string) $first->id, 'rate_percent' => '3', 'method_version' => 'Synthetic v2']);
    expect($this->read->obligation($this->owner, $up)['outstanding']['minor'])->toBe('100');
    $down = F::obligation($this->owner, $this->f, ['supersedes_id' => (string) $up->id, 'rate_percent' => '1', 'method_version' => 'Synthetic v3']);
    $v = $this->read->detail($this->owner, (string) $down->id);
    expect($v['obligation']['paid']['minor'])->toBe('200')->and($v['obligation']['excess']['minor'])->toBe('100')->and($v['obligation']['outstanding']['minor'])->toBe('0')
        ->and($v['allocations'][0]['obligation_id'])->toBe((string) $first->id)->and($this->read->payment($this->owner, $p)['available']['minor'])->toBe('0');
    $release = $this->service->release($this->owner, (string) $a->id, 'synthetic-release-retry', ['reason' => 'Synthetic allocation correction']);
    expect($this->service->release($this->owner, (string) $a->id, 'synthetic-release-retry', ['reason' => 'Synthetic allocation correction'])->id)->toBe($release->id);
    expect($this->read->payment($this->owner, $p)['available']['minor'])->toBe('200')->and($this->read->obligation($this->owner, $down)['outstanding']['minor'])->toBe('100');
    expect(DB::table('postings')->orderBy('id')->get()->toJson())->toBe($journal);
});
test('linking an existing expense attributes charity explicitly without another journal or cash debit', function () {
    $journal = app(PostingService::class)->record($this->owner, 'synthetic-existing-charity', ['kind' => 'expense', 'account_id' => (string) $this->f['account']->id, 'amount' => '2', 'effective_date' => now('UTC')->toDateString(), 'description' => 'Synthetic actual payment']);
    $before = DB::table('postings')->orderBy('id')->get()->toJson();
    $p = F::payment($this->owner, $this->f, ['mode' => 'link', 'journal_id' => (string) $journal->id, 'account_id' => '', 'amount' => '', 'effective_date' => '', 'description' => '']);
    expect((string) $p->journal_id)->toBe((string) $journal->id)->and(DB::table('postings')->orderBy('id')->get()->toJson())->toBe($before);
    expect(fn () => F::payment($this->owner, $this->f, ['mode' => 'link', 'journal_id' => (string) $journal->id, 'account_id' => '', 'amount' => '', 'effective_date' => '', 'description' => '']))->toThrow(ValidationException::class);
});
test('a duplicate payment business reference with a new request key rolls back without another expense', function () {
    F::payment($this->owner, $this->f, ['payment_reference' => 'SYNTHETIC-SAME-RECEIPT']);
    $before = DB::table('postings')->orderBy('id')->get()->toJson();
    expect(fn () => F::payment($this->owner, $this->f, ['payment_reference' => 'SYNTHETIC-SAME-RECEIPT']))->toThrow(ValidationException::class);
    expect(DB::table('postings')->orderBy('id')->get()->toJson())->toBe($before);
});
test('a reversed linked payment reopens the obligation and exact allocation retry remains recoverable', function () {
    $o = F::obligation($this->owner, $this->f);
    $input = F::paymentInput($this->f);
    $p = $this->service->payment($this->owner, 'synthetic-reverse-payment', $input);
    $a = F::allocate($this->owner, $o, $p, '2', 'synthetic-reverse-allocation');
    app(PostingService::class)->reverse($this->owner, (string) $p->journal_id, 'synthetic-charity-reversal', 'Synthetic recorded payment correction');
    expect($this->read->obligation($this->owner, $o)['status'])->toBe('outstanding')->and($this->read->obligation($this->owner, $o)['paid']['minor'])->toBe('0')
        ->and($this->service->payment($this->owner, 'synthetic-reverse-payment', $input)->id)->toBe($p->id)
        ->and(F::allocate($this->owner, $o, $p, '2', 'synthetic-reverse-allocation')->id)->toBe($a->id);
    expect(fn () => F::allocate($this->owner, $o, $p, '0.01'))->toThrow(ValidationException::class);
    expect($this->read->detail($this->owner, (string) $o->id)['allocations'][0]['active'])->toBeFalse();
});
test('replaced distribution retains one method series and requires explicit sourced version before more allocation', function () {
    $o = F::obligation($this->owner, $this->f);
    $d = $this->f['distribution'];
    $next = I::event($this->owner, $this->f['portfolio'], $this->f['asset'], ['kind' => 'dividend', 'amount' => '200', 'fee' => '1', 'tax' => '5', 'replaces_id' => (string) $d->id, 'action_reference' => $d->action_reference]);
    expect($this->read->obligation($this->owner, $o)['source_changed'])->toBeTrue();
    $f = [...$this->f, 'distribution' => $next];
    expect(fn () => F::obligation($this->owner, $f))->toThrow(ValidationException::class);
    $corrected = F::obligation($this->owner, $f, ['supersedes_id' => (string) $o->id, 'method_version' => 'Synthetic corrected basis']);
    expect((string) $corrected->due_minor)->toBe('400')->and($corrected->series_id)->toBe($o->series_id);
});
test('unrelated source assessment personal notes and mismatched currencies cannot become supported instructions or allocations', function () {
    $note = A::record($this->owner, $this->f['asset'], ['provenance' => 'personal_note', 'classification' => 'pending_review']);
    expect(fn () => F::obligation($this->owner, $this->f, ['assessment_id' => (string) $note->id]))->toThrow(ValidationException::class);
    $o = F::obligation($this->owner, $this->f);
    $foreign = F::distribution($this->owner, Currency::USD);
    $p = F::payment($this->owner, $foreign);
    expect(fn () => F::allocate($this->owner, $o, $p))->toThrow(ValidationException::class);
});
test('invalid payment attribution after posting rolls back the new journal atomically', function () {
    $before = DB::table('journal_entries')->count();
    expect(fn () => F::payment($this->owner, $this->f, ['evidence' => '']))->toThrow(ValidationException::class);
    expect(DB::table('journal_entries')->count())->toBe($before);
});

test('full precision tiny percentages survive native storage and database calculation guards', function () {
    $o = F::obligation($this->owner, $this->f, ['base_kind' => 'custom', 'custom_base' => '92233720368547758.07', 'base_explanation' => 'Synthetic explicitly sourced large income base', 'rate_percent' => '0.000000000001']);
    expect((string) $o->due_minor)->toBe('92234')->and((string) $o->base_minor)->toBe('9223372036854775807');
});
test('investment fees and ordinary income cannot be relabelled as charity payment journals', function () {
    $fee = I::event($this->owner, $this->f['portfolio'], $this->f['asset'], ['kind' => 'fee', 'amount' => '2']);
    $journal = DB::table('investment_event_journals')->where('event_id', $fee->id)->value('journal_id');
    $income = DB::table('journal_entries')->where('owner_id', $this->owner->id)->where('kind', 'income')->first();
    foreach ([(string) $journal, (string) $income->id] as $id) {
        expect(fn () => F::payment($this->owner, $this->f, ['mode' => 'link', 'journal_id' => $id, 'account_id' => '', 'amount' => '', 'effective_date' => '', 'description' => '']))->toThrow(ValidationException::class);
    }
});
test('a generic corrected expense reopens its original obligation without assuming replacement charity attribution', function () {
    $o = F::obligation($this->owner, $this->f);
    $p = F::payment($this->owner, $this->f);
    F::allocate($this->owner, $o, $p);
    $replacement = app(PostingService::class)->correct($this->owner, (string) $p->journal_id, 'synthetic-corrected-charity', ['kind' => 'expense', 'account_id' => (string) $this->f['account']->id, 'amount' => '1.5', 'effective_date' => now('UTC')->toDateString(), 'description' => 'Synthetic corrected actual payment']);
    expect($this->read->obligation($this->owner, $o)['paid']['minor'])->toBe('0')->and(DB::table('purification_payments')->where('journal_id', $replacement->id)->exists())->toBeFalse();
    $linked = F::payment($this->owner, $this->f, ['mode' => 'link', 'journal_id' => (string) $replacement->id, 'account_id' => '', 'amount' => '', 'effective_date' => '', 'description' => '']);
    F::allocate($this->owner, $o, $linked, '1.5');
    expect($this->read->obligation($this->owner, $o)['outstanding']['minor'])->toBe('50');
});
