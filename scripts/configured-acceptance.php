<?php

declare(strict_types=1);
require_once __DIR__.'/acceptance-map.php';
try {
    $junit = new DOMDocument;
    if (! $junit->load(__DIR__.'/../build/junit.xml', LIBXML_NONET)) {
        throw new RuntimeException('Current JUnit required.');
    }
    $map = json_decode(file_get_contents(__DIR__.'/acceptance-map.json'), true, flags: JSON_THROW_ON_ERROR);
    echo json_encode(HalalOS\Verification\acceptanceMap($map, $junit, dirname(__DIR__)), JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(2);
}
