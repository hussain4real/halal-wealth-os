<?php

use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Reporting\InvestmentValuation;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shariah\AssessmentRead;
use App\Domain\Shariah\AssessmentService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\AssessmentFixtures as A;
use Tests\Support\InvestmentFixtures as I;
use Tests\Support\PortfolioFixtures as P;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
    $this->owner = User::factory()->create();
    $this->asset = P::asset($this->owner);
    $this->view = fn (?string $cutoff = null): array => app(AssessmentRead::class)->view($this->owner, (string) $this->asset->id, $cutoff ?? now('UTC')->format('Y-m-d\TH:i:s\Z'));
});
afterEach(function () {
    $this->travelBack();
});

test('an asset without evidence is pending and never represented as verified compliant', function () {
    $view = ($this->view)();
    expect($view['summary']['classification'])->toBe('pending_review')->and($view['summary']['source_count'])->toBe(0)
        ->and($view['summary']['guidance_state'])->toBe('unresolved')->and($view['current'])->toBe([]);
});
test('source classifications require actual references and a methodology version', function (array $change) {
    expect(fn () => A::record($this->owner, $this->asset, $change))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('shariah_assessments', 0);
})->with(array_map(fn (array $change): array => [$change], [['evidence_refs' => []], ['methodology_version' => ''], ['provenance' => 'personal_note'], ['provenance' => 'ai_suggestion']]));
test('personal notes and AI suggestions remain separate pending context even with references', function (string $provenance) {
    $r = A::record($this->owner, $this->asset, ['provenance' => $provenance, 'classification' => 'pending_review']);
    $view = ($this->view)();
    expect($view['summary']['classification'])->toBe('pending_review')->and($view['summary']['source_count'])->toBe(0)
        ->and($view['current'][0]['provenance'])->toBe($provenance)->and($view['current'][0]['actor_id'])->toBe((string) $this->owner->id);
    expect($r->business_findings)->not->toBe($r->financial_findings);
})->with(['personal_note', 'ai_suggestion']);
test('independent current sources retain conflicting claims instead of selecting the newest globally', function () {
    $a = A::record($this->owner, $this->asset);
    $b = A::record($this->owner, $this->asset, ['provider' => 'Synthetic source B', 'classification' => 'non_compliant']);
    $view = ($this->view)();
    expect($view['summary']['conflict'])->toBeTrue()->and($view['summary']['guidance_state'])->toBe('unresolved')
        ->and(array_column($view['current'], 'id'))->toBe([(string) $a->id, (string) $b->id]);
    A::record($this->owner, $this->asset, ['supersedes_id' => (string) $a->id, 'classification' => 'questionable']);
    expect(($this->view)()['summary']['conflict'])->toBeTrue()->and(($this->view)()['summary']['source_count'])->toBe(2);
});
test('review is not overdue on its due date and becomes overdue after it without renewal', function () {
    $r = A::record($this->owner, $this->asset, ['effective_on' => '2026-10-17', 'review_due_on' => '2026-10-19']);
    expect(($this->view)()['summary']['overdue'])->toBeFalse()->and(($this->view)()['summary']['guidance_state'])->toBe('recorded_compliant');
    $this->travelTo(CarbonImmutable::parse('2026-10-20T00:00:00Z'));
    expect(($this->view)()['summary']['overdue'])->toBeTrue()->and(($this->view)()['summary']['classification'])->toBe('compliant')
        ->and(($this->view)()['summary']['guidance_state'])->toBe('unresolved');
    expect(DB::table('shariah_assessments')->sole()->review_due_on)->toBe($r->review_due_on);
});
test('future review dates are accepted and an absent review date remains explicitly unresolved', function () {
    A::record($this->owner, $this->asset, ['review_due_on' => '2031-02-28']);
    expect(($this->view)()['summary']['overdue'])->toBeFalse();
    A::record($this->owner, $this->asset, ['provider' => 'Synthetic source B', 'review_due_on' => '']);
    expect(($this->view)()['summary']['review_date_missing'])->toBeTrue()->and(($this->view)()['summary']['guidance_state'])->toBe('unresolved');
});
test('invalid future effective dates impossible review dates and dates before the source are refused', function (array $change) {
    expect(fn () => A::record($this->owner, $this->asset, $change))->toThrow(ValidationException::class);
})->with(array_map(fn (array $change): array => [$change], [['effective_on' => '2026-10-20'], ['review_due_on' => '2030-02-30'], ['review_due_on' => '2026-10-18'], ['review_due_on' => []], ['evidence_refs' => ['bad' => 'reference']], ['evidence_refs' => [5]], ['source_url' => 'javascript:alert(1)'], ['source_url' => 'https://user:secret@example.com/report']]));
test('as-of reads retain earlier source evidence and do not leak later backdated corrections', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17T09:00:00Z'));
    $first = A::record($this->owner, $this->asset, ['effective_on' => '2026-10-17']);
    $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
    $next = A::record($this->owner, $this->asset, ['supersedes_id' => (string) $first->id, 'effective_on' => '2026-10-17', 'classification' => 'questionable', 'methodology_version' => 'Synthetic methodology v2', 'evidence_refs' => ['Synthetic correction report']]);
    $past = ($this->view)('2026-10-18T23:59:59Z');
    expect($past['current'][0]['id'])->toBe((string) $first->id)->and(count($past['history']['items']))->toBe(1)
        ->and($past['current'][0]['methodology_version'])->toBe('Synthetic methodology v1');
    $now = ($this->view)();
    expect($now['current'][0]['id'])->toBe((string) $next->id)->and(count($now['history']['items']))->toBe(2)
        ->and($now['history']['items'][1]['evidence_refs'])->toBe(['Synthetic retained report, section 3']);
});
test('a later-effective revision leaves the predecessor current before its effective date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17T09:00:00Z'));
    $first = A::record($this->owner, $this->asset, ['effective_on' => '2026-10-17']);
    $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
    A::record($this->owner, $this->asset, ['supersedes_id' => (string) $first->id, 'effective_on' => '2026-10-19', 'classification' => 'non_compliant']);
    expect(($this->view)('2026-10-18T23:59:59Z')['current'][0]['id'])->toBe((string) $first->id);
    expect(($this->view)()['summary']['classification'])->toBe('non_compliant');
});
test('asset revisions never silently inherit another reference version assessment', function () {
    $old = A::record($this->owner, $this->asset);
    $newAsset = P::asset($this->owner, ['prior_id' => (string) $this->asset->id, 'identifier' => 'SYNTHETIC-CHANGED-IDENTITY']);
    $view = app(AssessmentRead::class)->view($this->owner, (string) $newAsset->id, now('UTC')->format('Y-m-d\TH:i:s\Z'));
    expect($view['summary']['classification'])->toBe('pending_review')->and($view['summary']['other_reference_evidence'])->toBeTrue()
        ->and($view['history']['items'][0]['asset_id'])->toBe((string) $this->asset->id);
    expect(fn () => A::record($this->owner, $newAsset, ['supersedes_id' => (string) $old->id]))->toThrow(ValidationException::class);
});
test('same successful attempts recover after asset or assessment revisions while changed attempts are refused', function () {
    $input = A::input($this->owner, $this->asset);
    $service = app(AssessmentService::class);
    $first = $service->record($this->owner, (string) $this->asset->id, 'synthetic-assessment-retry', $input);
    A::record($this->owner, $this->asset, ['supersedes_id' => (string) $first->id]);
    P::asset($this->owner, ['prior_id' => (string) $this->asset->id]);
    expect($service->record($this->owner, (string) $this->asset->id, 'synthetic-assessment-retry', $input)->id)->toBe($first->id);
    expect(fn () => $service->record($this->owner, (string) $this->asset->id, 'synthetic-assessment-new', $input))->toThrow(ValidationException::class);
    expect(fn () => $service->record($this->owner, (string) $this->asset->id, 'synthetic-assessment-retry', [...$input, 'notes' => 'Changed']))->toThrow(ValidationException::class);
});
test('revisions cannot rewrite source provenance or move their effective date backwards', function (array $change) {
    $first = A::record($this->owner, $this->asset);
    expect(fn () => A::record($this->owner, $this->asset, ['supersedes_id' => (string) $first->id, ...$change]))->toThrow(ValidationException::class);
})->with(array_map(fn (array $change): array => [$change], [['provider' => 'Different authority'], ['provenance' => 'personal_note', 'classification' => 'pending_review'], ['effective_on' => '2026-10-18']]));
test('assessment changes preserve cash units cost and saved valuation evidence exactly', function () {
    $account = P::account($this->owner);
    $p = P::link($this->owner, $account);
    P::opening($this->owner, $p, [P::row($this->asset)]);
    P::mode($this->owner, $p);
    I::fund($this->owner, $account);
    $saved = app(InvestmentValuation::class)->save($this->owner, 'synthetic-assessment-invariance', Currency::QAR, now('UTC')->format('Y-m-d\TH:i:s\Z'));
    $tables = ['journal_entries', 'postings', 'investment_events', 'portfolio_assets', 'portfolio_opening_rows', 'portfolio_modes', 'portfolio_prices', 'investment_valuation_runs'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    $book = app(PortfolioRead::class)->state($this->owner, (string) $p->id, now('UTC')->format('Y-m-d\TH:i:s\Z'));
    A::record($this->owner, $this->asset, ['classification' => 'non_compliant']);
    A::record($this->owner, $this->asset, ['provider' => 'Conflicting synthetic source']);
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($before[$table]);
    }
    expect(app(PortfolioRead::class)->state($this->owner, (string) $p->id, now('UTC')->format('Y-m-d\TH:i:s\Z')))->toBe($book);
    expect(DB::table('investment_valuation_runs')->where('id', $saved->id)->value('evidence'))->toBe($saved->evidence);
});
test('factual openings and trades remain recordable for every assessment classification', function (string $status) {
    A::record($this->owner, $this->asset, ['classification' => $status]);
    $account = P::account($this->owner);
    $p = P::link($this->owner, $account);
    P::opening($this->owner, $p, [P::row($this->asset)]);
    P::mode($this->owner, $p);
    I::fund($this->owner, $account);
    $event = I::event($this->owner, $p, $this->asset);
    expect($event->kind)->toBe('buy');
    $this->assertDatabaseCount('investment_events', 1);
})->with(['pending_review', 'compliant', 'non_compliant', 'questionable']);
