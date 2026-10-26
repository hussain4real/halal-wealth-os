<?php

use App\Domain\Integrations\AnswerEvaluationPolicy;

require_once __DIR__.'/../../scripts/answer-eval-budget.php';

beforeEach(function () {
    $this->budgetRoot = sys_get_temp_dir().'/hwo-eval-budget-'.bin2hex(random_bytes(8));
    mkdir($this->budgetRoot, 0700);
});
afterEach(function () {
    foreach (glob($this->budgetRoot.'/*') as $file) {
        unlink($file);
    }rmdir($this->budgetRoot);
});

test('every failed unknown or complete live attempt keeps its conservative global reservation', function () {
    expect(reserveAnswerEvaluation($this->budgetRoot, 'case1', str_repeat('a', 64), str_repeat('b', 64)))->toBe(29984);
    expect(reserveAnswerEvaluation($this->budgetRoot, 'case1-retry', str_repeat('c', 64), str_repeat('d', 64)))->toBe(58144);
    $total = 58144;
    for ($i = 0; $i < 211; $i++) {
        $total = reserveAnswerEvaluation($this->budgetRoot, 'case'.$i, str_repeat('a', 64), str_repeat('b', 64));
    }
    expect($total)->toBe(5999904);
    expect(fn () => reserveAnswerEvaluation($this->budgetRoot, 'over-cap', str_repeat('a', 64), str_repeat('b', 64)))->toThrow(RuntimeException::class, 'budget exhausted');
});

test('truncated replaced deleted or symlinked ledger state cannot silently reset the budget', function (string $fault) {
    reserveAnswerEvaluation($this->budgetRoot, 'case1', str_repeat('a', 64), str_repeat('b', 64));
    $ledger = $this->budgetRoot.'/live-budget-ledger.jsonl';
    if ($fault === 'truncated') {
        file_put_contents($ledger, explode("\n", file_get_contents($ledger))[0]."\n");
    } elseif ($fault === 'deleted') {
        unlink($ledger);
    } elseif ($fault === 'replaced') {
        $bytes = file_get_contents($ledger);
        rename($ledger, $ledger.'.old');
        file_put_contents($ledger, $bytes);
    } else {
        rename($ledger, $ledger.'.old');
        symlink($ledger.'.old', $ledger);
    }
    expect(fn () => reserveAnswerEvaluation($this->budgetRoot, 'reset', str_repeat('a', 64), str_repeat('b', 64)))->toThrow(RuntimeException::class);
})->with(['truncated', 'deleted', 'replaced', 'symlinked']);

require_once __DIR__.'/../../scripts/answer-eval-evidence.php';
test('private evaluation evidence writes commit complete json and reject invalid or symlinked destinations', function () {
    $path = $this->budgetRoot.'/report.json';
    writeAnswerEvaluationEvidence($path, ['result' => 'INCOMPLETE']);
    expect(json_decode(file_get_contents($path), true))->toBe(['result' => 'INCOMPLETE']);
    writeAnswerEvaluationEvidence($path, ['result' => 'PASS']);
    expect(json_decode(file_get_contents($path), true))->toBe(['result' => 'PASS']);
    expect(fn () => writeAnswerEvaluationEvidence($this->budgetRoot.'/missing/report.json', ['result' => 'PASS']))->toThrow(RuntimeException::class);
    $link = $this->budgetRoot.'/link.json';
    symlink($path, $link);
    expect(fn () => writeAnswerEvaluationEvidence($link, ['result' => 'PASS']))->toThrow(RuntimeException::class);
});

test('approved model prices and caps are required before a new reservation can touch the ledger', function (string $field, mixed $wrong) {
    $policy = AnswerEvaluationPolicy::current();
    $policy[$field] = $wrong;
    expect(fn () => reserveAnswerEvaluation($this->budgetRoot, 'bad-policy', str_repeat('a', 64), str_repeat('b', 64), $policy))->toThrow(RuntimeException::class, 'policy mismatch');
    expect(glob($this->budgetRoot.'/*'))->toBe([]);
})->with([
    'wrong model' => ['model', 'gpt-5.4-mini-2026-03-17'],
    'underpriced input' => ['input_price_micro_usd_per_million', 750000],
    'underpriced output' => ['output_price_micro_usd_per_million', 4500000],
    'wrong input bound' => ['max_input_tokens', 16384],
    'lower production charge' => ['production_reservation_tokens', 8192],
    'stale total cap' => ['total_cap_micro_usd', 5000000],
    'above approved total cap' => ['total_cap_micro_usd', 6000001],
    'higher total cap' => ['total_cap_micro_usd', 7000000],
    'wrong cap type' => ['total_cap_micro_usd', '6000000'],
    'stale policy version' => ['version', 'answer-evaluation-policy-v4'],
    'nonzero temperature' => ['temperature', 1],
]);

test('new-model reservations append to an intact anchored legacy mini history', function () {
    $probe = json_encode(['case' => 'earlier-minimal-access-probe', 'reserved_micro_usd' => 1824, 'actual_input_tokens' => 36, 'actual_output_tokens' => 13, 'status' => 'completed'], JSON_THROW_ON_ERROR)."\n";
    $old = $probe.json_encode(['case' => 'legacy-mini-failed', 'basis_sha256' => str_repeat('a', 64), 'reference_sha256' => str_repeat('b', 64), 'reserved_micro_usd' => 14592, 'status' => 'reserved_before_call'], JSON_THROW_ON_ERROR)."\n";
    $ledger = $this->budgetRoot.'/live-budget-ledger.jsonl';
    file_put_contents($ledger, $old);
    clearstatcache(true, $ledger);
    $inode = stat($ledger)['ino'];
    file_put_contents($this->budgetRoot.'/live-budget-anchor.json', json_encode(['inode' => $inode, 'probe_sha256' => hash('sha256', $probe), 'length' => strlen($old), 'prefix_sha256' => hash('sha256', $old)], JSON_THROW_ON_ERROR));
    expect(reserveAnswerEvaluation($this->budgetRoot, 'new-model', str_repeat('c', 64), str_repeat('d', 64)))->toBe(44576);
    $bytes = file_get_contents($ledger);
    expect(substr($bytes, 0, strlen($old)))->toBe($old);
    clearstatcache(true, $ledger);
    expect(stat($ledger)['ino'])->toBe($inode);
    $lines = explode("\n", trim($bytes));
    $last = json_decode($lines[2], true, flags: JSON_THROW_ON_ERROR);
    expect($last['reserved_micro_usd'])->toBe(28160)->and($last['model'])->toBe('gpt-5.4-2026-03-05')
        ->and($last['policy_sha256'])->toBe(AnswerEvaluationPolicy::digest());
});

test('approved evaluation policy binds the five dollar aggregate ceiling', function () {
    $historical = json_decode(file_get_contents(__DIR__.'/../../resources/copilot/evaluation/policy-v4.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($historical['total_cap_micro_usd'])->toBe(5000000);
    expect(AnswerEvaluationPolicy::current()['case_reservation_micro_usd'])->toBe(28160);
});

test('continuation refuses missing history rather than initializing another ledger', function () {
    $baseline = ['entries' => 1, 'bytes' => 1, 'sha256' => str_repeat('a', 64), 'total_micro_usd' => 1824, 'expected_new_entries' => 0];
    expect(fn () => assertAnswerEvaluationContinuation($this->budgetRoot, $baseline))->toThrow(RuntimeException::class, 'Existing private');
    expect(fn () => reserveAnswerEvaluation($this->budgetRoot, 'new-root', str_repeat('b', 64), str_repeat('c', 64), null, $baseline))->toThrow(RuntimeException::class, 'Existing private');
    expect(glob($this->budgetRoot.'/*'))->toBe([]);
});

test('continuation uses the unchanged anchored prefix and exact next admission count', function () {
    reserveAnswerEvaluation($this->budgetRoot, 'historical', str_repeat('a', 64), str_repeat('b', 64));
    $file = $this->budgetRoot.'/live-budget-ledger.jsonl';
    $bytes = file_get_contents($file);
    $baseline = ['entries' => 2, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'total_micro_usd' => 29984, 'expected_new_entries' => 0];
    $baseline['files'] = evaluationBudgetFileIdentities($this->budgetRoot);
    assertAnswerEvaluationContinuation($this->budgetRoot, $baseline);
    expect(reserveAnswerEvaluation($this->budgetRoot, 'current1', str_repeat('c', 64), str_repeat('d', 64), null, $baseline))->toBe(58144);
    expect(fn () => reserveAnswerEvaluation($this->budgetRoot, 'duplicate-run', str_repeat('c', 64), str_repeat('e', 64), null, $baseline))->toThrow(RuntimeException::class, 'admission count');
    $baseline['expected_new_entries'] = 1;
    expect(reserveAnswerEvaluation($this->budgetRoot, 'current2', str_repeat('c', 64), str_repeat('f', 64), null, $baseline))->toBe(86304);
    expect(substr(file_get_contents($file), 0, strlen($bytes)))->toBe($bytes);
    $baseline['sha256'] = str_repeat('0', 64);
    expect(fn () => assertAnswerEvaluationContinuation($this->budgetRoot, $baseline))->toThrow(RuntimeException::class, 'prefix');
});

test('current evaluation policy binds the separately approved six dollar aggregate ceiling', function () {
    expect(AnswerEvaluationPolicy::current()['total_cap_micro_usd'])->toBe(6000000);
    expect(AnswerEvaluationPolicy::current()['case_reservation_micro_usd'])->toBe(28160);
});

function evaluationBudgetFileIdentities(string $root): array
{
    $result = [];
    foreach (['live-budget.lock', 'live-budget-ledger.jsonl', 'live-budget-anchor.json'] as $name) {
        clearstatcache(true, $root.'/'.$name);
        $stat = stat($root.'/'.$name);
        $result[$name] = ['device' => $stat['dev'], 'inode' => $stat['ino']];
    }

    return $result;
}

test('continuation refuses copied reanchored history replaced locks anchors and changed devices', function (string $fault) {
    reserveAnswerEvaluation($this->budgetRoot, 'historical', str_repeat('a', 64), str_repeat('b', 64));
    $ledger = $this->budgetRoot.'/live-budget-ledger.jsonl';
    $bytes = file_get_contents($ledger);
    $baseline = ['entries' => 2, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'total_micro_usd' => 29984, 'expected_new_entries' => 0, 'files' => evaluationBudgetFileIdentities($this->budgetRoot)];
    $anchor = $this->budgetRoot.'/live-budget-anchor.json';
    if ($fault === 'copy_reanchor') {
        rename($ledger, $ledger.'.old');
        file_put_contents($ledger, $bytes);
        chmod($ledger, 0600);
        clearstatcache(true, $ledger);
        $a = json_decode(file_get_contents($anchor), true, flags: JSON_THROW_ON_ERROR);
        $a['inode'] = stat($ledger)['ino'];
        file_put_contents($anchor, json_encode($a, JSON_THROW_ON_ERROR));
    } elseif ($fault === 'device') {
        $baseline['files']['live-budget-ledger.jsonl']['device']++;
    } elseif ($fault === 'missing_identity') {
        unset($baseline['files']);
    } else {
        $path = $this->budgetRoot.'/'.($fault === 'lock' ? 'live-budget.lock' : 'live-budget-anchor.json');
        $old = file_get_contents($path);
        rename($path, $path.'.old');
        file_put_contents($path, $old);
        chmod($path, 0600);
    }
    $before = file_get_contents($ledger);
    expect(fn () => assertAnswerEvaluationContinuation($this->budgetRoot, $baseline))->toThrow(RuntimeException::class, 'physical budget');
    expect(fn () => reserveAnswerEvaluation($this->budgetRoot, 'must_not_send', str_repeat('c', 64), str_repeat('d', 64), null, $baseline))->toThrow(RuntimeException::class, 'physical budget');
    expect(file_get_contents($ledger))->toBe($before);
})->with(['copy_reanchor', 'lock', 'anchor', 'device', 'missing_identity']);
