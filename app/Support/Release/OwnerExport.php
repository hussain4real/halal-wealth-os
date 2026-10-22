<?php

declare(strict_types=1);

namespace App\Support\Release;

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** @phpstan-import-type Table from OwnerDataSchema */
final class OwnerExport
{
    private const int MAX_BYTES = 67108864;

    public function download(User $owner): PrivateExportDownload
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Owner export requires its own coherent snapshot.');
        }
        $directory = sys_get_temp_dir().'/hwo-owner-export-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Private export storage is unavailable.');
        }
        $output = fopen($directory.'/owner.json', 'x+b');
        if ($output === false) {
            rmdir($directory);
            throw new RuntimeException('Private export storage is unavailable.');
        }
        chmod($directory.'/owner.json', 0600);
        try {
            $connection->transaction(function () use ($owner, $connection, $output): void {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                $connection->statement("SET LOCAL TIME ZONE 'UTC'");
                $contract = OwnerDataSchema::current($connection);
                $id = (string) $owner->id;
                $profile = $connection->table('users')->where('id', $id)
                    ->selectRaw('id::text, name::text, email::text, email_verified_at::text, created_at::text, updated_at::text')->first();
                if ($profile === null) {
                    throw new RuntimeException('Export owner is unavailable.');
                }
                $captured = $connection->selectOne('SELECT transaction_timestamp()::text AS captured')->captured;
                $manifest = ['schema_version' => 1, 'captured_at' => $captured, 'encoding' => $contract['encoding'],
                    'contract_sha256' => $contract['sha256'],
                    'scope' => 'Complete retained owner database history; not an authentication or operational restore backup.',
                    'profile_fields' => $contract['profile_fields'], 'excluded_reason' => $contract['excluded_reason'],
                    'excluded_tables' => [], 'sections' => []];
                self::write($output, '{"schema_version":1,"profile":'.self::json($profile).',"data":{');
                $firstTable = true;
                foreach ($contract['tables'] as $table) {
                    if ($table['scope'] === 'excluded') {
                        $manifest['excluded_tables'][] = $table['table'];

                        continue;
                    }
                    if ($table['scope'] === 'profile') {
                        continue;
                    }
                    if (! $firstTable) {
                        self::write($output, ',');
                    }
                    $firstTable = false;
                    self::write($output, self::json($table['table']).':[');
                    $hash = hash_init('sha256');
                    hash_update($hash, '[');
                    $count = 0;
                    $query = $this->query($connection, $table, $id);
                    foreach ($query->cursor() as $record) {
                        $row = json_decode($record->export_row, false, flags: JSON_THROW_ON_ERROR);
                        foreach ($table['columns'] as $column) {
                            $value = $row->{$column['name']};
                            if ($value !== null && in_array($column['udt'], ['json', 'jsonb'], true)) {
                                $this->evidence(json_decode($value, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING), $id);
                            }
                        }
                        $bytes = ($count === 0 ? '' : ',').self::json($row);
                        self::write($output, $bytes);
                        hash_update($hash, $bytes);
                        $count++;
                    }
                    self::write($output, ']');
                    hash_update($hash, ']');
                    $manifest['sections'][$table['table']] = ['rows' => $count, 'sha256' => hash_final($hash), 'columns' => array_column($table['columns'], 'name'), 'ownership' => $table['scope']];
                }
                self::write($output, '},"manifest":'.self::json($manifest).'}');
            });
            fclose($output);

            return new PrivateExportDownload($directory);
        } catch (Throwable $error) {
            if (is_resource($output)) {
                fclose($output);
            }
            unlink($directory.'/owner.json');
            rmdir($directory);
            throw $error;
        }
    }

    /** @param Table $table */
    private function query(Connection $connection, array $table, string $owner): Builder
    {
        $query = $connection->table($table['table'].' as t');
        switch ($table['scope']) {
            case 'owner':
                $query->where('t.owner_id', $owner);
                break;
            case 'account':
                $query->join('accounts as a', 'a.id', '=', 't.account_id')->where('a.owner_id', $owner);
                break;
            case 'opening':
                $query->join('opening_balance_intents as i', 'i.id', '=', 't.opening_intent_id')
                    ->join('accounts as a', 'a.id', '=', 'i.account_id')->where('a.owner_id', $owner)
                    ->join('journal_entries as j', 'j.id', '=', 't.journal_id');
                if ((clone $query)->where('j.owner_id', '<>', $owner)->exists()) {
                    throw new RuntimeException('Opening evidence ownership is inconsistent.');
                }
                $query->where('j.owner_id', $owner);
                break;
            default:
                throw new RuntimeException('Unclassified owner export table.');
        }
        $query->selectRaw('(SELECT json_object_agg(key, value) FROM jsonb_each_text(to_jsonb(t)))::text AS export_row');
        foreach ($table['order'] as $column) {
            $query->orderBy('t.'.$column);
        }

        return $query;
    }

    private function evidence(mixed $value, string $owner): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            if ($key === 'owner_id' && ((! is_string($item) && ! is_int($item)) || (string) $item !== $owner)) {
                throw new RuntimeException('Retained evidence ownership is inconsistent.');
            }
            if (in_array($key, ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'access_token', 'refresh_token', 'csrf_token', '_token'], true)) {
                throw new RuntimeException('Authentication material cannot be exported as financial evidence.');
            }
            $this->evidence($item, $owner);
        }
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param resource $output */
    private static function write($output, string $bytes): void
    {
        $position = ftell($output);
        if ($position === false || $position + strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('Complete export exceeds the private release size limit.');
        }
        while ($bytes !== '') {
            $written = fwrite($output, $bytes);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Private export could not be written.');
            }
            $bytes = substr($bytes, $written);
        }
    }
}
