<?php

namespace Tests\Support;

use App\Domain\Imports\CsvStatement;
use App\Domain\Imports\StatementImports;
use App\Domain\Ledger\LedgerInput;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    /** Populate valid immutable mapping history near a real serialized-byte boundary. */
    public static function fillRetention(User $owner, int $minimumBytes): void
    {
        $index = 0;
        while (self::retentionBytes($owner) < $minimumBytes) {
            $batch = [];
            for ($j = 0, $size = $minimumBytes - self::retentionBytes($owner) < 512 * 1024 ? 1 : 10; $j < $size; $j++) {
                $prefix = sprintf('%08d', $index++);
                $mapping = app(CsvStatement::class)->mapping(self::mapping(['type_map' => array_map(
                    fn (int $i): array => ['value' => $prefix.sprintf('%02d', $i).str_repeat("é\"\\\u{2028}\u{2029}", 30), 'target' => 'income'], range(0, 39))]));
                $batch[] = ['owner_id' => $owner->id, 'reference' => (string) Str::ulid(), 'name' => 'Synthetic retained mapping',
                    'config' => json_encode($mapping, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'digest' => LedgerInput::hash($mapping)];
            }
            DB::table('statement_adapters')->insert($batch);
        }
    }

    public static function retentionBytes(User $owner): int
    {
        $bytes = 0;
        foreach (['adapters', 'sources', 'snapshots', 'receipts', 'claims'] as $table) {
            foreach (DB::table('statement_'.$table.' as t')->where('owner_id', $owner->id)->selectRaw('(SELECT json_object_agg(key,value) FROM jsonb_each_text(to_jsonb(t)))::text AS export_row')->cursor() as $row) {
                $bytes += strlen(json_encode(json_decode($row->export_row, false, flags: JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) + 1;
            }
        }

        return $bytes;
    }
}
