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

    public function __construct(private readonly float $deadlineSeconds = 30.0)
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
        $model = config('copilot.answer_model');
        $prompt = file_get_contents(resource_path('copilot/answer-plan-v1.txt'));
        $payload = ['model' => $model, 'store' => false, 'reasoning' => ['effort' => 'none'], 'max_output_tokens' => 512,
            'instructions' => $prompt, 'input' => json_encode(['QUESTION' => $input->question, 'DOCUMENT' => $input->document], JSON_THROW_ON_ERROR),
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'financial_answer_plan_v1', 'strict' => true, 'schema' => AnswerPlan::schema()]]];
        $bytes = strlen(json_encode($payload, JSON_THROW_ON_ERROR));
        if ($bytes + 1024 + 512 > config('copilot.tokens_per_request')) {
            throw new RuntimeException('Answer reservation is insufficient.');
        }
        try {
            $this->observation['http_attempts'] = 1;
            $response = Http::withToken(config('copilot.openai_key'))->acceptJson()->withHeaders(['Accept-Encoding' => 'identity'])->connectTimeout(min(5, $this->deadlineSeconds))->timeout($this->deadlineSeconds)
                ->withOptions(['allow_redirects' => false, 'stream' => false, 'decode_content' => false, 'version' => '1.1', 'expect' => false, '_curl_retries' => 2, 'curl' => [CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true],
                    'on_stats' => function (TransferStats $stats): void {
                        $this->observation['handler_transfers']++;
                        $raw = $stats->getHandlerStats();
                        $this->observation['native_curl_stats'] = isset($raw['primary_ip']) && is_string($raw['primary_ip']) && $raw['primary_ip'] !== '';
                    }, 'progress' => static function (float $total, float $downloaded): void {
                        if ($total > 65536 || $downloaded > 65536) {
                            throw new RuntimeException('Provider response exceeds byte ceiling.');
                        }
                    }])->post('https://api.openai.com/v1/responses', $payload);
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
            if ($usage['total_tokens'] !== $usage['input_tokens'] + $usage['output_tokens'] || $usage['output_tokens'] > 512) {
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
            $this->observation += ['response_id' => $data['id'], 'request_id_sha256' => hash('sha256', $response->header('x-request-id')),
                'model' => $model, 'usage' => array_intersect_key($usage, array_flip(['input_tokens', 'output_tokens', 'total_tokens'])),
                'claim' => $plan->claim->value, 'mode' => $plan->mode, 'scope' => $plan->scope, 'output_sha256' => hash('sha256', $texts[0])];

            return $plan;
        } catch (Throwable) {
            // Do not retain/forward provider errors, request headers or submitted text.
            $this->observation['outcome'] = 'unavailable';
            throw new RuntimeException('Answer provider unavailable.');
        }
    }
}
