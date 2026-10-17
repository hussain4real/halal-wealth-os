<?php

namespace Tests\Support;

use LogicException;

final class DisposablePostgres
{
    /** @param array<string, mixed> $connection */
    public static function assertConfiguration(string $environment, string $driver, array $connection, mixed $marker): void
    {
        if ($environment !== 'testing' || $driver !== 'pgsql'
            || $marker !== 'halal_wealth_os_test'
            || ($connection['database'] ?? null) !== $marker
            || ! empty($connection['url'])) {
            throw new LogicException('BLOCKED: tests and destructive migrations require the explicitly marked halal_wealth_os_test PostgreSQL database.');
        }

        $localSocket = dirname(__DIR__, 2).'/.runtime/postgresql';
        if (! in_array($connection['host'] ?? null, ['127.0.0.1', 'localhost', '::1', '/var/run/postgresql', $localSocket], true)) {
            throw new LogicException('BLOCKED: only local PostgreSQL hosts or the isolated development socket are allowed.');
        }
    }
}
