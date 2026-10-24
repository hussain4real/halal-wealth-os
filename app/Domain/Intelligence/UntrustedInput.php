<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use Illuminate\Validation\ValidationException;

final readonly class UntrustedInput
{
    private function __construct(public string $question, public string $document) {}

    public static function parse(mixed $question, mixed $document): self
    {
        if (! is_string($question) || ! is_string($document) || trim($question) === ''
            || ! mb_check_encoding($question.$document, 'UTF-8')
            || strlen($question) + strlen($document) > config('copilot.input_bytes')) {
            throw ValidationException::withMessages(['question' => 'Use a bounded text question and optional document.']);
        }

        return new self($question, $document);
    }
}
