<?php

use App\Domain\Integrations\Drive\DriveExports;
use App\Domain\Integrations\Drive\DriveFailure;
use App\Domain\Integrations\Drive\GoogleDrive;
use App\Domain\Integrations\Drive\SavedReport;
use App\Domain\Reporting\DashboardSnapshot;
use App\Jobs\ExportDriveReport;
use App\Models\User;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DriveFixtures as D;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->owner = User::factory()->create();
    $this->wire = new D;
    $this->wire->fake();
    $this->drive = app(DriveExports::class);
});

afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});

test('drive is disabled outside explicit synthetic mock runtime with an external epoch', function () {
    config(['drive_export.mode' => 'disabled']);
    expect(fn () => $this->drive->begin($this->owner, 'session'))->toThrow(DriveFailure::class, 'disabled_or_restore_held');
    $this->drive->run('99999');
    $this->drive->revoke('99999');
    $this->drive->tick();
    $this->artisan('drive:tick')->assertSuccessful();
    Http::assertNothingSent();
    config(['drive_export.mode' => 'mock', 'drive_export.epoch' => null]);
    expect(GoogleDrive::enabled())->toBeFalse();
});

test('oauth uses approved exact scopes pkce and one owner session bound callback', function () {
    $start = $this->drive->begin($this->owner, 'session-one');
    parse_str(parse_url($start['authorization'], PHP_URL_QUERY), $query);
    expect($query['scope'])->toBe(GoogleDrive::SCOPE)->and($query['code_challenge_method'])->toBe('S256');
    expect(fn () => $this->drive->finish($this->owner, 'session-two', $start['state'], 'code'))->toThrow(DriveFailure::class, 'invalid_callback');
    $c = $this->drive->finish($this->owner, 'session-one', $start['state'], 'code');
    expect($c)->toMatchArray(['email' => 'personal@example.test', 'status' => 'account_review', 'folder_id' => null]);
    expect($this->wire->creates)->toBe(0);
    expect(fn () => $this->drive->finish($this->owner, 'session-one', $start['state'], 'code'))->toThrow(DriveFailure::class, 'invalid_callback');
    expect(fn () => $this->drive->begin($this->owner, 'session'))->toThrow(DriveFailure::class, 'disconnect_first');
    $this->assertDatabaseCount('drive_connections', 1);
    $secret = DB::table('drive_secrets')->value('ciphertext');
    expect($secret)->not->toContain('synthetic-access')->and(Crypt::decryptString($secret))->toContain('synthetic-access');
    expect(DB::table('drive_controls')->value('oauth_verifier'))->toBeNull();
});

test('expired denied malformed and incorrectly decrypted callbacks cannot connect', function () {
    $start = $this->drive->begin($this->owner, 'session');
    $this->travel(11)->minutes();
    expect(fn () => $this->drive->finish($this->owner, 'session', $start['state'], 'code'))->toThrow(DriveFailure::class, 'invalid_callback');
    expect(fn () => $this->drive->finish($this->owner, 'session', $start['state'], ''))->toThrow(DriveFailure::class, 'invalid_callback');
    $start = $this->drive->begin($this->owner, 'session');
    DB::table('drive_controls')->update(['oauth_verifier' => 'invalid-cipher']);
    expect(fn () => $this->drive->finish($this->owner, 'session', $start['state'], 'code'))->toThrow(DriveFailure::class, 'reconnect_required');
    $this->assertDatabaseCount('drive_connections', 0);
});

test('wrong school account can be rejected before any destination or report is created', function () {
    $this->wire->identity['email'] = 'school@example.test';
    $c = $this->wire->connect($this->owner);
    expect($c['email'])->toBe('school@example.test');
    $this->drive->disconnect($this->owner, $c['id']);
    $this->drive->disconnect($this->owner, $c['id']);
    expect($this->wire->creates)->toBe(0)->and($this->drive->connection($this->owner, $c['id']))->toMatchArray(['status' => 'disconnected', 'revoke_state' => 'confirmed']);
    $this->assertDatabaseCount('drive_secrets', 0);
});

test('explicit account and destination report confirmation queues only immutable owner selected bytes', function () {
    $c = $this->wire->ready($this->owner);
    $id = D::snapshot($this->owner);
    $artifact = SavedReport::make($this->owner, $id);
    expect(SavedReport::make($this->owner, $id)->bytes)->toBe($artifact->bytes);
    $review = $this->drive->review($this->owner, $id);
    expect($review)->toMatchArray(['email' => $c['email'], 'target_id' => $c['folder_id'], 'content_hash' => $artifact->sha256, 'content_bytes' => strlen($artifact->bytes)]);
    expect($review['manifest']['coverage'])->toContain('Complete selected');
    $r = $this->drive->confirm($this->owner, 'confirmed-report-01', $review['review_token'], true);
    expect($r['state'])->toBe('queued')->and($this->wire->creates)->toBe(1);
    (new ExportDriveReport($r['id']))->handle($this->drive);
    (new ExportDriveReport($r['id']))->handle($this->drive);
    expect($this->drive->inspect($this->owner, 'confirmed-report-01')['state'])->toBe('uploaded');
    expect($this->wire->bodies[$r['remote_id']])->toBe($artifact->bytes)->and($this->wire->creates)->toBe(2);
    expect($this->wire->files[$r['remote_id']]['parents'])->toBe([$c['folder_id']]);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/permissions'));
    $this->assertDatabaseCount('journal_entries', 0);
    $job = serialize(new ExportDriveReport($r['id']));
    expect($job)->not->toContain('synthetic-access', $c['email'], $artifact->bytes);
});

test('committed exact confirmation recovers before review expiry disconnect or capacity validation', function () {
    $c = $this->wire->ready($this->owner);
    $review = $this->drive->review($this->owner, D::snapshot($this->owner));
    $first = $this->drive->confirm($this->owner, 'receipt-recovery-01', $review['review_token'], true);
    $this->drive->disconnect($this->owner, $c['id']);
    $this->travel(2)->days();
    expect($this->drive->confirm($this->owner, 'receipt-recovery-01', $review['review_token'], true)['id'])->toBe($first['id']);
    expect(fn () => $this->drive->confirm($this->owner, 'receipt-recovery-01', $review['review_token'], false))->toThrow(DriveFailure::class, 'request_key_conflict');
    $this->drive->run($first['id']);
    expect($this->drive->inspect($this->owner, 'receipt-recovery-01')['state'])->toBe('cancelled');
});

test('review expiry explicit acknowledgement and replacement invalidate uncommitted selections', function () {
    $this->wire->ready($this->owner);
    $id = D::snapshot($this->owner);
    $review = $this->drive->review($this->owner, $id);
    expect(fn () => $this->drive->confirm($this->owner, 'no-acknowledge-01', $review['review_token'], false))->toThrow(DriveFailure::class, 'review_expired_or_changed');
    $this->drive->review($this->owner, $id);
    expect(fn () => $this->drive->confirm($this->owner, 'stale-review-01', $review['review_token'], true))->toThrow(DriveFailure::class, 'review_expired_or_changed');
    $review = $this->drive->review($this->owner, $id);
    $this->travel(11)->minutes();
    expect(fn () => $this->drive->confirm($this->owner, 'expired-review-01', $review['review_token'], true))->toThrow(DriveFailure::class, 'review_expired_or_changed');
});

test('destination substitution or new sharing fails review and the final handoff', function () {
    $c = $this->wire->ready($this->owner);
    $id = D::snapshot($this->owner);
    $review = $this->drive->review($this->owner, $id);
    $this->wire->files[$c['folder_id']]['name'] = 'Changed destination';
    expect(fn () => $this->drive->confirm($this->owner, 'changed-target-01', $review['review_token'], true))->toThrow(DriveFailure::class, 'destination_changed');
    $r = D::queued($this->owner, $id);
    $this->wire->files[$c['folder_id']]['permissions'][] = ['id' => 'public', 'type' => 'anyone', 'role' => 'reader'];
    $this->drive->run($r['id']);
    expect($this->drive->inspect($this->owner, $r['request_key']))->toMatchArray(['state' => 'conflict', 'safe_code' => 'destination_not_private']);
    expect($this->wire->creates)->toBe(1);
});

test('unknown committed uploads reconcile the same remote ID after lost response without duplicate create', function () {
    $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    $this->wire->failure = 'after_create';
    $this->drive->run($r['id']);
    expect($this->drive->inspect($this->owner, $r['request_key']))->toMatchArray(['state' => 'unknown', 'safe_code' => 'transport_unknown']);
    $this->wire->failure = '';
    unset($this->wire->files[$r['remote_id']]['sha256Checksum']);
    $this->travel(2)->minutes();
    $this->drive->tick();
    expect($this->drive->inspect($this->owner, $r['request_key'])['state'])->toBe('uploaded')->and($this->wire->creates)->toBe(2);
});

test('uncommitted remote failures retry only the persisted ID and stop after three uncertain attempts', function () {
    $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    $this->wire->failure = 'before_create';
    for ($n = 0; $n < 4; $n++) {
        $this->drive->run($r['id']);
        $this->travel(6)->minutes();
    }
    expect($this->drive->inspect($this->owner, $r['request_key']))->toMatchArray(['state' => 'unknown', 'attempts' => 3, 'remote_id' => $r['remote_id']]);
    expect($this->wire->generated)->toBe(2)->and($this->wire->creates)->toBe(4);
});

test('non authoritative post create verification preserves unknown and reconciles the same file', function (string $kind, string $failure) {
    if ($kind === 'report') {
        $this->wire->ready($this->owner);
        $r = D::queued($this->owner, D::snapshot($this->owner));
    } else {
        $c = $this->wire->connect($this->owner);
        $r = $this->drive->prepare($this->owner, 'folder-parse-recovery', $c['id'], $c['generation']);
    }
    $this->wire->inspectFailureId = $r['remote_id'];
    $this->wire->inspectFailure = $failure;
    $this->drive->run($r['id']);
    expect($this->drive->inspect($this->owner, $r['request_key']))->toMatchArray(['state' => 'unknown', 'remote_id' => $r['remote_id'],
        'safe_code' => $failure === 'oversize' ? 'response_too_large' : 'invalid_response']);
    $creates = $this->wire->creates;
    $this->wire->inspectFailureId = '';
    $this->travel(2)->minutes();
    $this->drive->tick();
    expect($this->drive->inspect($this->owner, $r['request_key']))->toMatchArray(['state' => 'uploaded', 'remote_id' => $r['remote_id']]);
    expect($this->wire->creates)->toBe($creates);
})->with(['folder', 'report'])->with(['json', 'oversize']);

test('transient identity outage keeps queued and uncertain work eligible for same ID recovery', function (bool $alreadyHandedOff, bool $rotate, string $failure) {
    $c = $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    if ($alreadyHandedOff) {
        $this->wire->failure = 'after_create';
        $this->drive->run($r['id']);
        $this->wire->failure = '';
        $this->travel(2)->minutes();
    }
    if ($rotate) {
        $this->travel(61)->minutes();
        $this->wire->tokenChanges['refresh_token'] = 'synthetic-rotation-before-outage';
    }
    $this->wire->identityStatus = $failure === 'unavailable' ? 503 : 200;
    $this->wire->identityBody = match ($failure) {
        'json' => 'invalid-json',
        'oversize' => str_repeat('x', SavedReport::LIMIT + 1),
        default => null,
    };
    $this->drive->run($r['id']);
    expect($this->drive->connection($this->owner, $c['id'])['status'])->toBe('ready');
    expect($this->drive->inspect($this->owner, $r['request_key']))->toMatchArray(['state' => 'unknown', 'safe_code' => match ($failure) {
        'json' => 'invalid_response', 'oversize' => 'response_too_large', default => 'transport_unknown',
    }]);
    if ($rotate) {
        expect(Crypt::decryptString(DB::table('drive_secrets')->where('connection_id', $c['id'])->value('ciphertext')))->toContain('synthetic-rotation-before-outage');
    }
    $this->wire->identityStatus = 200;
    $this->wire->identityBody = null;
    $this->travel(6)->minutes();
    $this->drive->tick();
    expect($this->drive->inspect($this->owner, $r['request_key']))->toMatchArray(['state' => 'uploaded', 'remote_id' => $r['remote_id']]);
    expect($this->wire->creates)->toBe(2)->and($this->wire->generated)->toBe(2);
})->with([false, true])->with([false, true])->with(['unavailable', 'json', 'oversize']);

test('rejected OAuth grant scopes revoke issued credentials or report unconfirmed cleanup without admission', function (bool $revokeFails, bool $hasRefresh) {
    $start = $this->drive->begin($this->owner, 'rejected-grant-session');
    $this->wire->tokenChanges = ['scope' => GoogleDrive::SCOPE.' https://www.googleapis.com/auth/drive', 'refresh_token' => $hasRefresh ? 'synthetic-rejected-refresh' : null];
    $this->wire->failure = $revokeFails ? 'revoke' : '';
    expect(fn () => $this->drive->finish($this->owner, 'rejected-grant-session', $start['state'], 'synthetic-code'))->toThrow(DriveFailure::class,
        $revokeFails ? 'connection_rejected_revoke_unconfirmed' : 'unapproved_scope_or_token');
    Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/revoke'
        && $request['token'] === ($hasRefresh ? 'synthetic-rejected-refresh' : 'synthetic-access'));
    $this->assertDatabaseCount('drive_connections', 0);
    $this->assertDatabaseCount('drive_secrets', 0);
    expect(fn () => $this->drive->finish($this->owner, 'rejected-grant-session', $start['state'], 'synthetic-code'))->toThrow(DriveFailure::class, 'invalid_callback');
})->with([false, true])->with([false, true]);

test('rejected refreshed grants require reconnect rather than retrying revoked credentials as an identity outage', function (string $invalid, bool $revokeFails) {
    $c = $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    $this->travel(61)->minutes();
    $this->wire->tokenChanges = $invalid === 'scope' ? ['scope' => GoogleDrive::SCOPE.' extra-scope'] : ['access_token' => ''];
    $this->wire->failure = $revokeFails ? 'revoke' : '';
    $this->drive->run($r['id']);
    expect($this->drive->connection($this->owner, $c['id'])['status'])->toBe('reconnect');
    expect($this->drive->inspect($this->owner, $r['request_key']))->toMatchArray(['state' => 'reconnect',
        'safe_code' => $revokeFails ? 'connection_rejected_revoke_unconfirmed' : ($invalid === 'scope' ? 'unapproved_scope_or_token' : 'reconnect_required')]);
    Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/revoke');
    expect($this->wire->creates)->toBe(1);
    expect(Crypt::decryptString(DB::table('drive_secrets')->where('connection_id', $c['id'])->value('ciphertext')))->not->toContain('extra-scope');
})->with(['scope', 'access'])->with([false, true]);

test('remote content conflict cannot overwrite or claim success', function () {
    $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    $this->wire->failure = 'after_create';
    $this->drive->run($r['id']);
    $this->wire->files[$r['remote_id']]['sha256Checksum'] = str_repeat('b', 64);
    $this->wire->failure = '';
    $this->travel(2)->minutes();
    $this->drive->run($r['id']);
    expect($this->drive->inspect($this->owner, $r['request_key'])['state'])->toBe('conflict')->and($this->wire->creates)->toBe(2);
});

test('expired tokens refresh without losing an omitted refresh token and recheck actual identity', function () {
    $c = $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    $this->travel(61)->minutes();
    $this->wire->tokenChanges['refresh_token'] = null;
    $this->drive->run($r['id']);
    expect($this->drive->inspect($this->owner, $r['request_key'])['state'])->toBe('uploaded');
    $secret = DB::table('drive_secrets')->where('connection_id', $c['id'])->value('ciphertext');
    expect(Crypt::decryptString($secret))->toContain('synthetic-refresh');
});

test('revoked or wrong decrypted credentials fail safely and require reconnection', function (string $failure) {
    $c = $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    if ($failure === 'cipher') {
        DB::table('drive_secrets')->where('connection_id', $c['id'])->update(['ciphertext' => 'invalid']);
    } elseif ($failure === 'refresh') {
        $this->travel(61)->minutes();
        $this->wire->failure = 'refresh';
    } else {
        $this->wire->identity['email'] = 'other@example.test';
    }
    $this->drive->run($r['id']);
    expect($this->drive->inspect($this->owner, $r['request_key'])['state'])->toBe('reconnect')->and($this->wire->creates)->toBe(1);
    expect(json_encode(DB::table('drive_events')->get()))->not->toContain('synthetic-access', 'synthetic-refresh');
})->with(['cipher', 'refresh', 'identity']);

test('local disconnect survives remote revoke uncertainty and separates a reconnect from old work', function () {
    $old = $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    $this->wire->failure = 'revoke';
    $this->drive->disconnect($this->owner, $old['id']);
    expect($this->drive->connection($this->owner, $old['id'])['revoke_state'])->toBe('pending');
    $new = $this->wire->connect($this->owner);
    expect($new['id'])->not->toBe($old['id']);
    $this->drive->run($r['id']);
    expect($this->drive->inspect($this->owner, $r['request_key'])['state'])->toBe('cancelled');
    $this->drive->revoke($old['id']);
    $this->drive->revoke($old['id']);
    $this->drive->revoke($old['id']);
    expect($this->drive->connection($this->owner, $old['id'])['revoke_state'])->toBe('unconfirmed');
    expect(DB::table('drive_secrets')->pluck('connection_id')->map(strval(...))->all())->toBe([$new['id']]);
});

test('external restore epoch holds old work while preserving receipt inspection and local stop', function () {
    $c = $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    config(['drive_export.epoch' => str_repeat('b', 32)]);
    $this->drive->tick();
    expect($this->drive->inspect($this->owner, $r['request_key']))->toMatchArray(['state' => 'queued', 'restore_held' => true]);
    expect($this->drive->connection($this->owner, $c['id'])['restore_held'])->toBeTrue();
    $this->drive->disconnect($this->owner, $c['id']);
    expect($this->drive->connection($this->owner, $c['id'])['status'])->toBe('disconnected')->and($this->wire->creates)->toBe(1);
});

test('foreign owner and admin cannot inspect report connection receipt or disconnect', function () {
    $c = $this->wire->ready($this->owner);
    $id = D::snapshot($this->owner);
    $r = D::queued($this->owner, $id);
    $foreign = User::factory()->create(['is_admin' => true]);
    foreach ([fn () => SavedReport::make($foreign, $id), fn () => $this->drive->connection($foreign, $c['id']),
        fn () => $this->drive->inspect($foreign, $r['request_key']), fn () => $this->drive->disconnect($foreign, $c['id'])] as $call) {
        expect($call)->toThrow(HttpException::class);
    }
    $this->actingAs($foreign)->getJson('/drive/requests/'.$r['request_key'])->assertNotFound();
});

test('unverified owner cannot admit and handoff rechecks current verification', function () {
    $c = $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    DB::table('users')->where('id', $this->owner->id)->update(['email_verified_at' => null]);
    $this->drive->run($r['id']);
    expect($this->drive->inspect($this->owner, $r['request_key'])['state'])->toBe('unknown');
    expect(fn () => $this->drive->begin($this->owner, 'session'))->toThrow(DriveFailure::class, 'owner_unverified');
});

test('queued work expires without an upload after its explicit delivery deadline', function () {
    $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    $this->travel(2)->days();
    $this->drive->run($r['id']);
    expect($this->drive->inspect($this->owner, $r['request_key'])['state'])->toBe('expired')->and($this->wire->creates)->toBe(1);
});

test('private drive page and json routes expose actual review and safe definitive failures', function () {
    $this->get('/drive')->assertRedirect('/login');
    $c = $this->wire->ready($this->owner);
    $id = D::snapshot($this->owner);
    $this->actingAs($this->owner)->get('/drive')->assertInertia(fn (AssertableInertia $page) => $page->component('drive/Index')->where('connection.email', $c['email'])->has('snapshots', 1));
    $this->postJson('/drive/review', ['snapshot' => $id])->assertOk()->assertJsonPath('email', $c['email']);
    $this->postJson('/drive/confirm', ['idempotency_key' => 'invalid-token-01', 'review_token' => str_repeat('a', 64), 'acknowledged' => true])->assertUnprocessable()->assertJsonPath('definitive_no_write', true);
    $this->getJson('/drive/callback?error=access_denied')->assertUnprocessable();
    $this->postJson('/drive/connections/'.$c['id'].'/disconnect')->assertOk()->assertJsonPath('status', 'locally_disconnected');
    $this->postJson('/drive/connect')->assertOk()->assertJsonStructure(['authorization']);
    $this->getJson('/drive/callback?state='.str_repeat('a', 64).'&code=synthetic-code')->assertUnprocessable();
});

test('a newer oauth start supersedes an already consumed callback and rejected tokens are revoked', function () {
    $start = $this->drive->begin($this->owner, 'session');
    $new = null;
    $once = true;
    $exchanged = false;
    // The second owner action commits between leaf sends, never inside the first send's owner lock.
    Event::listen(TransactionCommitted::class, function () use (&$once, &$exchanged, &$new): void {
        if ($once && $exchanged && DB::connection()->transactionLevel() === 0) {
            $once = false;
            $new = $this->drive->begin($this->owner, 'session');
        }
    });
    Http::swap(new Factory);
    Http::fake(function ($request) use (&$exchanged) {
        if ($request->url() === 'https://oauth2.googleapis.com/token') {
            $exchanged = true;

            return Http::response(['access_token' => 'access', 'refresh_token' => 'refresh', 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => GoogleDrive::SCOPE]);
        }
        if ($request->url() === 'https://oauth2.googleapis.com/revoke') {
            return Http::response('', 200);
        }

        return Http::response($this->wire->identity);
    });
    expect(fn () => $this->drive->finish($this->owner, 'session', $start['state'], 'code'))->toThrow(DriveFailure::class, 'connection_changed');
    expect($new)->toBeArray();
    expect($this->drive->finish($this->owner, 'session', $new['state'], 'new-code')['status'])->toBe('account_review');
    $this->assertDatabaseCount('drive_connections', 1);
    Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/revoke');
});

test('credential rotation commits before later report and target validation can fail', function () {
    $c = $this->wire->ready($this->owner);
    $id = D::snapshot($this->owner);
    $this->travel(61)->minutes();
    $this->wire->tokenChanges['refresh_token'] = 'synthetic-rotated-refresh';
    unset($this->wire->files[$c['folder_id']]);
    expect(fn () => $this->drive->review($this->owner, $id))->toThrow(DriveFailure::class, 'destination_missing');
    $cipher = DB::table('drive_secrets')->where('connection_id', $c['id'])->value('ciphertext');
    expect(Crypt::decryptString($cipher))->toContain('synthetic-rotated-refresh');
});

test('final claim crash is visibly uncertain after lease expiry and never grants a fourth create', function () {
    $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    $this->wire->failure = 'before_create';
    for ($i = 0; $i < 2; $i++) {
        $this->drive->run($r['id']);
        $this->travel(6)->minutes();
    }
    $once = true;
    Event::listen(TransactionCommitted::class, function ($event) use (&$once, $r) {
        if ($once && $event->connection->transactionLevel() === 0 && DB::table('drive_operations')->where('id', $r['id'])->value('attempts') === 3) {
            $once = false;
            throw new RuntimeException('Synthetic loss after final committed claim');
        }
    });
    expect(fn () => $this->drive->run($r['id']))->toThrow(RuntimeException::class, 'Synthetic loss');
    $this->travel(3)->minutes();
    $this->drive->tick();
    expect($this->drive->inspect($this->owner, $r['request_key']))->toMatchArray(['state' => 'unknown', 'safe_code' => 'interrupted_handoff_reconcile', 'attempts' => 3]);
    expect($this->wire->creates)->toBe(3);
});

test('expired upload permission preserves uncertainty and permits only exact ID inspection', function (bool $committed) {
    $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    $this->wire->failure = $committed ? 'after_create' : 'before_create';
    $this->drive->run($r['id']);
    $count = $this->wire->creates;
    $this->travel(2)->days();
    $this->wire->failure = '';
    $this->drive->run($r['id']);
    expect($this->drive->inspect($this->owner, $r['request_key'])['state'])->toBe($committed ? 'uploaded' : 'unknown');
    expect($this->wire->creates)->toBe($count);
})->with([true, false]);

test('local disconnect remains reachable after app email verification is removed', function () {
    $c = $this->wire->ready($this->owner);
    DB::table('users')->where('id', $this->owner->id)->update(['email_verified_at' => null]);
    $this->actingAs($this->owner->fresh())->postJson('/drive/connections/'.$c['id'].'/disconnect')->assertOk();
    expect($this->drive->connection($this->owner, $c['id'])['status'])->toBe('disconnected');
});

test('Drive size preflight and final envelope reject oversize without changing ordinary saved downloads', function (string $boundary) {
    $report = ['captured_at' => '2026-10-24T00:00:00Z', 'facts' => ['base_currency' => 'QAR'], 'payload' => str_repeat('x', SavedReport::LIMIT - ($boundary === 'preflight' ? 0 : 200))];
    $id = (string) DB::table('dashboard_snapshots')->insertGetId(['owner_id' => $this->owner->id, 'reference' => (string) Str::ulid(),
        'idempotency_key' => 'large-snapshot', 'payload_hash' => str_repeat('a', 64), 'evidence' => json_encode($report, JSON_THROW_ON_ERROR), 'evidence_hash' => DashboardSnapshot::digest($report)]);
    expect(fn () => SavedReport::make($this->owner, $id))->toThrow(DriveFailure::class, 'report_too_large');
    $opened = app(DashboardSnapshot::class)->open($this->owner, $id);
    expect(hash('sha256', $opened['payload']))->toBe(hash('sha256', $report['payload']))->and($opened['captured_at'])->toBe($report['captured_at']);
    $this->actingAs($this->owner)->get('/dashboard/snapshots/'.$id.'/export')->assertOk();
})->with(['preflight', 'envelope']);

test('only one destination operation is admitted and exact folder retries recover its receipt', function () {
    $c = $this->wire->connect($this->owner);
    expect(fn () => $this->drive->review($this->owner, D::snapshot($this->owner)))->toThrow(DriveFailure::class, 'destination_not_ready');
    $r = $this->drive->prepare($this->owner, 'folder-retry-01', $c['id'], $c['generation']);
    expect($this->drive->prepare($this->owner, 'folder-retry-01', $c['id'], $c['generation'])['id'])->toBe($r['id']);
    expect(fn () => $this->drive->prepare($this->owner, 'folder-another-01', $c['id'], $c['generation']))->toThrow(DriveFailure::class, 'destination_already_queued');
    $this->drive->run($r['id']);
    expect(fn () => $this->drive->prepare($this->owner, 'folder-another-02', $c['id'], $c['generation']))->toThrow(DriveFailure::class, 'review_account_first');
});

test('valid authenticated oauth callback consumes local state without retaining query credentials in session', function () {
    $this->actingAs($this->owner);
    $started = $this->postJson('/drive/connect')->assertOk();
    $start = $started->json();
    $cookie = $started->getCookie(config('session.cookie'));
    expect($cookie)->not->toBeNull();
    $this->withCookie($cookie->getName(), $cookie->getValue())->get('/drive/callback?state='.$start['state'].'&code=synthetic-successful-callback-code')
        ->assertOk()->assertJsonPath('email', 'personal@example.test')->assertSessionMissing('url.intended');
    expect(json_encode(session()->all()))->not->toContain('synthetic-successful-callback-code', $start['state']);
    expect(DB::table('drive_controls')->where('owner_id', $this->owner->id)->value('oauth_hash'))->toBeNull();
});
