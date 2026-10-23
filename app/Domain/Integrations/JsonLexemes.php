<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use JsonException;

/** Strict bounded JSON retaining numeric lexemes instead of binary floats. */
final class JsonLexemes
{
    /** @return array<array-key,mixed> */
    public static function decode(string $body): array
    {
        if (strlen($body) > 65536) {
            throw new ProviderFailure('payload_size');
        }
        try {
            json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProviderFailure('malformed_json');
        }
        $pattern = <<<'RX'
~\G\s*("(?:[^"\\]|\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|true|false|null|[{}\[\]:,])~s
RX;
        $offset = 0;
        $tokens = [];
        while (preg_match($pattern, $body, $match, 0, $offset) === 1) {
            $tokens[] = $match[1];
            $offset += strlen($match[0]);
        }
        if (trim(substr($body, $offset)) !== '' || count($tokens) > 4096) {
            throw new ProviderFailure('payload_size');
        }
        $index = 0;
        $value = self::read($tokens, $index);
        if (! is_array($value) || $index !== count($tokens)) {
            throw new ProviderFailure('malformed_json');
        }

        return $value;
    }

    /** @param list<string> $tokens */
    private static function read(array $tokens, int &$index): mixed
    {
        $token = $tokens[$index++];
        if ($token === '{' || $token === '[') {
            $end = $token === '{' ? '}' : ']';
            $values = [];
            while ($tokens[$index] !== $end) {
                if ($values !== []) {
                    $index++; // Grammar was validated before tokenization.
                }
                if ($token === '{') {
                    $key = json_decode($tokens[$index++], true, flags: JSON_THROW_ON_ERROR);
                    if (array_key_exists($key, $values)) {
                        throw new ProviderFailure('duplicate_json_key');
                    }
                    $index++;
                    $values[$key] = self::read($tokens, $index);
                } else {
                    $values[] = self::read($tokens, $index);
                }
            }
            $index++;

            return $values;
        }
        if (str_starts_with($token, '"')) {
            return json_decode($token, true, flags: JSON_THROW_ON_ERROR);
        }

        return match ($token) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $token,
        };
    }
}
