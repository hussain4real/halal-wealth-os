<?php

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Support\DisposablePostgres;

uses(TestCase::class);
dataset('DisposablePostgresTest_unsafeTargets', function (): array {
    $local = ['host' => '127.0.0.1', 'database' => 'halal_wealth_os_test'];

    return ['production' => ['production', 'pgsql', $local, 'halal_wealth_os_test'], 'sqlite' => ['testing', 'sqlite', $local, 'halal_wealth_os_test'], 'no explicit marker' => ['testing', 'pgsql', $local, null], 'development database' => ['testing', 'pgsql', array_replace($local, ['database' => 'halal_wealth_os_dev']), 'halal_wealth_os_test'], 'remote host' => ['testing', 'pgsql', array_replace($local, ['host' => 'database.example.test']), 'halal_wealth_os_test'], 'unrecognized socket' => ['testing', 'pgsql', array_replace($local, ['host' => '/tmp/other-postgres']), 'halal_wealth_os_test'], 'URL override' => ['testing', 'pgsql', array_replace($local, ['url' => 'pgsql://database.example.test/shared']), 'halal_wealth_os_test']];
});
test('test_unsafe_targets_are_rejected', function (string $environment, string $driver, array $connection, mixed $marker): void {
    $this->expectException(LogicException::class);
    DisposablePostgres::assertConfiguration($environment, $driver, $connection, $marker);
})->with('DisposablePostgresTest_unsafeTargets');
test('test_explicit_local_disposable_target_is_allowed', function (): void {
    DisposablePostgres::assertConfiguration('testing', 'pgsql', ['host' => '127.0.0.1', 'database' => 'halal_wealth_os_test'], 'halal_wealth_os_test');
    $this->addToAssertionCount(1);
});
test('test_project_local_disposable_socket_is_allowed', function (): void {
    DisposablePostgres::assertConfiguration('testing', 'pgsql', ['host' => dirname(__DIR__, 2).'/.runtime/postgresql', 'database' => 'halal_wealth_os_test'], 'halal_wealth_os_test');
    $this->addToAssertionCount(1);
});
