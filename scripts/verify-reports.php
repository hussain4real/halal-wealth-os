<?php

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
echo 'Covered statements: '.$metrics->getAttribute('coveredstatements').'/'.$metrics->getAttribute('statements').". Future financial coverage gates are not applicable.\n";
