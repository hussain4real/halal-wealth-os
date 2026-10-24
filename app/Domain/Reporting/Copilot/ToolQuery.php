<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Copilot;

use App\Domain\Ledger\LedgerInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class ToolQuery
{
    /** @param array<string,string> $arguments */
    private function __construct(public Tool $tool, public array $arguments) {}

    /** A provider's proposal and a direct HTTP call cross exactly the same boundary.
     * @param array<string,mixed> $input */
    public static function parse(array $input): self
    {
        if (array_diff(array_keys($input), ['tool', 'arguments']) !== [] || ! is_string($input['tool'] ?? null)
            || ($tool = Tool::tryFrom($input['tool'])) === null || ! is_array($input['arguments'] ?? null)) {
            throw ValidationException::withMessages(['tool' => 'Choose one registered read-only tool with structured arguments.']);
        }
        $args = $input['arguments'];
        if (array_diff(array_keys($args), array_keys($tool->properties())) !== []) {
            throw ValidationException::withMessages(['arguments' => 'Additional tool arguments are forbidden.']);
        }
        $rules = [];
        foreach ($tool->properties() as $key => $schema) {
            $rules[$key] = [in_array($key, $tool->required(), true) ? 'required' : 'sometimes', 'string'];
            if (isset($schema['pattern'])) {
                $rules[$key][] = 'regex:/'.$schema['pattern'].'/D';
            }
            if ($key === 'month') {
                $rules[$key][] = 'date_format:Y-m';
            }
        }
        Validator::make($args, $rules)->validate();
        if (isset($args['cutoff'])) {
            $args['cutoff'] = LedgerInput::instant($args['cutoff'], 'cutoff');
            if ($args['cutoff'] > now('UTC')->format('Y-m-d\TH:i:s\Z')) {
                throw ValidationException::withMessages(['cutoff' => 'Future financial evidence is unavailable.']);
            }
        }

        return new self($tool, $args);
    }
}
