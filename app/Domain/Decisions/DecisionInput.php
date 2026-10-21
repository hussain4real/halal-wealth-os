<?php

declare(strict_types=1);

namespace App\Domain\Decisions;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Planning\PlanningInput;
use App\Domain\Portfolio\PortfolioInput;
use App\Domain\Shared\Money\Currency;
use InvalidArgumentException;

final class DecisionInput
{
    public const FIELDS = ['prior_id', 'name', 'status', 'currency', 'asset_id', 'target', 'maximum', 'tranches', 'rationale', 'risks', 'horizon', 'review_triggers', 'review_on', 'offer_status', 'offer_price', 'offer_eligibility', 'status_url', 'status_verified_on', 'status_review_on', 'status_official', 'price_url', 'price_verified_on', 'price_review_on', 'price_official', 'eligibility_url', 'eligibility_verified_on', 'eligibility_review_on', 'eligibility_official', 'currency_threshold', 'portfolio_total', 'currency_exposure', 'reference_source', 'reference_on', 'reference_review_on', 'reason'];

    public static function optional(mixed $value, string $field, int $max = 2000): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || mb_strlen($value) > $max || trim($value) === '') {
            LedgerInput::fail($field, "Enter text up to {$max} characters, or leave it unknown.");
        }

        return trim($value);
    }

    public static function date(mixed $value, string $field, bool $past = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = PlanningInput::date($value, $field);
        if ($past && $date > now('Asia/Qatar')->toDateString()) {
            LedgerInput::fail($field, 'A verification or observation date cannot be in the future.');
        }

        return $date;
    }

    public static function url(mixed $value, string $field): ?string
    {
        $url = self::optional($value, $field, 1000);
        if ($url !== null && (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null)) {
            LedgerInput::fail($field, 'Use an HTTPS source link without embedded credentials.');
        }

        return $url;
    }

    /** @param numeric-string|null $target
     * @return list<array{key:string,label:string,condition:string,amount_minor:numeric-string}> */
    public static function tranches(mixed $input, Currency $currency, ?string $target): array
    {
        if (is_string($input)) {
            if (strlen($input) > 64000 || ! json_validate($input)) {
                LedgerInput::fail('tranches', 'The saved tranche details are invalid or too large.');
            }
            $input = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
        }
        if (! is_array($input) || ! array_is_list($input) || count($input) > 24) {
            LedgerInput::fail('tranches', 'Use a list of up to 24 conditional tranches.');
        }
        $rows = [];
        $total = '0';
        $keys = [];
        foreach ($input as $i => $item) {
            if (! is_array($item)) {
                LedgerInput::fail('tranches', 'Each tranche needs its own key, label, amount and condition.');
            }
            PortfolioInput::only($item, ['key', 'label', 'amount', 'condition']);
            $key = $item['key'] ?? null;
            if (! is_string($key) || ! preg_match('/^[a-zA-Z0-9_-]{1,60}$/D', $key) || isset($keys[$key])) {
                LedgerInput::fail('tranches', 'Use a unique stable key for every tranche. Keep it when revising that tranche.');
            }
            $minor = PlanningInput::positive($currency, $item['amount'] ?? null, "tranches.{$i}.amount")->minor;
            $rows[] = ['key' => $key, 'label' => LedgerInput::text($item['label'] ?? null, 'tranches'), 'condition' => LedgerInput::text($item['condition'] ?? null, 'tranches'), 'amount_minor' => $minor];
            $keys[$key] = true;
            $total = bcadd($total, $minor, 0);
        }
        if (($target === null && $rows !== []) || ($target !== null && ($rows === [] || bccomp($total, $target, 0) !== 0))) {
            LedgerInput::fail('tranches', 'Tranches must reconcile exactly to the known target. An unknown watchlist target has no tranches.');
        }

        return $rows;
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public static function attributes(array $input, Currency $currency): array
    {
        $a = [];
        foreach (['rationale', 'risks', 'horizon', 'review_triggers'] as $field) {
            $a[$field] = self::optional($input[$field] ?? '', $field);
        }
        $a['review_on'] = self::date($input['review_on'] ?? '', 'review_on');
        $a['evidence'] = [];
        foreach (['status', 'price', 'eligibility'] as $field) {
            $value = self::optional($input['offer_'.$field] ?? '', 'offer_'.$field, 180);
            if ($field === 'price' && $value !== null) {
                $value = PortfolioInput::decimal($value, 'offer_price');
            }
            $on = self::date($input[$field.'_verified_on'] ?? '', $field.'_verified_on', true);
            $review = self::date($input[$field.'_review_on'] ?? '', $field.'_review_on');
            if ($on !== null && $review !== null && $review < $on) {
                LedgerInput::fail($field.'_review_on', 'Review validity must end on or after verification.');
            }
            $a['evidence'][$field] = ['value' => $value, 'url' => self::url($input[$field.'_url'] ?? '', $field.'_url'), 'verified_on' => $on, 'review_on' => $review, 'official' => PlanningInput::flag($input[$field.'_official'] ?? '0', $field.'_official')];
        }
        $percent = ($input['currency_threshold'] ?? '') === '' ? null : PortfolioInput::decimal($input['currency_threshold'], 'currency_threshold');
        if ($percent !== null && bccomp($percent, '100', 12) > 0) {
            LedgerInput::fail('currency_threshold', 'Choose an explicit threshold between zero and 100 percent.');
        }
        $total = ($input['portfolio_total'] ?? '') === '' ? null : PlanningInput::positive($currency, $input['portfolio_total'], 'portfolio_total')->minor;
        $exposure = ($input['currency_exposure'] ?? '') === '' ? null : PortfolioInput::money($currency, $input['currency_exposure'], 'currency_exposure');
        if ($total !== null && $exposure !== null && bccomp($exposure, $total, 0) > 0) {
            LedgerInput::fail('currency_exposure', 'Existing currency exposure cannot exceed its portfolio reference total.');
        }
        $on = self::date($input['reference_on'] ?? '', 'reference_on', true);
        $review = self::date($input['reference_review_on'] ?? '', 'reference_review_on');
        if ($on !== null && $review !== null && $review < $on) {
            LedgerInput::fail('reference_review_on', 'Review validity must end on or after observation.');
        }
        $a['concentration'] = ['threshold' => $percent, 'total_minor' => $total, 'exposure_minor' => $exposure, 'source' => self::optional($input['reference_source'] ?? '', 'reference_source', 180), 'on' => $on, 'review_on' => $review];

        return $a;
    }

    /** @return numeric-string|null */
    public static function amount(Currency $currency, mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return PlanningInput::positive($currency, $value, $field)->minor;
        } catch (InvalidArgumentException $e) {
            LedgerInput::fail($field, $e->getMessage());
        }
    }
}
