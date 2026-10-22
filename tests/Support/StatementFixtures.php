<?php

namespace Tests\Support;

use App\Domain\Imports\CsvStatement;
use App\Domain\Imports\StatementImports;
use App\Models\Account;
use App\Models\User;
use stdClass;

final class StatementFixtures
{
    public static function mapping(array $changes = []): array
    {
        return [...['version' => 1, 'delimiter' => ',', 'date_format' => 'Y-m-d', 'decimal_separator' => '.',
            'columns' => array_combine(CsvStatement::FIELDS, CsvStatement::FIELDS), 'type_map' => [], 'currency_map' => [], 'default_asset_id' => ''], ...$changes];
    }

    public static function bytes(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, CsvStatement::FIELDS, ',', '"', '', "\r\n");
        foreach ($rows as $row) {
            $data = [...['date' => now('UTC')->toDateString(), 'type' => 'income', 'amount' => '12.34', 'currency' => 'QAR', 'description' => 'Synthetic statement entry'], ...$row];
            fputcsv($stream, array_map(fn (string $field): string => $data[$field] ?? '', CsvStatement::FIELDS), ',', '"', '', "\r\n");
        }
        rewind($stream);
        try {
            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    public static function preview(User $owner, Account $account, array $rows, string $namespace = 'Synthetic source'): stdClass
    {
        return app(StatementImports::class)->preview($owner, self::bytes($rows), $namespace, (string) $account->id, 'Synthetic CSV mapping', self::mapping());
    }
}
