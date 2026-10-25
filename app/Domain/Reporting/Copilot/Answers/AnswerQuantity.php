<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Copilot\Answers;

final class AnswerQuantity
{
    public static function supported(string $question, string $kind): bool
    {
        $head = '\A *(?:please +)?(?:what(?: +(?:is|are))?|what\'s|show(?: +me)?|give +me|tell +me) +';
        $modifiers = '(?:(?:the|my|our|your|this|current|recorded|selected|saved|actual|known|covered|total|nigeria(?:-tagged)?|ng|geographic|monetary|cash|investment|native|reporting-base|fx|settlement|emergency|goal|fund|backed|target|remaining|valuation|essential-expense|source) +){0,6}';
        $prefix = $head.$modifiers;
        // Only the requested measurement head carries a unit. Other clauses,
        // quoted documents and incidental quantity words do not choose a claim.
        $heads = [
            [$prefix.'(?:percentage|percent|ratio)\b|'.$prefix.'share +of\b', 'percent'],
            ['\A *(?:please +)?how +(?:many|long)\b|'.$prefix.'(?:count\b|number +of\b)', 'number'],
            [$prefix.'(?:value|amount|money|balance)\b', 'money'],
            [$prefix.'(?:completeness|partialness|coverage +status)\b', 'text'],
        ];
        foreach ($heads as [$pattern, $expected]) {
            if (preg_match('~(?:'.$pattern.')~i', $question) === 1) {
                return $kind === $expected;
            }
        }

        return true;
    }
}
