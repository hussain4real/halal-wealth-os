<?php

use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../../scripts/performance-report.php';

beforeEach(function () {
    $this->performance = ['workload' => ['postings' => 10000, 'holdings' => 100], 'warmups' => 5, 'runs' => 30, 'p95_rule' => 'nearest-rank', 'p95_ms' => 1000, 'server_ms' => [...array_fill(0, 28, 200), 1100, 1000], 'client_ms' => array_fill(0, 30, 201.5), 'query_ms' => array_fill(0, 30, 120.25), 'queries' => array_fill(0, 30, 450)];
});

test('dashboard measurement uses the nearest-rank 29th sample and includes the exact one-second boundary', function () {
    expect(HalalOS\Verification\dashboardPerformance($this->performance))->toBe(1000);
});

test('dashboard measurement refuses invalid workloads samples query proof and overstated percentiles', function (string $fault) {
    switch ($fault) {
        case 'negative': $this->performance['server_ms'] = array_fill(0, 30, -1);
            $this->performance['p95_ms'] = -1;
            break;
        case 'numeric-string': $this->performance['server_ms'] = array_fill(0, 30, '200');
            $this->performance['p95_ms'] = '200';
            break;
        case 'infinite': $this->performance['server_ms'][0] = INF;
            break;
        case 'too-few': array_pop($this->performance['server_ms']);
            break;
        case 'too-many': $this->performance['server_ms'][] = 0;
            break;
        case 'empty-queries': $this->performance['queries'] = [];
            break;
        case 'zero-queries': $this->performance['queries'][0] = 0;
            break;
        case 'posting-count': $this->performance['workload']['postings'] = 9999;
            break;
        case 'holding-count': $this->performance['workload']['holdings'] = 99;
            break;
        case 'wrong-rank': $this->performance['p95_ms'] = 200;
            break;
        case 'slow': $this->performance['server_ms'] = array_fill(0, 30, 1001);
            $this->performance['p95_ms'] = 1001;
            break;
    }
    expect(fn () => HalalOS\Verification\dashboardPerformance($this->performance))->toThrow(RuntimeException::class);
})->with(['negative', 'numeric-string', 'infinite', 'too-few', 'too-many', 'empty-queries', 'zero-queries', 'posting-count', 'holding-count', 'wrong-rank', 'slow']);
