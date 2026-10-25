<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Copilot\Answers;

final class AnswerPeriod
{
    public static function supported(string $question, AnswerTopic $topic, string $cutoff, ?string $start = null): bool
    {
        // Relative periods have no declared reporting calendar/interval in this lane.
        // In particular a current cash read cannot answer an earlier balance question.
        if (preg_match('/\b(yesterday|tomorrow|last|previous|next|ago|future|historical)\b/i', $question) === 1) {
            return false;
        }
        preg_match_all('/\b20\d{2}-\d{2}(?:-\d{2})?\b|(?<=in )20\d{2}\b|(?<=during )20\d{2}\b|(?<=year )20\d{2}\b/i', $question, $dates);
        $allowed = [$cutoff];
        if ($topic === AnswerTopic::Changes && $start !== null) {
            $allowed[] = $start;
        }
        foreach ($dates[0] as $date) {
            if (! array_any($allowed, fn (string $value): bool => str_starts_with($value, $date))) {
                return false;
            }
        }

        return true;
    }
}
