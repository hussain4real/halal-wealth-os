<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Decisions\DecisionRead;
use App\Domain\Ledger\FinancialSnapshot;
use App\Domain\Ledger\LedgerInput;
use App\Domain\Planning\BudgetCalendar;
use App\Domain\Planning\CashAvailability;
use App\Domain\Planning\GoalView;
use App\Domain\Portfolio\ValuationFx;
use App\Domain\Portfolio\ValuationMath;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shariah\AssessmentRead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Source-domain calculations stay authoritative; this collector retains their complete basis. */
final class DashboardFacts
{
    /** Caller establishes the outer repeatable-read transaction before any source query.
     * @return array<string,mixed> */
    public function capture(User $owner, Currency $base): array
    {
        $cash = app(CashAvailability::class)->capture($owner, complete: true);
        $cutoff = $cash['cutoff'];
        $month = substr($cash['today'], 0, 7);
        $valuation = app(InvestmentValuation::class)->capture($owner, $base, $cutoff);
        $goals = [];
        $before = null;
        do {
            $page = app(GoalView::class)->index($owner, $month, $before);
            array_push($goals, ...$page['goals']);
            $before = $page['next'];
        } while ($before !== null);
        $opportunities = DB::table('decision_opportunities as p')->where('p.owner_id', $owner->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('decision_opportunities as newer')->whereColumn('newer.supersedes_id', 'p.id'))
            ->orderBy('p.id')->get()->map(fn (stdClass $r): array => app(DecisionRead::class)->row($r))->all();
        $assetIds = array_values(array_unique([...array_filter(array_column($valuation['ending']['components'], 'asset_id')), ...array_filter(array_column($opportunities, 'asset_id'))]));
        $assessments = [];
        foreach ($assetIds as $id) {
            $a = app(AssessmentRead::class)->view($owner, (string) $id, $cutoff);
            unset($a['history']);
            $assessments[] = $a;
        }
        $reconciliations = [];
        foreach ($valuation['ending']['net_worth_snapshot']['components'] as $account) {
            $comparison = DB::table('reconciliation_sessions')->where('owner_id', $owner->id)->where('account_id', $account['account_id'])->orderByDesc('id')->first();
            $basisHash = null;
            $changed = false;
            if ($comparison !== null) {
                $basis = app(FinancialSnapshot::class)->capture($owner, Currency::from($comparison->currency), LedgerInput::utc($comparison->cutoff), $account['account_id']);
                $basisHash = FinancialSnapshot::fingerprint($basis);
                $changed = ! hash_equals($comparison->evidence_hash, $basisHash);
            }
            $needs = $comparison === null || $comparison->status !== 'matched' || $changed;
            $explanation = $comparison === null ? 'No statement comparison is recorded for this account. Record a comparison; the ledger alone does not prove reconciliation.'
                : ($changed ? 'The retained comparison basis changed. Refresh the comparison before relying on its difference; no adjustment is suggested.'
                    : ($comparison->status === 'matched' ? 'The retained statement comparison matched at its stated cutoff; this does not reconcile later activity.' : 'The retained statement comparison is different or unavailable. Review its stated cutoff and source records.'));
            $reconciliations[] = ['account_id' => $account['account_id'], 'account' => $account['name'], 'session_id' => $comparison === null ? null : (string) $comparison->id,
                'status' => $comparison->status ?? 'missing', 'cutoff' => $comparison === null ? null : LedgerInput::utc($comparison->cutoff),
                'difference' => $comparison?->difference === null ? null : (string) $comparison->difference, 'basis_hash' => $basisHash, 'needs_review' => $needs, 'explanation' => $explanation];
        }
        $types = array_column($valuation['ending']['net_worth_snapshot']['components'], 'type', 'account_id');
        $components = $valuation['ending']['components'];
        $widgets = [
            'net_worth' => $this->metric($components, $base, $cutoff),
            'liabilities' => $this->metric(array_values(array_filter($components, fn (array $r): bool => $types[$r['account_id']] === 'liability')), $base, $cutoff),
            'liquid_cash' => $this->metric(array_values(array_filter($components, fn (array $r): bool => in_array($types[$r['account_id']], ['bank', 'cash'], true))), $base, $cutoff),
            'investments' => $this->metric(array_values(array_filter($components, fn (array $r): bool => $types[$r['account_id']] === 'investment')), $base, $cutoff),
        ];
        $goalSources = [];
        foreach ($goals as $goal) {
            if ($goal['closed']) {
                continue;
            }
            $native = $goal['backed'];
            $converted = $native === null ? ['value' => null, 'rate' => null, 'reason' => $goal['backing_reason']] : app(ValuationFx::class)->convert($owner, $native['minor'], Currency::from($goal['currency']), $base, $cutoff, 'reporting');
            $goalSources[] = ['account_id' => $goal['account_id'], 'account' => $goal['account_name'], 'asset' => $goal['name'], 'goal_id' => $goal['id'], 'kind' => 'funded_goal',
                'currency' => $goal['currency'], 'native' => $native, 'converted' => $converted['value'], 'fx' => $converted['rate'], 'reason' => $converted['reason'],
                'nominal' => $goal['nominal'], 'shortfall' => $goal['shortfall'], 'standing_order' => $goal['standing_order']];
        }
        $widgets['funded_goals'] = $this->metric($goalSources, $base, $cutoff);
        $sources = [];
        foreach (['net_worth', 'liabilities', 'liquid_cash', 'investments'] as $kind) {
            $sources[$kind] = $widgets[$kind]['sources'];
            unset($widgets[$kind]['sources']);
        }
        $sources['funded_goals'] = $goalSources;
        unset($widgets['funded_goals']['sources']);
        $sourceRecords = [];
        foreach (['accounts', 'journal_entries', 'postings', 'goal_plans', 'goal_reservations', 'goal_releases', 'goal_funding_links', 'planning_schedules', 'planning_occurrences', 'planning_allocations', 'planning_releases', 'cash_availability_settings'] as $table) {
            $sourceRecords[$table] = DB::table($table)->where('owner_id', $owner->id)->orderBy('id')->get()->map(fn (stdClass $r): array => $this->record($r))->all();
        }
        abort_unless($cash['today'] === BudgetCalendar::today() && substr($cutoff, 0, 10) === now('UTC')->toDateString(), 409, 'The planning date changed during capture. Refresh before saving a new snapshot.');

        return ['cutoff' => $cutoff, 'planning_date' => $cash['today'], 'base_currency' => $base->value, 'valuation' => $valuation, 'cash' => $cash,
            'widgets' => $widgets, 'sources' => $sources, 'goals' => $goals, 'opportunities' => $opportunities, 'assessments' => $assessments,
            'reconciliations' => $reconciliations, 'source_records' => $sourceRecords,
            'time_policy' => 'Financial ledger cutoff is a retrospective UTC business date; observations use their own observed/effective timestamps. Planning uses the current Qatar date. Retained snapshot evidence records what this repeatable-read transaction saw; recalculating the same cutoff later can differ.'];
    }

    /** @param list<array<string,mixed>> $rows
     * @return array<string,mixed> */
    private function metric(array $rows, Currency $base, string $cutoff): array
    {
        $sum = '0';
        $covered = 0;
        $native = [];
        foreach ($rows as $row) {
            $code = $row['currency'];
            $native[$code] ??= ['currency' => $code, 'minor' => '0', 'missing' => 0];
            if ($row['native'] === null) {
                $native[$code]['missing']++;
            } else {
                $native[$code]['minor'] = bcadd($native[$code]['minor'], $row['native']['minor'], 0);
            }
            if ($row['converted'] !== null) {
                $sum = bcadd($sum, $row['converted']['minor'], 0);
                $covered++;
            }
        }
        ksort($native);
        foreach ($native as &$group) {
            $group['value'] = $group['missing'] === 0 ? ValuationMath::money(Currency::from($group['currency']), $group['minor']) : null;
            $group['covered_subtotal'] = ValuationMath::money(Currency::from($group['currency']), $group['minor']);
        }
        unset($group);
        $money = ValuationMath::money($base, $sum);
        $complete = $covered === count($rows) && $money !== null;

        return ['state' => $complete ? 'complete' : ($covered > 0 ? 'partial' : 'unavailable'), 'total' => $complete ? $money : null,
            'covered_subtotal' => $covered > 0 || $rows === [] ? $money : null, 'covered' => $covered, 'count' => count($rows), 'missing' => count($rows) - $covered,
            'native' => array_values($native), 'sources' => $rows, 'cutoff' => $cutoff, 'policy' => InvestmentValuation::POLICY];
    }

    /** @return array<string,mixed> */
    private function record(stdClass $row): array
    {
        $result = (array) $row;
        foreach ($result as $key => &$value) {
            if ($value !== null && ($key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_minor'))) {
                $value = (string) $value;
            }
        }
        unset($value);

        return $result;
    }
}
