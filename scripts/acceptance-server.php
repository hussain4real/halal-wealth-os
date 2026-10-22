<?php

declare(strict_types=1);
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposablePostgres;

$start = hrtime(true);
define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$storage = getenv('LARAVEL_STORAGE_PATH');
if (! is_string($storage) || $storage !== dirname((string) getenv('HWO_ACCEPTANCE_METRICS')).'/storage' || ! is_dir($storage)) {
    throw new RuntimeException('Owned acceptance storage is required.');
}
$app->useStoragePath($storage);
$request = Request::capture();
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
DisposablePostgres::assertConfiguration($app->environment(), $app['db']->connection()->getDriverName(), $app['db']->connection()->getConfig(), env('HWO_DISPOSABLE_DATABASE'));
$queries = 0;
$queryMs = 0.0;
$profile = [];
$profileSql = [];
DB::listen(function ($query) use (&$queries, &$queryMs, &$profile, &$profileSql, $request): void {
    $queries++;
    $queryMs += $query->time;
    if ($request->header('X-HWO-Profile') === '1') {
        $key = hash('sha256', $query->sql);
        $profile[$key] ??= ['count' => 0, 'ms' => 0.0];
        $profile[$key]['count']++;
        $profile[$key]['ms'] += $query->time;
        $profileSql[$key] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'ms' => $profile[$key]['ms']];
    }
});
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
$id = $request->header('X-HWO-Measure', '');
if (preg_match('/^[a-z0-9-]{1,64}$/D', $id) === 1) {
    $record = ['id' => $id, 'status' => $response->getStatusCode(), 'server_ms' => (hrtime(true) - $start) / 1e6, 'queries' => $queries, 'query_ms' => $queryMs, 'console' => $app->runningInConsole(), 'variables_order' => ini_get('variables_order'), 'owned_storage' => $app->storagePath() === $storage, 'profile' => $profile];
    if ($profileSql !== []) {
        file_put_contents(dirname(getenv('HWO_ACCEPTANCE_METRICS')).'/query-profile.json', json_encode($profileSql, JSON_THROW_ON_ERROR));
    }
    file_put_contents(getenv('HWO_ACCEPTANCE_METRICS'), json_encode($record, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
}
