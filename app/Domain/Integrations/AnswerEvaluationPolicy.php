<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/** @phpstan-type Policy array{version:string,model:string,reasoning_effort:string,store:bool,max_input_tokens:int,max_output_tokens:int,wire_overhead_tokens:int,input_price_micro_usd_per_million:int,output_price_micro_usd_per_million:int,case_reservation_micro_usd:int,total_cap_micro_usd:int,production_reservation_tokens:int} */
final class AnswerEvaluationPolicy
{
    private const array APPROVED = [
        'version' => 'answer-evaluation-policy-v2', 'model' => 'gpt-5.4-2026-03-05',
        'reasoning_effort' => 'none', 'store' => false, 'max_input_tokens' => 8192,
        'max_output_tokens' => 512, 'wire_overhead_tokens' => 1024,
        'input_price_micro_usd_per_million' => 2500000, 'output_price_micro_usd_per_million' => 15000000,
        'case_reservation_micro_usd' => 28160, 'total_cap_micro_usd' => 2000000,
        'production_reservation_tokens' => 16384,
    ];

    /** @return Policy */
    public static function current(): array
    {
        $bytes = (new Filesystem)->get(dirname(__DIR__, 3).'/resources/copilot/evaluation/policy-v2.json');

        return self::validate(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return Policy */
    public static function validate(mixed $policy): array
    {
        if ($policy !== self::APPROVED) {
            throw new RuntimeException('Evaluation model/cap/pricing policy mismatch.');
        }

        return self::APPROVED;
    }

    public static function digest(): string
    {
        return hash('sha256', json_encode(self::current(), JSON_THROW_ON_ERROR));
    }
}
