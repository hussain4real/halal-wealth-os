<?php

declare(strict_types=1);

require_once __DIR__.'/acceptance-map.php';
require_once __DIR__.'/financial-coverage.php';
require_once __DIR__.'/performance-report.php';

try {
    $root = dirname(__DIR__);
    $junit = new DOMDocument;
    if (! $junit->load($root.'/build/junit.xml', LIBXML_NONET)) {
        throw new RuntimeException('BLOCKED: missing current JUnit.');
    }
    $map = json_decode(file_get_contents(__DIR__.'/acceptance-map.json'), true, flags: JSON_THROW_ON_ERROR);
    $report = HalalOS\Verification\acceptanceMap($map, $junit, $root);
    $http = json_decode(file_get_contents($root.'/build/acceptance-http.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($http['result'] ?? '') !== 'PASS' || $http['cleanup'] !== 'OWNED_SERVER_STOPPED_AND_DATABASE_RESET' || $http['journey']['result'] !== 'PASS' || $http['security']['result'] !== 'PASS' || $http['performance']['result'] !== 'PASS') {
        throw new RuntimeException('BLOCKED: current HTTP journey/security/performance did not pass.');
    }
    foreach (['preview_no_financial_writes', 'malformed_selection_atomic', 'exact_request_and_reupload_once', 'safe_formula_download', 'real_csrf_rejection', 'linked_root_reversal'] as $check) {
        if (($http['statement_import']['result'] ?? null) !== 'PASS' || ($http['statement_import'][$check] ?? null) !== true) {
            throw new RuntimeException('BLOCKED: current native HTTP statement-import evidence did not pass.');
        }
    }
    foreach (['outage_preserves_stale_observation', 'honest_daily_source', 'exact_retry_after_supersession', 'owner_admin_isolation', 'real_csrf_rejection'] as $check) {
        if (($http['market_data']['result'] ?? null) !== 'PASS' || ($http['market_data'][$check] ?? null) !== true || ($http['market_data']['live_access'] ?? null) !== 'BLOCKED_MOCK_ONLY') {
            throw new RuntimeException('BLOCKED: current native HTTP market evidence did not pass.');
        }
    }
    $process = proc_open(['node', 'scripts/verification-source.mjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (! is_resource($process)) {
        throw new RuntimeException('BLOCKED: source inventory could not start.');
    }
    $source = trim(stream_get_contents($pipes[1]));
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || preg_match('/^[a-f0-9]{64}$/D', $source) !== 1 || ! hash_equals($source, $http['source_sha256'] ?? '') || ! hash_equals(hash_file('sha256', $root.'/build/junit.xml'), $http['junit_sha256'] ?? '')) {
        throw new RuntimeException('BLOCKED: HTTP proof does not match current sources and JUnit.');
    }
    HalalOS\Verification\dashboardPerformance($http['performance']);
    $coverage = json_decode(file_get_contents($root.'/build/financial-coverage.json'), true, flags: JSON_THROW_ON_ERROR);
    $clover = new DOMDocument;
    if (! $clover->load($root.'/build/coverage.xml', LIBXML_NONET) || $coverage !== HalalOS\Verification\financialCoverage($clover, $root)) {
        throw new RuntimeException('BLOCKED: financial coverage differs from current Clover.');
    }
    if ($coverage === []) {
        throw new RuntimeException('BLOCKED: empty financial coverage inventory.');
    }
    foreach ($coverage as $row) {
        if ($row['status'] !== 'INTERFACE_NO_EXECUTABLE_LINES' && ($row['status'] !== 'PASS' || $row['executable'] < 1 || $row['covered'] * 100 < $row['executable'] * 90)) {
            throw new RuntimeException('BLOCKED: financial coverage floor failed.');
        }
    }
    $gitIdentity = [];
    foreach (['commit' => ['git', 'rev-parse', 'HEAD'], 'tree' => ['git', 'rev-parse', 'HEAD^{tree}'], 'status' => ['git', 'status', '--porcelain', '--untracked-files=normal']] as $field => $command) {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (! is_resource($process)) {
            throw new RuntimeException('BLOCKED: release Git identity is unavailable.');
        }
        $gitIdentity[$field] = trim(stream_get_contents($pipes[1]));
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('BLOCKED: release Git identity failed.');
        }
    }
    $release = json_decode(file_get_contents($root.'/build/release-record.json'), true, flags: JSON_THROW_ON_ERROR);
    $recovery = json_decode(file_get_contents($root.'/build/release-recovery.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($release['dirty'] ?? null) !== false || $gitIdentity['status'] !== ''
        || ($release['commit'] ?? '') !== $gitIdentity['commit'] || ($release['tree'] ?? '') !== $gitIdentity['tree']
        || ($release['result'] ?? '') !== 'PASS' || ($recovery['result'] ?? '') !== 'PASS'
        || ! hash_equals($source, $release['source_sha256'] ?? '') || ! hash_equals($source, $recovery['proof']['source_sha256'] ?? '')
        || ! hash_equals(hash_file('sha256', $root.'/build/junit.xml'), $release['junit_sha256'] ?? '')
        || ! hash_equals($release['junit_sha256'], $recovery['proof']['junit_sha256'] ?? '')
        || ! hash_equals(hash_file('sha256', $root.'/build/release-recovery.json'), $release['recovery_sha256'] ?? '')
        || ! hash_equals($source, $recovery['producer']['source_sha256'] ?? '')
        || ! is_string(getenv('HWO_RELEASE_RUN_ID')) || ! preg_match('/^[a-f0-9]{32}$/D', getenv('HWO_RELEASE_RUN_ID'))
        || ! hash_equals(getenv('HWO_RELEASE_RUN_ID'), $recovery['producer']['run_id'] ?? '')
        || $release['deployment_authorized'] !== false || $release['owner_phase_acceptance'] !== 'PENDING') {
        throw new RuntimeException('BLOCKED: current release identity and actual recovery evidence do not match.');
    }
    if (($release['live_evaluation']['result'] ?? null) !== 'PASS' || ($release['live_evaluation']['cases'] ?? 0) < 40 || ! hash_equals(hash_file('sha256', $root.'/docs/halal-wealth-os/runs/M7-P2-live-evaluation.json'), $release['live_evaluation_sha256'] ?? '')) {
        throw new RuntimeException('BLOCKED: current live answer evaluation receipt missing.');
    }
    $report['result'] = 'PASS';
    $report['tool_checks'] = ['HTTP_JOURNEY' => 'PASS', 'ACCEPTANCE_MAP' => 'PASS', 'FINANCIAL_COVERAGE' => 'PASS', 'HTTP_SECURITY' => 'PASS', 'DASHBOARD_PERFORMANCE' => 'PASS', 'RELEASE_READINESS' => 'PASS', 'LIVE_ANSWER_EVALUATION' => 'PASS'];
    foreach ($report['requirements'] as $row) {
        if ($row['tool_check'] !== null && ! isset($report['tool_checks'][$row['tool_check']])) {
            throw new RuntimeException('BLOCKED: unknown acceptance tool check.');
        }
    }
    $report['artifact_sha256'] = [];
    foreach (['junit.xml', 'coverage.xml', 'financial-coverage.json', 'acceptance-http.json', 'release-record.json', 'release-recovery.json'] as $file) {
        $report['artifact_sha256'][$file] = hash_file('sha256', $root.'/build/'.$file);
    }
    file_put_contents($root.'/build/acceptance-map-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    echo 'Acceptance map: '.count($report['requirements']).' AC/invariant IDs; '.$report['unique_executed_selectors']." exact executed cases; tool gates passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(2);
}
