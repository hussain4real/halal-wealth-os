<?php

use App\Domain\Reporting\Copilot\Answers\AnswerClaim;
use App\Domain\Reporting\Copilot\Answers\AnswerPeriod;

test('current emergency duration verbs remain months without granting another period', function () {
    foreach (['How many months does my emergency fund last at my recorded essential-expense rate?', 'How long will my reserve last?', 'How long can I last on my recorded fund?'] as $q) {
        expect(AnswerPeriod::supported($q, AnswerClaim::EmergencyCoverage, '2026-10-20T23:59:59Z'))->toBeTrue();
    }
    foreach (['How many days does my fund last?', 'How long did my reserve last?', 'How long will my reserve last next year?', 'How long will my fund last at last year rates?', 'How long will my fund last; what was cash last Christmas?'] as $q) {
        expect(AnswerPeriod::supported($q, AnswerClaim::EmergencyCoverage, '2026-10-20T23:59:59Z'))->toBeFalse();
    }
    expect(AnswerPeriod::supported('How long will my fund last?', AnswerClaim::CashAvailable, '2026-10-20T23:59:59Z'))->toBeFalse();
});

test('current required commitment due horizon is not future balance permission', function () {
    foreach (['How much is unfunded in required commitments before my next income?', 'What unpaid bills are due by the next income?', 'Show current required obligations until next income.'] as $q) {
        expect(AnswerPeriod::supported($q, AnswerClaim::CashCommitments, '2026-10-20T23:59:59Z'))->toBeTrue();
    }
    foreach (['What is my balance before next income?', 'What balance will I have after next income?', 'What required commitments will be unpaid before next income?', 'What is my available cash before next income with unpaid bills?', 'Show required commitments before next income if I pay extra.', 'Show unpaid commitments before next income and cash next month.', 'Show unpaid commitments before next income and after next income.', 'Show unpaid commitments before next income and before next income.', 'Show unpaid commitments before next payday.', 'Show unpaid commitments before next income during 2025.'] as $q) {
        expect(AnswerPeriod::supported($q, AnswerClaim::CashCommitments, '2026-10-20T23:59:59Z'))->toBeFalse();
    }
    expect(AnswerPeriod::supported('Show unpaid commitments before next income.', AnswerClaim::CashAvailable, '2026-10-20T23:59:59Z'))->toBeFalse();
});

test('unmasked relative periods explicit dates and selected intervals retain their source boundaries', function () {
    foreach (['cash yesterday', 'cash tomorrow', 'cash last month', 'cash previous period', 'cash next month', 'cash last Christmas', 'cash two days ago', 'historical cash', 'future cash', 'cash in 2025', 'cash on 2025-01-01'] as $q) {
        expect(AnswerPeriod::supported($q, AnswerClaim::CashAvailable, '2026-10-20T23:59:59Z'))->toBeFalse();
    }
    expect(AnswerPeriod::supported('How much remains toward my 2025 QAR target?', AnswerClaim::EmergencyRemaining, '2026-10-20T23:59:59Z'))->toBeTrue();
    expect(AnswerPeriod::supported('changes from 2026-10-19 to 2026-10-20', AnswerClaim::ChangesOverview, '2026-10-20T23:59:59Z', '2026-10-19T23:59:59Z'))->toBeTrue();
    expect(AnswerPeriod::supported('changes from 2026-10-18 to 2026-10-20', AnswerClaim::ChangesOverview, '2026-10-20T23:59:59Z', '2026-10-19T23:59:59Z'))->toBeFalse();
});

test('closed period grammar rejects date targets prefix contamination and arbitrary duration suffixes', function () {
    foreach (['How many months does my emergency fund last on Christmas?', 'How many months does my emergency fund last at retirement?', 'How many months does my emergency fund last on January 1?', 'At retirement, how long will my fund last?', 'How long will my fund at retirement last?', 'How long will my reserve last at twice my recorded essential-expense rate?', "How long will my reserve last?\nWhat is cash next month?", 'How long will my reserve last and what was cash last year?'] as $q) {
        expect(AnswerPeriod::supported($q, AnswerClaim::EmergencyCoverage, '2026-10-20T23:59:59Z'))->toBeFalse();
    }
});

test('closed period grammar rejects projected unpaid states and contaminated commitment objects', function () {
    foreach (['What required commitments are expected to remain unpaid before next income?', 'What required commitments are going to remain unpaid before next income?', 'What are my required commitments at retirement before next income?', 'Show current required obligations until next income and cash in 2025.', 'Show current required obligations until next income; show the same next month.'] as $q) {
        expect(AnswerPeriod::supported($q, AnswerClaim::CashCommitments, '2026-10-20T23:59:59Z'))->toBeFalse();
    }
});
