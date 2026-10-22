<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\ExactDecimal;
use App\Domain\Shared\Money\Money;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** The versioned interchange boundary. Financial scalars never pass through floats. */
final class CsvStatement
{
    public const FIELDS = ['date', 'type', 'amount', 'currency', 'description', 'provider_id', 'counterparty_account_id', 'operation_reference', 'asset_id', 'quantity', 'fee', 'tax', 'unit_price', 'match_id', 'direction', 'cash_amount', 'occurrence'];

    public const KINDS = ['income', 'expense', 'transfer_out', 'transfer_in', 'buy', 'sell', 'dividend', 'fee', 'match_transfer', 'match_investment'];

    /** @return array<string,mixed> */
    public function mapping(mixed $input): array
    {
        if (! is_array($input) || array_diff(array_keys($input), ['version', 'delimiter', 'date_format', 'decimal_separator', 'columns', 'type_map', 'currency_map', 'default_asset_id']) !== []
            || ($input['version'] ?? null) !== 1 || ! in_array($input['delimiter'] ?? null, [',', ';', "\t"], true)
            || ! in_array($input['date_format'] ?? null, ['Y-m-d', 'd/m/Y', 'm/d/Y'], true)
            || ! in_array($input['decimal_separator'] ?? null, ['.', ','], true)
            || ! is_array($input['columns'] ?? null) || array_diff(array_keys($input['columns']), self::FIELDS) !== []) {
            LedgerInput::fail('mapping', 'Choose a supported version1 mapping, delimiter and explicit date/decimal formats.');
        }
        $columns = [];
        foreach (self::FIELDS as $field) {
            $header = $input['columns'][$field] ?? '';
            if (! is_string($header) || mb_strlen($header) > 180) {
                LedgerInput::fail('mapping', 'Column names must be bounded text.');
            }
            $columns[$field] = $header;
        }
        foreach (['date', 'type', 'amount', 'description'] as $required) {
            if ($columns[$required] === '') {
                LedgerInput::fail('mapping', 'Map date, type, amount and description columns.');
            }
        }
        $maps = [];
        foreach (['type_map' => self::KINDS, 'currency_map' => array_column(Currency::cases(), 'value')] as $field => $allowed) {
            $entries = $input[$field] ?? [];
            if (! is_array($entries) || ! array_is_list($entries) || count($entries) > 40) {
                LedgerInput::fail('mapping', 'Use a bounded list of explicit value mappings.');
            }
            $seen = [];
            foreach ($entries as $entry) {
                if (! is_array($entry) || array_keys($entry) !== ['value', 'target'] || ! is_string($entry['value']) || $entry['value'] === '' || mb_strlen($entry['value']) > 180
                    || ! in_array($entry['target'], $allowed, true) || in_array($entry['value'], $seen, true)) {
                    LedgerInput::fail('mapping', 'Value mappings must have unique text values and supported targets.');
                }
                $seen[] = $entry['value'];
            }
            usort($entries, fn (array $a, array $b): int => strcmp($a['value'], $b['value']));
            $maps[$field] = $entries;
        }
        $asset = $input['default_asset_id'] ?? '';
        if (! is_string($asset) || ($asset !== '' && ! preg_match('/^[1-9][0-9]{0,18}$/D', $asset))) {
            LedgerInput::fail('mapping', 'Choose an explicit asset when using an asset default.');
        }

        return ['version' => 1, 'delimiter' => $input['delimiter'], 'date_format' => $input['date_format'], 'decimal_separator' => $input['decimal_separator'],
            'columns' => $columns, ...$maps, 'default_asset_id' => $asset];
    }

    /** @return array{headers:list<string>,rows:list<list<string>>} */
    public function read(string $bytes, string $delimiter): array
    {
        if ($bytes === '' || strlen($bytes) > 262144 || ! mb_check_encoding($bytes, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $bytes)) {
            LedgerInput::fail('file', 'Use a nonempty UTF8 CSV up to256KiB without binary/control data.');
        }
        $text = str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;
        $records = [];
        $row = [];
        $field = '';
        $state = 'start';
        $endField = function () use (&$row, &$field, &$state): void {
            if (strlen($field) > 4096 || count($row) >= 32) {
                LedgerInput::fail('file', 'CSV fields are limited to4KiB and records to32 columns.');
            }
            $row[] = $field;
            $field = '';
            $state = 'start';
        };
        $endRow = function () use (&$records, &$row, $endField): void {
            $endField();
            $records[] = $row;
            $row = [];
            if (count($records) > 1001) {
                LedgerInput::fail('file', 'A CSV may contain at most1000 data rows.');
            }
        };
        for ($i = 0, $size = strlen($text); $i < $size; $i++) {
            $char = $text[$i];
            if ($state === 'quoted') {
                if ($char === '"') {
                    $state = 'closed';
                } else {
                    $field .= $char;
                }
            } elseif ($state === 'closed' && $char === '"') {
                $field .= '"';
                $state = 'quoted';
            } elseif ($char === $delimiter) {
                $endField();
            } elseif ($char === "\n" || $char === "\r") {
                if ($char === "\r" && ($text[$i + 1] ?? '') === "\n") {
                    $i++;
                }
                $endRow();
            } elseif ($state === 'start' && $char === '"') {
                $state = 'quoted';
            } elseif ($state === 'closed' || $char === '"') {
                LedgerInput::fail('file', 'Malformed CSV quoting. Use doubled quotes inside quoted fields.');
            } else {
                $field .= $char;
                $state = 'plain';
            }
            if (strlen($field) > 4096) {
                LedgerInput::fail('file', 'CSV fields are limited to4KiB.');
            }
        }
        if ($state === 'quoted') {
            LedgerInput::fail('file', 'A quoted CSV field was not closed.');
        }
        if ($state !== 'start' || $row !== [] || $field !== '') {
            $endRow();
        }
        $headers = array_shift($records) ?? [];
        if ($records === [] || count($headers) < 4 || count(array_unique($headers)) !== count($headers)) {
            LedgerInput::fail('file', 'Use unique headers and at least one data row.');
        }
        foreach ($headers as $header) {
            if ($header === '' || mb_strlen($header) > 180 || preg_match('/[<>\r\n]/', $header)) {
                LedgerInput::fail('file', 'Headers must be unique bounded CSV labels, not markup.');
            }
        }

        return ['headers' => $headers, 'rows' => $records];
    }

    /** @param array<string,mixed> $mapping
     * @return list<array<string,mixed>> */
    public function rows(string $bytes, array $mapping, Currency $native, array $resolutions = []): array
    {
        if (count($resolutions) > 1000) {
            LedgerInput::fail('resolutions', 'Use at most1000 retained row resolutions.');
        }
        foreach ($resolutions as $number => $values) {
            if (! preg_match('/^[1-9][0-9]{0,3}$/D', (string) $number) || ! is_array($values)
                || array_diff(array_keys($values), ['type', 'provider_id', 'counterparty_account_id', 'operation_reference', 'asset_id', 'match_id', 'direction', 'occurrence']) !== []) {
                LedgerInput::fail('resolutions', 'Resolve only explicit type, references, direction or occurrence for a retained row.');
            }
            foreach ($values as $value) {
                if (! is_string($value) || mb_strlen($value) > 180) {
                    LedgerInput::fail('resolutions', 'Resolution values must be bounded text.');
                }
            }
        }
        $csv = $this->read($bytes, $mapping['delimiter']);
        foreach (array_keys($resolutions) as $number) {
            if ((int) $number > count($csv['rows'])) {
                LedgerInput::fail('resolutions', 'A resolution refers to a row outside this source.');
            }
        }
        foreach ($mapping['columns'] as $header) {
            if ($header !== '' && ! in_array($header, $csv['headers'], true)) {
                LedgerInput::fail('mapping', 'A mapped header is absent from this CSV.');
            }
        }
        $rows = [];
        foreach ($csv['rows'] as $index => $cells) {
            $raw = [];
            foreach ($mapping['columns'] as $field => $header) {
                $position = $header === '' ? false : array_search($header, $csv['headers'], true);
                $raw[$field] = $position === false ? '' : trim($cells[$position] ?? '');
            }
            $raw = [...$raw, ...($resolutions[(string) ($index + 1)] ?? [])];
            try {
                if (count($cells) !== count($csv['headers'])) {
                    LedgerInput::fail('row', 'The row does not have the same column count as its header.');
                }
                $date = DateTimeImmutable::createFromFormat('!'.$mapping['date_format'], $raw['date']);
                if ($date === false || $date->format($mapping['date_format']) !== $raw['date']) {
                    LedgerInput::fail('date', 'The date does not match the selected format.');
                }
                $kind = $this->mapped($raw['type'], $mapping['type_map']);
                if (! in_array($kind, self::KINDS, true)) {
                    LedgerInput::fail('type', 'Unsupported or ambiguous transaction type. Choose an explicit supported mapping.');
                }
                $code = $raw['currency'] === '' ? $native->value : $this->mapped($raw['currency'], $mapping['currency_map']);
                if ($code !== $native->value) {
                    LedgerInput::fail('currency', 'Currency must explicitly match the selected account. No conversion is inferred.');
                }
                $amount = $this->money($raw['amount'], $mapping, $native);
                if (bccomp($amount, '0', 0) <= 0) {
                    LedgerInput::fail('amount', 'Amount is positive magnitude or gross consideration; type/direction records its cash sign.');
                }
                $data = ['kind' => $kind, 'effective_date' => LedgerInput::date($date->format('Y-m-d')), 'currency' => $code,
                    'amount_minor' => $amount, 'amount' => Money::fromMinor($native, $amount)->amount(), 'description' => LedgerInput::text($raw['description'], 'description')];
                foreach (['provider_id', 'operation_reference', 'occurrence'] as $field) {
                    $data[$field] = $raw[$field] === '' ? '' : LedgerInput::text($raw[$field], $field);
                }
                foreach (['counterparty_account_id', 'match_id', 'asset_id'] as $field) {
                    $value = $raw[$field] ?: ($field === 'asset_id' ? $mapping['default_asset_id'] : '');
                    if ($value !== '' && ! preg_match('/^[1-9][0-9]{0,18}$/D', $value)) {
                        LedgerInput::fail($field, 'An explicit owned reference must be a positive identifier.');
                    }
                    $data[$field] = $value;
                }
                $data['direction'] = $raw['direction'];
                foreach (['fee', 'tax'] as $field) {
                    $data[$field.'_minor'] = $this->money($raw[$field] ?: '0', $mapping, $native);
                    if (bccomp($data[$field.'_minor'], '0', 0) < 0) {
                        LedgerInput::fail($field, 'Fees and tax cannot be negative.');
                    }
                }
                foreach (['quantity', 'unit_price'] as $field) {
                    $data[$field] = $raw[$field] === '' ? '' : $this->decimal($raw[$field], $mapping);
                }
                $data['cash_minor'] = $raw['cash_amount'] === '' ? null : $this->money($raw['cash_amount'], $mapping, $native);
                $rows[] = ['number' => (string) ($index + 1), 'cells' => $cells, 'data' => $data, 'errors' => []];
            } catch (ValidationException $error) {
                $rows[] = ['number' => (string) ($index + 1), 'cells' => $cells, 'data' => null, 'errors' => array_values(array_map(fn (array $messages): string => $messages[0], $error->errors()))];
            }
        }

        return $rows;
    }

    /** @param list<array{value:string,target:string}> $entries */
    private function mapped(string $value, array $entries): string
    {
        foreach ($entries as $entry) {
            if ($entry['value'] === $value) {
                return $entry['target'];
            }
        }

        return $value;
    }

    /** @param array<string,mixed> $mapping */
    private function money(string $value, array $mapping, Currency $currency): string
    {
        try {
            $literal = $mapping['decimal_separator'] === ',' ? str_replace(',', '.', $value) : $value;
            $this->decimal($value, $mapping);

            return Money::fromDecimal($currency, $literal)->minor;
        } catch (InvalidArgumentException) {
            LedgerInput::fail('amount', 'Amount exceeds the currency precision or exact native money range.');
        }
    }

    /** @param array<string,mixed> $mapping */
    private function decimal(string $value, array $mapping): string
    {
        if ($mapping['decimal_separator'] === ',') {
            if (str_contains($value, '.')) {
                LedgerInput::fail('decimal', 'Grouping separators are unsupported. Use the selected decimal convention only.');
            }
            $value = str_replace(',', '.', $value);
        }
        try {
            return ExactDecimal::fromString($value)->value;
        } catch (InvalidArgumentException) {
            LedgerInput::fail('decimal', 'Use an exact decimal string without grouping, exponent notation or excess precision.');
        }
    }
}
