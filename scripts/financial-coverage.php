<?php

declare(strict_types=1);

namespace HalalOS\Verification;

use DOMDocument;
use DOMElement;
use RuntimeException;

/** @return list<array{path:string,covered:int,executable:int,status:string}> */
function financialCoverage(DOMDocument $report, string $root): array
{
    $app = $root.'/app/Domain/';
    $paths = [];
    foreach (['Ledger', 'Planning', 'Portfolio', 'Reporting', 'Decisions', 'Imports', 'Integrations', 'Notifications', 'Intelligence', 'Shared/Money', 'Shared/Observations'] as $directory) {
        if (! is_dir($app.$directory)) {
            continue;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($app.$directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
    }
    $paths = [...$paths, ...glob($app.'Shariah/Purification*.php') ?: []];
    if ($paths === []) {
        throw new RuntimeException('BLOCKED: financial source inventory is empty.');
    }
    sort($paths);
    $files = [];
    foreach ($report->getElementsByTagName('file') as $file) {
        $path = $file->getAttribute('name');
        if (isset($files[$path])) {
            throw new RuntimeException('BLOCKED: duplicate coverage file '.$path);
        }
        $files[$path] = $file;
    }
    $rows = [];
    foreach ($paths as $path) {
        $relative = substr($path, strlen($root) + 1);
        $tokens = token_get_all(file_get_contents($path));
        $kinds = array_column(array_filter($tokens, 'is_array'), 0);
        if (in_array(T_INTERFACE, $kinds, true) && ! in_array(T_CLASS, $kinds, true) && ! in_array(T_TRAIT, $kinds, true)) {
            $rows[] = ['path' => $relative, 'covered' => 0, 'executable' => 0, 'status' => 'INTERFACE_NO_EXECUTABLE_LINES'];

            continue;
        }
        $file = $files[$path] ?? null;
        if (! $file instanceof DOMElement) {
            throw new RuntimeException('BLOCKED: missing financial coverage '.$relative);
        }
        $metrics = null;
        foreach ($file->childNodes as $node) {
            if ($node instanceof DOMElement && $node->tagName === 'metrics') {
                $metrics = $node;
            }
        }
        if (! $metrics instanceof DOMElement) {
            throw new RuntimeException('BLOCKED: missing financial line metrics '.$relative);
        }
        $totalText = $metrics->getAttribute('statements');
        $coveredText = $metrics->getAttribute('coveredstatements');
        if (! ctype_digit($totalText) || ! ctype_digit($coveredText)) {
            throw new RuntimeException('BLOCKED: invalid financial line metrics '.$relative);
        }
        $total = (int) $totalText;
        $covered = (int) $coveredText;
        $lines = [];
        $constructors = [];
        foreach ($file->getElementsByTagName('line') as $line) {
            $type = $line->getAttribute('type');
            if ($type !== 'stmt' && ! ($type === 'method' && $line->getAttribute('name') === '__construct')) {
                continue;
            }
            $number = $line->getAttribute('num');
            $count = $line->getAttribute('count');
            if (! ctype_digit($number) || (int) $number < 1 || ! ctype_digit($count)) {
                throw new RuntimeException('BLOCKED: invalid financial executable line '.$relative);
            }
            if ($type === 'stmt') {
                if (isset($lines[$number])) {
                    throw new RuntimeException('BLOCKED: duplicate financial executable line '.$relative);
                }
                $lines[$number] = (int) $count > 0;
            } else {
                $constructors[$number] = (int) $count > 0;
            }
        }
        $hits = count(array_filter($lines));
        $extra = $total - count($lines);
        $extraHits = $covered - $hits;
        $constructors = array_diff_key($constructors, $lines);
        if ($total < 1 || $covered > $total || $extra < 0 || $extra > count($constructors) || $extraHits < 0 || $extraHits > $extra || $extraHits > count(array_filter($constructors))) {
            throw new RuntimeException('BLOCKED: inconsistent financial executable lines '.$relative);
        }
        if ($covered * 100 < $total * 90) {
            throw new RuntimeException('BLOCKED: financial line coverage below 90%: '.$relative.' '.$covered.'/'.$total);
        }
        $rows[] = ['path' => $relative, 'covered' => $covered, 'executable' => $total, 'status' => 'PASS'];
    }

    return $rows;
}
