<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Copilot\Answers;

use InvalidArgumentException;

final readonly class AnswerPlan
{
    private function __construct(public AnswerClaim $claim, public string $mode, public string $scope) {}

    public static function parse(mixed $input): self
    {
        if (! is_array($input)) {
            throw new InvalidArgumentException('Unverified answer plan.');
        }
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['claim', 'mode', 'scope'] || ! is_string($input['claim']) || ! is_string($input['mode']) || ! is_string($input['scope'])
            || ($claim = AnswerClaim::tryFrom($input['claim'])) === null
            || ! in_array($input['mode'], ['recorded', 'hypothetical'], true)
            || ! in_array($input['scope'], ['current', 'selected', 'unsupported'], true)) {
            throw new InvalidArgumentException('Unverified answer plan.');
        }

        return new self($claim, $input['mode'], $input['scope']);
    }

    /** @return array<string,mixed> */
    public static function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'claim' => ['type' => 'string', 'enum' => array_map(fn (AnswerClaim $c): string => $c->value, AnswerClaim::cases())],
            'mode' => ['type' => 'string', 'enum' => ['recorded', 'hypothetical']],
            'scope' => ['type' => 'string', 'enum' => ['current', 'selected', 'unsupported']],
        ], 'required' => ['claim', 'mode', 'scope'], 'additionalProperties' => false];
    }
}
