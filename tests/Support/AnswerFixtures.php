<?php

namespace Tests\Support;

use App\Domain\Intelligence\Answers\AnswerPlanner;
use App\Domain\Intelligence\UntrustedInput;
use App\Domain\Reporting\Copilot\Answers\AnswerContext;
use App\Domain\Reporting\Copilot\Answers\AnswerPlan;
use App\Domain\Reporting\InvestmentValuation;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

final class AnswerFixtures implements AnswerPlanner
{
    public int $calls = 0;

    public ?\Closure $onCall = null;

    public bool $throws = false;

    public function __construct(public string $claim = 'cash.available', public string $mode = 'recorded', public string $scope = 'current') {}

    public function available(): bool
    {
        return true;
    }

    public function plan(UntrustedInput $input): AnswerPlan
    {
        $this->calls++;
        if ($this->onCall) {
            ($this->onCall)();
        }
        if ($this->throws) {
            throw new \RuntimeException('SYNTHETIC-PRIVATE-ANSWER-ERROR');
        }

        return AnswerPlan::parse(['claim' => $this->claim, 'mode' => $this->mode, 'scope' => $this->scope]);
    }

    public static function seed(User $owner): array
    {
        Date::setTestNow(CarbonImmutable::parse('2026-10-19T23:59:59Z'));
        $investment = PortfolioFixtures::account($owner);
        $portfolio = PortfolioFixtures::link($owner, $investment);
        $asset = PortfolioFixtures::asset($owner, ['geography' => 'Nigeria', 'geography_source' => 'Synthetic disclosed issuer geography']);
        PortfolioFixtures::opening($owner, $portfolio, [PortfolioFixtures::row($asset, ['quantity' => '10', 'unit_price' => '100'])]);
        PortfolioFixtures::mode($owner, $portfolio);
        ValuationFixtures::price($owner, $portfolio, $asset, ['unit_price' => '100']);
        Date::setTestNow(CarbonImmutable::parse('2026-10-20T23:59:59Z'));
        ValuationFixtures::price($owner, $portfolio, $asset, ['unit_price' => '120']);
        $cash = FxFixtures::account($owner);
        GoalFixtures::cash($owner, $cash, '1000');
        $goal = GoalFixtures::goal($owner, $cash, ['target' => '1000', 'opening' => '200']);
        $settings = AvailabilityFixtures::settings($owner, ['next_income_date' => '2026-10-28']);
        $assessment = AssessmentFixtures::record($owner, $asset);
        $run = app(InvestmentValuation::class)->save($owner, (string) Str::uuid(), Currency::QAR, '2026-10-20T23:59:59Z', (string) $investment->id, '2026-10-19T23:59:59Z');
        $context = ['goal_id' => (string) $goal->id, 'valuation_id' => (string) $run->id, 'asset_id' => (string) $asset->id, 'month' => '2026-10', 'cutoff' => '2026-10-20T23:59:59Z'];

        return compact('cash', 'goal', 'settings', 'investment', 'portfolio', 'asset', 'assessment', 'run', 'context');
    }

    public static function context(array $fixture, ?string $missing = null): AnswerContext
    {
        $v = $fixture['context'];
        if ($missing !== null) {
            $v[$missing] = null;
        }

        return AnswerContext::parse($v);
    }
}
