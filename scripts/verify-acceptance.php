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
    $report['result'] = 'PASS';
    $report['tool_checks'] = ['HTTP_JOURNEY' => 'PASS', 'ACCEPTANCE_MAP' => 'PASS', 'FINANCIAL_COVERAGE' => 'PASS', 'HTTP_SECURITY' => 'PASS', 'DASHBOARD_PERFORMANCE' => 'PASS'];
    foreach ($report['requirements'] as $row) {
        if ($row['tool_check'] !== null && ! isset($report['tool_checks'][$row['tool_check']])) {
            throw new RuntimeException('BLOCKED: unknown acceptance tool check.');
        }
    }
    $report['artifact_sha256'] = [];
    foreach (['junit.xml', 'coverage.xml', 'financial-coverage.json', 'acceptance-http.json'] as $file) {
        $report['artifact_sha256'][$file] = hash_file('sha256', $root.'/build/'.$file);
    }
    file_put_contents($root.'/build/acceptance-map-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    echo 'Acceptance map: '.count($report['requirements']).' AC/invariant IDs; '.$report['unique_executed_selectors']." exact executed cases; tool gates passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(2);
}
