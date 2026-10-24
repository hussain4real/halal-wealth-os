<?php

use Symfony\Component\Process\Process;
use Tests\Support\ReleaseRecovery;
use Tests\TestCase;

uses(TestCase::class);

test('encrypted populated recovery reconciles complete tables balances positions reservations and shariah references', function () {
    $identity = function (): string {
        $process = new Process(['node', 'scripts/verification-source.mjs'], base_path());
        $process->mustRun();

        return trim($process->getOutput());
    };
    $source = $identity();
    $report = (new ReleaseRecovery)->run();
    expect($identity())->toBe($source);
    $report['producer'] = ['source_sha256' => $source, 'run_id' => getenv('HWO_RELEASE_RUN_ID') ?: 'FOCUSED_ONLY'];
    expect($report['result'])->toBe('PASS')->and($report['tables'])->toBe(79)
        ->and($report['reader_fingerprints'])->toHaveKeys(['drive', 'cash', 'positions', 'reservations', 'shariah', 'imports', 'market', 'reminders'])
        ->and($report['table_fingerprints']['statement_sources']['rows'])->toBe(3)
        ->and($report['table_fingerprints']['statement_receipts']['rows'])->toBe(3)
        ->and($report['table_fingerprints']['statement_claims']['rows'])->toBeGreaterThan(0)
        ->and($report['table_fingerprints']['market_mappings']['rows'])->toBe(5)
        ->and($report['table_fingerprints']['market_attempts']['rows'])->toBe(5)
        ->and($report['table_fingerprints']['market_results']['rows'])->toBe(4)
        ->and($report['table_fingerprints']['market_admissions']['rows'])->toBe(3)
        ->and($report['table_fingerprints']['reminder_preferences']['rows'])->toBe(5)
        ->and($report['table_fingerprints']['reminder_claims']['rows'])->toBe(5)
        ->and($report['table_fingerprints']['reminder_reads']['rows'])->toBe(1)
        ->and($report['table_fingerprints']['drive_operations']['rows'])->toBe(4)
        ->and($report['table_fingerprints']['drive_connections']['rows'])->toBe(2)
        ->and($report['table_fingerprints']['drive_secrets']['rows'])->toBe(2)
        ->and($report['restored_sequences_write'])->toBeTrue()->and($report['restored_immutability_guard'])->toBeTrue()
        ->and($report['populated_downgrade_refused'])->toBeTrue()
        ->and($report['wrong_key_target_unchanged'])->toBeTrue()
        ->and($report['cleanup'])->toBe('OWNED_DATABASES_DROPPED_AND_PRIVATE_FILES_REMOVED');
    if (! is_dir(base_path('build'))) {
        mkdir(base_path('build'), 0700);
    }
    file_put_contents(base_path('build/release-recovery.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
});
