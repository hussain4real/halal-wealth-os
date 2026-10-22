<?php

namespace Tests\Support;

use App\Domain\Imports\StatementImports;
use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Ledger\PostingService;
use App\Domain\Planning\GoalService;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\User;
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

        return ['owner' => (string) $owner->id, 'foreign' => (string) $foreign->id, 'bank' => (string) $bank->id,
            'goal' => (string) $goal->id, 'portfolio' => (string) $fractionalPortfolio->id,
            'assessment' => (string) $fixture['assessment']->id, 'import_source' => (string) $source->source_id, 'import_snapshot' => (string) $source->id,
            'import_receipt' => (string) $imported->id, 'import_corrected' => (string) $corrected->id, 'import_cancelled' => (string) $cancelled->id];
    }
}
