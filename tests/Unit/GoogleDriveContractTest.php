<?php

use App\Domain\Integrations\Drive\DriveFailure;
use App\Domain\Integrations\Drive\GoogleDrive;
use App\Domain\Integrations\Drive\SavedReport;
use App\Domain\Operations\DriveAction;
use App\Domain\Operations\OperationPermit;
use App\Domain\Operations\Operations;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\DriveFixtures;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    RefreshDatabaseState::$migrated = false;
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->owner = User::factory()->create();
    $this->wire = new DriveFixtures;
    $this->wire->fake();
    $this->google = app(GoogleDrive::class);
});

afterEach(function () {
    try {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    } finally {
        RefreshDatabaseState::$migrated = false;
    }
});

/** @template T
 * @param callable(GoogleDrive):T $work
 * @return T */
function admittedDriveContract(User $owner, callable $work, DriveAction $action = DriveAction::Connect): mixed
{
    return app(Operations::class)->drive($owner, $action, fn (OperationPermit $permit) => $work(app(GoogleDrive::class)->scoped($permit)));
}

test('google boundary accepts only bounded bearer tokens with the approved exact scopes', function (array $change) {
    $this->wire->tokenChanges = $change;
    expect(fn () => admittedDriveContract($this->owner, fn (GoogleDrive $google) => $google->exchange('code', 'verifier')))->toThrow(DriveFailure::class);
    Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/revoke' && $request['token'] === 'synthetic-refresh');
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

test('rejected token response without a safe cleanup credential is explicitly unconfirmed', function () {
    $this->wire->tokenChanges = ['access_token' => '', 'refresh_token' => "unsafe\ncredential"];
    expect(fn () => admittedDriveContract($this->owner, fn (GoogleDrive $google) => $google->exchange('code', 'verifier')))->toThrow(DriveFailure::class, 'connection_rejected_revoke_unconfirmed');
    Http::assertNotSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/revoke');
});

test('rejected refresh response can clean up with a bounded prior token when returned credentials are unusable', function () {
    $this->wire->tokenChanges = ['access_token' => '', 'refresh_token' => "unsafe\ncredential"];
    expect(fn () => admittedDriveContract($this->owner, fn (GoogleDrive $google) => $google->refresh('synthetic-known-prior')))->toThrow(DriveFailure::class, 'invalid_response');
    Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/revoke' && $request['token'] === 'synthetic-known-prior');
});

test('rejected grant exceptions redact response and credential arguments even when trace arguments are enabled', function () {
    $previous = ini_set('zend.exception_ignore_args', '0');
    try {
        $this->wire->tokenChanges = ['scope' => 'unapproved-scope', 'access_token' => 'synthetic-private-access', 'refresh_token' => 'synthetic-private-refresh'];
        try {
            admittedDriveContract($this->owner, fn (GoogleDrive $google) => $google->exchange('synthetic-private-code', 'synthetic-private-verifier'));
            $this->fail('Rejected token response must not be admitted');
        } catch (DriveFailure $error) {
            expect($error->reason)->toBe('unapproved_scope_or_token');
            expect(json_encode($error->getTrace(), JSON_THROW_ON_ERROR).(string) $error)->not->toContain('synthetic-private-access', 'synthetic-private-refresh', 'synthetic-private-code', 'synthetic-private-verifier');
        }
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/revoke');
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
});

test('google refresh accepts the documented email scope alias and preserves omitted refresh credentials', function () {
    $this->wire->tokenChanges = ['scope' => str_replace(' email', ' https://www.googleapis.com/auth/userinfo.email', GoogleDrive::SCOPE), 'refresh_token' => null];
    $tokens = admittedDriveContract($this->owner, fn (GoogleDrive $google) => $google->refresh('synthetic-prior-refresh'));
    expect($tokens['refresh'])->toBe('synthetic-prior-refresh')->and($tokens['access'])->toBe('synthetic-access');
    expect(fn () => $this->google->refresh(''))->toThrow(DriveFailure::class, 'reconnect_required');
});

test('google identity requires a real verified response and never infers account kind from domain', function (array $change) {
    $this->wire->identity = [...$this->wire->identity, ...$change];
    $reason = array_key_exists('sub', $change) ? 'invalid_response' : 'unverified_identity';
    expect(fn () => admittedDriveContract($this->owner, fn (GoogleDrive $google) => $google->identity('synthetic-access')))->toThrow(DriveFailure::class, $reason);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://openidconnect.googleapis.com/v1/userinfo');
})->with([
    [['sub' => '']], [['email' => 'invalid']], [['email_verified' => false]], [['email_verified' => 'true']], [['sub' => str_repeat('x', 256)]],
]);

test('fake-only wire rejects unfaked endpoints redirects oversized and malformed responses without leaking bodies', function (string $body, int $status, string $reason) {
    Http::swap(new Factory);
    Http::fake(['https://openidconnect.googleapis.com/v1/userinfo' => Http::response($body, $status)]);
    expect(fn () => admittedDriveContract($this->owner, fn (GoogleDrive $google) => $google->identity('synthetic-access')))->toThrow(DriveFailure::class, $reason);
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
    expect(fn () => admittedDriveContract($this->owner, fn (GoogleDrive $google) => $google->identity('synthetic-access')))->toThrow(DriveFailure::class, 'transport_unknown');
});

test('remote IDs and missing generateIds payloads are validated at the boundary', function () {
    expect(fn () => GoogleDrive::identifier('../not-an-id'))->toThrow(DriveFailure::class, 'invalid_remote_id');
    Http::swap(new Factory);
    Http::fake(['*' => Http::response(['ids' => []])]);
    expect(fn () => admittedDriveContract($this->owner, fn (GoogleDrive $google) => $google->generate('synthetic-access'), DriveAction::Prepare))->toThrow(DriveFailure::class, 'invalid_remote_id');
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
    admittedDriveContract($this->owner, function (GoogleDrive $google) use ($metadata): void {
        $google->create('access', $metadata, null);
        $google->create('access', $metadata, null);
    }, DriveAction::Prepare);
    expect($this->wire->files)->toHaveCount(1)->and($this->wire->creates)->toBe(2);
});

test('unscoped Google transport cannot consume a fake or live handoff', function () {
    expect(fn () => $this->google->identity('synthetic-access'))->toThrow(DriveFailure::class, 'operational_permit_required');
    Http::assertNothingSent();
});
