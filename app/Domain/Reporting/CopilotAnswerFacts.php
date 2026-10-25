<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Planning\CashAvailability;
use App\Domain\Planning\GoalView;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Portfolio\ValuationMath;
use App\Domain\Reporting\Copilot\Answers\AnswerContext;
use App\Domain\Reporting\Copilot\Answers\AnswerPeriod;
use App\Domain\Reporting\Copilot\Answers\AnswerPlan;
use App\Domain\Reporting\Copilot\Answers\AnswerTopic;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shariah\AssessmentRead;
use App\Models\User;

final class CopilotAnswerFacts
{
    /** @return array<string,mixed> */
    public function answer(User $owner, AnswerPlan $plan, AnswerContext $context, string $question): array
    {
        return PortfolioRead::coherent(function () use ($owner, $plan, $context, $question): array {
            $context->authorize($owner);
            $topic = $plan->claim->topic();
            $base = ['schema' => 'grounded-answer-v1', 'claim' => $plan->claim->value, 'mode' => $plan->mode,
                'generated_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'), 'facts' => [], 'citations' => [],
                'scope' => 'One explicitly scoped topic; no financial action or unrestricted model prose. Values are captured facts, not a recreated historical answer.'];
            if ($topic === AnswerTopic::Refuse) {
                return [...$base, 'state' => 'refused', 'text' => 'I cannot execute trades/payments, reveal another owner, certify religious status, invent prices or promise returns.'];
            }
            if ($topic === AnswerTopic::Unsupported || $plan->scope === 'unsupported'
                || ($plan->scope !== $topic->scope())) {
                return [...$base, 'state' => 'unsupported', 'text' => 'This request or period is outside the bounded evidence topics. Ask one question and explicitly select its source context.'];
            }
            $data = match ($topic) {
                AnswerTopic::Cash => $this->cash($owner),
                AnswerTopic::Emergency => $context->goal === null ? null : $this->emergency($owner, $context),
                AnswerTopic::Nigeria, AnswerTopic::Changes => $context->valuation === null ? null : $this->valuation($owner, $context, $topic),
                AnswerTopic::Assessments => $context->asset === null ? null : $this->assessments($owner, $context),
            };
            if ($data === null) {
                return [...$base, 'state' => 'missing_context', 'text' => 'Select the owned goal, saved valuation or asset required for this question. Emergency purpose is an explicit user selection, never inferred from a name.'];
            }
            if (! AnswerPeriod::supported($question, $topic, $data['as_of'], $data['start'] ?? null)) {
                return [...$base, 'state' => 'unsupported', 'text' => 'The requested dates or relative period are not supported by the selected source. No current value is substituted.'];
            }
            $field = $plan->claim->field();
            $facts = $field === 'overview' ? array_values($data['facts']) : (isset($data['facts'][$field]) ? [$data['facts'][$field]] : []);
            if ($facts === []) {
                return [...$base, 'state' => 'unsupported', 'text' => 'That claim is not available in this source context.'];
            }

            return [...$base, 'state' => 'answered', 'text' => $plan->mode === 'hypothetical'
                ? 'Hypothetical request: these are recorded baseline facts only. No scenario outcome, future return or successful action is calculated or implied.'
                : 'Recorded evidence for the selected topic and stated dates. No unsupported assumption or external action is implied.',
                'as_of' => $data['as_of'], 'captured_at' => $data['captured_at'], 'completeness' => $data['completeness'],
                'limitations' => $data['limitations'], 'facts' => $facts, 'citations' => $data['citations']];
        });
    }

    /** @return array<string,mixed> */
    private function cash(User $owner): array
    {
        $r = app(CashAvailability::class)->capture($owner, complete: true);
        $a = $r['actual'];
        $sources = [];
        foreach ($r['accounts'] as $row) {
            $sources[] = app(AnswerSources::class)->citation($owner, 'account', (string) $row['id'], 'Included cash source account');
        }

        if ($r['settings'] !== null) {
            $sources[] = app(AnswerSources::class)->citation($owner, 'cash_settings', $r['settings']['id'], 'Cash buffer and explicit commitment configuration');
        }

        return ['as_of' => $r['cutoff'], 'captured_at' => $r['cutoff'], 'completeness' => $a['state'], 'citations' => $sources,
            'limitations' => ['Current UTC capture; Qatar planning date '.$r['today'].'. Forecast salary and promised transfers are never actual cash.', ...$a['missing']],
            'facts' => ['available' => $this->fact('cash.available', 'Deployable actual cash', 'money', $a['deployable']),
                'raw' => $this->fact('cash.raw', 'Signed raw availability', 'money', $a['raw']),
                'buffer' => $this->fact('cash.buffer', 'Configured cash buffer', 'money', $a['buffer']),
                'shortfall' => $this->fact('cash.shortfall', 'Actual shortfall', 'money', $a['shortfall']),
                'commitments' => $this->fact('cash.commitments', 'Unfunded required commitments', 'money', $a['breakdown']['unfunded'] ?? null)]];
    }

    /** @return array<string,mixed> */
    private function emergency(User $owner, AnswerContext $c): array
    {
        $r = app(GoalView::class)->show($owner, $c->goal, $c->month);
        $g = $r['goal'];
        $now = now('UTC')->format('Y-m-d\TH:i:s\Z');

        return ['as_of' => $now, 'captured_at' => $now, 'completeness' => $g['backing_reason'] === null && $g['projection']['coverage_months'] !== null ? 'complete' : 'incomplete',
            'citations' => [app(AnswerSources::class)->citation($owner, 'goal', $g['id'], 'Selected current goal version'), app(AnswerSources::class)->citation($owner, 'account', $g['account_id'], 'Goal backing account')],
            'limitations' => ['You explicitly selected this goal as emergency context; no purpose was inferred. Current state only; month selects essential-run-rate context. Nominal funding is not backed cash. Closed goal: '.($g['closed'] ? 'yes' : 'no').'.', $g['backing_reason'] ?? 'Backing is available.', $g['projection']['coverage_reason'] ?? 'Essential-expense coverage is available.'],
            'facts' => ['target' => $this->fact('emergency.target', 'Goal target', 'money', $g['target']),
                'backed' => $this->fact('emergency.backed', 'Actual backed funding', 'money', $g['backed']),
                'remaining' => $this->fact('emergency.remaining', 'Remaining backed target', 'money', $g['projection']['remaining']),
                'progress' => $this->fact('emergency.progress', 'Backed target progress', 'percent', $g['projection']['percent']),
                'coverage' => $this->fact('emergency.coverage', 'Essential-expense coverage months', 'number', $g['projection']['coverage_months'])]];
    }

    /** @return array<string,mixed> */
    private function valuation(User $owner, AnswerContext $c, AnswerTopic $topic): array
    {
        $row = LedgerInput::owned('investment_valuation_runs', $owner, $c->valuation);
        $r = json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR);
        $a = $r['ending']['allocation'];
        $currency = Currency::from($r['base_currency']);
        $citations = [app(AnswerSources::class)->citation($owner, 'valuation', (string) $row->id, 'Immutable saved valuation and its exact period')];
        if ($topic === AnswerTopic::Nigeria) {
            $minor = '0';
            $components = 0;
            $unknown = false;
            foreach ($a['dimensions']['geography'] as $group) {
                if (in_array(mb_strtolower(trim($group['label'] ?? '')), ['nigeria', 'ng'], true)) {
                    $minor = bcadd($minor, $group['minor'], 0);
                    $components += $group['known_components'];
                }
                if ($group['label'] === null || trim($group['label']) === '' || $group['label'] === 'Unknown / no look-through') {
                    $unknown = true;
                }
            }
            $ratio = ValuationMath::ratio($minor, $a['denominator_minor']);
            $unknownOnly = $unknown && $components === 0;
            $facts = ['amount' => $this->fact('nigeria.amount', 'Explicit Nigeria-tagged covered value', 'money', $unknownOnly ? null : ValuationMath::money($currency, $minor)),
                'share' => $this->fact('nigeria.share', 'Nigeria share of known covered signed denominator', 'percent', $unknownOnly ? null : ($ratio['percent'] ?? null)),
                'denominator' => $this->fact('nigeria.denominator', 'Known covered denominator', 'money', ValuationMath::money($currency, $a['denominator_minor'])),
                'coverage' => $this->fact('nigeria.coverage', 'Valuation coverage', 'text', $a['coverage']),
                'components' => $this->fact('nigeria.components', 'Known Nigeria-tagged components', 'number', (string) $components)];
            $limits = ['Geographic source tags Nigeria/NG only; NGN currency is not geography. Covered selected scope only, not an unobserved complete portfolio. Unknown/aggregate geography is not classified as non-Nigeria.', $a['denominator'], $a['percentage_policy'], 'Unknown geography present: '.($unknown ? 'yes' : 'no').'.'];
        } else {
            $performance = $r['performance'];
            $facts = ['amount' => $this->fact('changes.amount', 'Saved period monetary value change', 'money', $performance['monetary_change'] ?? null),
                'return' => $this->fact('changes.return', 'Supported no-external-flow value return', 'percent', $performance['base_return']['percent'] ?? null),
                'flows' => $this->fact('changes.flows', 'Recorded external-movement evidence', 'records', $performance['external_movements'] ?? null),
                'native' => $this->fact('changes.native', 'Supported native monetary movement', 'money', $performance['native_monetary_change'] ?? null),
                'fx' => $this->fact('changes.fx', 'FX and settlement effect', 'money', $performance['FX_and_settlement_effect'] ?? null)];
            $limits = ['Selected saved interval '.($r['start'] ?? 'not supplied').' to '.$r['cutoff'].'. Value change is not automatically profit or return. No time-weighted/XIRR calculation or guaranteed outcome.', ...($performance['reasons'] ?? ['Comparison start was not supplied.'])];
        }

        return ['as_of' => $r['cutoff'], 'captured_at' => $r['captured_at'], 'completeness' => $r['ending']['state'], 'start' => $r['start'] ?? null, 'citations' => $citations, 'facts' => $facts, 'limitations' => $limits];
    }

    /** @return array<string,mixed> */
    private function assessments(User $owner, AnswerContext $c): array
    {
        $r = app(AssessmentRead::class)->view($owner, $c->asset, $c->cutoff);
        $claims = [];
        $sources = [app(AnswerSources::class)->citation($owner, 'asset', $c->asset, 'Selected exact asset version')];
        foreach ($r['current'] as $row) {
            $sources[] = app(AnswerSources::class)->citation($owner, 'assessment', $row['id'], 'Recorded attributed assessment version');
            $claims[] = array_intersect_key($row, array_flip(['id', 'asset_id', 'version', 'classification', 'provenance', 'provider', 'methodology_version', 'effective_on', 'review_due_on', 'recorded_at']));
        }
        $join = fn (string $key): ?string => $claims === [] ? null : implode('; ', array_map(fn (array $row): string => $row[$key] ?? 'not supplied', $claims));

        return ['as_of' => $r['cutoff'], 'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'), 'completeness' => $r['summary']['guidance_state'], 'citations' => $sources,
            'limitations' => ['Recorded sources and their methodology/date only; this application does not certify securities or religious compliance.', $r['summary']['guidance'], 'Current exact asset version; other-reference evidence: '.($r['summary']['other_reference_evidence'] ? 'yes' : 'no').'.'],
            'facts' => ['classification' => $this->fact('assessments.classification', 'Recorded summary classification', 'text', $r['summary']['classification']),
                'review' => $this->fact('assessments.review', 'Recorded source review dates', 'text', $join('review_due_on')),
                'provider' => $this->fact('assessments.provider', 'Recorded providers', 'text', $join('provider')),
                'methodology' => $this->fact('assessments.methodology', 'Recorded methodologies', 'text', $join('methodology_version')),
                'count' => $this->fact('assessments.count', 'Recorded source count', 'number', (string) $r['summary']['source_count']),
                'overview' => $this->fact('assessments.overview', 'Attributed recorded claims', 'records', $claims)]];
    }

    /** @return array{key:string,label:string,kind:string,value:mixed} */
    private function fact(string $key, string $label, string $kind, mixed $value): array
    {
        return ['key' => $key, 'label' => $label, 'kind' => $kind, 'value' => $value];
    }
}
