<?php

namespace Tests\Support;

use App\Domain\Integrations\Drive\DriveExports;
use App\Domain\Integrations\Drive\GoogleDrive;
use App\Domain\Integrations\Drive\SavedReport;
use App\Domain\Reporting\DashboardSnapshot;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class DriveFixtures
{
    public array $files = [];

    public array $bodies = [];

    public array $identity = ['sub' => 'synthetic-oidc-sub', 'email' => 'personal@example.test', 'email_verified' => true];

    public array $tokenChanges = [];

    public string $failure = '';

    public int $identityStatus = 200;

    public ?string $identityBody = null;

    public string $inspectFailureId = '';

    public string $inspectFailure = '';

    public int $creates = 0;

    public int $generated = 0;

    public function fake(): void
    {
        Http::swap(new Factory);
        config(['drive_export.mode' => 'mock', 'drive_export.epoch' => str_repeat('a', 32)]);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($url === 'https://oauth2.googleapis.com/token') {
                if ($this->failure === 'refresh' && $request['grant_type'] === 'refresh_token') {
                    return Http::response(['error' => 'invalid_grant'], 400);
                }

                return Http::response([...['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'expires_in' => 3600,
                    'token_type' => 'Bearer', 'scope' => GoogleDrive::SCOPE], ...$this->tokenChanges]);
            }
            if ($url === 'https://openidconnect.googleapis.com/v1/userinfo') {
                return Http::response($this->identityBody ?? $this->identity, $this->identityStatus);
            }
            if ($url === 'https://oauth2.googleapis.com/revoke') {
                return Http::response('', $this->failure === 'revoke' ? 503 : 200);
            }
            if (str_contains($url, '/generateIds?')) {
                return Http::response(['ids' => ['synthetic-file-'.++$this->generated]]);
            }
            if ($request->method() === 'POST') {
                $this->creates++;
                if (str_contains($url, '/upload/')) {
                    preg_match('/\r\n\r\n(.*?)\r\n--hwo-drive-export-boundary\r\nContent-Type: application\/json\r\n\r\n(.*)\r\n--hwo-drive-export-boundary--\r\n/s', $request->body(), $parts);
                    $metadata = json_decode($parts[1], true, flags: JSON_THROW_ON_ERROR);
                    $bytes = $parts[2];
                } else {
                    $metadata = $request->data();
                    $bytes = '';
                }
                $id = $metadata['id'];
                if (isset($this->files[$id])) {
                    return Http::response('', 409);
                }
                if ($this->failure === 'before_create') {
                    throw new \RuntimeException('synthetic-access must not leak');
                }
                $this->files[$id] = [...$metadata, 'trashed' => false, 'owners' => [['me' => true, 'permissionId' => 'drive-permission-distinct-from-oidc']],
                    'permissions' => [['id' => 'drive-permission-distinct-from-oidc', 'type' => 'user', 'role' => 'owner']],
                    'parents' => $metadata['parents'] ?? ['actual-my-drive-root'], 'capabilities' => ['canAddChildren' => $bytes === ''],
                    'size' => (string) strlen($bytes), 'sha256Checksum' => hash('sha256', $bytes)];
                $this->bodies[$id] = $bytes;
                if ($this->failure === 'after_create') {
                    throw new \RuntimeException('synthetic-refresh must not leak');
                }

                return Http::response(['id' => $id], 201);
            }
            $path = parse_url($url, PHP_URL_PATH);
            $id = basename($path);
            if (! isset($this->files[$id])) {
                return Http::response('', 404);
            }
            if ($id === $this->inspectFailureId) {
                return Http::response($this->inspectFailure === 'oversize' ? str_repeat('x', SavedReport::LIMIT + 1) : 'invalid-json');
            }
            if (str_contains($url, 'alt=media')) {
                return Http::response($this->bodies[$id]);
            }

            return Http::response($this->files[$id]);
        });
    }

    public function connect(User $owner): array
    {
        $drive = app(DriveExports::class);
        $start = $drive->begin($owner, 'synthetic-session');

        return $drive->finish($owner, 'synthetic-session', $start['state'], 'synthetic-code');
    }

    public function ready(User $owner): array
    {
        $c = $this->connect($owner);
        $drive = app(DriveExports::class);
        $r = $drive->prepare($owner, 'folder-'.Str::ulid(), $c['id'], $c['generation']);
        $drive->run($r['id']);

        return $drive->connection($owner, $c['id']);
    }

    public static function snapshot(User $owner): string
    {
        return (string) app(DashboardSnapshot::class)->save($owner, 'snapshot-'.Str::ulid(), ['base_currency' => 'QAR', 'settings_id' => ''])->id;
    }

    public static function queued(User $owner, string $snapshot): array
    {
        $drive = app(DriveExports::class);
        $review = $drive->review($owner, $snapshot);

        return $drive->confirm($owner, 'drive-'.Str::ulid(), $review['review_token'], true);
    }
}
