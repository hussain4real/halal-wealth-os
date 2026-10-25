<?php

use App\Domain\Reporting\Copilot\Answers\AnswerQuantity;

test('requested quantity heads distinguish scalar units without using incidental words or guessing unknown constructions', function () {
    $questions = [
        ['What Nigeria-tagged value is covered in my saved valuation?', 'money'],
        ['What is the current amount?', 'money'],
        ['Please show my recorded balance.', 'money'],
        ['What percentage of the covered value is Nigeria-tagged?', 'percent'],
        ['What share of the covered value is Nigeria-tagged?', 'percent'],
        ['What is the ratio of covered money?', 'percent'],
        ['How many valued components are covered?', 'number'],
        ['How long will my reserve last?', 'number'],
        ['What is my source count?', 'number'],
        ['What number of current sources was counted?', 'number'],
        ['What is valuation coverage status?', 'text'],
        ['What is the completeness of my valuation?', 'text'],
        ['What is the value with an incidental percentage mentioned?', 'money'],
        ['Show the amount, not the percentage or count.', 'money'],
        ["What is my share's monetary value?", null],
        ['What is the share value?', null],
        ['What is the Nigeria-tagged share in monetary value?', null],
        ['What share within monetary value is covered?', null],
        ['Given the percentage, what is the value?', null],
        ['What is not the percentage but the value?', null],
        ['Explain the word value in a document.', null],
    ];
    foreach ($questions as [$question, $expected]) {
        foreach (['money', 'percent', 'number', 'text', 'records'] as $kind) {
            expect(AnswerQuantity::supported($question, $kind))->toBe($expected === null || $kind === $expected);
        }
    }
});
