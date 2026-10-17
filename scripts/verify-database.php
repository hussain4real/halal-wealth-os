<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\Support\DisposablePostgres;

require __DIR__.'/../vendor/autoload.php';

if (file_exists(__DIR__.'/../bootstrap/cache/config.php')) {
    fwrite(STDERR, "BLOCKED: remove cached application configuration before disposable database checks.\n");
    exit(2);
}

try {
    /** @var Application $app */
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $connection = $app['db']->connection();
    DisposablePostgres::assertConfiguration(
        $app->environment(),
        $connection->getDriverName(),
        $connection->getConfig(),
        env('HWO_DISPOSABLE_DATABASE'),
    );
    $identity = $connection->selectOne('SELECT current_database() AS name, current_user AS role, version() AS version');
    if ($identity === null || $identity->name !== 'halal_wealth_os_test') {
        throw new RuntimeException('Connected database identity does not match the disposable test database.');
    }
    echo "Disposable PostgreSQL identity verified: {$identity->name}; {$identity->version}\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'BLOCKED: disposable PostgreSQL check failed ('.$error::class."). No migrations were started.\n");
    exit(2);
}
