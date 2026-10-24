<?php

namespace Tests\Support;

use App\Domain\Imports\StatementImports;
use App\Domain\Integrations\MarketFeed;
use App\Domain\Integrations\MarketRefresh;
use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Ledger\PostingService;
use App\Domain\Planning\GoalService;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class ReleaseFixtures
{
    public static function seed(): array
    {
        $owner = User::factory()->withTwoFactor()->create(['name' => 'Synthetic recovery owner']);
        $foreign = User::factory()->create(['name' => 'Synthetic foreign recovery owner']);
        $bank = app(CreateAccount::class)->create($owner, 'Synthetic recovery cash', AccountType::Bank, Currency::QAR, Money::fromMinor(Currency::QAR, '750000'));
        app(PostingService::class)->postOpening($owner, (string) $bank->id, (string) Str::uuid());
        $reversed = GoalFixtures::cash($owner, $bank, '17.43');
        app(PostingService::class)->reverse($owner, (string) $reversed->id, (string) Str::uuid(), 'Synthetic retained correction');
        $goal = GoalFixtures::goal($owner, $bank, ['opening' => '1500', 'target' => '9000']);
        $released = app(GoalService::class)->reserve($owner, (string) Str::uuid(), (string) $goal->id, '24.89', 'Synthetic released reservation');
        app(GoalService::class)->release($owner, (string) Str::uuid(), (string) $released->id, 'Synthetic release history');
        $plan = DecisionFixtures::plan($owner, ['currency' => 'QAR']);
        DecisionFixtures::reserve($owner, $plan, $goal, ['amount' => '125.45']);
        $fixture = PurificationFixtures::distribution($owner, Currency::QAR);
        AssessmentFixtures::record($owner, $fixture['asset'], ['supersedes_id' => (string) $fixture['assessment']->id, 'classification' => 'questionable', 'reason' => 'Synthetic retained assessment revision']);
        $obligation = PurificationFixtures::obligation($owner, $fixture);
        $payment = PurificationFixtures::payment($owner, $fixture);
        PurificationFixtures::allocate($owner, $obligation, $payment);
        $fractional = PortfolioFixtures::asset($owner);
        $fractionalPortfolio = PortfolioFixtures::link($owner, PortfolioFixtures::account($owner));
        PortfolioFixtures::opening($owner, $fractionalPortfolio, [PortfolioFixtures::row($fractional)]);
        PortfolioFixtures::mode($owner, $fractionalPortfolio);
        $foreignBank = FxFixtures::account($foreign);
        GoalFixtures::cash($foreign, $foreignBank, '23.67');
        $imports = app(StatementImports::class);
        $source = StatementFixtures::preview($owner, $bank, [['amount' => '1.23', 'provider_id' => 'SYNTHETIC-RECOVERY-IMPORT']]);
        $imported = $imports->approve($owner, (string) $source->id, 'synthetic-recovery-import', ['1']);
        $replacement = StatementFixtures::preview($owner, $bank, [['amount' => '2.34', 'provider_id' => 'SYNTHETIC-RECOVERY-IMPORT']]);
        $corrected = $imports->correct($owner, (string) $imported->id, '1', 'synthetic-recovery-import-replace', 'Synthetic retained replacement', (string) $replacement->id, '1');
        $cancelled = $imports->correct($owner, (string) $corrected->id, '1', 'synthetic-recovery-import-cancel', 'Synthetic retained cancellation');
        StatementFixtures::preview($foreign, $foreignBank, [['description' => 'FOREIGN-STATEMENT-SOURCE-MUST-NOT-APPEAR', 'provider_id' => 'FOREIGN-STATEMENT-ID']]);

        $market = app(MarketRefresh::class);
        $marketPrice = MarketFixtures::price($owner);
        $market->map($owner, 'recovery-market-fx', MarketFixtures::fxInput());
        $market->map($foreign, 'recovery-market-foreign', MarketFixtures::fxInput('EUR', 'QAR'));
        $priorMode = config('market_data.mode');
        config(['market_data.mode' => 'mock']);
        Http::preventStrayRequests();
        Http::fake(['api.twelvedata.com/*' => function ($request) {
            return Http::response(str_contains($request->url(), '/time_series')
                ? MarketFixtures::priceBody(now('America/New_York')->subDay()->toDateString()) : MarketFixtures::fxBody($request['symbol']));
        }]);
        foreach ([$marketPrice['feed'], MarketFeed::fx('USD', 'QAR'), MarketFeed::fx('EUR', 'QAR')] as $feed) {
            if ($market->refresh($feed, now('UTC')->toDateString())['status'] !== 'accepted') {
                throw new \RuntimeException('Populated market recovery seed did not admit its synthetic observation.');
            }
        }
        $market->map($owner, 'recovery-market-failed', MarketFixtures::fxInput('NGN', 'QAR'));
        // Replacing the fake factory preserves an explicit, total no-network boundary.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.twelvedata.com/*' => Http::response('', 401)]);
        $market->refresh(MarketFeed::fx('NGN', 'QAR'), now('UTC')->toDateString());
        $market->map($owner, 'recovery-market-interrupted', MarketFixtures::fxInput('JPY', 'QAR'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function () {
            throw new \RuntimeException('Synthetic worker termination after charged claim');
        });
        try {
            $market->refresh(MarketFeed::fx('JPY', 'QAR'), now('UTC')->toDateString());
            throw new \LogicException('Interrupted recovery fixture must retain an unfinished charged request.');
        } catch (\RuntimeException $error) {
            if ($error->getMessage() !== 'Synthetic worker termination after charged claim') {
                throw $error;
            }
        } finally {
            config(['market_data.mode' => $priorMode]);
            Http::swap(new Factory);
            Http::preventStrayRequests();
        }

        ReminderRecoveryFixtures::seed($owner, $foreign);

        return ['owner' => (string) $owner->id, 'foreign' => (string) $foreign->id, 'bank' => (string) $bank->id,
            'goal' => (string) $goal->id, 'portfolio' => (string) $fractionalPortfolio->id,
            'market_price_account' => (string) $marketPrice['account']->id, 'market_price_asset' => (string) $marketPrice['asset']->id,
            'assessment' => (string) $fixture['assessment']->id, 'import_source' => (string) $source->source_id, 'import_snapshot' => (string) $source->id,
            'import_receipt' => (string) $imported->id, 'import_corrected' => (string) $corrected->id, 'import_cancelled' => (string) $cancelled->id];
    }
}
