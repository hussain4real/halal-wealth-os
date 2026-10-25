<?php

use App\Domain\Integrations\OpenAiAnswerPlanner;
use App\Domain\Intelligence\UntrustedInput;
use App\Domain\Reporting\Copilot\Answers\AnswerClaim;
use App\Domain\Reporting\Copilot\Answers\AnswerContext;
use App\Domain\Reporting\Copilot\Answers\AnswerPlan;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function answerProviderReceipt(): array
{
    return ['id' => 'resp_synthetic_not_live', 'status' => 'completed', 'model' => 'gpt-5.4-mini-2026-03-17', 'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120],
        'output' => [['type' => 'reasoning'], ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"claim":"cash.available","mode":"recorded","scope":"current"}']]]]];
}

beforeEach(function () {
    config(['copilot.answer_enabled' => true, 'copilot.openai_key' => 'synthetic-unit-test-key']);
    Http::preventStrayRequests();
});

test('closed answer plan admits every enum claim and rejects write number owner or url fields', function () {
    foreach (AnswerClaim::cases() as $claim) {
        $tool = match (explode('.', $claim->value)[0]) {
            'cash' => 'cash_availability','emergency' => 'goals','nigeria','changes' => 'valuation_evidence','assessments' => 'shariah_history',default => null
        };
        expect($claim->tool()?->value)->toBe($tool);
        $p = AnswerPlan::parse(['claim' => $claim->value, 'mode' => 'recorded', 'scope' => $claim->topic()->scope()]);
        expect($p->claim)->toBe($claim)->and($claim->field())->toBeString();
    }
    expect(AnswerPlan::schema()['additionalProperties'])->toBeFalse();
    foreach ([null, [], ['claim' => 'buy', 'mode' => 'recorded', 'scope' => 'current'], ['claim' => 'cash.available', 'mode' => 'execute', 'scope' => 'current'], ['claim' => 'cash.available', 'mode' => 'recorded', 'scope' => 'owner999'], ['claim' => 'cash.available', 'mode' => 'recorded', 'scope' => 'current', 'amount' => '999']] as $value) {
        expect(fn () => AnswerPlan::parse($value))->toThrow(Exception::class);
    }
});

test('valid strict response selects a claim while outgoing payload excludes trusted record context and financial values', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(answerProviderReceipt(), 200, ['x-request-id' => 'synthetic-request'])]);
    $wire = new OpenAiAnswerPlanner;
    $plan = $wire->plan(UntrustedInput::parse('available cash', 'UNTRUSTED synthetic document'));
    expect($plan->claim)->toBe(AnswerClaim::CashAvailable)->and($wire->observation['usage']['total_tokens'])->toBe(120)->and($wire->observation['http_attempts'])->toBe(1);
    Http::assertSent(function ($request) {
        expect($request['store'])->toBeFalse()->and($request['model'])->toBe('gpt-5.4-mini-2026-03-17')->and($request['text']['format']['strict'])->toBeTrue();
        expect(array_keys(json_decode($request['input'], true)))->toBe(['QUESTION', 'DOCUMENT']);
        expect($request->data())->not->toHaveKeys(['tools', 'owner_id', 'context', 'facts', 'previous_response_id']);

        return true;
    });
    expect(json_encode($wire->observation))->not->toContain('UNTRUSTED', 'available cash', 'synthetic-unit-test-key');
});

test('disabled missing key insufficient reservation and transport errors fail without raw disclosure', function () {
    $wire = new OpenAiAnswerPlanner;
    config(['copilot.answer_enabled' => false]);
    expect($wire->available())->toBeFalse();
    expect(fn () => $wire->plan(UntrustedInput::parse('q', '')))->toThrow(RuntimeException::class, 'Answer provider unavailable.');
    config(['copilot.answer_enabled' => true, 'copilot.openai_key' => null]);
    expect($wire->available())->toBeFalse();
    config(['copilot.openai_key' => 'synthetic-unit-test-key', 'copilot.tokens_per_request' => 1]);
    expect(fn () => $wire->plan(UntrustedInput::parse('q', '')))->toThrow(RuntimeException::class, 'reservation');
    config(['copilot.tokens_per_request' => 16384]);
    Http::fake(fn () => throw new RuntimeException('SYNTHETIC-RAW-PROVIDER-PRIVATE-ERROR'));
    expect(fn () => $wire->plan(UntrustedInput::parse('q', '')))->toThrow(RuntimeException::class, 'Answer provider unavailable.');
    expect(json_encode($wire->observation))->not->toContain('PRIVATE');
});

$invalid = [
    'http500' => [[], 500], 'oversize' => [str_repeat('x', 65537), 200], 'malformed' => ['bad JSON', 200], 'null' => ['null', 200],
];
foreach (['id' => 'wrong', 'status' => 'incomplete', 'model' => 'another-model', 'usage' => [], 'output' => []] as $key => $v) {
    $r = answerProviderReceipt();
    $r[$key] = $v;
    $invalid['bad-'.$key] = [$r, 200];
}
foreach (['input_tokens' => -1, 'output_tokens' => 513, 'total_tokens' => 121] as $key => $v) {
    $r = answerProviderReceipt();
    $r['usage'][$key] = $v;
    $invalid['bad-'.$key] = [$r, 200];
}
foreach ([['type' => 'function_call'], ['type' => 'message', 'content' => [['type' => 'refusal']]], ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => str_repeat('x', 2049)]]], ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"claim":"buy"}']]]] as $i => $output) {
    $r = answerProviderReceipt();
    $r['output'] = [$output];
    $invalid['bad-output-'.$i] = [$r, 200];
}
test('unverified response schema usage tool calls and unsafe plans cannot become an answer', function ($receipt, int $status) {
    Http::fake(['api.openai.com/v1/responses' => Http::response($receipt, $status)]);
    $wire = new OpenAiAnswerPlanner;
    expect(fn () => $wire->plan(UntrustedInput::parse('synthetic q', '')))->toThrow(RuntimeException::class, 'Answer provider unavailable.');
    expect($wire->observation['outcome'])->toBe('unavailable');
    Http::assertSentCount(1);
})->with($invalid);

test('answer context rejects owner sql arbitrary keys invalid ids dates and future cutoffs', function () {
    $base = ['month' => now()->format('Y-m'), 'cutoff' => now('UTC')->format('Y-m-d\TH:i:s\Z')];
    foreach ([null, 'bad', [...$base, 'owner_id' => '1'], [...$base, 'goal_id' => '0'], [...$base, 'asset_id' => 1], [...$base, 'month' => '2026-99'], [...$base, 'cutoff' => '2999-01-01T00:00:00Z']] as $v) {
        expect(fn () => AnswerContext::parse($v))->toThrow(Exception::class);
    }
    expect(AnswerContext::parse($base)->goal)->toBeNull();
});

test('provider deadline covers delayed response bodies and complete chunked receipts within the byte ceiling', function () {
    // This uses a real owned loopback HTTP connection, not Http::fake. The fixed
    // production URL is rewritten only in this test's freshly bound factory.
    Http::swap(new Factory);
    $process = proc_open(['node', base_path('tests/Support/answer-wire.mjs')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Owned test wire failed to start.');
    }
    try {
        stream_set_timeout($pipes[1], 5);
        $port = trim(fgets($pipes[1]) ?: '');
        expect(ctype_digit($port))->toBeTrue();
        foreach (['/chunked', '/slow', '/oversize', '/compressed', '/lost'] as $path) {
            Http::swap(new Factory);
            Http::globalRequestMiddleware(fn ($request) => $request->withUri(new Uri('http://127.0.0.1:'.$port.$path)));
            $wire = new OpenAiAnswerPlanner(0.2);
            $at = microtime(true);
            if ($path === '/chunked') {
                expect($wire->plan(UntrustedInput::parse('cash', ''))->claim)->toBe(AnswerClaim::CashAvailable);
            } else {
                expect(fn () => $wire->plan(UntrustedInput::parse('cash', '')))->toThrow(RuntimeException::class, 'Answer provider unavailable.');
            }
            expect(microtime(true) - $at)->toBeLessThan(1.0)->and($wire->observation['http_attempts'])->toBe(1)->and($wire->observation['handler_transfers'])->toBe(1);
            if ($path === '/chunked') {
                expect($wire->observation['native_curl_stats'])->toBeTrue();
            }
        }
        Http::swap(new Factory);
        $seen = Http::get('http://127.0.0.1:'.$port.'/counts')->json();
        foreach (['/chunked', '/slow', '/oversize', '/compressed', '/lost'] as $path) {
            expect($seen['counts'][$path])->toBe(1);
        }
        expect(array_unique($seen['protocols']))->toBe(['1.1']);
        foreach ([0, -1, 31] as $invalidDeadline) {
            expect(fn () => new OpenAiAnswerPlanner($invalidDeadline))->toThrow(RuntimeException::class, 'Invalid provider deadline.');
        }
    } finally {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
    }
});
