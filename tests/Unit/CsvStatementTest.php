<?php

use App\Domain\Imports\CsvStatement;
use App\Domain\Imports\StatementCsv;
use App\Domain\Shared\Money\Currency;
use Illuminate\Validation\ValidationException;
use Tests\Support\StatementFixtures as S;
use Tests\TestCase;

uses(TestCase::class);

test('CSV mapping is versioned explicit reusable and canonical across value map ordering', function () {
    $csv = new CsvStatement;
    $mapping = $csv->mapping(S::mapping(['type_map' => [['value' => 'Salary', 'target' => 'income'], ['value' => 'Debit', 'target' => 'expense']], 'currency_map' => [['value' => 'Riyal', 'target' => 'QAR']]]));
    expect($mapping['version'])->toBe(1)->and($mapping['type_map'][0]['value'])->toBe('Debit');
    $rows = $csv->rows(S::bytes([['type' => 'Salary', 'currency' => 'Riyal']]), $mapping, Currency::QAR);
    expect($rows[0]['errors'])->toBe([])->and($rows[0]['data']['kind'])->toBe('income')->and($rows[0]['data']['amount_minor'])->toBe('1234');
    foreach ([['version' => 2], ['delimiter' => '|'], ['date_format' => 'guess'], ['decimal_separator' => 'guess'], ['columns' => ['owner_id' => 'Owner']], ['columns' => ['date' => '']], ['columns' => ['date' => str_repeat('a', 181)]], ['default_asset_id' => 'foreign'], ['type_map' => [['value' => 'x', 'target' => 'unknown']]], ['type_map' => [['value' => 'x', 'target' => 'income'], ['value' => 'x', 'target' => 'expense']]], ['type_map' => 'wrong'], ['unexpected' => 'field']] as $change) {
        expect(fn () => $csv->mapping(S::mapping($change)))->toThrow(ValidationException::class);
    }
});

test('strict CSV supports BOM escaped quotes multiline records and explicit separator', function () {
    $csv = new CsvStatement;
    $bytes = "\xEF\xBB\xBFdate;type;amount;description\r\n2025-12-31;income;12,340;\"Synthetic \"\"quoted\"\"\nline\"\r\n";
    $mapping = $csv->mapping(S::mapping(['delimiter' => ';', 'decimal_separator' => ',', 'columns' => ['date' => 'date', 'type' => 'type', 'amount' => 'amount', 'description' => 'description']]));
    $rows = $csv->rows($bytes, $mapping, Currency::KWD);
    expect($rows[0]['errors'])->toBe([])->and($rows[0]['data']['amount_minor'])->toBe('12340')->and($rows[0]['data']['description'])->toContain('"quoted"', "\nline");
    $noNewline = $csv->read("a,b,c,d\n1,2,3,4", ',');
    expect($noNewline['rows'])->toBe([['1', '2', '3', '4']]);
});

test('unsafe or malformed CSV containers fail before source retention', function (string $bytes) {
    expect(fn () => (new CsvStatement)->read($bytes, ','))->toThrow(ValidationException::class);
})->with([
    'empty' => [''], 'oversized' => [str_repeat('x', 262145)], 'binary' => ["a,b,c,d\n1,2,3,\x00"], 'invalid utf8' => ["a,b,c,d\n1,2,3,\xff"],
    'unclosed quote' => ["a,b,c,d\n1,2,3,\"unclosed"], 'unquoted quote' => ["a,b,c,d\n1,2,3,abc\"def"], 'trailing quoted junk' => ["a,b,c,d\n1,2,3,\"value\"junk"],
    'duplicate header' => ["a,b,c,a\n1,2,3,4"], 'empty header' => ["a,b,c,\n1,2,3,4"], 'markup header' => ["<html>,b,c,d\n1,2,3,4"], 'header only' => ["a,b,c,d\n"],
    'long header' => [str_repeat('a', 181).",b,c,d\n1,2,3,4"], 'long field' => ["a,b,c,d\n1,2,3,".str_repeat('a', 4097)],
    'too many columns' => [implode(',', range(1, 33))."\n".implode(',', range(1, 33))], 'too many rows' => ["a,b,c,d\n".str_repeat("1,2,3,4\n", 1001)],
]);

test('CSV invalid scalars remain row reasons with no guessed dates currency sign or precision', function (array $row) {
    $csv = new CsvStatement;
    $rows = $csv->rows(S::bytes([$row]), $csv->mapping(S::mapping()), Currency::QAR);
    expect($rows[0]['data'])->toBeNull()->and($rows[0]['errors'])->not->toBeEmpty();
})->with([
    'calendar' => [['date' => '2025-02-30']], 'format' => [['date' => '01/02/2025']], 'future' => [['date' => '9998-01-01']], 'type' => [['type' => 'IPO guessed']],
    'currency' => [['currency' => 'USD']], 'precision' => [['amount' => '1.001']], 'zero precision preserved' => [['amount' => '1.000']], 'overflow' => [['amount' => '92233720368547758.08']],
    'exponent' => [['amount' => '1e2']], 'grouping' => [['amount' => '1,000.00']], 'negative' => [['amount' => '-1']], 'zero' => [['amount' => '0']],
    'missing description' => [['description' => '']], 'reference' => [['match_id' => 'guess']], 'negative fee' => [['fee' => '-1']], 'negative tax' => [['tax' => '-1']],
    'unit precision' => [['quantity' => '1.1234567890123']], 'long source ID' => [['provider_id' => str_repeat('a', 181)]],
]);

test('CSV explicit date decimal currency and missing optional column policies remain exact', function () {
    $csv = new CsvStatement;
    foreach (['d/m/Y' => '31/12/2025', 'm/d/Y' => '12/31/2025'] as $format => $date) {
        $m = $csv->mapping(S::mapping(['date_format' => $format]));
        expect($csv->rows(S::bytes([['date' => $date, 'amount' => '90071992547409.93']]), $m, Currency::QAR)[0]['data']['amount_minor'])->toBe('9007199254740993');
    }
    $m = $csv->mapping(S::mapping(['columns' => ['date' => 'date', 'type' => 'type', 'amount' => 'amount', 'description' => 'description']]));
    expect($csv->rows("date,type,amount,description\n2025-12-31,income,123,Synthetic yen", $m, Currency::JPY)[0]['data']['amount_minor'])->toBe('123');
    $bad = $csv->rows("date,type,amount,description\n2025-12-31,income,123.0,Synthetic yen", $m, Currency::JPY);
    expect($bad[0]['errors'])->not->toBeEmpty();
    expect($csv->rows("date,type,amount,description\n2025-12-31,income,123", $m, Currency::JPY)[0]['errors'][0])->toContain('column count');
    expect(fn () => $csv->rows("other,type,amount,description\n2025-12-31,income,123,Synthetic", $m, Currency::JPY))->toThrow(ValidationException::class);
    foreach ([['0' => ['type' => 'income']], ['1' => ['amount' => '100']], ['1' => ['type' => []]], ['2' => ['type' => 'income']]] as $resolutions) {
        expect(fn () => $csv->rows(S::bytes([[]]), $csv->mapping(S::mapping()), Currency::QAR, $resolutions))->toThrow(ValidationException::class);
    }
    $comma = $csv->mapping(S::mapping(['decimal_separator' => ',']));
    expect($csv->rows(S::bytes([['amount' => '1.234,56']]), $comma, Currency::QAR)[0]['errors'][0])->toContain('Grouping');
});

test('spreadsheet CSV neutralizes formulas including whitespace control and unicode prefixes', function (string $value) {
    expect(StatementCsv::safe($value))->toBe("'".$value);
})->with(['=1+1', '+SUM(A1)', '-cmd', '@formula', '  =value', "\t=payload", "\r+payload", "\n@payload", "\u{00a0}=payload"]);

test('spreadsheet CSV preserves ordinary exact text and values', function () {
    foreach (['2025-12-31', '12345', 'Synthetic source', ''] as $value) {
        expect(StatementCsv::safe($value))->toBe($value);
    }
});

test('CSV canonical value map keys trim like source cells and retain explicit mapped targets', function () {
    $csv = new CsvStatement;
    $mapping = $csv->mapping(S::mapping(['type_map' => [['value' => ' Salary ', 'target' => 'income']], 'currency_map' => [['value' => "\tRiyal\t", 'target' => 'QAR']]]));
    expect($mapping['type_map'][0]['value'])->toBe('Salary')->and($mapping['currency_map'][0]['value'])->toBe('Riyal');
    $row = $csv->rows(S::bytes([['type' => ' Salary ', 'currency' => ' Riyal ']]), $mapping, Currency::QAR)[0];
    expect($row['errors'])->toBe([])->and($row['data']['kind'])->toBe('income')->and($row['data']['currency'])->toBe('QAR');
});

test('CSV canonical value maps reject labels that collide after trimming', function (string $field, string $first, string $second) {
    expect(fn () => (new CsvStatement)->mapping(S::mapping([$field => [['value' => 'Label', 'target' => $first], ['value' => ' Label ', 'target' => $second]]])))->toThrow(ValidationException::class);
})->with([['type_map', 'income', 'expense'], ['currency_map', 'QAR', 'USD']]);

test('CSV canonical value maps reject whitespace-only keys', function (string $field, string $target) {
    expect(fn () => (new CsvStatement)->mapping(S::mapping([$field => [['value' => " \t ", 'target' => $target]]])))->toThrow(ValidationException::class);
})->with([['type_map', 'income'], ['currency_map', 'QAR']]);

test('CSV value maps preserve Unicode boundary labels consistently with source cell trimming', function () {
    $csv = new CsvStatement;
    foreach (["\u{00A0}", "\u{2028}", "\u{2029}"] as $edge) {
        $label = $edge.'Salary'.$edge;
        $mapping = $csv->mapping(S::mapping(['type_map' => [['value' => $label, 'target' => 'income']]]));
        expect($mapping['type_map'][0]['value'])->toBe($label);
        $row = $csv->rows(S::bytes([['type' => $label]]), $mapping, Currency::QAR)[0];
        expect($row['errors'])->toBe([])->and($row['data']['kind'])->toBe('income');
    }
});
