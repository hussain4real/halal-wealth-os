<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Ledger\FxMath;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;

final class AvailabilityMath
{
    /** @param array<string,array<string,mixed>> $groups
     * @param array<string,array<string,mixed>> $rates
     * @param list<string> $missing
     * @return array<string,mixed> */
    public function calculate(Currency $base, array $groups, array $rates, string $cutoff, ?string $buffer, ?string $investmentTarget, array $missing): array
    {
        $totals = array_fill_keys(['cash', 'protected', 'unfunded', 'salary', 'contribution', 'monthly_commitments', 'monthly_overlap', 'investment_backed', 'nominal_reserved', 'reservation_shortfall'], '0');
        $actualMissing = $missing;
        $forecastMissing = [];
        $salaryCount = 0;
        $components = [];
        foreach ($groups as $code => $group) {
            $currency = Currency::from($code);
            $g = [];
            foreach (array_keys($totals) as $field) {
                $g[$field] = PlanningInput::minor((string) ($group[$field] ?? '0'));
            }
            $salaryCount += (int) ($group['salary_count'] ?? 0);
            if (($group['reason'] ?? null) !== null) {
                $actualMissing[] = $code.':'.$group['reason'];
            }
            $reason = null;
            $converted = [];
            try {
                $cash = $this->convert($currency, $g['cash'], $base, $rates[$code] ?? null, $cutoff);
                $unearmarked = $this->convert($currency, bcsub($g['cash'], $g['protected'], 0), $base, $rates[$code] ?? null, $cutoff);
                $raw = $this->convert($currency, bcsub(bcsub($g['cash'], $g['protected'], 0), $g['unfunded'], 0), $base, $rates[$code] ?? null, $cutoff);
                $converted = ['cash' => $cash, 'protected' => bcsub($cash, $unearmarked, 0), 'unfunded' => bcsub($unearmarked, $raw, 0)];
                foreach ($converted as $field => $minor) {
                    $totals[$field] = bcadd($totals[$field], $minor, 0);
                }
            } catch (InvalidArgumentException $error) {
                $reason = $error->getMessage();
                $actualMissing[] = $code.':'.$reason;
            }
            foreach (['salary', 'contribution', 'investment_backed'] as $field) {
                try {
                    $minor = $this->convert($currency, $g[$field], $base, $rates[$code] ?? null, $cutoff);
                    $totals[$field] = bcadd($totals[$field], $minor, 0);
                } catch (InvalidArgumentException $error) {
                    $forecastMissing[] = $field.':'.$code.':'.$error->getMessage();
                }
            }
            try {
                $bills = $this->convert($currency, $g['monthly_commitments'], $base, $rates[$code] ?? null, $cutoff);
                $unfunded = $this->convert($currency, bcsub($g['monthly_commitments'], $g['monthly_overlap'], 0), $base, $rates[$code] ?? null, $cutoff);
                $totals['monthly_commitments'] = bcadd($totals['monthly_commitments'], $bills, 0);
                $totals['monthly_overlap'] = bcadd($totals['monthly_overlap'], bcsub($bills, $unfunded, 0), 0);
            } catch (InvalidArgumentException $error) {
                $forecastMissing[] = 'commitments:'.$code.':'.$error->getMessage();
            }
            if ((int) ($group['variable_count'] ?? 0) > 0) {
                $forecastMissing[] = 'contribution:variable';
            }
            $components[] = ['currency' => $code, 'native' => $g, 'converted' => $converted, 'reason' => $group['reason'] ?? $reason,
                'rate' => $currency === $base ? null : ($rates[$code] ?? null), 'conversion' => $currency === $base ? 'same_currency' : 'direct_reporting_rate'];
        }
        if ($salaryCount === 0) {
            $forecastMissing[] = 'salary:no_expected_salary';
        }
        if ($buffer === null) {
            $actualMissing[] = 'minimum_buffer_missing';
        }
        $raw = bcsub(bcsub(bcsub($totals['cash'], $totals['protected'], 0), $totals['unfunded'], 0), PlanningInput::minor($buffer ?? '0'), 0);
        $actual = [];
        try {
            foreach (['cash', 'protected', 'unfunded'] as $field) {
                $actual[$field] = Money::fromMinor($base, $totals[$field])->jsonSerialize();
            }
            $rawMoney = Money::fromMinor($base, $raw)->jsonSerialize();
            $deployable = Money::fromMinor($base, bccomp($raw, '0', 0) < 0 ? '0' : $raw)->jsonSerialize();
            $shortfall = Money::fromMinor($base, bccomp($raw, '0', 0) < 0 ? bcsub('0', $raw, 0) : '0')->jsonSerialize();
        } catch (InvalidArgumentException) {
            $actualMissing[] = 'aggregate_out_of_range';
            $rawMoney = $deployable = $shortfall = null;
        }

        return ['state' => $actualMissing === [] ? 'complete' : 'incomplete', 'missing' => array_values(array_unique($actualMissing)),
            'raw' => $actualMissing === [] ? $rawMoney : null, 'deployable' => $actualMissing === [] ? $deployable : null, 'shortfall' => $actualMissing === [] ? $shortfall : null,
            'breakdown' => $actual, 'buffer' => $buffer === null ? null : Money::fromMinor($base, $buffer)->jsonSerialize(),
            'components' => $components, 'forecast_missing' => array_values(array_unique($forecastMissing)),
            'forecast_inputs' => [...$totals, 'buffer' => $buffer, 'investment_target' => $investmentTarget], 'currency' => $base->value];
    }

    /** @param array<string,mixed>|null $rate
     * @return numeric-string */
    public function convert(Currency $currency, string $minor, Currency $base, ?array $rate, string $cutoff): string
    {
        $money = Money::fromMinor($currency, $minor);
        if ($currency === $base || $minor === '0') {
            return $money->minor;
        }
        if ($rate === null) {
            throw new InvalidArgumentException('missing_fx');
        }
        $instant = new DateTimeImmutable($cutoff);
        $observed = new DateTimeImmutable($rate['observed_at']);
        $effective = new DateTimeImmutable($rate['effective_at']);
        if ($rate['base'] !== $currency->value || $rate['quote'] !== $base->value || $observed > $instant || $effective > $instant) {
            throw new InvalidArgumentException('invalid_fx');
        }
        if (max($instant->getTimestamp() - $observed->getTimestamp(), $instant->getTimestamp() - $effective->getTimestamp()) > 604800) {
            throw new InvalidArgumentException('stale_fx');
        }

        return FxMath::convert($money, FxMath::rate($rate['value']), $base)->minor;
    }

    /** Stateless overrides affect forecast only, never actual-now.
     * @param array<string,mixed> $actual
     * @param array<string,mixed> $overrides
     * @return array<string,mixed> */
    public function forecast(array $actual, array $overrides = []): array
    {
        PlanningInput::only($overrides, ['salary', 'contribution', 'investment_target', 'commitments']);
        $base = Currency::from($actual['currency']);
        $input = $actual['forecast_inputs'];
        $mapping = ['salary' => 'salary', 'contribution' => 'contribution', 'investment_target' => 'investment_target', 'commitments' => 'monthly_commitments'];
        foreach ($overrides as $field => $value) {
            $input[$mapping[$field]] = GoalService::nonnegative($base, $value, $field)->minor;
        }
        $missing = $actual['missing'];
        foreach ($actual['forecast_missing'] as $reason) {
            $field = explode(':', $reason)[0];
            if (! array_key_exists($field, $overrides)) {
                $missing[] = $reason;
            }
        }
        if ($input['investment_target'] === null) {
            $missing[] = 'investment_target_missing';
        }
        $overlap = bccomp($input['monthly_overlap'], $input['monthly_commitments'], 0) > 0 ? $input['monthly_commitments'] : $input['monthly_overlap'];
        $roundingOverlap = '0';
        if (bccomp($overlap, $input['protected'], 0) > 0) {
            $roundingOverlap = bcsub($overlap, $input['protected'], 0);
            $overlap = $input['protected'];
        }
        $investment = bcsub($input['investment_target'] ?? '0', $input['investment_backed'], 0);
        if (bccomp($investment, '0', 0) < 0) {
            $investment = '0';
        }
        $cash = bcsub(bcadd($input['cash'], $input['salary'], 0), $input['monthly_commitments'], 0);
        $protection = bcsub($input['protected'], $overlap, 0);
        $free = bcsub(bcsub(bcsub(bcsub($cash, $protection, 0), $input['contribution'], 0), $investment, 0), $input['buffer'] ?? '0', 0);
        $money = [];
        try {
            foreach (['cash' => $cash, 'free' => $free, 'remaining_protection' => $protection, 'additional_investment' => $investment, 'bill_overlap' => $overlap, 'rounding_overlap_not_applied' => $roundingOverlap] as $field => $minor) {
                $money[$field] = Money::fromMinor($base, $minor)->jsonSerialize();
            }
        } catch (InvalidArgumentException) {
            $missing[] = 'forecast_out_of_range';
        }

        return ['state' => $missing === [] ? 'complete' : 'incomplete', 'missing' => array_values(array_unique($missing)),
            'cash' => $missing === [] ? ($money['cash'] ?? null) : null, 'free' => $missing === [] ? ($money['free'] ?? null) : null,
            'details' => $money, 'inputs' => $input, 'scenario' => $overrides !== []];
    }
}
