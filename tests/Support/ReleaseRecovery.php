<?php

namespace Tests\Support;

use App\Domain\Imports\StatementImports;
use App\Domain\Ledger\FinancialSnapshot;
use App\Domain\Planning\GoalView;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Shared\Money\Currency;
use App\Models\Account;
use App\Models\User;
use App\Support\Release\EncryptedBackup;
use App\Support\Release\OwnerDataSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Only creates and removes its own random databases on the guarded disposable test server. */
final class ReleaseRecovery
{
    private array $created = [];

    private string $nonce;

    private string $original;

    public function run(): array
    {
        $this->original = config('database.default');
        $base = config('database.connections.'.$this->original);
        DisposablePostgres::assertConfiguration(app()->environment(), $base['driver'], $base, env('HWO_DISPOSABLE_DATABASE'));
        $admin = DB::connection($this->original);
        $identity = $admin->selectOne('SELECT current_database() AS db,current_user AS owner');
        if ($identity->db !== 'halal_wealth_os_test' || $identity->owner !== $base['username'] || $admin->transactionLevel() !== 0) {
            throw new RuntimeException('Recovery rehearsal requires the owned, nontransactional disposable connection.');
        }
        foreach (['pg_dump', 'pg_restore'] as $tool) {
            $process = new Process([$tool, '--version']);
            $process->mustRun();
            if (! preg_match('/\(PostgreSQL\) 18\./', $process->getOutput()) || (int) $admin->selectOne('SHOW server_version_num')->server_version_num < 180000 || (int) $admin->selectOne('SHOW server_version_num')->server_version_num >= 190000) {
                throw new RuntimeException('Recovery rehearsal requires matching PostgreSQL 18 clients/server.');
            }
        }
        $this->nonce = bin2hex(random_bytes(10));
        $directory = sys_get_temp_dir().'/hwo-recovery-'.$this->nonce;
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Private recovery storage is unavailable.');
        }
        $key = random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
        $report = [];
        $archive = null;
        $this->created = [];
        try {
            foreach (['source', 'target'] as $role) {
                $name = 'halal_wealth_os_rehearsal_'.$this->nonce.'_'.$role;
                if ($admin->selectOne('SELECT 1 FROM pg_database WHERE datname=?', [$name]) !== null) {
                    throw new RuntimeException('Refusing an occupied recovery database.');
                }
                $admin->statement('CREATE DATABASE "'.$name.'" TEMPLATE template0');
                $this->created[$role] = $name;
                $admin->statement('COMMENT ON DATABASE "'.$name.'" IS '.$admin->getPdo()->quote('hwo-m5p3:'.$this->nonce.':'.$role));
                config(['database.connections.recovery_'.$role => [...$base, 'database' => $name]]);
            }
            $this->select('source');
            if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
                throw new RuntimeException('Source migration failed.');
            }
            $fixture = ReleaseFixtures::seed();
            // Advance beyond database microseconds before fixing a valid UTC cutoff.
            usleep(1100000);
            $cutoff = now('UTC')->format('Y-m-d\TH:i:s\Z');
            $before = $this->capture($fixture, $cutoff);
            $migrations = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
            $archive = fopen($directory.'/backup.enc', 'x+b');
            if ($archive === false || ! chmod($directory.'/backup.enc', 0600)) {
                throw new RuntimeException('Private archive could not be created.');
            }
            $plain = tmpfile();
            if ($plain === false) {
                throw new RuntimeException('Private dump spool is unavailable.');
            }
            try {
                $this->databaseProcess('pg_dump', 'source', ['--format=custom', '--no-owner', '--no-acl'], null, $plain);
                rewind($plain);
                EncryptedBackup::seal($plain, $archive, $key);
            } finally {
                fclose($plain);
            }
            $this->select('target');
            $empty = $this->tableCount();
            if ($empty !== 0) {
                throw new RuntimeException('Refusing a nonempty recovery target.');
            }
            $wrong = tmpfile();
            if ($wrong === false) {
                throw new RuntimeException('Private recovery spool is unavailable.');
            }
            try {
                rewind($archive);
                try {
                    EncryptedBackup::open($archive, $wrong, random_bytes(32));
                    throw new RuntimeException('Wrong-key archive was unexpectedly accepted.');
                } catch (RuntimeException $error) {
                    if ($error->getMessage() !== 'Backup authentication failed.' || ftell($wrong) !== 0 || $this->tableCount() !== 0) {
                        throw $error;
                    }
                }
            } finally {
                fclose($wrong);
            }
            $restored = tmpfile();
            if ($restored === false) {
                throw new RuntimeException('Private restore spool is unavailable.');
            }
            try {
                rewind($archive);
                EncryptedBackup::open($archive, $restored, $key);
                rewind($restored);
                $this->databaseProcess('pg_restore', 'target', ['--single-transaction', '--exit-on-error', '--no-owner', '--no-acl'], $restored, null);
            } finally {
                fclose($restored);
                fclose($archive);
            }
            $after = $this->capture($fixture, $cutoff);
            if ($before !== $after || Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0
                || $migrations !== DB::table('migrations')->orderBy('id')->pluck('migration')->all()) {
                throw new RuntimeException('Restored data/readers/migration order did not reconcile.');
            }
            $rollbackRefused = false;
            DB::beginTransaction();
            try {
                Artisan::call('migrate:rollback', ['--step' => 1000, '--force' => true, '--no-interaction' => true]);
            } catch (\Throwable $error) {
                $rollbackRefused = str_starts_with($error->getMessage(), 'Retained ') && str_contains($error->getMessage(), 'downgrade refused before DDL.');
            } finally {
                DB::rollBack();
            }
            if (! $rollbackRefused || $after !== $this->capture($fixture, $cutoff)) {
                throw new RuntimeException('Populated downgrade must refuse; rollback rehearsal must preserve restored data.');
            }
            // A subsequent real write must use restored sequences and database constraints.
            $owner = User::findOrFail($fixture['owner']);
            GoalFixtures::cash($owner, Account::findOrFail($fixture['bank']), '1.01');
            $protected = false;
            try {
                DB::table('postings')->where('id', DB::table('postings')->min('id'))->update(['amount_minor' => '1']);
            } catch (QueryException $error) {
                $protected = $error->getCode() === 'P0001' && str_contains($error->getMessage(), 'Financial history is immutable');
            }
            if (! $protected) {
                throw new RuntimeException('Restored immutable journal guard is missing.');
            }
            $report = ['result' => 'PASS', 'tables' => count($before['tables']), 'table_fingerprints' => $before['tables'],
                'reader_fingerprints' => $before['readers'], 'migrations' => $migrations,
                'encryption' => 'sodium-secretstream-xchacha20poly1305-v1', 'wrong_key_target_unchanged' => true,
                'restored_sequences_write' => true, 'restored_immutability_guard' => true,
                'populated_downgrade_refused' => true, 'deployment_authorized' => false];
        } finally {
            sodium_memzero($key);
            DB::setDefaultConnection($this->original);
            $cleanupFailures = [];
            try {
                foreach ($this->created as $role => $name) {
                    try {
                        DB::purge('recovery_'.$role);
                        $marker = $admin->selectOne('SELECT shobj_description(oid,\'pg_database\') AS marker FROM pg_database WHERE datname=?', [$name]);
                        if ($marker === null || $marker->marker !== 'hwo-m5p3:'.$this->nonce.':'.$role) {
                            throw new RuntimeException('Recovery cleanup refused an unrecognized database.');
                        }
                        $admin->statement('DROP DATABASE "'.$name.'"');
                    } catch (\Throwable) {
                        $cleanupFailures[] = $role;
                    }
                }
            } finally {
                if (is_resource($archive)) {
                    fclose($archive);
                }
                foreach (glob($directory.'/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
            if ($cleanupFailures !== []) {
                throw new RuntimeException('Recovery cleanup incomplete; no cleanup success is claimed.');
            }
        }
        $report['cleanup'] = 'OWNED_DATABASES_DROPPED_AND_PRIVATE_FILES_REMOVED';

        return $report;
    }

    private function select(string $role): void
    {
        DB::setDefaultConnection('recovery_'.$role);
        $actual = DB::selectOne('SELECT current_database() AS db');
        if ($actual->db !== $this->created[$role]) {
            throw new RuntimeException('Recovery database identity mismatch.');
        }
        DB::statement("SET TIME ZONE 'UTC'");
    }

    private function tableCount(): int
    {
        return (int) DB::selectOne("SELECT count(*) AS n FROM information_schema.tables WHERE table_schema='public'")->n;
    }

    private function capture(array $fixture, string $cutoff): array
    {
        $contract = OwnerDataSchema::current(DB::connection());
        $tables = [];
        foreach ($contract['tables'] as $table) {
            $rows = DB::table($table['table'].' as t')->selectRaw('to_jsonb(t)::text AS value')->get()->pluck('value')->all();
            sort($rows, SORT_STRING);
            $tables[$table['table']] = ['rows' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
        }
        $owner = User::findOrFail($fixture['owner']);
        $snapshot = app(FinancialSnapshot::class);
        $portfolio = app(PortfolioRead::class);
        $readers = ['cash' => $snapshot->fingerprint($snapshot->capture($owner, Currency::QAR, $cutoff)),
            'positions' => $portfolio->fingerprint($portfolio->state($owner, $fixture['portfolio'], $cutoff)),
            'reservations' => hash('sha256', json_encode(app(GoalView::class)->show($owner, $fixture['goal'], substr($cutoff, 0, 7)), JSON_THROW_ON_ERROR)),
            'shariah' => hash('sha256', json_encode(DB::table('shariah_assessments')->where('owner_id', $owner->id)->orderBy('id')->get(), JSON_THROW_ON_ERROR)),
            'imports' => hash('sha256', json_encode(['preview' => app(StatementImports::class)->show($owner, $fixture['import_snapshot']),
                'receipt' => app(StatementImports::class)->receipt($owner, $fixture['import_cancelled']),
                'source_base64' => DB::table('statement_sources')->where('owner_id', $owner->id)->where('id', $fixture['import_source'])->value('source_base64')], JSON_THROW_ON_ERROR))];

        return ['tables' => $tables, 'readers' => $readers];
    }

    /** @param resource|null $input
     * @param resource|null $output */
    private function databaseProcess(string $tool, string $role, array $options, $input, $output): void
    {
        $config = config('database.connections.recovery_'.$role);
        $args = [$tool, '--host='.$config['host'], '--port='.$config['port'], '--username='.$config['username'], '--dbname='.$this->created[$role], '--no-password', ...$options];
        $errors = tmpfile();
        if ($errors === false) {
            throw new RuntimeException('Private process log is unavailable.');
        }
        try {
            $process = proc_open($args, [0 => $input ?? ['file', '/dev/null', 'r'], 1 => $output ?? ['file', '/dev/null', 'w'], 2 => $errors], $pipes, base_path(), ['PATH' => getenv('PATH'), 'PGPASSWORD' => $config['password'] ?? '', 'PGOPTIONS' => '-c timezone=UTC']);
            if (! is_resource($process) || proc_close($process) !== 0) {
                throw new RuntimeException('PostgreSQL backup/restore process failed; no successful recovery is claimed.');
            }
        } finally {
            fclose($errors);
        }
    }
}
