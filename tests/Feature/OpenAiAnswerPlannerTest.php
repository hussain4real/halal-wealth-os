<?php

use App\Domain\Integrations\AnswerEvaluationPolicy;
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
    return ['id' => 'resp_synthetic_not_live', 'status' => 'completed', 'model' => 'gpt-5.4-2026-03-05', 'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120],
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
    $meanings = [
        'cash.overview' => 'All current cash measures, money.',
        'cash.available' => 'Deployable money: max(signed raw availability, zero).',
        'cash.raw' => 'Signed money after protected cash, unfunded requirements and buffer.',
        'cash.buffer' => 'Configured minimum buffer money.',
        'cash.shortfall' => 'Money deficit when raw availability is negative; not unfunded commitments.',
        'cash.commitments' => 'Next-income-horizon required unpaid money minus explicit backed overlap.',
        'emergency.overview' => 'All measures for the selected emergency goal.',
        'emergency.target' => 'Selected goal target money.',
        'emergency.backed' => 'Actual goal backing money; not a percentage.',
        'emergency.remaining' => 'Target money still unbacked.',
        'emergency.progress' => 'Backed/target percentage; not a money amount.',
        'emergency.coverage' => 'Months of essential expenses covered; may be unavailable.',
        'nigeria.overview' => 'All selected saved geographic Nigeria exposure measures.',
        'nigeria.amount' => 'Money explicitly tagged Nigeria/NG; not NGN currency exposure.',
        'nigeria.share' => 'Nigeria percentage of the known covered denominator.',
        'nigeria.denominator' => 'Known covered denominator money for geographic allocation.',
        'nigeria.coverage' => 'Valuation completeness/partialness, not a share or money amount.',
        'nigeria.components' => 'Count of known Nigeria-tagged components.',
        'changes.overview' => 'All investment changes in the selected saved interval.',
        'changes.amount' => 'Saved interval value change money; not automatically return.',
        'changes.return' => 'Supported no-external-flow value return percentage.',
        'changes.flows' => 'External-or-unsupported movement evidence; not necessarily proven external flows.',
        'changes.native' => 'Supported native-currency monetary value movement.',
        'changes.fx' => 'Reporting-base money due to FX and settlement effects.',
        'assessments.overview' => 'Eligible current assessment records, attributed by provenance.',
        'assessments.classification' => 'Deterministic source-claim summary; may be pending_review for conflict or no claims.',
        'assessments.review' => 'Recorded review-due dates of eligible current records.',
        'assessments.provider' => 'Recorded provider labels; provenance is retained.',
        'assessments.methodology' => 'Recorded methodology versions; provenance is retained.',
        'assessments.count' => 'Count of current source_claim records only.',
        'refuse' => 'Prohibited data/actions/claims; no tool facts.',
        'unsupported' => 'Outside one supported topic/period; no tool facts.',
    ];
    $schema = AnswerPlan::schema();
    expect(array_column(AnswerClaim::cases(), 'value'))->toBe(array_keys($meanings))
        ->and($schema['properties']['claim']['enum'])->toBe(array_keys($meanings))
        ->and($schema['required'])->toBe(['claim', 'mode', 'scope'])
        ->and(array_keys($schema['properties']))->toBe(['claim', 'mode', 'scope'])
        ->and($schema['additionalProperties'])->toBeFalse();
    foreach (AnswerClaim::cases() as $claim) {
        expect($claim->meaning())->toBe($meanings[$claim->value]);
    }
    expect($schema['properties']['claim']['description'])->toBe('Choose the requested metric by meaning and units, not word similarity. '.implode('; ', array_map(fn ($key) => $key.'='.$meanings[$key], array_keys($meanings))));
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
        expect($request['store'])->toBeFalse()->and($request['model'])->toBe('gpt-5.4-2026-03-05')->and($request['text']['format']['strict'])->toBeTrue()
            ->and($request->data()['temperature'] ?? null)->toBe(0);
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

test('evaluation intent pins the exact serialized request once while production reservation remains unchanged', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response(answerProviderReceipt(), 200)]);
    $input = UntrustedInput::parse('What percentage is backed?', 'Synthetic only');
    $planner = new OpenAiAnswerPlanner(evaluation: true);
    $intent = $planner->prepareEvaluation($input);
    expect($intent['policy']['max_input_tokens'])->toBe(8192)
        ->and(config('copilot.tokens_per_request'))->toBe(16384)
        ->and($intent['wire_bytes'] + 1024)->toBeLessThanOrEqual(8192);
    $planner->plan($input);
    Http::assertSent(function ($request) use ($intent) {
        expect($request['text']['format']['schema'])->toBe(AnswerPlan::schema());

        return hash('sha256', $request->body()) === $intent['payload_sha256'] && strlen($request->body()) === $intent['wire_bytes'];
    });
    expect($planner->observation['policy_sha256'])->toBe(AnswerEvaluationPolicy::digest())
        ->and($planner->observation['evaluation'])->toBeTrue()
        ->and($planner->observation['max_input_tokens'])->toBe(8192);
    expect(fn () => $planner->plan($input))->toThrow(RuntimeException::class, 'prepared request');
    expect(fn () => $planner->prepareEvaluation($input))->toThrow(RuntimeException::class, 'fresh evaluation');
    Http::assertSentCount(1);
});

test('evaluation guards reject missing changed or oversized intents and mismatched model before any request', function () {
    Http::fake();
    $input = UntrustedInput::parse('cash', '');
    expect(fn () => (new OpenAiAnswerPlanner(evaluation: true))->plan($input))->toThrow(RuntimeException::class, 'prepared request');
    expect(fn () => (new OpenAiAnswerPlanner)->prepareEvaluation($input))->toThrow(RuntimeException::class, 'fresh evaluation');
    $planner = new OpenAiAnswerPlanner(evaluation: true);
    $planner->prepareEvaluation($input);
    expect(fn () => $planner->plan(UntrustedInput::parse('different question', '')))->toThrow(RuntimeException::class, 'prepared request');
    // Control characters expand on the actual JSON wire; the ordinary 2KiB input bound still holds.
    expect(fn () => (new OpenAiAnswerPlanner(evaluation: true))->prepareEvaluation(UntrustedInput::parse('cash', str_repeat("\x01", 1000))))->toThrow(RuntimeException::class, 'input ceiling');
    config(['copilot.answer_model' => 'gpt-5.4-mini-2026-03-17']);
    expect(fn () => (new OpenAiAnswerPlanner(evaluation: true))->prepareEvaluation($input))->toThrow(RuntimeException::class, 'model mismatch');
    Http::assertNothingSent();
});

test('evaluation rejects observed input usage over its tighter limit without another transfer', function () {
    $receipt = answerProviderReceipt();
    $receipt['usage'] = ['input_tokens' => 8193, 'output_tokens' => 20, 'total_tokens' => 8213];
    Http::fake(['api.openai.com/v1/responses' => Http::response($receipt, 200)]);
    $input = UntrustedInput::parse('cash', '');
    $planner = new OpenAiAnswerPlanner(evaluation: true);
    $planner->prepareEvaluation($input);
    expect(fn () => $planner->plan($input))->toThrow(RuntimeException::class, 'unavailable');
    expect($planner->observation['http_attempts'])->toBe(1)->and($planner->observation['outcome'])->toBe('unavailable');
    Http::assertSentCount(1);
});

test('all fixed evaluation inputs fit the actual semantic schema wire without provider access', function () {
    Http::fake();
    config(['copilot.openai_key' => null]);
    $cases = json_decode(file_get_contents(base_path('resources/copilot/evaluation/questions-v1.json')), true, flags: JSON_THROW_ON_ERROR)['cases'];
    expect($cases)->toHaveCount(40);
    foreach ($cases as $case) {
        $intent = (new OpenAiAnswerPlanner(evaluation: true))->prepareEvaluation(UntrustedInput::parse($case['question'], $case['document']));
        expect($intent['wire_bytes'] + 1024)->toBeLessThanOrEqual(8192)
            ->and($intent['policy']['max_output_tokens'])->toBe(512)
            ->and($intent['policy_sha256'])->toBe(AnswerEvaluationPolicy::digest());
    }
    Http::assertNothingSent();
});
