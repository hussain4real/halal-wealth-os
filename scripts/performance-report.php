<?php

declare(strict_types=1);

namespace HalalOS\Verification;

use RuntimeException;

/** @param array<string,mixed> $data */
function dashboardPerformance(array $data): int|float
{
    if (($data['workload']['postings'] ?? null) !== 10000 || ($data['workload']['holdings'] ?? null) !== 100 || ($data['warmups'] ?? null) !== 5 || ($data['runs'] ?? null) !== 30 || ($data['p95_rule'] ?? null) !== 'nearest-rank') {
        throw new RuntimeException('BLOCKED: wrong measured workload, warmup or sample contract.');
    }
    foreach (['server_ms', 'client_ms', 'query_ms', 'queries'] as $key) {
        $values = $data[$key] ?? null;
        if (! is_array($values) || ! array_is_list($values) || count($values) !== 30) {
            throw new RuntimeException('BLOCKED: missing measured samples '.$key);
        }
        foreach ($values as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0 || ($key === 'queries' && (! is_int($value) || $value < 1))) {
                throw new RuntimeException('BLOCKED: invalid measured sample '.$key);
            }
        }
    }
    $samples = $data['server_ms'];
    sort($samples, SORT_NUMERIC);
    $p95 = $samples[28];
    if ($p95 > 1000 || ! isset($data['p95_ms']) || $data['p95_ms'] !== $p95) {
        throw new RuntimeException('BLOCKED: measured server p95 failed.');
    }

    return $p95;
}
