<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Planning\CashAvailability;
use App\Domain\Planning\GoalView;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Reporting\Copilot\Evidence;
use App\Domain\Reporting\Copilot\Tool;
use App\Domain\Reporting\Copilot\ToolQuery;
use App\Domain\Shariah\AssessmentRead;
use App\Models\User;

final class CopilotEvidence
{
    public function read(User $owner, ToolQuery $query): Evidence
    {
        return PortfolioRead::coherent(function () use ($owner, $query): Evidence {
            $a = $query->arguments;

            return match ($query->tool) {
                Tool::Cash => $this->cash($owner),
                Tool::Goals => $this->goals($owner, $a['month'], $a['before'] ?? null),
                Tool::Holdings => $this->holdings($owner, $a['portfolio_id'], $a['cutoff']),
                Tool::Valuation => $this->valuation($owner, $a['run_id']),
                Tool::Assessments => $this->assessments($owner, $a['asset_id'], $a['cutoff'], $a['before'] ?? null),
            };
        });
    }

    private function cash(User $owner): Evidence
    {
        $r = app(CashAvailability::class)->capture($owner, complete: true);
        $actual = $r['actual'];
        $sources = [];
        if ($r['settings'] !== null) {
            $sources[] = ['table' => 'cash_availability_settings', 'id' => $r['settings']['id']];
        }
        foreach ($r['accounts'] as $account) {
            $sources[] = ['table' => 'accounts', 'id' => (string) $account['id']];
        }
        foreach ($actual['components'] as $component) {
            if ($component['rate'] !== null) {
                $sources[] = ['table' => 'fx_observations', 'id' => $component['rate']['id']];
            }
        }

        return new Evidence(Tool::Cash, $r['cutoff'], $r['cutoff'], $actual['state'], null,
            ['policy' => $r['policy'], 'planning_date' => $r['today'], 'planning_timezone' => 'Asia/Qatar',
                'currency' => $actual['currency'], 'missing' => $actual['missing'], 'raw' => $actual['raw'],
                'deployable' => $actual['deployable'], 'shortfall' => $actual['shortfall'], 'breakdown' => $actual['breakdown'],
                'buffer' => $actual['buffer'], 'fingerprint' => $r['fingerprint'], 'forecast_included' => false], $sources);
    }

    private function goals(User $owner, string $month, ?string $before): Evidence
    {
        $r = app(GoalView::class)->index($owner, $month, $before);
        $rows = array_map(fn (array $g): array => $this->pick($g, ['id', 'root_id', 'version', 'currency', 'mode', 'closed', 'target', 'opening', 'nominal', 'backed', 'shortfall', 'backing_reason', 'contribution', 'projection']), $r['goals']);
        // Projection already carries deterministic monetary forms; do not expose account names or user notes.
        $now = now('UTC')->format('Y-m-d\TH:i:s\Z');

        return new Evidence(Tool::Goals, $now, $now, $r['next'] === null ? 'complete_page' : 'partial_page', $r['next'],
            ['month_context' => $month, 'time_policy' => 'Current goal state; month selects essential-run-rate context, not historical goal versions.', 'goals' => $rows],
            array_values(array_map(fn (array $g): array => $this->reference('goal_plans', $g['id']), $rows)));
    }

    private function holdings(User $owner, string $id, string $cutoff): Evidence
    {
        $r = app(PortfolioRead::class)->state($owner, $id, $cutoff);
        $rows = [];
        if ($r['contribution']['mode'] === 'detailed') {
            foreach ($r['rows'] as $row) {
                $rows[] = [...$this->pick($row, ['asset_id', 'asset_family', 'quantity', 'currency', 'cost_basis']),
                    'valuation' => [...$this->pick($row['valuation'], ['market_value', 'reason', 'unrealized_gain', 'gain_reason']), 'price' => $this->observation($row['valuation']['price'])]];
            }
        }
        $sources = [['table' => 'portfolio_accounts', 'id' => $id]];
        if ($r['mode_id'] !== null) {
            $sources[] = ['table' => 'portfolio_modes', 'id' => $r['mode_id']];
        }
        if ($r['opening'] !== null) {
            $sources[] = ['table' => 'portfolio_openings', 'id' => $r['opening']['id']];
        }
        foreach ($r['investment']['source_event_ids'] as $event) {
            $sources[] = ['table' => 'investment_events', 'id' => $event];
        }

        return new Evidence(Tool::Holdings, $cutoff, now('UTC')->format('Y-m-d\TH:i:s\Z'), $r['contribution']['state'], null,
            ['portfolio_id' => $id, 'currency' => $r['currency'], 'contribution' => $r['contribution'],
                'holdings' => $rows, 'look_through' => $r['contribution']['mode'] === 'detailed',
                'policy' => $r['price_policy']], $sources);
    }

    private function valuation(User $owner, string $id): Evidence
    {
        $run = LedgerInput::owned('investment_valuation_runs', $owner, $id);
        $r = json_decode($run->evidence, true, flags: JSON_THROW_ON_ERROR);
        // Existing immutable JSONB receipts retain their original producer digest;
        // decoding JSONB cannot reconstruct the producer's object key order.
        $point = $r['ending'];
        $components = array_map(fn (array $c): array => [...$this->pick($c, ['account_id', 'portfolio_account_id', 'asset_id', 'currency', 'kind', 'native', 'converted', 'reason', 'quantity', 'cost_reason']), 'price' => $this->observation($c['price'] ?? null), 'fx' => $this->observation($c['fx'] ?? null)], $point['components']);

        return new Evidence(Tool::Valuation, $r['cutoff'], $r['captured_at'], $point['state'], null,
            ['run_id' => $id, 'policy' => $r['policy'], 'base_currency' => $r['base_currency'], 'total' => $point['total'],
                'covered_subtotal' => $point['covered_subtotal'], 'covered_components' => $point['covered_components'],
                'component_count' => $point['component_count'], 'components' => $components, 'total_reason' => $point['total_reason']],
            [['table' => 'investment_valuation_runs', 'id' => $id]]);
    }

    private function assessments(User $owner, string $id, string $cutoff, ?string $before): Evidence
    {
        $r = app(AssessmentRead::class)->view($owner, $id, $cutoff, $before);
        $project = fn (array $row): array => $this->pick($row, ['id', 'series_id', 'version', 'supersedes_id', 'asset_id', 'classification', 'provenance', 'methodology_version', 'effective_on', 'review_due_on', 'overdue', 'recorded_at']);
        $history = array_map($project, $r['history']['items']);

        return new Evidence(Tool::Assessments, $cutoff, now('UTC')->format('Y-m-d\TH:i:s\Z'), $r['history']['next'] === null ? 'complete_page' : 'partial_page', $r['history']['next'],
            ['asset_id' => $id, 'summary' => $r['summary'], 'current' => array_map($project, $r['current']), 'history' => $history,
                'history_scope' => 'asset family; current summary concerns the exact selected asset version; not a certification'],
            [$this->reference('portfolio_assets', $id), ...array_values(array_map(fn (array $row): array => $this->reference('shariah_assessments', $row['id']), $history))]);
    }

    /** @return array{table:string,id:string} */
    private function reference(string $table, string $id): array
    {
        return ['table' => $table, 'id' => $id];
    }

    /** @param array<string,mixed>|null $value
     * @return array<string,mixed>|null */
    private function observation(?array $value): ?array
    {
        return $value === null ? null : $this->pick($value, ['id', 'series_id', 'version', 'value', 'base', 'quote',
            'observed_at', 'effective_at', 'received_at', 'source_date', 'source_timezone', 'eligible_at',
            'age_from', 'time_policy', 'provider_time', 'unit_basis_confirmed']);
    }

    /** @param array<string,mixed> $row
     * @param list<string> $keys
     * @return array<string,mixed> */
    private function pick(array $row, array $keys): array
    {
        return array_intersect_key($row, array_flip($keys));
    }
}
