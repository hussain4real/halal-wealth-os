<?php

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
    expect(reserveAnswerEvaluation($this->budgetRoot, 'case1', str_repeat('a', 64), str_repeat('b', 64)))->toBe(16416);
    expect(reserveAnswerEvaluation($this->budgetRoot, 'case1-retry', str_repeat('c', 64), str_repeat('d', 64)))->toBe(31008);
    for ($i = 0; $i < 134; $i++) {
        reserveAnswerEvaluation($this->budgetRoot, 'case'.$i, str_repeat('a', 64), str_repeat('b', 64));
    }
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
