<?php

declare(strict_types=1);

use Pest\Support\Str;
use SebastianBergmann\CodeCoverage\Report\Facade;
use SebastianBergmann\CodeCoverage\Serialization\Merger;

require __DIR__.'/../vendor/autoload.php';

function loadShardXml(string $path): DOMDocument
{
    $report = new DOMDocument;
    if (! $report->load($path, LIBXML_NONET)) {
        throw new RuntimeException('Missing or invalid verification XML: '.$path);
    }

    return $report;
}

function actualTestId(DOMElement $case): string
{
    $class = $case->getAttribute('class');
    $name = preg_split('/ with data set (?="[^"]*.*"$|#\d+$)/', $case->getAttribute('name'), 2);
    $method = Str::evaluable($name[0]);
    $suffix = isset($name[1]) ? '#'.(str_starts_with($name[1], '"') ? substr($name[1], 1, -1) : substr($name[1], 1)) : '';

    return 'P\\'.$class.'::'.$method.$suffix;
}

try {
    $root = dirname(__DIR__);
    $inventory = $argv[1] ?? throw new InvalidArgumentException('Missing discovery inventory.');
    $directory = $argv[2] ?? throw new InvalidArgumentException('Missing shard reports directory.');
    $xpath = new DOMXPath(loadShardXml($inventory));
    $xpath->registerNamespace('p', 'https://xml.phpunit.de/testSuite');
    $expected = [];
    foreach ($xpath->query('//p:testMethod/@id') as $id) {
        $expected[] = $id->value;
    }
    sort($expected);

    $junit = new DOMDocument('1.0', 'UTF-8');
    $junit->appendChild($junit->createElement('testsuites'));
    $coverageFiles = [];
    $recoveryFiles = [];
    $actual = [];
    foreach (range(1, 8) as $number) {
        $shard = "{$directory}/pest-shard-{$number}";
        $xpath = new DOMXPath(loadShardXml($shard.'/junit.xml'));
        if ($xpath->evaluate('count(//testcase)') < 1 || $xpath->evaluate('count(//failure | //error | //skipped)') > 0) {
            throw new RuntimeException("Shard {$number} contains failures or skipped tests, or ran no tests.");
        }
        foreach ($xpath->query('//testcase') as $case) {
            $actual[] = actualTestId($case);
        }
        foreach ($xpath->query('/testsuites/testsuite') as $suite) {
            $junit->documentElement->appendChild($junit->importNode($suite, true));
        }
        $coverageFiles[] = $shard.'/coverage.php';
        if (is_file($shard.'/release-recovery.json')) {
            $recoveryFiles[] = $shard.'/release-recovery.json';
        }
    }
    sort($actual);
    if ($actual !== $expected || count($actual) !== count(array_unique($actual))) {
        throw new RuntimeException('Shard reports do not match the full, distinct Pest test inventory.');
    }
    if (count($recoveryFiles) !== 1) {
        throw new RuntimeException('Exactly one shard must produce the recovery proof.');
    }

    $merged = (new Merger)->merge($coverageFiles);
    $merged['basePath'] = $root.'/app';
    Facade::fromSerializedData($merged)->renderClover($root.'/build/coverage.xml');
    if ($junit->save($root.'/build/junit.xml') === false || ! copy($recoveryFiles[0], $root.'/build/release-recovery.json')) {
        throw new RuntimeException('Unable to write the merged verification reports.');
    }
    echo count($actual)." distinct tests ran across eight shards.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'BLOCKED: '.$error->getMessage()."\n");
    exit(2);
}
