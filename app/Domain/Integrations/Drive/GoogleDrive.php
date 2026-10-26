<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Drive;

use App\Domain\Operations\OperationBlocked;
use App\Domain\Operations\OperationPermit;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Fixed, fake-only Google boundary. No method can enable live transport. */
final class GoogleDrive
{
    private ?OperationPermit $permit = null;

    public function scoped(OperationPermit $permit): self
    {
        $copy = clone $this;
        $copy->permit = $permit;

        return $copy;
    }

    public const string SCOPE = 'https://www.googleapis.com/auth/drive.file openid email';

    public const string FOLDER = 'application/vnd.google-apps.folder';

    private const string FILES = 'https://www.googleapis.com/drive/v3/files';

    public static function enabled(): bool
    {
        return app()->environment('testing') && config('drive_export.mode') === 'mock'
            && is_string(config('drive_export.epoch')) && preg_match('/^[a-f0-9]{32}$/D', config('drive_export.epoch')) === 1;
    }

    public static function requireMock(): void
    {
        if (! self::enabled()) {
            throw new DriveFailure('disabled_or_restore_held');
        }
    }

    public function authorization(string $state, string $challenge): string
    {
        self::requireMock();

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id' => 'synthetic-mock-only',
            'redirect_uri' => 'http://localhost/drive/callback', 'response_type' => 'code', 'scope' => self::SCOPE,
            'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'access_type' => 'offline', 'prompt' => 'select_account consent']);
    }

    /** @return array{access:string,refresh:string,expires:int} */
    public function exchange(#[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier): array
    {
        return $this->tokens($this->request('POST', 'https://oauth2.googleapis.com/token', '', [
            'client_id' => 'synthetic-mock-only', 'code' => $code, 'code_verifier' => $verifier,
            'redirect_uri' => 'http://localhost/drive/callback', 'grant_type' => 'authorization_code',
        ]), '');
    }

    /** @return array{access:string,refresh:string,expires:int} */
    public function refresh(#[\SensitiveParameter] string $refresh): array
    {
        if ($refresh === '') {
            throw new DriveFailure('reconnect_required');
        }

        return $this->tokens($this->request('POST', 'https://oauth2.googleapis.com/token', '', [
            'client_id' => 'synthetic-mock-only', 'refresh_token' => $refresh, 'grant_type' => 'refresh_token',
        ]), $refresh);
    }

    /** @return array{subject:string,email:string} */
    public function identity(#[\SensitiveParameter] string $access): array
    {
        $data = $this->json($this->request('GET', 'https://openidconnect.googleapis.com/v1/userinfo', $access));
        $subject = self::text($data, 'sub', 255);
        $email = self::text($data, 'email', 254);
        if (($data['email_verified'] ?? null) !== true || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DriveFailure('unverified_identity');
        }

        return ['subject' => $subject, 'email' => $email];
    }

    public function revoke(#[\SensitiveParameter] string $token): void
    {
        $this->request('POST', 'https://oauth2.googleapis.com/revoke', '', ['token' => $token]);
    }

    public function generate(#[\SensitiveParameter] string $access): string
    {
        $data = $this->json($this->request('GET', self::FILES.'/generateIds', $access, ['count' => '1', 'space' => 'drive', 'type' => 'files']));
        if (! is_array($data['ids'] ?? null) || count($data['ids']) !== 1 || ! is_string($data['ids'][0])) {
            throw new DriveFailure('invalid_remote_id');
        }

        return self::identifier($data['ids'][0]);
    }

    /** @return array<string,mixed>|null */
    public function inspect(#[\SensitiveParameter] string $access, string $id): ?array
    {
        $response = $this->request('GET', self::FILES.'/'.self::identifier($id), $access,
            ['fields' => 'id,name,mimeType,parents,owners,permissions,driveId,trashed,size,sha256Checksum,appProperties,capabilities'], [404]);

        return $response->status() === 404 ? null : $this->json($response);
    }

    /** @param array<string,mixed> $file */
    public static function privateFile(array $file, string $id, string $mime, ?string $parent): string
    {
        if (($file['id'] ?? null) !== $id || ($file['mimeType'] ?? null) !== $mime || ($file['trashed'] ?? null) !== false
            || isset($file['driveId']) || ! is_array($file['owners'] ?? null) || count($file['owners']) !== 1
            || ! is_array($file['owners'][0] ?? null) || ($file['owners'][0]['me'] ?? null) !== true || ! is_array($file['permissions'] ?? null) || count($file['permissions']) !== 1 || ! is_array($file['permissions'][0] ?? null)
            || ($mime === self::FOLDER && ($file['capabilities']['canAddChildren'] ?? null) !== true)) {
            throw new DriveFailure('destination_not_private');
        }
        $permission = self::text($file['owners'][0], 'permissionId', 128);
        $p = $file['permissions'][0];
        if (($p['id'] ?? null) !== $permission || ($p['type'] ?? null) !== 'user' || ($p['role'] ?? null) !== 'owner'
            || ($parent !== null && ($file['parents'] ?? null) !== [$parent])) {
            throw new DriveFailure('destination_not_private');
        }

        return hash('sha256', SavedReport::encode(['id' => $id, 'name' => self::text($file, 'name', 120), 'permission_id' => $permission,
            'mime' => $mime, 'parents' => $file['parents'] ?? [], 'private' => true]));
    }

    /** @param array<string,mixed> $metadata */
    public function create(#[\SensitiveParameter] string $access, array $metadata, ?string $bytes): void
    {
        if ($bytes === null) {
            $this->request('POST', self::FILES, $access, $metadata, [409], json: true);

            return;
        }
        if (strlen($bytes) > SavedReport::LIMIT) {
            throw new DriveFailure('report_too_large');
        }
        $boundary = 'hwo-drive-export-boundary';
        $body = '--'.$boundary."\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".json_encode($metadata, JSON_THROW_ON_ERROR)
            ."\r\n--".$boundary."\r\nContent-Type: application/json\r\n\r\n".$bytes."\r\n--".$boundary."--\r\n";
        $this->request('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart', $access, [], [409], $body, 'multipart/related; boundary='.$boundary);
    }

    /** @param array<string,mixed> $file */
    public function verifyContent(#[\SensitiveParameter] string $access, array $file, string $id, string $sha, int $size, string $reference): void
    {
        if (($file['size'] ?? null) !== (string) $size || ! self::properties($file['appProperties'] ?? null, $reference, $sha)) {
            throw new DriveFailure('remote_content_conflict');
        }
        $actual = $file['sha256Checksum'] ?? null;
        if ($actual === null) {
            $body = $this->request('GET', self::FILES.'/'.self::identifier($id), $access, ['alt' => 'media'])->body();
            $actual = hash('sha256', $body);
        }
        if (! is_string($actual) || ! hash_equals($sha, $actual)) {
            throw new DriveFailure('remote_content_conflict');
        }
    }

    /** @param array<string,mixed> $data
     * @param list<int> $allowed */
    private function request(string $method, string $url, #[\SensitiveParameter] string $access, #[\SensitiveParameter] array $data = [], array $allowed = [], #[\SensitiveParameter] ?string $body = null, string $type = '', bool $json = false): Response
    {
        self::requireMock();
        if ($this->permit === null) {
            throw new DriveFailure('operational_permit_required');
        }
        try {
            return $this->permit->call(fn () => $this->sendRequest($method, $url, $access, $data, $allowed, $body, $type, $json));
        } catch (OperationBlocked) {
            throw new DriveFailure('operations_held');
        }
    }

    /** @param array<string,mixed> $data
     * @param list<int> $allowed */
    private function sendRequest(string $method, string $url, #[\SensitiveParameter] string $access, #[\SensitiveParameter] array $data = [], array $allowed = [], #[\SensitiveParameter] ?string $body = null, string $type = '', bool $json = false): Response
    {
        self::requireMock();
        Http::preventStrayRequests();
        try {
            $client = Http::connectTimeout(3)->timeout(10)->withOptions(['allow_redirects' => false]);
            if ($access !== '') {
                $client = $client->withToken($access);
            }
            if ($body !== null) {
                $response = $client->withBody($body, $type)->send($method, $url);
            } elseif ($method === 'GET') {
                $response = $client->get($url, $data);
            } else {
                $response = ($json ? $client->asJson() : $client->asForm())->post($url, $data);
            }
        } catch (Throwable) {
            throw new DriveFailure('transport_unknown');
        }
        if (strlen($response->body()) > SavedReport::LIMIT) {
            throw new DriveFailure('response_too_large');
        }
        if (in_array($response->status(), [401, 403], true) || $response->status() === 400) {
            throw new DriveFailure('reconnect_required');
        }
        if (! $response->successful() && ! in_array($response->status(), $allowed, true)) {
            throw new DriveFailure('transport_unknown');
        }

        return $response;
    }

    /** @return array<string,mixed> */
    private function json(#[\SensitiveParameter] Response $response): array
    {
        try {
            $data = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new DriveFailure('invalid_response');
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new DriveFailure('invalid_response');
        }

        return $data;
    }

    /** @return array{access:string,refresh:string,expires:int} */
    private function tokens(#[\SensitiveParameter] Response $response, #[\SensitiveParameter] string $prior): array
    {
        $data = $this->json($response);
        $cleanup = null;
        foreach ([$data['refresh_token'] ?? null, $data['access_token'] ?? null, $prior] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && strlen($candidate) <= 4096 && ! preg_match('/[\x00-\x1f\x7f]/', $candidate)) {
                $cleanup = $candidate;
                break;
            }
        }
        try {
            $scopes = explode(' ', self::text($data, 'scope', 512));
            $scopes = array_map(fn (string $scope): string => $scope === 'https://www.googleapis.com/auth/userinfo.email' ? 'email' : $scope, $scopes);
            sort($scopes);
            $expected = explode(' ', self::SCOPE);
            sort($expected);
            if ($scopes !== $expected || ($data['token_type'] ?? null) !== 'Bearer' || ! is_int($data['expires_in'] ?? null)
                || $data['expires_in'] < 60 || $data['expires_in'] > 86400) {
                throw new DriveFailure('unapproved_scope_or_token');
            }

            return ['access' => self::text($data, 'access_token', 4096), 'refresh' => isset($data['refresh_token']) ? self::text($data, 'refresh_token', 4096) : $prior,
                'expires' => now()->getTimestamp() + $data['expires_in']];
        } catch (DriveFailure $error) {
            if ($cleanup === null) {
                throw new DriveFailure('connection_rejected_revoke_unconfirmed');
            }
            try {
                $this->revoke($cleanup);
            } catch (DriveFailure) {
                throw new DriveFailure('connection_rejected_revoke_unconfirmed');
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $data */
    private static function text(#[\SensitiveParameter] array $data, string $key, int $max): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || $value === '' || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new DriveFailure('invalid_response');
        }

        return $value;
    }

    public static function properties(mixed $properties, string $reference, string $sha): bool
    {
        return is_array($properties) && count($properties) === 2 && ($properties['halalos'] ?? null) === $reference && ($properties['sha256'] ?? null) === $sha;
    }

    public static function identifier(string $id): string
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id)) {
            throw new DriveFailure('invalid_remote_id');
        }

        return $id;
    }
}
