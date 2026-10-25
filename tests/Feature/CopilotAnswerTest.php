<?php

use App\Domain\Intelligence\Answers\AnswerPlanner;
use App\Domain\Intelligence\CopilotBoundary;
use App\Domain\Intelligence\UntrustedInput;
use App\Domain\Reporting\AnswerSources;
use App\Domain\Reporting\Copilot\Answers\AnswerClaim;
use App\Domain\Reporting\Copilot\Answers\AnswerContext;
use App\Domain\Reporting\Copilot\Answers\AnswerPeriod;
use App\Domain\Reporting\Copilot\Answers\AnswerPlan;
use App\Domain\Reporting\CopilotAnswerFacts;
use App\Domain\Reporting\InvestmentValuation;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AnswerFixtures as A;
use Tests\Support\AnswerOracle;
use Tests\Support\AssessmentFixtures;
use Tests\Support\AvailabilityFixtures;
use Tests\Support\BudgetFixtures as B;
use Tests\Support\CopilotFixtures as C;
use Tests\Support\FxFixtures;
use Tests\Support\GoalFixtures;
use Tests\TestCase;

uses(TestCase::class);
beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    Http::preventStrayRequests();
});
afterEach(function () {
    Date::setTestNow();
});

function answerFinancialHash(): string
{
    $rows = [];
    foreach (DB::select("SELECT table_name FROM information_schema.columns WHERE table_schema='public' AND column_name='owner_id' ORDER BY table_name") as $table) {
        if (! in_array($table->table_name, ['copilot_controls', 'copilot_requests'], true)) {
            $values = DB::table($table->table_name)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($values, SORT_STRING);
            $rows[$table->table_name] = $values;
        }
    }

    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
}

$cases = json_decode(file_get_contents(__DIR__.'/../../resources/copilot/evaluation/questions-v1.json'), true, flags: JSON_THROW_ON_ERROR)['cases'];
test('fixed evaluation answers match independent exact facts and authorized citations without financial writes', function (array $case) {
    $fixture = A::seed($this->owner);
    C::allow($this->owner);
    $planner = new A($case['claim'], $case['mode'], $case['scope']);
    $this->app->instance(AnswerPlanner::class, $planner);
    $before = answerFinancialHash();
    $reference = (string) Str::uuid();
    $service = app(CopilotBoundary::class);
    $answer = $service->answer($reference, UntrustedInput::parse($case['question'], $case['document']), A::context($fixture, $case['missing'] ?? null), true);
    expect($answer['status'])->toBe('complete');
    expect(AnswerOracle::check($this->owner, $case, $answer['evidence'], $fixture))->not->toBeEmpty();
    expect(answerFinancialHash())->toBe($before)->and($planner->calls)->toBe(1);
    expect($service->answer($reference, UntrustedInput::parse($case['question'], $case['document']), A::context($fixture, $case['missing'] ?? null), true)['status'])->toBe('already_charged');
    expect($planner->calls)->toBe(1)->and(DB::table('copilot_requests')->value('lane'))->toBe('answer');
    expect(DB::table('copilot_requests')->get()->toJson())->not->toContain($case['question']);
    foreach ($answer['evidence']['citations'] as $citation) {
        $this->getJson($citation['href'])->assertOk()->assertJsonPath('record_id', $citation['record_id']);
    }
    Http::assertNothingSent();
})->with(array_combine(array_column($cases, 'id'), array_map(fn ($row) => [$row], $cases)));

test('foreign context and source table injection are rejected before model admission even for an admin', function () {
    $foreign = User::factory()->create();
    $fixture = A::seed($foreign);
    $this->owner->update(['is_admin' => true]);
    C::allow($this->owner);
    $planner = new A;
    $this->app->instance(AnswerPlanner::class, $planner);
    foreach (['goal_id', 'valuation_id', 'asset_id'] as $kind) {
        $context = ['goal_id' => null, 'valuation_id' => null, 'asset_id' => null, 'month' => '2026-10', 'cutoff' => '2026-10-20T23:59:59Z'];
        $context[$kind] = $fixture['context'][$kind];
        $this->postJson('/copilot/answer', ['reference' => (string) Str::uuid(), 'question' => 'cash', 'document' => '', 'context' => $context, 'disclosure_confirmed' => true])->assertNotFound();
    }
    foreach (['goal' => 'goal', 'asset' => 'asset', 'valuation' => 'run', 'assessment' => 'assessment', 'cash_settings' => 'settings'] as $kind => $record) {
        $this->getJson('/copilot/source/'.$kind.'/'.$fixture[$record]->id)->assertNotFound();
    }
    $this->getJson('/copilot/source/users/1')->assertNotFound();
    expect($planner->calls)->toBe(0);
    $this->assertDatabaseCount('copilot_requests', 0);
});

test('revocation and reconsent fence the old plan and exact receipt retry cannot execute again', function () {
    $f = A::seed($this->owner);
    C::allow($this->owner);
    $planner = new A;
    $this->app->instance(AnswerPlanner::class, $planner);
    $boundary = app(CopilotBoundary::class);
    $planner->onCall = function () use ($boundary): void {
        $boundary->consent(false, '0');
        $boundary->consent(true, '1');
    };
    $reference = (string) Str::uuid();
    expect($boundary->answer($reference, UntrustedInput::parse('available cash', ''), A::context($f), true)['status'])->toBe('unavailable');
    expect($boundary->answer($reference, UntrustedInput::parse('available cash', ''), A::context($f), true)['status'])->toBe('already_charged');
    expect($planner->calls)->toBe(1)->and($boundary->page()['consent'])->toBeTrue();
});

test('answer disclosure failures budgets and source constraints preserve privacy and ordinary core pages', function () {
    $f = A::seed($this->owner);
    C::allow($this->owner);
    $planner = new A;
    $this->app->instance(AnswerPlanner::class, $planner);
    $body = ['reference' => (string) Str::uuid(), 'question' => 'SYNTHETIC-PRIVATE-QUESTION', 'document' => 'SYNTHETIC-PRIVATE-DOCUMENT', 'context' => $f['context'], 'disclosure_confirmed' => false];
    $this->post('/copilot/answer', $body, ['Accept' => 'text/html'])->assertUnprocessable()->assertSessionMissing('_old_input');
    $body['disclosure_confirmed'] = true;
    foreach (['owner_id', 'tool', 'sql'] as $extra) {
        $this->postJson('/copilot/answer', [...$body, $extra => 'untrusted'])->assertUnprocessable();
    }
    for ($i = 0; $i < 4; $i++) {
        $this->postJson('/copilot/answer', [...$body, 'reference' => (string) Str::uuid()])->assertOk()->assertJsonPath('status', 'complete');
    }
    $this->postJson('/copilot/answer', [...$body, 'reference' => (string) Str::uuid()])->assertStatus(429);
    expect($planner->calls)->toBe(4)->and(DB::table('copilot_requests')->sum('reserved_tokens'))->toBe(65536);
    $this->get('/dashboard')->assertOk();
});

test('unknown geography cannot masquerade as known zero Nigeria exposure', function () {
    $f = C::seed($this->owner);
    $r = app(CopilotAnswerFacts::class)->answer($this->owner, AnswerPlan::parse(['claim' => 'nigeria.overview', 'mode' => 'recorded', 'scope' => 'selected']), AnswerContext::parse(['valuation_id' => (string) $f['run']->id, 'month' => now()->format('Y-m'), 'cutoff' => $f['cutoff']]), 'Nigeria exposure');
    expect($r['facts'][0]['value'])->toBeNull()->and($r['facts'][1]['value'])->toBeNull()->and($r['limitations'])->toContain('Unknown geography present: yes.');
});

test('missing essential run rate remains explicit while backed emergency money stays exact', function () {
    $f = A::seed($this->owner);
    $r = app(CopilotAnswerFacts::class)->answer($this->owner, AnswerPlan::parse(['claim' => 'emergency.coverage', 'mode' => 'recorded', 'scope' => 'current']), A::context($f), 'essential coverage');
    expect($r['facts'][0]['value'])->toBeNull()->and($r['completeness'])->toBe('incomplete')->and($r['limitations'])->toContain('essential_unavailable');
});

test('period mismatch cannot replace requested historical cash with current facts while money amounts are not years', function () {
    foreach (['How much cash yesterday?', 'cash in 2025', 'cash on 2025-01-01', 'cash next month'] as $q) {
        expect(AnswerPeriod::supported($q, AnswerClaim::CashAvailable, '2026-10-20T23:59:59Z'))->toBeFalse();
    }
    expect(AnswerPeriod::supported('How much remains toward my 2025 QAR target?', AnswerClaim::EmergencyRemaining, '2026-10-20T23:59:59Z'))->toBeTrue();
    expect(AnswerPeriod::supported('changes from 2026-10-19 to 2026-10-20', AnswerClaim::ChangesOverview, '2026-10-20T23:59:59Z', '2026-10-19T23:59:59Z'))->toBeTrue();
    $f = A::seed($this->owner);
    $r = app(CopilotAnswerFacts::class)->answer($this->owner, AnswerPlan::parse(['claim' => 'cash.available', 'mode' => 'recorded', 'scope' => 'current']), A::context($f), 'cash yesterday');
    expect($r['state'])->toBe('unsupported')->and($r['facts'])->toBe([]);
});

test('answer migration preserves predecessors and refuses downgrade before populated lane ddl', function () {
    C::allow($this->owner);
    $migration = require database_path('migrations/2026_10_25_080000_add_copilot_answer_lane.php');
    $migration->down();
    $migration->up();
    $f = A::seed($this->owner);
    $this->app->instance(AnswerPlanner::class, new A);
    expect(app(CopilotBoundary::class)->answer((string) Str::uuid(), UntrustedInput::parse('cash', ''), A::context($f), true)['status'])->toBe('complete');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(DB::getSchemaBuilder()->hasTable('journal_entries'))->toBeTrue();
});

test('negative signed availability and missing FX are never replaced with invented deployable cash', function () {
    $f = A::seed($this->owner);
    AvailabilityFixtures::settings($this->owner, ['prior_id' => (string) $f['settings']->id, 'buffer' => '900', 'next_income_date' => '2026-10-28']);
    $plan = AnswerPlan::parse(['claim' => 'cash.overview', 'mode' => 'recorded', 'scope' => 'current']);
    $r = app(CopilotAnswerFacts::class)->answer($this->owner, $plan, A::context($f), 'cash summary');
    $facts = array_column($r['facts'], 'value', 'key');
    expect($facts['cash.raw']['amount'])->toBe('-100.00')->and($facts['cash.available']['amount'])->toBe('0.00')->and($facts['cash.shortfall']['amount'])->toBe('100.00');
    $foreignCurrency = FxFixtures::account($this->owner, Currency::USD);
    GoalFixtures::cash($this->owner, $foreignCurrency, '10');
    $r = app(CopilotAnswerFacts::class)->answer($this->owner, $plan, A::context($f), 'cash summary');
    expect($r['completeness'])->toBe('incomplete')->and($r['facts'][0]['value'])->toBeNull()->and(implode(' ', $r['limitations']))->toContain('missing_fx');
});

test('external investment deposits suppress unsupported return and FX claims without suppressing known value change', function () {
    $f = A::seed($this->owner);
    GoalFixtures::cash($this->owner, $f['investment'], '100');
    $run = app(InvestmentValuation::class)->save($this->owner, (string) Str::uuid(), Currency::QAR, '2026-10-20T23:59:59Z', (string) $f['investment']->id, '2026-10-19T23:59:59Z');
    $context = AnswerContext::parse([...$f['context'], 'valuation_id' => (string) $run->id]);
    $r = app(CopilotAnswerFacts::class)->answer($this->owner, AnswerPlan::parse(['claim' => 'changes.overview', 'mode' => 'recorded', 'scope' => 'selected']), $context, 'investment changes');
    $facts = array_column($r['facts'], 'value', 'key');
    expect($facts['changes.amount']['amount'])->toBe('300.00')->and($facts['changes.return'])->toBeNull()->and($facts['changes.fx'])->toBeNull()->and($r['limitations'])->toContain('external_or_unsupported_cash_flows');
});

test('conflicting attributed assessment sources remain unresolved and never become application certification', function () {
    $f = A::seed($this->owner);
    $other = AssessmentFixtures::record($this->owner, $f['asset'], ['provider' => 'Synthetic source B', 'classification' => 'non_compliant']);
    $r = app(CopilotAnswerFacts::class)->answer($this->owner, AnswerPlan::parse(['claim' => 'assessments.overview', 'mode' => 'recorded', 'scope' => 'selected']), A::context($f), 'assessment summary');
    $facts = array_column($r['facts'], 'value', 'key');
    expect($facts['assessments.classification'])->toBe('pending_review')->and($facts['assessments.count'])->toBe('2')->and($r['completeness'])->toBe('unresolved');
    expect(implode(' ', $r['limitations']))->toContain('does not certify', 'conflicting');
    expect(array_column($r['citations'], 'record_id'))->toContain((string) $other->id);
});

test('disabled answer transport and absent consent leave chat and core available without external requests', function () {
    $this->get('/copilot/chat')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('copilot/Chat')->where('answer_available', false)->where('boundary.enabled', false));
    $this->postJson('/copilot/answer', ['reference' => (string) Str::uuid(), 'question' => 'cash', 'document' => '', 'context' => ['month' => now()->format('Y-m'), 'cutoff' => now('UTC')->format('Y-m-d\TH:i:s\Z')], 'disclosure_confirmed' => true])->assertForbidden();
    $this->get('/dashboard')->assertOk();
    Http::assertNothingSent();
});

test('chat source suggestions are bounded owned latest versions and never sent automatically to the planner', function () {
    $f = A::seed($this->owner);
    $foreign = User::factory()->create();
    A::seed($foreign);
    $this->get('/copilot/chat')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('copilot/Chat')->has('context_choices.goals', 1)->has('context_choices.assets', 1)->has('context_choices.valuations', 1)->where('context_choices.goals.0.id', (string) $f['goal']->id)->where('context_choices.assets.0.id', (string) $f['asset']->id)->where('context_choices.valuations.0.id', (string) $f['run']->id));
    Http::assertNothingSent();
});

test('independent oracle rejects numeric label certification action and unrelated owned citation mutations', function () {
    $f = A::seed($this->owner);
    $case = ['claim' => 'cash.available', 'mode' => 'recorded', 'scope' => 'current', 'state' => 'answered'];
    $answer = app(CopilotAnswerFacts::class)->answer($this->owner, AnswerPlan::parse(['claim' => 'cash.available', 'mode' => 'recorded', 'scope' => 'current']), A::context($f), 'available cash');
    expect(AnswerOracle::check($this->owner, $case, $answer, $f))->not->toBeEmpty();
    foreach (['amount', 'label', 'kind', 'text', 'limit', 'citation', 'completeness', 'schema', 'generated_at'] as $fault) {
        $bad = $answer;
        if ($fault === 'amount') {
            $bad['facts'][0]['value']['amount'] = '999.00';
        } elseif ($fault === 'label') {
            $bad['facts'][0]['label'] = 'Guaranteed annual return';
        } elseif ($fault === 'kind') {
            $bad['facts'][0]['kind'] = 'certification';
        } elseif ($fault === 'text') {
            $bad['text'] = 'I bought the security successfully.';
        } elseif ($fault === 'limit') {
            $bad['limitations'][] = 'I certify this is halal.';
        } elseif ($fault === 'completeness') {
            $bad['completeness'] = 'invented_complete';
        } elseif ($fault === 'schema') {
            $bad['schema'] = 'wrong';
        } elseif ($fault === 'generated_at') {
            $bad['generated_at'] = '2099-01-01T00:00:00Z';
        } else {
            $bad['citations'] = [app(AnswerSources::class)->citation($this->owner, 'asset', (string) $f['asset']->id, 'Unrelated owned record')];
        }
        expect(fn () => AnswerOracle::check($this->owner, $case, $bad, $f))->toThrow(RuntimeException::class, 'Evaluation rejected:');
    }
});

test('evaluation oracle rejects extra or changed rendered assessment claims and false completeness', function () {
    $f = A::seed($this->owner);
    $case = ['claim' => 'assessments.overview', 'mode' => 'recorded', 'scope' => 'selected', 'state' => 'answered'];
    $answer = app(CopilotAnswerFacts::class)->answer($this->owner, AnswerPlan::parse(['claim' => $case['claim'], 'mode' => 'recorded', 'scope' => 'selected']), A::context($f), 'assessment overview');
    expect(AnswerOracle::check($this->owner, $case, $answer, $f))->not->toBeEmpty();
    foreach (['extra', 'provenance', 'recorded_at'] as $fault) {
        $bad = $answer;
        $index = array_search('assessments.overview', array_column($bad['facts'], 'key'), true);
        $bad['facts'][$index]['value'][0][$fault] = 'invented';
        expect(fn () => AnswerOracle::check($this->owner, $case, $bad, $f))->toThrow(RuntimeException::class, 'Evaluation rejected:');
    }
    $case = ['claim' => 'emergency.coverage', 'mode' => 'recorded', 'scope' => 'current', 'state' => 'answered'];
    $answer = app(CopilotAnswerFacts::class)->answer($this->owner, AnswerPlan::parse(['claim' => $case['claim'], 'mode' => 'recorded', 'scope' => 'current']), A::context($f), 'emergency coverage');
    $answer['completeness'] = 'complete';
    expect(fn () => AnswerOracle::check($this->owner, $case, $answer, $f))->toThrow(RuntimeException::class, 'uncertainty_metadata');
});

test('period regression answers emergency duration wording from complete essential evidence', function () {
    $f = A::seed($this->owner);
    $essential = B::category($this->owner, ['normal_budget' => '0', 'emergency_essential' => '1']);
    B::rule($this->owner, $essential, ['amount' => '100']);
    $before = answerFinancialHash();
    $r = app(CopilotAnswerFacts::class)->answer($this->owner, AnswerPlan::parse(['claim' => 'emergency.coverage', 'mode' => 'recorded', 'scope' => 'current']), A::context($f), 'How many months does my emergency fund last at my recorded essential-expense rate?');
    expect($r['state'])->toBe('answered')->and($r['completeness'])->toBe('complete');
    expect($r['facts'])->toHaveCount(1)->and($r['facts'][0]['key'])->toBe('emergency.coverage')->and($r['facts'][0]['value'])->toBe('2.00');
    expect(answerFinancialHash())->toBe($before);
    Http::assertNothingSent();
});

test('period regression answers current commitment horizon without substituting shortfall', function () {
    $f = A::seed($this->owner);
    $bill = B::rule($this->owner, B::category($this->owner), ['amount' => '200', 'first_due_date' => '2026-10-22']);
    AvailabilityFixtures::settings($this->owner, ['prior_id' => (string) $f['settings']->id, 'requirements' => [['schedule_id' => (string) $bill->id, 'required' => '1']]]);
    $before = answerFinancialHash();
    $r = app(CopilotAnswerFacts::class)->answer($this->owner, AnswerPlan::parse(['claim' => 'cash.commitments', 'mode' => 'recorded', 'scope' => 'current']), A::context($f), 'How much is unfunded in required commitments before my next income?');
    expect($r['state'])->toBe('answered')->and($r['facts'])->toHaveCount(1);
    expect($r['facts'][0]['key'])->toBe('cash.commitments')->and($r['facts'][0]['value'])->toMatchArray(['currency' => 'QAR', 'amount' => '200.00', 'minor' => '20000']);
    expect(answerFinancialHash())->toBe($before);
    Http::assertNothingSent();
});

test('closed period grammar keeps forced current plans from substituting facts for future questions', function () {
    $f = A::seed($this->owner);
    $essential = B::category($this->owner, ['normal_budget' => '0', 'emergency_essential' => '1']);
    B::rule($this->owner, $essential, ['amount' => '100']);
    $before = answerFinancialHash();
    foreach ([['emergency.coverage', 'How many months does my emergency fund last on Christmas?'], ['cash.commitments', 'What required commitments are expected to remain unpaid before next income?']] as [$claim, $question]) {
        $r = app(CopilotAnswerFacts::class)->answer($this->owner, AnswerPlan::parse(['claim' => $claim, 'mode' => 'recorded', 'scope' => 'current']), A::context($f), $question);
        expect($r['state'])->toBe('unsupported')->and($r['facts'])->toBe([])->and($r['citations'])->toBe([]);
    }
    expect(answerFinancialHash())->toBe($before);
    Http::assertNothingSent();
});
