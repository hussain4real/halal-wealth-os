<?php

declare(strict_types=1);

namespace App\Support\Release;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Throwable;

final class ReleaseReadiness
{
    public function healthy(): bool
    {
        try {
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'pgsql' || (int) $connection->selectOne('SELECT 1 AS ready')->ready !== 1) {
                return false;
            }
            $migrator = app(Migrator::class);
            if (! $migrator->repositoryExists()) {
                return false;
            }
            $expected = array_keys($migrator->getMigrationFiles(database_path('migrations')));
            $applied = $migrator->getRepository()->getRan();
            sort($expected);
            sort($applied);
            if ($expected !== $applied) {
                return false;
            }
            OwnerDataSchema::current($connection);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{result:string,checks:array<string,bool>,deployment_authorized:bool,environment_signoff:string} */
    public function inspect(): array
    {
        $configured = (string) config('app.key');
        $key = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : $configured;
        $url = parse_url((string) config('app.url'));
        $checks = [
            'backup_crypto_available' => extension_loaded('sodium'),
            'production_context' => app()->isProduction(),
            'debug_off' => config('app.debug') === false,
            'valid_application_key' => is_string($key) && Encrypter::supported($key, (string) config('app.cipher')),
            'https_application_url' => is_array($url) && ($url['scheme'] ?? '') === 'https' && ! empty($url['host']) && ! isset($url['user']) && ! isset($url['pass']),
            'persistent_secure_sessions' => config('session.secure') === true && config('session.http_only') === true
                && config('session.encrypt') === true && in_array(config('session.same_site'), ['lax', 'strict'], true)
                && in_array(config('session.driver'), ['file', 'database', 'redis'], true),
            'registration_closed' => ! Features::enabled(Features::registration()) && ! Route::has('register'),
            'confirmed_two_factor_policy' => Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm')
                && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
            'explicit_admin_policy' => Gate::has('access-admin'),
            'configured_mail_transport' => ! in_array(config('mail.default'), ['array', 'log', null, ''], true),
            'database_migrations_and_export_schema' => $this->healthy(),
        ];

        return ['result' => in_array(false, $checks, true) ? 'FAIL' : 'PASS', 'checks' => $checks,
            'deployment_authorized' => false, 'environment_signoff' => 'Owner hosting, backup custody/retention, mail delivery and recovery objectives require separate signoff.'];
    }
}
