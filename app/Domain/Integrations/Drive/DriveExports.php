<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Drive;

use App\Domain\Ledger\LedgerInput;
use App\Models\User;
use App\Support\Release\OwnerExport;
use App\Support\Release\OwnerExportLimit;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/** Single writer of Drive lifecycle. Durable intents are authoritative; queue hints contain IDs only. */
final class DriveExports
{
    public function __construct(private readonly GoogleDrive $google) {}

    /** @return array{authorization:string,state:string} */
    public function begin(User $owner, #[\SensitiveParameter] string $session): array
    {
        GoogleDrive::requireMock();
        $state = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $this->locked($owner, function (stdClass $control) use ($owner, $session, $state, $verifier): void {
            $this->capacity($owner, 'drive_connections', 16);
            if (! $control->stopped) {
                throw new DriveFailure('disconnect_first');
            }
            DB::table('drive_controls')->where('owner_id', $owner->id)->update(['generation' => bin2hex(random_bytes(16)), 'oauth_hash' => hash('sha256', $state),
                'session_hash' => hash('sha256', $session), 'oauth_verifier' => Crypt::encryptString($verifier), 'oauth_expires' => now()->addMinutes(10)]);
        });

        return ['authorization' => $this->google->authorization($state, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')), 'state' => $state];
    }

    /** @return array<string,mixed> */
    public function finish(User $owner, #[\SensitiveParameter] string $session, #[\SensitiveParameter] string $state, #[\SensitiveParameter] string $code): array
    {
        GoogleDrive::requireMock();
        if (strlen($code) < 1 || strlen($code) > 2048) {
            throw new DriveFailure('invalid_callback');
        }
        [$verifier, $generation] = $this->locked($owner, function (stdClass $c) use ($owner, $session, $state): array {
            if (! $c->stopped || $c->oauth_hash === null || ! hash_equals($c->oauth_hash, hash('sha256', $state))
                || ! hash_equals($c->session_hash ?? '', hash('sha256', $session)) || now()->gte($c->oauth_expires)) {
                throw new DriveFailure('invalid_callback');
            }
            $cipher = $c->oauth_verifier;
            DB::table('drive_controls')->where('owner_id', $owner->id)->update(['oauth_hash' => null, 'session_hash' => null, 'oauth_verifier' => null, 'oauth_expires' => null]);

            return [$cipher, $c->generation];
        });
        try {
            $verifier = Crypt::decryptString($verifier);
        } catch (Throwable) {
            throw new DriveFailure('reconnect_required');
        }
        $tokens = $this->google->exchange($code, $verifier);
        try {
            $identity = $this->google->identity($tokens['access']);

            return $this->locked($owner, function (stdClass $c) use ($owner, $generation, $tokens, $identity): array {
                if (! $c->stopped || $c->generation !== $generation) {
                    throw new DriveFailure('connection_changed');
                }
                $this->capacity($owner, 'drive_connections', 16);
                $generation = bin2hex(random_bytes(16));
                $id = DB::table('drive_connections')->insertGetId(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(),
                    'subject' => $identity['subject'], 'email' => $identity['email'], 'generation' => $generation,
                    'epoch' => $this->epoch(), 'status' => 'account_review']);
                DB::table('drive_controls')->where('owner_id', $owner->id)->update(['active_id' => $id, 'generation' => $generation, 'stopped' => false, 'review' => null, 'review_hash' => null, 'review_expires' => null]);
                $this->storeTokens($owner, (string) $id, $tokens);
                $this->event($owner, (string) $id, null, 'account_review');
                $this->headroom($owner);

                return $this->connection($owner, (string) $id);
            });
        } catch (DriveFailure $error) {
            try {
                $this->google->revoke($tokens['refresh'] === '' ? $tokens['access'] : $tokens['refresh']);
            } catch (DriveFailure) {
                throw new DriveFailure('connection_rejected_revoke_unconfirmed');
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function prepare(User $owner, string $key, string $connection, string $generation): array
    {
        $input = ['connection' => $connection, 'generation' => $generation, 'acknowledge_account' => true];
        if ($cached = $this->cached($owner, $key, $input)) {
            return $this->receipt($cached);
        }
        $this->warm($owner, $connection);

        return $this->locked($owner, function (stdClass $c) use ($owner, $key, $connection, $generation, $input): array {
            if ($row = $this->cached($owner, $key, $input)) {
                return $this->receipt($row);
            }
            $link = $this->eligible($owner, $c, $connection, $generation);
            if ($link->status !== 'account_review') {
                throw new DriveFailure('review_account_first');
            }
            if (DB::table('drive_operations')->where('owner_id', $owner->id)->where('connection_id', $connection)->where('kind', 'folder')->exists()) {
                throw new DriveFailure('destination_already_queued');
            }
            $this->capacity($owner, 'drive_operations', 64);
            $access = $this->access($owner, $link);
            $remote = $this->google->generate($access);

            return $this->admit($owner, $key, $input, $link, 'folder', null, str_repeat('0', 64), 0, '', '', $remote);
        });
    }

    /** @return array<string,mixed> */
    public function review(User $owner, string $snapshot): array
    {
        $this->warm($owner);

        return $this->locked($owner, function (stdClass $c) use ($owner, $snapshot): array {
            $link = $this->eligible($owner, $c, (string) $c->active_id, $c->generation);
            if ($link->status !== 'ready' || $link->folder_id === null) {
                throw new DriveFailure('destination_not_ready');
            }
            $artifact = SavedReport::make($owner, $snapshot);
            $file = $this->google->inspect($this->access($owner, $link), $link->folder_id);
            if ($file === null) {
                throw new DriveFailure('destination_missing');
            }
            $fingerprint = GoogleDrive::privateFile($file, $link->folder_id, GoogleDrive::FOLDER, null);
            $token = bin2hex(random_bytes(32));
            $review = ['snapshot_id' => $snapshot, 'connection_id' => (string) $link->id, 'generation' => $c->generation,
                'target_id' => $link->folder_id, 'target_hash' => $fingerprint, 'content_hash' => $artifact->sha256, 'content_bytes' => strlen($artifact->bytes)];
            DB::table('drive_controls')->where('owner_id', $owner->id)->update(['review' => json_encode($review, JSON_THROW_ON_ERROR), 'review_hash' => hash('sha256', $token), 'review_expires' => now()->addMinutes(10)]);

            return [...$review, 'review_token' => $token, 'email' => $link->email, 'folder_name' => $file['name'],
                'expires_at' => now()->addMinutes(10)->toIso8601String(), 'manifest' => $artifact->manifest, 'mode' => 'synthetic_mock_only'];
        });
    }

    /** @return array<string,mixed> */
    public function confirm(User $owner, string $key, string $token, bool $acknowledged): array
    {
        $input = ['review_token_hash' => hash('sha256', $token), 'acknowledged' => $acknowledged];
        if ($cached = $this->cached($owner, $key, $input)) {
            return $this->receipt($cached);
        }
        $this->warm($owner);

        return $this->locked($owner, function (stdClass $c) use ($owner, $key, $token, $acknowledged, $input): array {
            if ($row = $this->cached($owner, $key, $input)) {
                return $this->receipt($row);
            }
            if (! $acknowledged || $c->review === null || ! hash_equals($c->review_hash ?? '', hash('sha256', $token)) || now()->gte($c->review_expires)) {
                throw new DriveFailure('review_expired_or_changed');
            }
            $review = json_decode($c->review, true, flags: JSON_THROW_ON_ERROR);
            $link = $this->eligible($owner, $c, $review['connection_id'], $review['generation']);
            $artifact = SavedReport::make($owner, $review['snapshot_id']);
            if ($artifact->sha256 !== $review['content_hash'] || strlen($artifact->bytes) !== $review['content_bytes']) {
                throw new DriveFailure('content_changed');
            }
            $access = $this->access($owner, $link);
            $this->target($access, $link, $review['target_hash']);
            $remote = $this->google->generate($access);
            $receipt = $this->admit($owner, $key, $input, $link, 'report', $review['snapshot_id'], $artifact->sha256, strlen($artifact->bytes), $review['target_id'], $review['target_hash'], $remote);
            DB::table('drive_controls')->where('owner_id', $owner->id)->update(['review' => null, 'review_hash' => null, 'review_expires' => null]);

            return $receipt;
        });
    }

    /** @return array<string,mixed> */
    public function inspect(User $owner, string $key): array
    {
        $this->key($key);
        $row = DB::table('drive_operations')->where('owner_id', $owner->id)->where('idempotency_key', $key)->first();
        abort_unless($row !== null, 404);

        return $this->receipt($row);
    }

    /** @return array<string,mixed> */
    public function connection(User $owner, string $id): array
    {
        $r = LedgerInput::owned('drive_connections', $owner, $id);

        return ['id' => (string) $r->id, 'email' => $r->email, 'generation' => $r->generation, 'status' => $r->status,
            'folder_id' => $r->folder_id, 'folder_name' => $r->folder_name, 'revoke_state' => $r->revoke_state,
            'restore_held' => $r->epoch !== config('drive_export.epoch')];
    }

    /** @return array<string,mixed> */
    public function page(User $owner): array
    {
        $c = DB::table('drive_controls')->where('owner_id', $owner->id)->first();

        return ['enabled' => GoogleDrive::enabled(), 'connection' => $c?->active_id === null ? null : $this->connection($owner, (string) $c->active_id),
            'operations' => DB::table('drive_operations')->where('owner_id', $owner->id)->orderByDesc('id')->limit(64)->get()->map(fn (stdClass $r): array => $this->receipt($r))->all()];
    }

    public function disconnect(User $owner, string $id): void
    {
        $this->locked($owner, function (stdClass $c) use ($owner, $id): void {
            $link = LedgerInput::owned('drive_connections', $owner, $id, true);
            if ($link->status === 'disconnected') {
                return;
            }
            DB::table('drive_connections')->where('id', $id)->update(['status' => 'disconnected', 'revoke_state' => 'pending']);
            if ((string) $c->active_id === $id) {
                DB::table('drive_controls')->where('owner_id', $owner->id)->update(['stopped' => true, 'generation' => bin2hex(random_bytes(16)),
                    'oauth_hash' => null, 'session_hash' => null, 'oauth_verifier' => null, 'oauth_expires' => null,
                    'review' => null, 'review_hash' => null, 'review_expires' => null]);
            }
            $this->event($owner, $id, null, 'locally_disconnected');
        }, verified: false);
        // Local stop is committed even when revoke/transport fails or the credential cannot decrypt.
        $this->revoke($id);
    }

    public function revoke(string $connection): void
    {
        $row = DB::table('drive_connections')->where('id', LedgerInput::identifier($connection))->first();
        if ($row === null) {
            return;
        }
        $owner = User::whereKey((string) $row->owner_id)->first();
        if ($owner === null) {
            return;
        }
        $attempt = $this->locked($owner, function () use ($owner, $connection): ?int {
            $c = LedgerInput::owned('drive_connections', $owner, $connection, true);
            if ($c->revoke_state !== 'pending' || ! GoogleDrive::enabled() || $c->epoch !== $this->epoch()) {
                return null;
            }
            if ($c->revoke_lease_until !== null && now()->lt($c->revoke_lease_until)) {
                return null;
            }
            if ($c->revoke_attempts >= 3) {
                DB::table('drive_connections')->where('id', $connection)->update(['revoke_state' => 'unconfirmed']);
                DB::table('drive_secrets')->where('owner_id', $owner->id)->where('connection_id', $connection)->delete();

                return null;
            }
            $attempt = (int) $c->revoke_attempts + 1;
            DB::table('drive_connections')->where('id', $connection)->update(['revoke_attempts' => $attempt, 'revoke_lease_until' => now()->addMinutes(2)]);
            $this->event($owner, $connection, null, 'revoke_claimed');

            return $attempt;
        }, verified: false);
        if ($attempt === null) {
            return;
        }
        $this->locked($owner, function () use ($owner, $connection, $attempt): void {
            $c = LedgerInput::owned('drive_connections', $owner, $connection, true);
            if ($c->revoke_state !== 'pending' || (int) $c->revoke_attempts !== $attempt) {
                return;
            }
            $state = 'pending';
            try {
                $tokens = $this->tokens($owner, $connection);
                $this->google->revoke($tokens['refresh'] === '' ? $tokens['access'] : $tokens['refresh']);
                $state = 'confirmed';
            } catch (DriveFailure) {
                if ($attempt === 3) {
                    $state = 'unconfirmed';
                }
            }
            DB::table('drive_connections')->where('id', $connection)->update(['revoke_state' => $state, 'revoke_lease_until' => null]);
            if ($state !== 'pending') {
                DB::table('drive_secrets')->where('owner_id', $owner->id)->where('connection_id', $connection)->delete();
            }
            $this->event($owner, $connection, null, 'revoke_'.$state);
        }, verified: false);
    }

    public function run(string $operation): void
    {
        $row = DB::table('drive_operations')->where('id', LedgerInput::identifier($operation))->first();
        if ($row === null || ! GoogleDrive::enabled()) {
            return;
        }
        $owner = User::whereKey((string) $row->owner_id)->first();
        if ($owner === null) {
            return;
        }
        $lease = bin2hex(random_bytes(16));
        $claimed = $this->locked($owner, function (stdClass $c) use ($owner, $operation, $lease): bool {
            $r = LedgerInput::owned('drive_operations', $owner, $operation, true);
            if (! in_array($r->state, ['queued', 'claimed', 'unknown'], true) || ($r->next_at !== null && now()->lt($r->next_at)) || $r->attempts >= 3) {
                return false;
            }
            if ($r->epoch !== $this->epoch()) {
                return false;
            }
            if ($c->stopped || (string) $c->active_id !== (string) $r->connection_id || $c->generation !== $r->generation) {
                $this->result($r, $r->attempts === 0 ? 'cancelled' : 'unknown', $r->attempts === 0 ? 'disconnected' : 'stopped_remote_unconfirmed');

                return false;
            }
            if (now()->gte($r->deadline) && $r->attempts === 0) {
                $this->result($r, 'expired', 'review_deadline');

                return false;
            }
            DB::table('drive_operations')->where('id', $operation)->update(['state' => 'claimed', 'lease' => $lease, 'attempts' => $r->attempts + 1, 'next_at' => now()->addMinutes(2), 'safe_code' => 'handoff_or_reconciliation']);
            $this->event($owner, (string) $r->connection_id, $operation, 'claimed');

            return true;
        }, verified: false);
        if (! $claimed) {
            return;
        }
        $preflight = null;
        try {
            $this->warm($owner, (string) $row->connection_id);
        } catch (DriveFailure $error) {
            $preflight = $error->reason;
        }
        $this->locked($owner, function (stdClass $c) use ($owner, $operation, $lease, $preflight): void {
            $r = LedgerInput::owned('drive_operations', $owner, $operation, true);
            if ($r->lease !== $lease || $r->state !== 'claimed') {
                return;
            }
            try {
                if ($preflight !== null) {
                    throw new DriveFailure($preflight);
                }
                $link = $this->eligible($owner, $c, (string) $r->connection_id, $r->generation);
                $access = $this->access($owner, $link);
                $this->headroom($owner);
                $artifact = $r->kind === 'report' ? SavedReport::make($owner, (string) $r->snapshot_id) : null;
                if ($artifact !== null) {
                    if ($artifact->sha256 !== $r->content_hash || strlen($artifact->bytes) !== (int) $r->content_bytes) {
                        throw new DriveFailure('content_changed');
                    }
                    $this->target($access, $link, $r->target_hash);
                }
                $file = $this->google->inspect($access, $r->remote_id);
                if ($file === null && now()->gte($r->deadline)) {
                    throw new DriveFailure('expired_unconfirmed');
                }
                if ($file === null) {
                    $metadata = ['appProperties' => ['halalos' => $r->reference, 'sha256' => $r->content_hash], 'id' => $r->remote_id, 'name' => $artifact === null ? 'HalalOS private reports' : 'halalos-saved-report.json',
                        'mimeType' => $artifact === null ? GoogleDrive::FOLDER : 'application/json'];
                    if ($artifact !== null) {
                        $metadata['parents'] = [$r->target_id];
                        $metadata['appProperties'] = ['halalos' => $r->reference, 'sha256' => $r->content_hash];
                    }
                    $this->google->create($access, $metadata, $artifact?->bytes);
                    $file = $this->google->inspect($access, $r->remote_id);
                }
                if ($file === null) {
                    throw new DriveFailure('transport_unknown');
                }
                GoogleDrive::privateFile($file, $r->remote_id, $artifact === null ? GoogleDrive::FOLDER : 'application/json', $artifact === null ? null : $r->target_id);
                if ($artifact !== null) {
                    $this->google->verifyContent($access, $file, $r->remote_id, $r->content_hash, (int) $r->content_bytes, $r->reference);
                } else {
                    if (! GoogleDrive::properties($file['appProperties'] ?? null, $r->reference, $r->content_hash) || ($file['name'] ?? null) !== 'HalalOS private reports') {
                        throw new DriveFailure('remote_content_conflict');
                    }
                    DB::table('drive_connections')->where('id', $link->id)->update(['folder_id' => $r->remote_id, 'folder_name' => $file['name'], 'status' => 'ready']);
                }
                $this->result($r, 'uploaded', 'remote_evidence_verified');
            } catch (DriveFailure $error) {
                $state = match ($error->reason) {
                    'transport_unknown', 'expired_unconfirmed', 'invalid_response', 'response_too_large' => 'unknown',
                    'reconnect_required', 'unverified_identity', 'identity_changed', 'unapproved_scope_or_token', 'connection_rejected_revoke_unconfirmed' => 'reconnect',
                    'connection_changed', 'owner_unverified' => $r->attempts === 0 ? 'cancelled' : 'unknown',
                    'destination_not_private', 'destination_changed', 'remote_content_conflict' => 'conflict',
                    default => 'failed',
                };
                $this->result($r, $state, $state === 'unknown' && in_array($error->reason, ['connection_changed', 'owner_unverified'], true) ? 'stopped_remote_unconfirmed' : $error->reason);
                if ($state === 'reconnect') {
                    DB::table('drive_connections')->where('id', $r->connection_id)->update(['status' => 'reconnect']);
                }
            }
            $this->event($owner, (string) $r->connection_id, $operation, 'result_recorded');
        }, verified: false);
    }

    public function tick(): void
    {
        if (! GoogleDrive::enabled()) {
            return;
        }
        foreach (DB::table('drive_operations')->where('epoch', $this->epoch())->whereIn('state', ['queued', 'claimed', 'unknown'])->whereNotIn('safe_code', ['stopped_remote_unconfirmed', 'expired_unconfirmed'])->where('attempts', '<', 3)
            ->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<=', now()))->orderBy('id')->limit(32)->pluck('id') as $id) {
            $this->run((string) $id);
        }
        foreach (DB::table('drive_connections')->where('epoch', $this->epoch())->where('revoke_state', 'pending')->orderBy('id')->limit(16)->pluck('id') as $id) {
            $this->revoke((string) $id);
        }
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    private function admit(User $owner, string $key, array $input, stdClass $link, string $kind, ?string $snapshot, string $hash, int $bytes, string $target, string $targetHash, string $remote): array
    {
        $this->capacity($owner, 'drive_operations', 64);
        $id = DB::table('drive_operations')->insertGetId(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(), 'idempotency_key' => $key,
            'payload_hash' => LedgerInput::hash($input), 'kind' => $kind, 'connection_id' => $link->id, 'generation' => $link->generation,
            'epoch' => $link->epoch, 'snapshot_id' => $snapshot, 'content_hash' => $hash, 'content_bytes' => $bytes,
            'target_id' => $target, 'target_hash' => $targetHash === '' ? str_repeat('0', 64) : $targetHash, 'remote_id' => $remote, 'deadline' => now()->addDay()]);
        $this->event($owner, (string) $link->id, (string) $id, 'queued');
        $this->headroom($owner);

        return $this->receipt(LedgerInput::owned('drive_operations', $owner, (string) $id));
    }

    private function result(stdClass $row, string $state, string $code): void
    {
        DB::table('drive_operations')->where('id', $row->id)->update(['state' => $state, 'safe_code' => $code, 'lease' => null,
            'next_at' => $state === 'unknown' && ! in_array($code, ['stopped_remote_unconfirmed', 'expired_unconfirmed'], true) && $row->attempts < 3 ? now()->addMinutes($row->attempts === 1 ? 1 : 5) : null]);
    }

    /** @return array<string,mixed> */
    private function receipt(stdClass $r): array
    {
        return ['id' => (string) $r->id, 'request_key' => $r->idempotency_key, 'kind' => $r->kind, 'state' => $r->state === 'claimed' && $r->next_at !== null && now()->gte($r->next_at) ? 'unknown' : $r->state,
            'safe_code' => $r->state === 'claimed' && $r->next_at !== null && now()->gte($r->next_at) ? 'interrupted_handoff_reconcile' : $r->safe_code,
            'attempts' => (int) $r->attempts, 'content_hash' => $r->content_hash, 'content_bytes' => (int) $r->content_bytes,
            'remote_id' => $r->remote_id, 'target_id' => $r->target_id, 'restore_held' => $r->epoch !== config('drive_export.epoch')];
    }

    private function eligible(User $owner, stdClass $c, string $connection, string $generation): stdClass
    {
        GoogleDrive::requireMock();
        if (! $owner->hasVerifiedEmail()) {
            throw new DriveFailure('owner_unverified');
        }
        $r = LedgerInput::owned('drive_connections', $owner, $connection, true);
        if ($c->stopped || (string) $c->active_id !== $connection || $c->generation !== $generation || $r->generation !== $generation
            || $r->epoch !== $this->epoch() || ! in_array($r->status, ['account_review', 'ready'], true)) {
            throw new DriveFailure('connection_changed');
        }

        return $r;
    }

    private function target(#[\SensitiveParameter] string $access, stdClass $link, string $expected): void
    {
        $file = $this->google->inspect($access, $link->folder_id);
        if ($file === null || GoogleDrive::privateFile($file, $link->folder_id, GoogleDrive::FOLDER, null) !== $expected) {
            throw new DriveFailure('destination_changed');
        }
    }

    /** Persist accepted rotation even if a later identity read is unavailable. Every handoff still verifies identity. */
    private function warm(User $owner, ?string $connection = null): void
    {
        $failure = $this->locked($owner, function (stdClass $control) use ($owner, $connection): ?string {
            $id = $connection ?? (string) $control->active_id;
            $link = $this->eligible($owner, $control, $id, $control->generation);
            $identityRead = false;
            try {
                $tokens = $this->tokens($owner, $id);
                if ($tokens['expires'] <= now()->getTimestamp() + 30) {
                    $tokens = $this->google->refresh($tokens['refresh']);
                    $this->storeTokens($owner, $id, $tokens);
                }
                $identityRead = true;
                $identity = $this->google->identity($tokens['access']);
                if ($identity['subject'] !== $link->subject || $identity['email'] !== $link->email) {
                    throw new DriveFailure('identity_changed');
                }
            } catch (DriveFailure $error) {
                if ($identityRead && in_array($error->reason, ['transport_unknown', 'invalid_response', 'response_too_large'], true)) {
                    return $error->reason;
                }
                DB::table('drive_connections')->where('id', $id)->update(['status' => 'reconnect']);

                return $identityRead || in_array($error->reason, ['unapproved_scope_or_token', 'connection_rejected_revoke_unconfirmed'], true)
                    ? $error->reason : 'reconnect_required';
            }

            return null;
        });
        if ($failure !== null) {
            throw new DriveFailure($failure);
        }
    }

    private function access(User $owner, stdClass $link): string
    {
        $tokens = $this->tokens($owner, (string) $link->id);
        if ($tokens['expires'] <= now()->getTimestamp() + 30) {
            throw new DriveFailure('reconnect_required');
        }
        $identity = $this->google->identity($tokens['access']);
        if ($identity['subject'] !== $link->subject || $identity['email'] !== $link->email) {
            throw new DriveFailure('identity_changed');
        }

        return $tokens['access'];
    }

    /** @return array{access:string,refresh:string,expires:int} */
    private function tokens(User $owner, string $connection): array
    {
        try {
            $cipher = DB::table('drive_secrets')->where('owner_id', $owner->id)->where('connection_id', $connection)->value('ciphertext');
            $data = json_decode(Crypt::decryptString(is_string($cipher) ? $cipher : ''), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['owner'] ?? null) !== (string) $owner->id || ($data['connection'] ?? null) !== $connection
                || ! is_string($data['access'] ?? null) || ! is_string($data['refresh'] ?? null) || ! is_int($data['expires'] ?? null)) {
                throw new DriveFailure('reconnect_required');
            }

            return ['access' => $data['access'], 'refresh' => $data['refresh'], 'expires' => $data['expires']];
        } catch (Throwable) {
            throw new DriveFailure('reconnect_required');
        }
    }

    /** @param array{access:string,refresh:string,expires:int} $tokens */
    private function storeTokens(User $owner, string $connection, #[\SensitiveParameter] array $tokens): void
    {
        DB::table('drive_secrets')->updateOrInsert(['owner_id' => $owner->id, 'connection_id' => $connection],
            ['ciphertext' => Crypt::encryptString(json_encode(['owner' => (string) $owner->id, 'connection' => $connection, ...$tokens], JSON_THROW_ON_ERROR))]);
    }

    private function event(User $owner, string $connection, ?string $operation, string $code): void
    {
        $query = DB::table('drive_events')->where('owner_id', $owner->id)->where('connection_id', $connection)->where('operation_id', $operation);
        $ordinal = (int) $query->max('ordinal') + 1;
        if ($ordinal <= 8) {
            DB::table('drive_events')->insert(['owner_id' => $owner->id, 'connection_id' => $connection, 'operation_id' => $operation, 'ordinal' => $ordinal, 'safe_code' => $code]);
        }
    }

    private function headroom(User $owner): void
    {
        try {
            $bytes = app(OwnerExport::class)->bytes($owner);
        } catch (OwnerExportLimit) {
            throw new DriveFailure('owner_export_capacity');
        }
        if ($bytes > OwnerExport::MAX_BYTES - 65536) {
            throw new DriveFailure('owner_export_capacity');
        }
    }

    private function capacity(User $owner, string $table, int $max): void
    {
        if (DB::table($table)->where('owner_id', $owner->id)->count() >= $max) {
            throw new DriveFailure('retained_capacity');
        }
    }

    /** @param array<string,mixed> $input */
    private function cached(User $owner, string $key, array $input): ?stdClass
    {
        $this->key($key);
        $row = DB::table('drive_operations')->where('owner_id', $owner->id)->where('idempotency_key', $key)->first();
        if ($row !== null && ! hash_equals($row->payload_hash, LedgerInput::hash($input))) {
            throw new DriveFailure('request_key_conflict');
        }

        return $row;
    }

    private function key(string $key): void
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{8,120}$/D', $key)) {
            throw new DriveFailure('invalid_request_key');
        }
    }

    private function epoch(): string
    {
        $epoch = config('drive_export.epoch');
        if (! is_string($epoch) || ! preg_match('/^[a-f0-9]{32}$/D', $epoch)) {
            throw new DriveFailure('disabled_or_restore_held');
        }

        return $epoch;
    }

    private function locked(User $owner, callable $operation, bool $verified = true): mixed
    {
        if (DB::transactionLevel() !== 0) {
            throw new DriveFailure('caller_transaction_not_supported');
        }

        return DB::transaction(function () use ($owner, $operation, $verified): mixed {
            LedgerInput::lock($owner);
            $fresh = User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            if ($verified && ! $fresh->hasVerifiedEmail()) {
                throw new DriveFailure('owner_unverified');
            }
            DB::table('drive_controls')->insertOrIgnore(['owner_id' => $owner->id, 'generation' => bin2hex(random_bytes(16))]);
            $c = DB::table('drive_controls')->where('owner_id', $owner->id)->lockForUpdate()->first();
            // Use fresh state for handoff eligibility, not a stale serialized user model.
            $owner->email_verified_at = $fresh->email_verified_at;

            return $operation($c);
        }, 1);
    }
}
