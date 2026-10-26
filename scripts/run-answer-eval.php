<?php

declare(strict_types=1);

// Explicit local live-evaluation entrypoint; ordinary tests/CI never load a key.
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/answer-eval-budget.php';
require __DIR__.'/answer-eval-evidence.php';

use App\Domain\Integrations\AnswerEvaluationPolicy;
use App\Domain\Integrations\OpenAiAnswerPlanner;
use App\Domain\Intelligence\Answers\AnswerPlanner;
use App\Domain\Intelligence\CopilotBoundary;
use App\Domain\Intelligence\UntrustedInput;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AnswerFixtures;
use Tests\Support\AnswerOracle;
use Tests\Support\CopilotFixtures;
use Tests\Support\DisposablePostgres;

try {
    if (($argv[1] ?? '') !== '--actual-synthetic-openai' || count($argv) !== 6) {
        throw new RuntimeException('Explicit live-evaluation flag, private env, NEW evidence destination, existing budget root and reviewed continuation baseline required.');
    }
    $root = dirname(__DIR__);
    $budgetRoot = realpath($argv[4]);
    $baselineFile = realpath($argv[5]);
    if ($budgetRoot === false || $budgetRoot === $root || $baselineFile === false || is_link($argv[5]) || (fileperms($baselineFile) & 0777) !== 0600) {
        throw new RuntimeException('Explicit original budget root and private reviewed baseline required.');
    }
    $baseline = json_decode(file_get_contents($baselineFile), true, flags: JSON_THROW_ON_ERROR);
    $baseline['expected_new_entries'] = 0;
    assertAnswerEvaluationContinuation($budgetRoot, $baseline);
    $envFile = realpath($argv[2]);
    $destination = $argv[3];
    if (is_link($argv[2]) || $envFile === false || $envFile !== realpath($budgetRoot.'/.env.local') || (fileperms($envFile) & 0777) !== 0600 || file_exists($destination)) {
        throw new RuntimeException('Use the approved private env and a new private destination.');
    }
    $parent = realpath(dirname($destination));
    if ($parent === false || $parent !== realpath(dirname($root))) {
        throw new RuntimeException('Evidence must remain outside the checkout.');
    }
    if (! mkdir($destination, 0700) || realpath($destination) !== $parent.'/'.basename($destination)) {
        throw new RuntimeException('Private destination creation failed.');
    }
    $createdDestination = true;
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $connection = DB::connection();
    DisposablePostgres::assertConfiguration(app()->environment(), $connection->getDriverName(), $connection->getConfig(), env('HWO_DISPOSABLE_DATABASE'));
    $policy = AnswerEvaluationPolicy::current();
    $policyHash = AnswerEvaluationPolicy::digest();
    if (config('copilot.answer_model') !== $policy['model'] || config('copilot.tokens_per_request') !== $policy['production_reservation_tokens']) {
        throw new RuntimeException('Evaluation policy/config mismatch.');
    }
    $dataset = file_get_contents($root.'/resources/copilot/evaluation/questions-v1.json');
    $cases = json_decode($dataset, true, flags: JSON_THROW_ON_ERROR)['cases'];
    $basis = trim(shell_exec('node '.escapeshellarg($root.'/scripts/answer-eval-basis.mjs')) ?? '');
    if (! preg_match('/^[a-f0-9]{64}$/D', $basis) || count($cases) !== 40) {
        throw new RuntimeException('Invalid evaluation basis/inventory.');
    }
    $envBytes = file_get_contents($envFile);
    preg_match('/^OPENAI_API_KEY=[\"\']?([A-Za-z0-9_-]{20,})[\"\']?\s*$/m', $envBytes, $keyMatch);
    $key = $keyMatch[1] ?? null;
    if (! is_string($key) || trim($key) === '') {
        throw new RuntimeException('Approved key unavailable.');
    }
    config(['app.debug' => false, 'copilot.answer_enabled' => true, 'copilot.openai_key' => $key]);
    unset($envBytes, $keyMatch, $key);
    $report = ['version' => 'live-answer-evaluation-v4', 'started_at' => gmdate('Y-m-d\TH:i:s\Z'), 'result' => 'INCOMPLETE', 'synthetic_only' => true, 'transport' => 'actual-openai-responses', 'basis_sha256' => $basis,
        'dataset_sha256' => hash('sha256', $dataset), 'prompt_sha256' => hash_file('sha256', $root.'/resources/copilot/answer-plan-v2.txt'), 'model' => $policy['model'], 'temperature' => $policy['temperature'], 'store' => $policy['store'], 'max_output_tokens' => $policy['max_output_tokens'], 'max_input_tokens' => $policy['max_input_tokens'], 'policy' => $policy, 'policy_sha256' => $policyHash,
        'total_eval_cap_micro_usd' => $policy['total_cap_micro_usd'], 'cases' => [], 'limitation' => 'CI verifies this current-basis retained observed receipt; not a provider-signed attestation and not a CI live rerun. Measures bounded model claim/mode/scope selection and integrated deterministic facts, not unrestricted prose quality. Selected tool is the redacted server admission selection; missing context can prevent retrieval. No provider tools are offered. Handler transfers are observed; no provider-side exactly-once execution guarantee is claimed.'];
    $hashFinance = static function (): string {
        $rows = [];
        foreach (DB::select("SELECT table_name FROM information_schema.columns WHERE table_schema='public' AND column_name='owner_id' ORDER BY table_name") as $t) {
            if (in_array($t->table_name, ['copilot_controls', 'copilot_requests', 'operation_reservations', 'operation_metrics'], true)) {
                continue;
            }
            $v = DB::table($t->table_name)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($v, SORT_STRING);
            $rows[$t->table_name] = $v;
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    };
    foreach ($cases as $caseIndex => $case) {
        // Only exact versioned synthetic questions/documents can reach this wire.
        $caseHash = hash('sha256', json_encode($case, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $owner = User::factory()->create();
        $fixture = AnswerFixtures::seed($owner);
        Auth::login($owner);
        CopilotFixtures::allow($owner);
        $planner = new OpenAiAnswerPlanner(evaluation: true);
        $input = UntrustedInput::parse($case['question'], $case['document']);
        $intent = $planner->prepareEvaluation($input);
        app()->instance(AnswerPlanner::class, $planner);
        $before = $hashFinance();
        $reference = (string) Str::uuid();
        $baseline['expected_new_entries'] = $caseIndex;
        $totalReserved = reserveAnswerEvaluation($budgetRoot, $case['id'], $basis, hash('sha256', $reference), $policy, $baseline);
        writeAnswerEvaluationEvidence($destination.'/'.$case['id'].'-intent.json', ['case_id' => $case['id'], 'case_sha256' => $caseHash, 'basis_sha256' => $basis, 'reference_sha256' => hash('sha256', $reference), 'status' => 'reserved_before_handoff', 'request' => $intent, 'reserved_micro_usd' => $policy['case_reservation_micro_usd'], 'reserved_total_micro_usd' => $totalReserved, 'production_reservation_tokens' => $policy['production_reservation_tokens']]);
        $result = app(CopilotBoundary::class)->answer($reference, $input, AnswerFixtures::context($fixture, $case['missing'] ?? null), true);
        writeAnswerEvaluationEvidence($destination.'/'.$case['id'].'-answer.json', $result);
        $observation = $planner->observation;
        writeAnswerEvaluationEvidence($destination.'/'.$case['id'].'-provider.json', $observation);
        if ($result['status'] !== 'complete' || ($observation['http_status'] ?? null) !== 200 || ($observation['handler_transfers'] ?? null) !== 1 || ($observation['native_curl_stats'] ?? false) !== true || ($observation['scope'] ?? null) !== $case['scope'] || ($observation['policy_sha256'] ?? null) !== $policyHash || ($observation['payload_sha256'] ?? null) !== $intent['payload_sha256'] || ($observation['wire_bytes'] ?? null) !== $intent['wire_bytes'] || ($observation['evaluation'] ?? null) !== true || ($observation['temperature'] ?? null) !== $policy['temperature'] || ($observation['max_input_tokens'] ?? null) !== $policy['max_input_tokens'] || ($observation['max_output_tokens'] ?? null) !== $policy['max_output_tokens']) {
            throw new RuntimeException('Live case failed: '.$case['id'].' (redacted observation retained).');
        }
        $checks = AnswerOracle::check($owner, $case, $result['evidence'], $fixture);
        if ($before !== $hashFinance() || DB::table('copilot_requests')->where('owner_id', $owner->id)->count() !== 1 || DB::table('copilot_requests')->where('owner_id', $owner->id)->sum('reserved_tokens') !== 16384) {
            throw new RuntimeException('No-write or admission gate failed: '.$case['id']);
        }
        $selectedTool = DB::table('copilot_requests')->where('owner_id', $owner->id)->where('reference', $reference)->value('tool');
        $topic = explode('.', $case['claim'])[0];
        $expectedTool = match ($topic) {
            'cash' => 'cash_availability','emergency' => 'goals','nigeria','changes' => 'valuation_evidence','assessments' => 'shariah_history',default => null
        };
        if ($selectedTool !== $expectedTool) {
            throw new RuntimeException('Evaluation rejected: tool_selection');
        }
        $checks += ['tool_selection' => true, 'no_financial_writes' => true, 'once_only_charge' => true, 'budget_reserved_before_call' => true];
        $report['cases'][] = ['id' => $case['id'], 'case_sha256' => $caseHash, 'input_sha256' => hash('sha256', json_encode(['QUESTION' => $case['question'], 'DOCUMENT' => $case['document']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'observed_at' => gmdate('Y-m-d\TH:i:s\Z'), 'http_status' => $observation['http_status'], 'http_attempts' => $observation['http_attempts'], 'handler_transfers' => $observation['handler_transfers'], 'native_curl_stats' => $observation['native_curl_stats'], 'model' => $observation['model'], 'evaluation' => $observation['evaluation'], 'policy_sha256' => $policyHash, 'payload_sha256' => $observation['payload_sha256'], 'wire_bytes' => $observation['wire_bytes'], 'max_input_tokens' => $observation['max_input_tokens'], 'max_output_tokens' => $observation['max_output_tokens'], 'reasoning_effort' => $observation['reasoning_effort'], 'temperature' => $observation['temperature'], 'store' => $observation['store'], 'response_id_sha256' => hash('sha256', $observation['response_id']), 'usage' => $observation['usage'], 'claim' => $observation['claim'], 'mode' => $observation['mode'], 'scope' => $observation['scope'], 'selected_tool' => $selectedTool, 'checks' => $checks];
        $report['reserved_total_micro_usd'] = $totalReserved;
        writeAnswerEvaluationEvidence($destination.'/report.json', $report);
        Date::setTestNow();
        echo 'Observed synthetic live case '.$case['id']." PASS\n";
    }
    $report['totals'] = ['cases' => count($report['cases']), 'input_tokens' => array_sum(array_column(array_column($report['cases'], 'usage'), 'input_tokens')), 'output_tokens' => array_sum(array_column(array_column($report['cases'], 'usage'), 'output_tokens'))];
    $report['completed_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $report['result'] = 'PASS';
    writeAnswerEvaluationEvidence($destination.'/report.json', $report);
    echo "PASS actual synthetic live evaluation; private observed receipts retained.\n";
} catch (Throwable $e) {
    if (isset($app)) {
        Date::setTestNow();
    }
    // Only explicit safe gate messages; no provider exception/trace/config dump.
    $safe = str_starts_with($e->getMessage(), 'Live case failed:') || str_starts_with($e->getMessage(), 'Evaluation rejected:') ? $e->getMessage() : 'Live evaluation blocked; inspect local gate configuration. No success is claimed.';
    if (isset($createdDestination) && $createdDestination) {
        try {
            writeAnswerEvaluationEvidence($destination.'/failure.json', ['result' => 'FAILED', 'failed_at' => gmdate('Y-m-d\TH:i:s\Z'), 'basis_sha256' => $basis ?? null, 'case_id' => $case['id'] ?? null, 'category' => 'live_case_or_gate_failed', 'safe_message' => $safe]);
        } catch (Throwable) {/* The original failed write remains a blocking exit, never a PASS. */
        }
    }
    fwrite(STDERR, $safe."\n");
    exit(2);
}
