<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Integrations\Drive\DriveExports;
use App\Domain\Integrations\Drive\DriveFailure;
use App\Domain\Ledger\LedgerInput;
use App\Http\Middleware\DriveCallbackPrivacy;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class DriveExportController extends Controller
{
    public function index(Request $request, DriveExports $drive): Response
    {
        $owner = $this->owner($request);
        $before = $request->query('before');
        $snapshots = DB::table('dashboard_snapshots')->where('owner_id', $owner->id);
        if ($before !== null) {
            $snapshots->where('id', '<', LedgerInput::identifier($before));
        }
        $rows = $snapshots->orderByDesc('id')->limit(21)->get(['id', 'reference', 'recorded_at']);
        $next = $rows->count() > 20 ? (string) $rows[19]->id : null;

        return Inertia::render('drive/Index', [...$drive->page($owner), 'next' => $next,
            'snapshots' => $rows->take(20)->map(fn ($r): array => ['id' => (string) $r->id, 'reference' => $r->reference, 'recorded_at' => LedgerInput::utc($r->recorded_at)])->all()]);
    }

    public function begin(Request $request, DriveExports $drive): JsonResponse
    {
        return $this->safe(fn (): array => $drive->begin($this->owner($request), $request->session()->getId()));
    }

    public function callback(Request $request, DriveExports $drive): JsonResponse
    {
        ['state' => $state, 'code' => $code] = DriveCallbackPrivacy::payload($request);
        $owner = $request->user();
        if (! $owner instanceof User || ! $owner->hasVerifiedEmail() || (app()->isProduction() && ! $owner->hasEnabledTwoFactorAuthentication())) {
            return response()->json(['message' => 'Sign in with a verified owner and start a new connection. This callback was not completed.'], $owner instanceof User ? 403 : 401)
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }

        // Deliberately avoid validation redirects/session flashing of OAuth query credentials.
        if (! is_string($state) || ! preg_match('/^[a-f0-9]{64}$/D', $state) || ! is_string($code) || strlen($code) > 2048) {
            return response()->json(['message' => 'Connection was not completed. Start again.', 'definitive_no_write' => true], 422)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }

        return $this->safe(fn (): array => $drive->finish($owner, $request->session()->getId(), $state, $code))->header('Referrer-Policy', 'no-referrer');
    }

    public function prepare(Request $request, DriveExports $drive): JsonResponse
    {
        $input = $request->validate(['idempotency_key' => 'required|string|max:120', 'connection' => 'required|string|max:18', 'generation' => 'required|string|size:32', 'acknowledged' => 'accepted']);

        return $this->safe(fn (): array => $drive->prepare($this->owner($request), $input['idempotency_key'], LedgerInput::identifier($input['connection']), $input['generation']));
    }

    public function review(Request $request, DriveExports $drive): JsonResponse
    {
        $input = $request->validate(['snapshot' => 'required|string|max:18']);

        return $this->safe(fn (): array => $drive->review($this->owner($request), LedgerInput::identifier($input['snapshot'])));
    }

    public function confirm(Request $request, DriveExports $drive): JsonResponse
    {
        $input = $request->validate(['idempotency_key' => 'required|string|max:120', 'review_token' => 'required|string|size:64', 'acknowledged' => 'required|boolean']);

        return $this->safe(fn (): array => $drive->confirm($this->owner($request), $input['idempotency_key'], $input['review_token'], (bool) $input['acknowledged']));
    }

    public function status(Request $request, string $key, DriveExports $drive): JsonResponse
    {
        return $this->safe(fn (): array => $drive->inspect($this->owner($request), $key));
    }

    public function disconnect(Request $request, string $connection, DriveExports $drive): JsonResponse
    {
        return $this->safe(function () use ($request, $connection, $drive): array {
            $drive->disconnect($this->owner($request), LedgerInput::identifier($connection));

            return ['status' => 'locally_disconnected'];
        });
    }

    private function safe(callable $operation): JsonResponse
    {
        try {
            return response()->json($operation())->header('Cache-Control', 'no-store');
        } catch (DriveFailure $error) {
            $message = match ($error->reason) {
                'retained_capacity', 'owner_export_capacity' => 'Retained export capacity is full. No new export was queued. Existing receipts and disconnect remain available.',
                'report_too_large' => 'This saved report exceeds the 5,000,000-byte Drive limit. Use its existing download; no report was truncated or queued.',
                'review_expired_or_changed', 'content_changed' => 'This review expired or changed. Review the account, destination and report again before confirming.',
                'destination_not_private', 'destination_changed', 'destination_missing' => 'The destination could not be confirmed private and unchanged. Review its account and sharing before trying again.',
                'disabled_or_restore_held' => 'Live Google export is disabled. Restored work also stays held until its execution environment is approved.',
                'reconnect_required', 'identity_changed', 'unverified_identity', 'connection_changed' => 'The connected account changed or needs reconnection. Check the identity and start a new review.',
                'connection_rejected_revoke_unconfirmed' => 'Connection was rejected and remote revocation was not confirmed. Remove the app in Google account security before reconnecting.',
                default => 'The Drive request could not be confirmed. Review the current account and destination before trying again.',
            };

            return response()->json(['message' => $message, 'reason' => $error->reason, 'definitive_no_write' => true], 422)->header('Cache-Control', 'no-store');
        }
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 401);

        return $owner;
    }
}
