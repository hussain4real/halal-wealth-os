<?php

declare(strict_types=1);

namespace HalalOS\Verification;

use DOMDocument;
use DOMElement;
use RuntimeException;

/** @return list<string> */
function evidenceIds(mixed $value, string $label): array
{
    if (! is_array($value) || ! array_is_list($value) || count($value) !== count(array_unique($value, SORT_REGULAR))) {
        throw new RuntimeException('BLOCKED: invalid evidence list '.$label);
    }
    foreach ($value as $id) {
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('BLOCKED: invalid evidence identifier '.$label);
        }
    }

    return $value;
}

/** @param array<string,mixed> $map
 * @return array<string,mixed> */
function acceptanceMap(array $map, DOMDocument $junit, string $root): array
{
    foreach (['requirements', 'cases', 'manual_artifacts', 'fixtures'] as $section) {
        if (! isset($map[$section]) || ! is_array($map[$section]) || $map[$section] === [] || array_is_list($map[$section])) {
            throw new RuntimeException('BLOCKED: invalid acceptance section '.$section);
        }
    }
    $fixtureIds = array_keys($map['fixtures']);
    sort($fixtureIds);
    if ($fixtureIds !== array_map(fn (int $i): string => sprintf('F%02d', $i), range(1, 18))) {
        throw new RuntimeException('BLOCKED: fixture inventory must contain exactly F01 through F18.');
    }
    $roadmap = json_decode(file_get_contents($root.'/docs/halal-wealth-os/ROADMAP.json'), true, flags: JSON_THROW_ON_ERROR);
    $required = [];
    foreach ($roadmap['phases'] as $phase) {
        $required = [...$required, ...$phase['acceptanceIds']];
        if ($phase['id'] === 'M7-P1') {
            break;
        }
    }
    for ($i = 1; $i <= 12; $i++) {
        $required[] = sprintf('INV-%02d', $i);
    }
    $actual = array_keys($map['requirements']);
    sort($required);
    sort($actual);
    if ($required !== $actual) {
        throw new RuntimeException('BLOCKED: acceptance IDs differ from the implemented roadmap and invariants.');
    }
    $executed = [];
    foreach ($junit->getElementsByTagName('testcase') as $case) {
        if ($case->getElementsByTagName('failure')->length || $case->getElementsByTagName('error')->length || $case->getElementsByTagName('skipped')->length) {
            throw new RuntimeException('BLOCKED: acceptance tests contain failures, errors or skips.');
        }
        $key = $case->getAttribute('class')."\0".$case->getAttribute('name');
        if (isset($executed[$key])) {
            throw new RuntimeException('BLOCKED: duplicate JUnit test identity.');
        }
        $executed[$key] = $case;
    }
    if ($executed === []) {
        throw new RuntimeException('BLOCKED: no executed acceptance tests.');
    }
    foreach ($map['cases'] as $id => $selector) {
        $case = $executed[$selector['class']."\0".$selector['name']] ?? null;
        if (! $case instanceof DOMElement || explode('::', $case->getAttribute('file'))[0] !== $selector['file'] || (int) $case->getAttribute('assertions') < 1) {
            throw new RuntimeException('BLOCKED: missing exact executed acceptance case '.$id);
        }
    }
    foreach ($map['manual_artifacts'] as $id => $artifact) {
        $file = $artifact['public_path'];
        if (! str_starts_with($file, 'docs/halal-wealth-os/runs/') || str_contains($file, '..') || ! is_file($root.'/'.$file) || ! hash_equals($artifact['sha256'], hash_file('sha256', $root.'/'.$file)) || trim($artifact['scope']) === '') {
            throw new RuntimeException('BLOCKED: missing or changed scoped manual evidence '.$id);
        }
    }
    $rows = [];
    foreach ($map['requirements'] as $id => $entry) {
        if (($entry['status'] ?? null) !== 'CONFIGURED_REQUIRES_CURRENT_EXECUTION') {
            throw new RuntimeException('BLOCKED: invalid configured acceptance status '.$id);
        }
        $caseIds = evidenceIds($entry['actual_case_ids'] ?? null, $id);
        $manualIds = evidenceIds($entry['manual_artifact_ids'] ?? null, $id);
        foreach ($caseIds as $caseId) {
            if (! isset($map['cases'][$caseId])) {
                throw new RuntimeException('BLOCKED: unknown acceptance case '.$caseId);
            }
        }
        foreach ($manualIds as $artifactId) {
            if (! isset($map['manual_artifacts'][$artifactId])) {
                throw new RuntimeException('BLOCKED: unknown manual evidence '.$artifactId);
            }
        }
        if ($caseIds === [] && $manualIds === [] && ($entry['tool_check'] ?? null) === null) {
            throw new RuntimeException('BLOCKED: acceptance requirement has no evidence '.$id);
        }
        $rows[$id] = ['exact_executed_cases' => $entry['actual_case_ids'], 'scoped_manual_artifacts' => $entry['manual_artifact_ids'], 'tool_check' => $entry['tool_check'] ?? null];
    }
    foreach ($map['fixtures'] as $id => $fixture) {
        evidenceIds($fixture['case_ids'] ?? null, $id);
        if ($id === 'F18') {
            if (($fixture['case_ids'] ?? []) === [] || ($fixture['status'] ?? null) !== 'IMPLEMENTED_M7_P1_BOUNDARY_ONLY'
                || ($fixture['implemented_subscenarios'] ?? null) !== ['readonly_registry', 'owner_authorization', 'untrusted_prompt_boundary']
                || ($fixture['future_subscenarios'] ?? null) !== ['M7-P2_live_answer_citations_and_quality']) {
                throw new RuntimeException('BLOCKED: F18 requires scoped executed tool boundaries, with live answer evaluation still future.');
            }
            $families = [];
            foreach ($fixture['case_ids'] as $caseId) {
                $case = $map['cases'][$caseId] ?? null;
                if (! is_array($case) || $case['file'] !== 'tests/Feature/CopilotBoundaryTest.php') {
                    throw new RuntimeException('BLOCKED: F18 evidence must resolve exact implemented copilot boundary cases.');
                }
                foreach (['write aliases' => 'registry', 'foreign valid' => 'owner', 'provider malicious' => 'untrusted'] as $prefix => $family) {
                    if (str_starts_with($case['name'], $prefix)) {
                        $families[$family] = true;
                    }
                }
            }
            if (count($families) !== 3) {
                throw new RuntimeException('BLOCKED: F18 requires registry, owner and untrusted text execution.');
            }

            continue;
        }
        if ($id === 'F17') {
            if (($fixture['status'] ?? null) !== 'IMPLEMENTED_M6_P3_STATEMENT_PROVIDER_OUTBOX_EXECUTED' || ($fixture['case_ids'] ?? []) === []
                || ($fixture['implemented_subscenarios'] ?? null) !== ['statement_reupload', 'provider_job_retry', 'outbox_delivery_retry'] || ($fixture['future_subscenarios'] ?? null) !== []) {
                throw new RuntimeException('BLOCKED: F17 requires explicit executed statement, provider and outbox retry evidence.');
            }
            $families = [];
            foreach ($fixture['case_ids'] as $caseId) {
                if (! isset($map['cases'][$caseId]) || (! str_starts_with($map['cases'][$caseId]['file'], 'tests/Feature/StatementImport') && ! in_array($map['cases'][$caseId]['file'], ['tests/Feature/MarketRefreshTest.php', 'tests/Feature/MarketHttpTest.php', 'tests/Feature/MarketConcurrencyTest.php', 'tests/Feature/ReminderTest.php', 'tests/Feature/ReminderConcurrencyTest.php'], true))) {
                    throw new RuntimeException('BLOCKED: F17 evidence must resolve an exact implemented import/provider/outbox case.');
                }
                $file = $map['cases'][$caseId]['file'];
                $families[str_contains($file, 'StatementImport') ? 'statement' : (str_contains($file, 'Reminder') ? 'outbox' : 'provider')] = true;
            }
            if (count($families) !== 3) {
                throw new RuntimeException('BLOCKED: F17 requires all three executed retry families.');
            }

            continue;
        }
        if (($fixture['status'] ?? null) !== 'IMPLEMENTED_SYNTHETIC_TRANSLATION_RETAINED_EXECUTED') {
            throw new RuntimeException('BLOCKED: applicable fixture is not passing '.$id);
        }
        if (($fixture['case_ids'] ?? []) === []) {
            throw new RuntimeException('BLOCKED: applicable fixture has no executed cases '.$id);
        }
        foreach ($fixture['case_ids'] as $caseId) {
            if (! isset($map['cases'][$caseId])) {
                throw new RuntimeException('BLOCKED: unknown fixture case '.$caseId);
            }
        }
    }

    return ['fixtures' => array_map(fn (array $fixture): array => ['status' => $fixture['status'], 'case_ids' => $fixture['case_ids'], 'scope' => $fixture['scope'], 'implemented_subscenarios' => $fixture['implemented_subscenarios'] ?? [], 'future_subscenarios' => $fixture['future_subscenarios'] ?? [], 'current_executed_cases' => $fixture['case_ids'] !== [], 'original_input_execution_claim' => false], $map['fixtures']), 'requirements' => $rows, 'unique_executed_selectors' => count($map['cases']), 'manual_evidence_semantics' => 'Scoped manual observations retain their declared limits; they cannot replace configured executed cases, hosted CI or owner acceptance.'];
}
