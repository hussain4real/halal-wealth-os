<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Intelligence\Answers\AnswerPlanner;
use App\Domain\Intelligence\UntrustedInput;
use App\Domain\Reporting\Copilot\Answers\AnswerPlan;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class OpenAiAnswerPlanner implements AnswerPlanner
{
    /** Redacted observation for an explicitly bound evaluation runner; never headers, input or financial results.
     * @var array<string,mixed> */
    public array $observation = [];

    private ?string $evaluationPayloadHash = null;

    private bool $evaluationPrepared = false;

    public function __construct(private readonly float $deadlineSeconds = 30.0, private readonly bool $evaluation = false)
    {
        if ($deadlineSeconds <= 0 || $deadlineSeconds > 30) {
            throw new RuntimeException('Invalid provider deadline.');
        }
    }

    public function available(): bool
    {
        return extension_loaded('curl') && config('copilot.answer_enabled') === true && is_string(config('copilot.openai_key')) && trim(config('copilot.openai_key')) !== '';
    }

    public function plan(UntrustedInput $input): AnswerPlan
    {
        $this->observation = ['transport' => 'openai-responses', 'http_attempts' => 0, 'handler_transfers' => 0, 'native_curl_stats' => false];
        if (! $this->available()) {
            throw new RuntimeException('Answer provider unavailable.');
        }
        $wire = $this->wire($input);
        $policy = AnswerEvaluationPolicy::current();
        $model = $policy['model'];
        $payloadHash = hash('sha256', $wire);
        if ($this->evaluation && $this->evaluationPayloadHash !== $payloadHash) {
            throw new RuntimeException('Evaluation requires the exact prepared request intent.');
        }
        $this->observation += ['policy_sha256' => AnswerEvaluationPolicy::digest(), 'payload_sha256' => $payloadHash,
            'wire_bytes' => strlen($wire), 'evaluation' => $this->evaluation, 'reasoning_effort' => $policy['reasoning_effort'],
            'store' => $policy['store'], 'temperature' => $policy['temperature'], 'max_input_tokens' => $this->evaluation ? $policy['max_input_tokens'] : $policy['production_reservation_tokens'] - $policy['max_output_tokens'],
            'max_output_tokens' => $policy['max_output_tokens']];
        $this->evaluationPayloadHash = null;
        try {
            $this->observation['http_attempts'] = 1;
            $response = Http::withToken(config('copilot.openai_key'))->acceptJson()->withBody($wire, 'application/json')->withHeaders(['Accept-Encoding' => 'identity'])->connectTimeout(min(5, $this->deadlineSeconds))->timeout($this->deadlineSeconds)
                ->withOptions(['allow_redirects' => false, 'stream' => false, 'decode_content' => false, 'version' => '1.1', 'expect' => false, '_curl_retries' => 2, 'curl' => [CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true],
                    'on_stats' => function (TransferStats $stats): void {
                        $this->observation['handler_transfers']++;
                        $raw = $stats->getHandlerStats();
                        $this->observation['native_curl_stats'] = isset($raw['primary_ip']) && is_string($raw['primary_ip']) && $raw['primary_ip'] !== '';
                    }, 'progress' => static function (float $total, float $downloaded): void {
                        if ($total > 65536 || $downloaded > 65536) {
                            throw new RuntimeException('Provider response exceeds byte ceiling.');
                        }
                    }])->post('https://api.openai.com/v1/responses');
            $this->observation['http_status'] = $response->status();
            $body = $response->toPsrResponse()->getBody();
            try {
                $raw = $body->getContents();
            } finally {
                $body->close();
            }
            if ($response->status() !== 200 || ! in_array(strtolower($response->header('Content-Encoding')), ['', 'identity'], true) || strlen($raw) > 65536) {
                throw new RuntimeException('Unverified provider response.');
            }
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['status'] ?? null) !== 'completed' || ($data['model'] ?? null) !== $model
                || ! is_string($data['id'] ?? null) || ! preg_match('/^resp_[A-Za-z0-9_-]{1,160}$/D', $data['id'])) {
                throw new RuntimeException('Unverified provider receipt.');
            }
            $usage = $data['usage'] ?? [];
            foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $key) {
                if (! is_int($usage[$key] ?? null) || $usage[$key] < 0 || $usage[$key] > config('copilot.tokens_per_request')) {
                    throw new RuntimeException('Unverified provider usage.');
                }
            }
            if ($usage['total_tokens'] !== $usage['input_tokens'] + $usage['output_tokens'] || $usage['output_tokens'] > $policy['max_output_tokens']
                || ($this->evaluation && $usage['input_tokens'] > $policy['max_input_tokens'])) {
                throw new RuntimeException('Unverified provider usage.');
            }
            $this->observation += ['response_id' => $data['id'], 'model' => $model, 'usage' => array_intersect_key($usage, array_flip(['input_tokens', 'output_tokens', 'total_tokens']))];
            $texts = [];
            foreach ($data['output'] ?? [] as $output) {
                if (($output['type'] ?? null) !== 'message') {
                    if (($output['type'] ?? null) !== 'reasoning') {
                        throw new RuntimeException('Unexpected provider tool.');
                    }

                    continue;
                }
                foreach ($output['content'] ?? [] as $content) {
                    if (($content['type'] ?? null) !== 'output_text' || ! is_string($content['text'] ?? null)) {
                        throw new RuntimeException('Unverified provider answer.');
                    }
                    $texts[] = $content['text'];
                }
            }
            if (count($texts) !== 1 || strlen($texts[0]) > config('copilot.provider_output_bytes')) {
                throw new RuntimeException('Unverified provider plan.');
            }
            $plan = AnswerPlan::parse(json_decode($texts[0], true, 8, JSON_THROW_ON_ERROR));
            $this->observation += ['request_id_sha256' => hash('sha256', $response->header('x-request-id')),
                'claim' => $plan->claim->value, 'mode' => $plan->mode, 'scope' => $plan->scope, 'output_sha256' => hash('sha256', $texts[0])];

            return $plan;
        } catch (Throwable) {
            // Do not retain/forward provider errors, request headers or submitted text.
            $this->observation['outcome'] = 'unavailable';
            throw new RuntimeException('Answer provider unavailable.');
        }
    }

    /** Redacted pre-handoff description of the exact body that plan() must send.
     * @return array{policy:array<string,mixed>,policy_sha256:string,payload_sha256:string,wire_bytes:int} */
    public function prepareEvaluation(UntrustedInput $input): array
    {
        if (! $this->evaluation || $this->evaluationPrepared) {
            throw new RuntimeException('Evaluation preparation requires a fresh evaluation planner.');
        }
        $wire = $this->wire($input);
        $this->evaluationPrepared = true;
        $payloadHash = hash('sha256', $wire);
        $this->evaluationPayloadHash = $payloadHash;

        return ['policy' => AnswerEvaluationPolicy::current(), 'policy_sha256' => AnswerEvaluationPolicy::digest(),
            'payload_sha256' => $payloadHash, 'wire_bytes' => strlen($wire)];
    }

    private function wire(UntrustedInput $input): string
    {
        $policy = AnswerEvaluationPolicy::current();
        if (config('copilot.answer_model') !== $policy['model'] || config('copilot.tokens_per_request') !== $policy['production_reservation_tokens']) {
            throw new RuntimeException('Answer policy reservation/model mismatch.');
        }
        $prompt = file_get_contents(resource_path('copilot/answer-plan-v2.txt'));
        $payload = ['model' => $policy['model'], 'store' => $policy['store'], 'reasoning' => ['effort' => $policy['reasoning_effort']], 'temperature' => $policy['temperature'], 'max_output_tokens' => $policy['max_output_tokens'],
            'instructions' => $prompt, 'input' => json_encode(['QUESTION' => $input->question, 'DOCUMENT' => $input->document], JSON_THROW_ON_ERROR),
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'financial_answer_plan_v1', 'strict' => true, 'schema' => AnswerPlan::schema()]]];
        $wire = json_encode($payload, JSON_THROW_ON_ERROR);
        $bytes = strlen($wire);
        if ($bytes + $policy['wire_overhead_tokens'] + $policy['max_output_tokens'] > $policy['production_reservation_tokens']) {
            throw new RuntimeException('Answer reservation is insufficient.');
        }
        if ($this->evaluation && $bytes + $policy['wire_overhead_tokens'] > $policy['max_input_tokens']) {
            throw new RuntimeException('Evaluation input ceiling exceeded before handoff.');
        }

        return $wire;
    }
}
