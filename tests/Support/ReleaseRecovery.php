<?php

namespace Tests\Support;

use App\Domain\Imports\StatementImports;
use App\Domain\Integrations\MarketRefresh;
use App\Domain\Ledger\FinancialSnapshot;
use App\Domain\Notifications\Reminders;
use App\Domain\Operations\Channel;
use App\Domain\Operations\DriveAction;
use App\Domain\Operations\OperationBlocked;
use App\Domain\Operations\Operations;
use App\Domain\Planning\GoalView;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Portfolio\PositionPricing;
use App\Domain\Portfolio\ValuationFx;
use App\Domain\Shared\Money\Currency;
use App\Models\Account;
use App\Models\User;
use App\Support\Release\EncryptedBackup;
use App\Support\Release\OwnerDataSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            DriveRecoveryFixtures::assertHeld();
            CopilotRecoveryFixtures::assertHeld(User::findOrFail($fixture['owner']));
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
            $operationalRollback = $this->operationalRollback($owner, $fixture, $cutoff);
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
                'populated_downgrade_refused' => true, 'operational_rollback' => $operationalRollback, 'deployment_authorized' => false];
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

    /** Matching-build optional disable, never an old-binary/schema downgrade. */
    private function operationalRollback(User $owner, array $fixture, string $cutoff): array
    {
        $ops = app(Operations::class);
        if (config('operations.paused') !== false) {
            throw new RuntimeException('Rehearsal must observe owner stop independently of operator stop.');
        }
        foreach (Channel::cases() as $channel) {
            if ($ops->policy($owner, $channel)['paused']) {
                throw new RuntimeException('Rehearsal requires four explicitly running synthetic owner policies.');
            }
        }
        $before = $this->capture($fixture, $cutoff);
        $policies = DB::table('operation_controls')->orderBy('id')->get();
        $foreignBefore = DB::table('operation_controls')->where('owner_id', '<>', $owner->id)->orderBy('id')->get()->toJson();
        $ops->pauseAll($owner);
        $changed = 0;
        foreach ($policies as $old) {
            $new = DB::table('operation_controls')->where('id', $old->id)->first();
            if ((string) $old->owner_id === (string) $owner->id) {
                if (! $new->paused || (int) $new->generation !== (int) $old->generation + 1) {
                    throw new RuntimeException('Owner stop did not fence every current policy.');
                }
                $changed++;
                foreach (['paused', 'generation', 'updated_at'] as $allowed) {
                    unset($old->$allowed, $new->$allowed);
                }
            }
            if ($old != $new) {
                throw new RuntimeException('Operational stop changed unrelated policy fields.');
            }
        }
        $foreignAfter = DB::table('operation_controls')->where('owner_id', '<>', $owner->id)->orderBy('id')->get()->toJson();
        $after = $this->capture($fixture, $cutoff);
        unset($before['tables']['operation_controls'], $after['tables']['operation_controls']);
        unset($before['readers']['operations'], $after['readers']['operations']);
        if ($changed !== 4 || $before !== $after || $foreignBefore !== $foreignAfter) {
            throw new RuntimeException('Optional stop changed retained history, foreign policies or financial readers.');
        }
        $denials = [];
        foreach (Channel::cases() as $channel) {
            try {
                DB::transaction(fn () => $ops->reserve($owner, $channel, (string) Str::uuid(), $channel === Channel::Drive ? DriveAction::Revoke : null));
                throw new RuntimeException('Paused optional admission escaped.');
            } catch (OperationBlocked $error) {
                if ($error->reason !== 'paused') {
                    throw $error;
                }
                $denials[$channel->value] = 'paused';
            }
        }
        $postDenials = $this->capture($fixture, $cutoff);
        unset($postDenials['tables']['operation_controls'], $postDenials['readers']['operations']);
        if ($postDenials !== $after) {
            throw new RuntimeException('Denied optional work allocated retained evidence.');
        }
        $oldRows = $this->rows();
        $manual = GoalFixtures::cash($owner, Account::findOrFail($fixture['bank']), '1.01');
        $newRows = $this->rows();
        $added = [];
        foreach ($oldRows as $table => $rows) {
            $oldCounts = array_count_values($rows);
            $newCounts = array_count_values($newRows[$table]);
            foreach ($oldCounts as $row => $count) {
                if (($newCounts[$row] ?? 0) < $count) {
                    throw new RuntimeException('Manual fallback modified or deleted retained history.');
                }
                $newCounts[$row] -= $count;
            }
            foreach ($newCounts as $row => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $added[$table][] = json_decode($row, true, flags: JSON_THROW_ON_ERROR);
                }
            }
        }
        ksort($added);
        if (array_keys($added) !== ['journal_entries', 'postings'] || count($added['journal_entries']) !== 1 || count($added['postings']) !== 2
            || (string) $added['journal_entries'][0]['id'] !== (string) $manual->id || $added['journal_entries'][0]['status'] !== 'posted') {
            throw new RuntimeException('Manual fallback added unexpected history.');
        }
        foreach ($added['postings'] as $posting) {
            if ((string) $posting['journal_id'] !== (string) $manual->id || (string) $posting['owner_id'] !== (string) $owner->id || $posting['currency_code'] !== 'QAR') {
                throw new RuntimeException('Manual fallback postings are not owned and linked.');
            }
        }
        if (DB::table('postings')->where('journal_id', $manual->id)->selectRaw('sum(amount_minor) AS total')->first()->total !== '0') {
            throw new RuntimeException('Manual fallback journal did not balance.');
        }

        return ['result' => 'PASS', 'strategy' => 'matching_build_optional_disable', 'changed_owner_policies' => $changed,
            'denied_channels' => $denials, 'unchanged_tables' => array_keys($after['tables']),
            'history_before_sha256' => hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)),
            'history_after_sha256' => hash('sha256', json_encode($after, JSON_THROW_ON_ERROR)),
            'reader_fingerprints_before' => $before['readers'], 'reader_fingerprints_after' => $after['readers'],
            'foreign_policies_unchanged' => true, 'denials_allocate_no_evidence' => true,
            'manual_posting' => true, 'preexisting_rows_preserved' => true, 'new_rows' => ['journal_entries' => 1, 'postings' => 2],
            'code_downgrade_rehearsed' => false, 'deployment_authorized' => false];
    }

    /** Raw rows remain in memory only; public evidence contains hashes and counts. */
    private function rows(): array
    {
        $result = [];
        foreach (OwnerDataSchema::current(DB::connection())['tables'] as $table) {
            $result[$table['table']] = DB::table($table['table'].' as t')->selectRaw('to_jsonb(t)::text AS value')->get()->pluck('value')->all();
        }

        return $result;
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
        $readers = ['operations' => hash('sha256', json_encode(app(Operations::class)->page($owner), JSON_THROW_ON_ERROR)), 'intelligence' => hash('sha256', json_encode(CopilotRecoveryFixtures::read($owner), JSON_THROW_ON_ERROR)), 'drive' => hash('sha256', json_encode(DriveRecoveryFixtures::read($owner), JSON_THROW_ON_ERROR)), 'reminders' => hash('sha256', json_encode(app(Reminders::class)->inbox($owner), JSON_THROW_ON_ERROR)), 'market' => hash('sha256', json_encode([
            'status' => app(MarketRefresh::class)->status($owner),
            'price' => app(PositionPricing::class)->value($owner, $fixture['market_price_account'], ['kind' => 'unitized_fund', 'currency' => 'USD', 'quantity' => '1', 'cost_basis' => null, 'unit_price' => null, 'asset_family' => DB::table('portfolio_assets')->where('id', $fixture['market_price_asset'])->value('family_reference')], null, $cutoff),
            'fx' => app(ValuationFx::class)->convert($owner, '100', Currency::USD, Currency::QAR, $cutoff, 'reporting'),
            'charged' => DB::table('market_attempts')->orderBy('id')->get(),
            'results' => DB::table('market_results')->orderBy('id')->get(),
        ], JSON_THROW_ON_ERROR)), 'cash' => $snapshot->fingerprint($snapshot->capture($owner, Currency::QAR, $cutoff)),
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
