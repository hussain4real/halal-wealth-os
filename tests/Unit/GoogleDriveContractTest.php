<?php

use App\Domain\Integrations\Drive\DriveFailure;
use App\Domain\Integrations\Drive\GoogleDrive;
use App\Domain\Integrations\Drive\SavedReport;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\DriveFixtures;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->wire = new DriveFixtures;
    $this->wire->fake();
    $this->google = app(GoogleDrive::class);
});

test('google boundary accepts only bounded bearer tokens with the approved exact scopes', function (array $change) {
    $this->wire->tokenChanges = $change;
    expect(fn () => $this->google->exchange('code', 'verifier'))->toThrow(DriveFailure::class);
})->with([
    'extra scope' => [['scope' => GoogleDrive::SCOPE.' https://www.googleapis.com/auth/drive']],
    'missing scope' => [['scope' => 'openid email']],
    'bad type' => [['token_type' => 'Basic']],
    'string expiry' => [['expires_in' => '3600']],
    'short expiry' => [['expires_in' => 1]],
    'huge expiry' => [['expires_in' => 1000000]],
    'empty access' => [['access_token' => '']],
    'oversize access' => [['access_token' => str_repeat('x', 4097)]],
    'control access' => [['access_token' => "x\ny"]],
]);

test('google refresh accepts the documented email scope alias and preserves omitted refresh credentials', function () {
    $this->wire->tokenChanges = ['scope' => str_replace(' email', ' https://www.googleapis.com/auth/userinfo.email', GoogleDrive::SCOPE), 'refresh_token' => null];
    $tokens = $this->google->refresh('synthetic-prior-refresh');
    expect($tokens['refresh'])->toBe('synthetic-prior-refresh')->and($tokens['access'])->toBe('synthetic-access');
    expect(fn () => $this->google->refresh(''))->toThrow(DriveFailure::class, 'reconnect_required');
});

test('google identity requires a real verified response and never infers account kind from domain', function (array $change) {
    $this->wire->identity = [...$this->wire->identity, ...$change];
    expect(fn () => $this->google->identity('synthetic-access'))->toThrow(DriveFailure::class);
})->with([
    [['sub' => '']], [['email' => 'invalid']], [['email_verified' => false]], [['email_verified' => 'true']], [['sub' => str_repeat('x', 256)]],
]);

test('fake-only wire rejects unfaked endpoints redirects oversized and malformed responses without leaking bodies', function (string $body, int $status, string $reason) {
    Http::swap(new Factory);
    Http::fake(['https://openidconnect.googleapis.com/v1/userinfo' => Http::response($body, $status)]);
    expect(fn () => $this->google->identity('synthetic-access'))->toThrow(DriveFailure::class, $reason);
})->with([
    ['synthetic-secret', 302, 'transport_unknown'],
    ['synthetic-secret', 401, 'reconnect_required'],
    ['synthetic-secret', 403, 'reconnect_required'],
    ['synthetic-secret', 429, 'transport_unknown'],
    ['synthetic-secret', 503, 'transport_unknown'],
    ['not-json', 200, 'invalid_response'],
    ['[]', 200, 'invalid_response'],
    [str_repeat('s', SavedReport::LIMIT + 1), 200, 'response_too_large'],
]);

test('unmatched mock requests fail closed rather than reaching Google', function () {
    Http::swap(new Factory);
    expect(fn () => $this->google->identity('synthetic-access'))->toThrow(DriveFailure::class, 'transport_unknown');
});

test('remote IDs and missing generateIds payloads are validated at the boundary', function () {
    expect(fn () => GoogleDrive::identifier('../not-an-id'))->toThrow(DriveFailure::class, 'invalid_remote_id');
    Http::swap(new Factory);
    Http::fake(['*' => Http::response(['ids' => []])]);
    expect(fn () => $this->google->generate('synthetic-access'))->toThrow(DriveFailure::class, 'invalid_remote_id');
});

function privateDriveFolder(): array
{
    return ['id' => 'private-folder', 'name' => 'Private folder', 'mimeType' => GoogleDrive::FOLDER,
        'trashed' => false, 'parents' => ['actual-root-id'], 'owners' => [['me' => true, 'permissionId' => 'drive-permission-not-oidc-sub']],
        'permissions' => [['id' => 'drive-permission-not-oidc-sub', 'type' => 'user', 'role' => 'owner']], 'capabilities' => ['canAddChildren' => true]];
}

test('actual file privacy uses Drive me and permission identity independently of OIDC sub', function () {
    $file = privateDriveFolder();
    $first = GoogleDrive::privateFile($file, 'private-folder', GoogleDrive::FOLDER, null);
    expect($first)->toHaveLength(64);
    $file['name'] = 'Changed name';
    expect(GoogleDrive::privateFile($file, 'private-folder', GoogleDrive::FOLDER, null))->not->toBe($first);
});

test('missing foreign inherited and shared permissions or changed destination fail closed', function (array $change) {
    $file = [...privateDriveFolder(), ...$change];
    expect(fn () => GoogleDrive::privateFile($file, 'private-folder', GoogleDrive::FOLDER, null))->toThrow(DriveFailure::class, 'destination_not_private');
})->with([
    [['id' => 'other-id']], [['mimeType' => 'text/plain']], [['trashed' => true]], [['driveId' => 'school-shared-drive']],
    [['owners' => []]], [['owners' => [['me' => false, 'permissionId' => 'other-owner']]]],
    [['permissions' => []]], [['permissions' => [['id' => 'public', 'type' => 'anyone', 'role' => 'reader']]]],
    [['permissions' => [['id' => 'drive-permission-not-oidc-sub', 'type' => 'user', 'role' => 'reader']]]],
    [['permissions' => [['id' => 'drive-permission-not-oidc-sub', 'type' => 'group', 'role' => 'owner']]]],
    [['permissions' => [['id' => 'drive-permission-not-oidc-sub', 'type' => 'user', 'role' => 'owner'], ['id' => 'foreign', 'type' => 'user', 'role' => 'reader']]]],
    [['capabilities' => ['canAddChildren' => false]]],
]);

test('remote file parent substitution and mismatched size metadata or bytes never prove upload', function () {
    $file = privateDriveFolder();
    expect(fn () => GoogleDrive::privateFile($file, 'private-folder', GoogleDrive::FOLDER, 'wrong-parent'))->toThrow(DriveFailure::class, 'destination_not_private');
    expect(fn () => $this->google->verifyContent('access', [], 'private-folder', str_repeat('a', 64), 1, 'reference'))->toThrow(DriveFailure::class, 'remote_content_conflict');
    expect(fn () => $this->google->create('access', [], str_repeat('a', SavedReport::LIMIT + 1)))->toThrow(DriveFailure::class, 'report_too_large');
});

test('duplicate create returns 409 for inspection instead of allocating a different remote identity', function () {
    $metadata = ['id' => 'stable-folder-id', 'name' => 'HalalOS private reports', 'mimeType' => GoogleDrive::FOLDER];
    $this->google->create('access', $metadata, null);
    $this->google->create('access', $metadata, null);
    expect($this->wire->files)->toHaveCount(1)->and($this->wire->creates)->toBe(2);
});
