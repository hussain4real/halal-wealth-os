<?php

require_once __DIR__.'/financial-coverage.php';

$reports = [
    'junit' => __DIR__.'/../build/junit.xml',
    'coverage' => __DIR__.'/../build/coverage.xml',
];

foreach ($reports as $name => $path) {
    if (! is_file($path)) {
        fwrite(STDERR, "BLOCKED: missing {$name} report.\n");
        exit(2);
    }
}

$junit = new DOMDocument;
$coverage = new DOMDocument;
if (! $junit->load($reports['junit'], LIBXML_NONET) || ! $coverage->load($reports['coverage'], LIBXML_NONET)) {
    fwrite(STDERR, "BLOCKED: invalid XML verification report.\n");
    exit(2);
}
$tests = new DOMXPath($junit);
if ($tests->evaluate('count(//testcase)') < 1 || $tests->evaluate('count(//failure | //error | //skipped)') > 0) {
    fwrite(STDERR, "BLOCKED: baseline tests must execute with no failures, errors or skipped required tests.\n");
    exit(2);
}
$metrics = (new DOMXPath($coverage))->query('/coverage/project/metrics')?->item(0);
if (! $metrics instanceof DOMElement || (int) $metrics->getAttribute('statements') < 1 || (int) $metrics->getAttribute('coveredstatements') < 1) {
    fwrite(STDERR, "BLOCKED: no executable/covered application statements were reported.\n");
    exit(2);
}
echo 'Executed tests: '.$tests->evaluate('count(//testcase)')."; coverage includes configured app/ paths.\n";
echo 'Covered statements: '.$metrics->getAttribute('coveredstatements').'/'.$metrics->getAttribute('statements').".\n";
try {
    $financial = HalalOS\Verification\financialCoverage($coverage, dirname(__DIR__));
    foreach ($financial as $row) {
        echo 'FINANCIAL '.$row['path'].' '.$row['covered'].'/'.$row['executable'].' '.$row['status']."\n";
    }
    file_put_contents(__DIR__.'/../build/financial-coverage.json', json_encode($financial, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(2);
}
