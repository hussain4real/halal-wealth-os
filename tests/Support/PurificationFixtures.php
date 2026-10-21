<?php

namespace Tests\Support;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shariah\PurificationService;
use App\Models\User;
use Illuminate\Support\Str;
use stdClass;

final class PurificationFixtures
{
    public static function distribution(User $owner, Currency $currency = Currency::NGN): array
    {
        $asset = PortfolioFixtures::asset($owner, ['currency' => $currency->value]);
        $account = PortfolioFixtures::account($owner, $currency);
        $portfolio = PortfolioFixtures::link($owner, $account);
        PortfolioFixtures::opening($owner, $portfolio, [PortfolioFixtures::row($asset, ['quantity' => '50000', 'unit_price' => '70'])]);
        PortfolioFixtures::mode($owner, $portfolio);
        InvestmentFixtures::fund($owner, $account);
        $distribution = InvestmentFixtures::event($owner, $portfolio, $asset, ['kind' => 'dividend', 'amount' => '100', 'fee' => '1', 'tax' => '5']);
        $assessment = AssessmentFixtures::record($owner, $asset);

        return compact('asset', 'account', 'portfolio', 'distribution', 'assessment');
    }

    public static function input(User $owner, array $fixture, array $changes = []): array
    {
        return [...['context' => app(PurificationService::class)->context($owner, $fixture['distribution']), 'distribution_id' => (string) $fixture['distribution']->id,
            'supersedes_id' => '', 'assessment_id' => (string) $fixture['assessment']->id, 'method_reference' => 'SYNTHETIC-PURIFICATION-METHOD', 'method_version' => 'Synthetic method v1',
            'source_instruction' => 'Synthetic fixture only: 2 percent of gross distribution income. Not religious guidance.', 'base_kind' => 'gross', 'custom_base' => '', 'base_explanation' => '',
            'calculation' => 'percentage', 'rate_percent' => '2', 'fixed_amount' => '', 'reason' => 'Synthetic source instruction capture'], ...$changes];
    }

    public static function obligation(User $owner, array $fixture, array $changes = [], ?string $key = null): stdClass
    {
        return app(PurificationService::class)->obligation($owner, $key ?? (string) Str::uuid(), self::input($owner, $fixture, $changes));
    }

    public static function paymentInput(array $fixture, array $changes = []): array
    {
        return [...['mode' => 'record', 'payment_reference' => 'SYNTHETIC-CHARITY-'.Str::ulid(), 'journal_id' => '', 'account_id' => (string) $fixture['account']->id,
            'amount' => '2', 'effective_date' => now('UTC')->toDateString(), 'description' => 'Synthetic charity payment already made', 'evidence' => 'Synthetic retained receipt', 'reason' => 'Synthetic actual payment capture'], ...$changes];
    }

    public static function payment(User $owner, array $fixture, array $changes = [], ?string $key = null): stdClass
    {
        return app(PurificationService::class)->payment($owner, $key ?? (string) Str::uuid(), self::paymentInput($fixture, $changes));
    }

    public static function allocate(User $owner, stdClass $obligation, stdClass $payment, string $amount = '2', ?string $key = null): stdClass
    {
        return app(PurificationService::class)->allocate($owner, $key ?? (string) Str::uuid(), ['obligation_id' => (string) $obligation->id, 'payment_id' => (string) $payment->id, 'amount' => $amount, 'reason' => 'Synthetic actual allocation']);
    }
}
