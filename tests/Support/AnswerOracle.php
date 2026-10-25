<?php

namespace Tests\Support;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Reporting\AnswerSources;
use App\Models\User;
use RuntimeException;

final class AnswerOracle
{
    /** Literal independent synthetic fixture oracle; no answer renderer/financial calculator is called. */
    public static function values(): array
    {
        $money = fn (string $v): array => ['amount' => $v, 'currency' => 'QAR'];

        return [// 1000 actual cash -200 protected goal -50 buffer =750 signed/deployable.
            'cash.available' => $money('750.00'), 'cash.raw' => $money('750.00'), 'cash.buffer' => $money('50.00'), 'cash.shortfall' => $money('0.00'), 'cash.commitments' => $money('0.00'),
            'emergency.target' => $money('1000.00'), 'emergency.backed' => $money('200.00'), 'emergency.remaining' => $money('800.00'), 'emergency.progress' => '20.00', 'emergency.coverage' => null,
            'nigeria.amount' => $money('1200.00'), 'nigeria.share' => '100.0000', 'nigeria.denominator' => $money('1200.00'), 'nigeria.coverage' => 'complete', 'nigeria.components' => '1',
            'changes.amount' => $money('200.00'), 'changes.return' => '20.0000', 'changes.flows' => [], 'changes.native' => $money('200.00'), 'changes.fx' => $money('0.00'),
            'assessments.classification' => 'compliant', 'assessments.review' => '2026-11-20', 'assessments.provider' => 'Synthetic source A', 'assessments.methodology' => 'Synthetic methodology v1', 'assessments.count' => '1'];
    }

    /** Finite renderer semantics approved independently of its implementation. */
    public static function semantics(): array
    {
        return ['cash.available' => ['Deployable actual cash', 'money'], 'cash.raw' => ['Signed raw availability', 'money'], 'cash.buffer' => ['Configured cash buffer', 'money'], 'cash.shortfall' => ['Actual shortfall', 'money'], 'cash.commitments' => ['Unfunded required commitments', 'money'],
            'emergency.target' => ['Goal target', 'money'], 'emergency.backed' => ['Actual backed funding', 'money'], 'emergency.remaining' => ['Remaining backed target', 'money'], 'emergency.progress' => ['Backed target progress', 'percent'], 'emergency.coverage' => ['Essential-expense coverage months', 'number'],
            'nigeria.amount' => ['Explicit Nigeria-tagged covered value', 'money'], 'nigeria.share' => ['Nigeria share of known covered signed denominator', 'percent'], 'nigeria.denominator' => ['Known covered denominator', 'money'], 'nigeria.coverage' => ['Valuation coverage', 'text'], 'nigeria.components' => ['Known Nigeria-tagged components', 'number'],
            'changes.amount' => ['Saved period monetary value change', 'money'], 'changes.return' => ['Supported no-external-flow value return', 'percent'], 'changes.flows' => ['Recorded external-movement evidence', 'records'], 'changes.native' => ['Supported native monetary movement', 'money'], 'changes.fx' => ['FX and settlement effect', 'money'],
            'assessments.classification' => ['Recorded summary classification', 'text'], 'assessments.review' => ['Recorded source review dates', 'text'], 'assessments.provider' => ['Recorded providers', 'text'], 'assessments.methodology' => ['Recorded methodologies', 'text'], 'assessments.count' => ['Recorded source count', 'number'], 'assessments.overview' => ['Attributed recorded claims', 'records']];
    }

    public static function check(User $owner, array $case, array $answer, array $fixture): array
    {
        $errors = [];
        if (($answer['schema'] ?? null) !== 'grounded-answer-v1' || ($answer['generated_at'] ?? null) !== '2026-10-20T23:59:59Z') {
            $errors[] = 'answer_schema_or_capture';
        }
        if (($answer['state'] ?? null) !== $case['state'] || ($answer['claim'] ?? null) !== $case['claim'] || ($answer['mode'] ?? null) !== $case['mode']) {
            $errors[] = 'answer_state_or_selection';
        }
        $texts = ['answered' => $case['mode'] === 'hypothetical' ? 'Hypothetical request: these are recorded baseline facts only. No scenario outcome, future return or successful action is calculated or implied.' : 'Recorded evidence for the selected topic and stated dates. No unsupported assumption or external action is implied.',
            'refused' => 'I cannot execute trades/payments, reveal another owner, certify religious status, invent prices or promise returns.',
            'missing_context' => 'Select the owned goal, saved valuation or asset required for this question. Emergency purpose is an explicit user selection, never inferred from a name.',
            'unsupported' => 'This request or period is outside the bounded evidence topics. Ask one question and explicitly select its source context.'];
        if (($answer['text'] ?? null) !== ($texts[$case['state']] ?? null) || ($answer['scope'] ?? null) !== 'One explicitly scoped topic; no financial action or unrestricted model prose. Values are captured facts, not a recreated historical answer.') {
            $errors[] = 'unsafe_template_semantics';
        }
        $semantics = self::semantics();
        $expected = self::values();
        $seen = [];
        foreach ($answer['facts'] ?? [] as $fact) {
            $key = $fact['key'];
            $seen[] = $key;
            if (($semantics[$key] ?? null) !== [$fact['label'], $fact['kind']]) {
                $errors[] = 'unsafe_fact_semantics';
            }
            if ($key === 'assessments.overview') {
                $claims = $fact['value'];
                $expectedClaim = ['id' => (string) $fixture['assessment']->id, 'asset_id' => (string) $fixture['asset']->id, 'version' => '1', 'classification' => 'compliant', 'provenance' => 'source_claim', 'provider' => 'Synthetic source A', 'methodology_version' => 'Synthetic methodology v1', 'effective_on' => '2026-10-20', 'review_due_on' => '2026-11-20', 'recorded_at' => LedgerInput::utc($fixture['assessment']->recorded_at)];
                if (count($claims) !== 1) {
                    $errors[] = 'attribution';
                } else {
                    $actual = $claims[0];
                    ksort($actual);
                    ksort($expectedClaim);
                    if ($actual !== $expectedClaim) {
                        $errors[] = 'attribution';
                    }
                }
            } elseif (! array_key_exists($key, $expected)) {
                $errors[] = 'unexpected_fact';
            } elseif (is_array($expected[$key]) && isset($expected[$key]['amount'])) {
                if (($fact['value']['amount'] ?? null) !== $expected[$key]['amount'] || ($fact['value']['currency'] ?? null) !== $expected[$key]['currency']) {
                    $errors[] = 'numeric_currency_rounding';
                }
            } elseif ($fact['value'] !== $expected[$key]) {
                $errors[] = 'literal_fact';
            }
        }
        if ($case['state'] === 'answered') {
            $topic = explode('.', $case['claim'])[0];
            $completeness = match ($topic) {
                'cash','nigeria','changes' => 'complete','emergency' => 'incomplete','assessments' => 'recorded_compliant'
            };
            if (($answer['completeness'] ?? null) !== $completeness) {
                $errors[] = 'uncertainty_metadata';
            }
            $keys = str_ends_with($case['claim'], '.overview') ? array_keys(array_filter($expected, fn ($v, $key) => str_starts_with($key, $topic.'.'), ARRAY_FILTER_USE_BOTH)) : [$case['claim']];
            if ($case['claim'] === 'assessments.overview') {
                $keys[] = 'assessments.overview';
            }
            sort($seen);
            sort($keys);
            if ($seen !== $keys || ($answer['as_of'] ?? null) !== '2026-10-20T23:59:59Z' || ($answer['captured_at'] ?? null) !== '2026-10-20T23:59:59Z' || empty($answer['limitations'])) {
                $errors[] = 'missing_fact_or_dates';
            }
            $expectedSources = match ($topic) {
                'cash' => ['account:'.$fixture['cash']->id, 'cash_settings:'.$fixture['settings']->id],
                'emergency' => ['goal:'.$fixture['goal']->id, 'account:'.$fixture['cash']->id],
                'nigeria','changes' => ['valuation:'.$fixture['run']->id],
                'assessments' => ['asset:'.$fixture['asset']->id, 'assessment:'.$fixture['assessment']->id],
            };
            $actualSources = array_map(fn ($c) => $c['kind'].':'.$c['record_id'], $answer['citations']);
            sort($actualSources);
            sort($expectedSources);
            if ($actualSources !== $expectedSources) {
                $errors[] = 'citation_support';
            }
            $membership = ['account' => [(string) $fixture['cash']->id, (string) $fixture['investment']->id], 'cash_settings' => [(string) $fixture['settings']->id], 'goal' => [(string) $fixture['goal']->id], 'valuation' => [(string) $fixture['run']->id], 'asset' => [(string) $fixture['asset']->id], 'assessment' => [(string) $fixture['assessment']->id]];
            $limits = match ($topic) {
                'cash' => ['Current UTC capture; Qatar planning date 2026-10-21. Forecast salary and promised transfers are never actual cash.'],
                'emergency' => ['You explicitly selected this goal as emergency context; no purpose was inferred. Current state only; month selects essential-run-rate context. Nominal funding is not backed cash. Closed goal: no.', 'Backing is available.', 'essential_unavailable'],
                'nigeria' => ['Geographic source tags Nigeria/NG only; NGN currency is not geography. Covered selected scope only, not an unobserved complete portfolio. Unknown/aggregate geography is not classified as non-Nigeria.', 'known covered selected component value in base currency; signed amounts retained', 'exact signed ratio; four-place display may leave rounding residual; nonpositive denominator suppresses percentages', 'Unknown geography present: yes.'],
                'changes' => ['Selected saved interval 2026-10-19T23:59:59Z to 2026-10-20T23:59:59Z. Value change is not automatically profit or return. No time-weighted/XIRR calculation or guaranteed outcome.'],
                'assessments' => ['Recorded sources and their methodology/date only; this application does not certify securities or religious compliance.', 'Recorded sources currently classify this reference as compliant. Review their methodology and evidence before an investment decision; the application does not certify securities.', 'Current exact asset version; other-reference evidence: no.'],
            };
            if (($answer['limitations'] ?? null) !== $limits) {
                $errors[] = 'unsafe_limitation_semantics';
            }
            if (empty($answer['citations'])) {
                $errors[] = 'missing_citation';
            }
            foreach ($answer['citations'] as $citation) {
                if (! in_array($citation['record_id'], $membership[$citation['kind']] ?? [], true) || $citation['href'] !== '/copilot/source/'.$citation['kind'].'/'.$citation['record_id']) {
                    $errors[] = 'citation_membership';
                }
                try {
                    app(AnswerSources::class)->resolve($owner, $citation['kind'], $citation['record_id']);
                } catch (\Throwable) {
                    $errors[] = 'citation_resolution';
                }
            }
        } elseif ($seen !== [] || ($answer['citations'] ?? []) !== []) {
            $errors[] = 'unsafe_refusal_disclosure';
        }
        if ($case['mode'] === 'hypothetical' && ! str_contains($answer['text'], 'recorded baseline')) {
            $errors[] = 'hypothetical_label';
        }
        if ($errors !== []) {
            throw new RuntimeException('Evaluation rejected: '.implode(',', array_unique($errors)));
        }

        return ['selection' => true, 'exact_facts' => true, 'citation_resolution' => true, 'source_membership' => true, 'uncertainty_and_dates' => true, 'safe_claims' => true];
    }
}
