<?php

declare(strict_types=1);

namespace App\Domain\Decisions;

use App\Domain\Portfolio\PortfolioInput;

final class DecisionMath
{
    /** @param array<string,mixed> $reference
     * @return array{state:string,percent:?string,threshold:?string,explanation:string} */
    public static function concentration(?string $target, array $reference, string $today): array
    {
        $threshold = $reference['threshold'];
        if ($target === null || $threshold === null || $reference['total_minor'] === null || $reference['exposure_minor'] === null || $reference['source'] === null || $reference['on'] === null || $reference['review_on'] === null) {
            return ['state' => 'unknown', 'percent' => null, 'threshold' => $threshold, 'explanation' => 'A target, threshold and complete dated portfolio reference are needed. No concentration result is inferred.'];
        }
        if ($reference['on'] > $today || $reference['review_on'] < $today) {
            return ['state' => 'stale', 'percent' => null, 'threshold' => $threshold, 'explanation' => 'The manual portfolio reference is outside its stated validity dates. Refresh it before relying on a warning.'];
        }
        $numerator = bcmul(bcadd(PortfolioInput::integer($reference['exposure_minor']), PortfolioInput::integer($target), 0), '100', 0);
        $denominator = PortfolioInput::integer($reference['total_minor']);
        $over = bccomp($numerator, bcmul($denominator, PortfolioInput::decimal($threshold, 'currency_threshold'), 12), 12) > 0;

        return ['state' => $over ? 'over_limit' : 'within_limit', 'percent' => bcdiv($numerator, $denominator, 4), 'threshold' => $threshold,
            'explanation' => '(Existing currency exposure + proposed target) / manual portfolio reference total × 100. The proposed purchase reallocates existing wealth; its target is not added to the denominator. This is a planning warning, not a live valuation or recommendation.'];
    }
}
