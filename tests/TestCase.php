<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Tests\Support\DisposablePostgres;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app['db']->connection();

        // This executes before RefreshDatabase can run destructive migrations.
        DisposablePostgres::assertConfiguration(
            $app->environment(),
            $connection->getDriverName(),
            $connection->getConfig(),
            env('HWO_DISPOSABLE_DATABASE'),
        );

        return $app;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
