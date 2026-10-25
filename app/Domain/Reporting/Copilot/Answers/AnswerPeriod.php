<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Copilot\Answers;

final class AnswerPeriod
{
    public static function supported(string $question, AnswerClaim $claim, string $cutoff, ?string $start = null): bool
    {
        $topic = $claim->topic();
        $periodQuestion = $question;
        if ($claim === AnswerClaim::EmergencyCoverage && self::recordedDurationRole($question)) {
            $periodQuestion = preg_replace('/\blast\b/i', 'duration', $periodQuestion, 1) ?? $periodQuestion;
        } elseif ($claim === AnswerClaim::CashCommitments && self::currentCommitmentRole($question)) {
            $periodQuestion = preg_replace('/\bnext\b/i', 'configured', $periodQuestion, 1) ?? $periodQuestion;
        }
        // Unmatched language keeps the original fail-closed relative-token policy.
        if (preg_match('/\b(yesterday|tomorrow|last|previous|next|ago|future|historical)\b/i', $periodQuestion) === 1) {
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

    private static function recordedDurationRole(string $question): bool
    {
        $question = preg_replace('/ +/', ' ', trim($question, ' ')) ?? $question;
        $determiner = '(?:(?:my|the|this|our|your) )?';
        $subject = $determiner.'(?:(?:current|recorded|backed) )?(?:(?:emergency|cash) )?(?:funds?|reserves?|money|savings)';
        $rate = '(?: (?:at|with|using|given) '.$determiner.'(?:recorded|current) essential(?:-expense| expense| expenses| spending)? (?:rate|run rate))?';
        $fundDuration = 'how (?:long|many months) (?:does|can|could|will|would) '.$subject.' last'.$rate;
        $personalDuration = 'how long (?:can|could|will|would) i last on '.$subject.$rate;

        return preg_match('/\A(?:'.$fundDuration.'|'.$personalDuration.')[?.]?\z/i', $question) === 1;
    }

    private static function currentCommitmentRole(string $question): bool
    {
        $question = preg_replace('/ +/', ' ', trim($question, ' ')) ?? $question;
        $determiner = '(?:(?:my|the|this|our|your) )?';
        $qualifier = '(?:(?:current|recorded) )?';
        $nouns = '(?:commitments|obligations|bills)';
        $object = $determiner.$qualifier.'(?:(?:required|unpaid|unfunded) ){0,3}'.$nouns;
        $currentObject = $determiner.$qualifier.'(?:required|unpaid|unfunded)(?: (?:required|unpaid|unfunded)){0,2} '.$nouns;
        $horizon = '(?:before|until|by|through) '.$determiner.'next income';
        $amount = 'how much is (?:currently |now )?(?:unfunded|unpaid) (?:in|for) '.$object;
        $due = 'what '.$currentObject.' are (?:due|unpaid)';
        $present = 'what are '.$currentObject;
        $show = 'show '.$currentObject;

        return preg_match('/\A(?:'.$amount.'|'.$due.'|'.$present.'|'.$show.') '.$horizon.'[?.]?\z/i', $question) === 1;
    }
}
