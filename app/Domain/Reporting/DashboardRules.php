<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Portfolio\PortfolioInput;
use App\Domain\Portfolio\ValuationMath;
use App\Domain\Shared\Money\Currency;
use DateTimeImmutable;
use JsonException;

final class DashboardRules
{
    public const string POLICY = 'dashboard-actions-v1;explicit-dates-zero-grace;relevant-episode-dismissal;no-financial-decisions';

    public const array RULES = ['reconciliation' => 'Reconciliation review', 'funding' => 'Funding plan review', 'evidence' => 'Evidence review', 'allocation' => 'Currency allocation drift'];

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return ['enabled' => ['reconciliation', 'funding', 'evidence'], 'grace_days' => '0', 'targets' => []];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public static function settings(array $input): array
    {
        $enabled = [];
        foreach (self::RULES as $code => $label) {
            $value = $input[$code] ?? '';
            if (! in_array($value, ['0', '1'], true)) {
                LedgerInput::fail($code, 'Choose whether to enable this review rule.');
            }
            if ($value === '1') {
                $enabled[] = $code;
            }
        }
        $grace = $input['grace_days'] ?? '';
        if (! is_string($grace) || ! preg_match('/^(0|[1-9][0-9]{0,2})$/D', $grace) || (int) $grace > 365) {
            LedgerInput::fail('grace_days', 'Enter an explicit operational review grace period from 0 to 365 days.');
        }
        try {
            $raw = $input['targets'] ?? '';
            if (! is_string($raw) || strlen($raw) > 4000) {
                LedgerInput::fail('targets', 'Enter a bounded JSON array of currency targets and tolerances.');
            }
            if (! str_starts_with(ltrim($raw), '[')) {
                LedgerInput::fail('targets', 'Targets must be a JSON array, including when empty.');
            }
            $rows = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            LedgerInput::fail('targets', 'Enter a valid JSON target array.');
        }
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > count(Currency::cases())) {
            LedgerInput::fail('targets', 'Enter one target per currency as an array.');
        }
        $targets = [];
        $total = '0';
        foreach ($rows as $row) {
            if (! is_array($row) || array_diff(array_keys($row), ['currency', 'target', 'tolerance']) !== [] || ! is_string($row['currency'] ?? null)) {
                LedgerInput::fail('targets', 'Each target needs currency, target and tolerance as exact text.');
            }
            $currency = PortfolioInput::currency($row['currency'])->value;
            $target = PortfolioInput::decimal($row['target'] ?? null, 'targets');
            $tolerance = PortfolioInput::decimal($row['tolerance'] ?? null, 'targets');
            if (isset($targets[$currency]) || bccomp($target, '100', 12) > 0 || bccomp($tolerance, '100', 12) > 0) {
                LedgerInput::fail('targets', 'Use unique currencies and percentages between zero and 100.');
            }
            $targets[$currency] = ['currency' => $currency, 'target' => $target, 'tolerance' => $tolerance];
            $total = bcadd($total, $target, 12);
        }
        if ($targets !== [] && bccomp($total, '100', 12) !== 0) {
            LedgerInput::fail('targets', 'Explicit currency targets must total exactly 100 percent.');
        }
        ksort($targets);

        return ['enabled' => $enabled, 'grace_days' => $grace, 'targets' => array_values($targets)];
    }

    /** @param array<string,mixed> $facts
     * @param array<string,mixed> $settings
     * @return list<array<string,mixed>> */
    public static function evaluate(array $facts, array $settings): array
    {
        $actions = [];
        $today = $facts['planning_date'];
        $deadline = (new DateTimeImmutable($today))->modify('-'.$settings['grace_days'].' days')->format('Y-m-d');
        $add = function (string $rule, string $subject, string $title, string $why, string $url, array $basis) use (&$actions, $settings): void {
            if (! in_array($rule, $settings['enabled'], true)) {
                return;
            }
            $basis = ['rule' => $rule, 'subject' => $subject, 'policy' => self::POLICY, 'grace_days' => $settings['grace_days'], ...$basis];
            $actions[] = ['key' => DashboardSnapshot::digest($basis), 'rule' => $rule, 'subject' => $subject, 'title' => $title, 'explanation' => $why,
                'url' => $url, 'basis' => $basis, 'dismissed' => false];
        };
        foreach ($facts['reconciliations'] as $r) {
            if ($r['needs_review']) {
                $add('reconciliation', 'account:'.$r['account_id'], 'Review account reconciliation', $r['explanation'], '/reconciliations',
                    ['account_id' => $r['account_id'], 'session_id' => $r['session_id'], 'status' => $r['status'], 'basis_hash' => $r['basis_hash'], 'threshold' => 'Recorded difference is nonzero, missing comparison, or comparison basis changed.']);
            }
        }
        foreach ($facts['goals'] as $g) {
            if (! $g['closed'] && $g['projection']['date_reason'] === 'missed_plan_needs_update' && $g['first_deposit'] < $deadline) {
                $add('funding', 'goal:'.$g['root_id'], 'Review funding plan · '.$g['name'], 'The planned first contribution date is past the configured '.$settings['grace_days'].'-day grace period. This does not prove that a transfer failed. Review the recorded plan and linked actuals.', '/goals/'.$g['id'],
                    ['goal_version' => $g['id'], 'first_deposit' => $g['first_deposit'], 'backed' => $g['backed'], 'standing_order' => $g['standing_order'], 'threshold' => 'Past planned first-contribution date; no automatic standing-order setup reminder.']);
            }
        }
        foreach ($facts['assessments'] as $a) {
            if ($a['summary']['guidance_state'] === 'unresolved') {
                $add('evidence', 'asset:'.$a['asset']['id'], 'Review evidence · '.$a['asset']['name'], $a['summary']['guidance'], '/assets/'.$a['asset']['id'].'/assessments',
                    ['summary' => $a['summary'], 'claims' => $a['current'], 'threshold' => 'Missing, uncertain, conflicting or overdue source evidence; no automatic ruling.']);
            }
        }
        foreach ($facts['opportunities'] as $o) {
            if ($o['status'] === 'closed') {
                continue;
            }
            $reasons = [];
            foreach ($o['attributes']['evidence'] as $name => $e) {
                if (! $e['verified']) {
                    $reasons[] = $name.' is missing or unverified';
                }
            }
            if ($o['attributes']['review_on'] === null || $o['attributes']['review_on'] < $deadline) {
                $reasons[] = 'thesis review date is missing or past due';
            }
            if ($reasons !== []) {
                $add('evidence', 'opportunity:'.$o['series_id'], 'Review thesis · '.$o['name'], implode('; ', $reasons).'. Check the original sources before making a decision.', '/opportunities/'.$o['id'],
                    ['version' => $o['id'], 'reasons' => $reasons, 'evidence' => $o['attributes']['evidence'], 'review_on' => $o['attributes']['review_on'], 'threshold' => 'Missing/unverified offer evidence or overdue configured thesis review.']);
            }
        }
        $allocation = $facts['valuation']['ending']['allocation'];
        if (in_array('allocation', $settings['enabled'], true) && $settings['targets'] !== []) {
            $targets = array_column($settings['targets'], null, 'currency');
            $groups = $allocation['dimensions']['currency'];
            $known = $allocation['coverage'] === 'complete' && bccomp(PortfolioInput::integer($allocation['denominator_minor']), '0', 0) > 0;
            foreach ($groups as $g) {
                if (! isset($targets[$g['label']])) {
                    $known = false;
                }
            }
            if (! $known) {
                $add('allocation', 'allocation:coverage', 'Allocation comparison needs inputs', 'Complete valuation, a positive signed denominator and an explicit target for every currency are required. A partial subtotal cannot prove allocation is within target.', '/valuations',
                    ['targets' => $settings['targets'], 'coverage' => $allocation['coverage'], 'denominator' => $allocation['denominator_minor'], 'threshold' => 'Complete coverage and explicit targets required.']);
            } else {
                $values = array_column($groups, null, 'label');
                foreach ($targets as $currency => $target) {
                    $minor = PortfolioInput::integer($values[$currency]['minor'] ?? '0');
                    $denominator = PortfolioInput::integer($allocation['denominator_minor']);
                    $difference = bcsub(bcmul($minor, '100', 0), bcmul($denominator, $target['target'], 12), 12);
                    if (bccomp(ltrim($difference, '-'), bcmul($denominator, $target['tolerance'], 12), 12) > 0) {
                        $percent = ValuationMath::ratio($minor, $denominator)['percent'];
                        $add('allocation', 'currency:'.$currency, 'Review '.$currency.' allocation', 'Recorded weight '.$percent.'% differs from your '.$target['target'].'% target by more than '.$target['tolerance'].' percentage points. This is a review prompt, not a trade instruction.', '/valuations',
                            ['currency' => $currency, 'minor' => $minor, 'denominator' => $denominator, 'target' => $target, 'threshold' => 'Absolute percentage-point difference exceeds explicit tolerance.']);
                    }
                }
            }
        }
        usort($actions, fn (array $a, array $b): int => [$a['rule'], $a['subject']] <=> [$b['rule'], $b['subject']]);

        return $actions;
    }
}
