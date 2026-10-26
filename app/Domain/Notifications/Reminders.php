<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Operations\Channel;
use App\Domain\Operations\OperationBlocked;
use App\Domain\Operations\Operations;
use App\Domain\Planning\BudgetView;
use App\Domain\Reporting\DashboardSnapshot;
use App\Domain\Shared\Money\Currency;
use App\Jobs\DeliverReminder;
use App\Models\User;
use App\Support\Release\OwnerExport;
use App\Support\Release\OwnerExportLimit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

final class Reminders
{
    public const int MAX_NOTIFICATIONS = 512;

    public const int MAX_EXPORT_BYTES = 62914560;

    public function __construct(private readonly ReminderMail $mail, private readonly int $exportLimit = self::MAX_EXPORT_BYTES) {}

    /** @return array{id:?string,token:string,version:int,data:array<string,mixed>} */
    public function settings(User $owner): array
    {
        $row = DB::table('reminder_preferences')->where('owner_id', $owner->id)->orderByDesc('version')->first();
        $data = $row === null ? ReminderPolicy::defaults() : json_decode($row->data, true, flags: JSON_THROW_ON_ERROR);
        $control = DB::table('reminder_controls')->where('owner_id', $owner->id)->first();
        $data['external_paused'] = $control === null || (bool) $control->external_paused;

        return ['id' => $row === null ? null : (string) $row->id, 'token' => $row === null ? '' : $row->id.':'.$control->generation, 'version' => (int) ($row->version ?? 0), 'data' => $data];
    }

    /** @param array<string,mixed> $input */
    public function configure(User $owner, string $key, string $prior, array $input): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $prior, $input): stdClass {
            LedgerInput::lock($owner);
            $payload = ['prior' => $prior, 'data' => $input];
            if ($old = LedgerInput::cached('reminder_preferences', $owner, $key, $payload)) {
                return $old;
            }
            $current = $this->settings($owner);
            if ($current['token'] !== $prior) {
                LedgerInput::fail('preferences', 'Preferences changed. Reload before saving. An exact committed request still recovers.');
            }
            if ($current['version'] >= 128) {
                LedgerInput::fail('preferences', 'Retained preference capacity reached. External pause remains available.');
            }
            $data = ReminderPolicy::parse($input);
            foreach ($data['schedule_ids'] as $id) {
                $schedule = LedgerInput::owned('planning_schedules', $owner, $id);
                $category = LedgerInput::owned('planning_categories', $owner, (string) $schedule->category_id);
                if ($category->direction !== 'income' || DB::table('planning_schedules')->where('owner_id', $owner->id)->where('supersedes_id', $id)->exists()) {
                    LedgerInput::fail('schedule_ids', 'Choose current owned incoming contribution schedule versions explicitly.');
                }
            }
            $id = DB::table('reminder_preferences')->insertGetId(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(),
                'idempotency_key' => $key, 'payload_hash' => LedgerInput::hash($payload), 'version' => $current['version'] + 1,
                'supersedes_id' => $current['id'], 'data' => $this->json($data)]);
            DB::table('reminder_controls')->updateOrInsert(['owner_id' => $owner->id], ['external_paused' => $data['external_paused'], 'generation' => bin2hex(random_bytes(16))]);
            $this->headroom($owner);

            return LedgerInput::owned('reminder_preferences', $owner, (string) $id);
        });
    }

    /** Always available, including full retained byte/row capacity. No new audit allocation. */
    public function pause(User $owner): void
    {
        DB::transaction(function () use ($owner): void {
            LedgerInput::lock($owner);
            // No control means no configuration and therefore already stopped.
            DB::table('reminder_controls')->where('owner_id', $owner->id)->where('external_paused', false)->update(['external_paused' => true, 'generation' => bin2hex(random_bytes(16))]);
        });
    }

    /** @return array{status:string,ids:list<string>} */
    public function plan(User $owner): array
    {
        return DB::transaction(function () use ($owner): array {
            LedgerInput::lock($owner);
            $settings = $this->settings($owner);
            if ($settings['id'] === null || ! $owner->fresh()?->hasVerifiedEmail()) {
                return ['status' => 'not_opted_in', 'ids' => []];
            }
            $ids = [];
            $capacity = false;
            foreach ($this->episodes($owner, $settings['data']) as $episode) {
                $channels = array_keys(array_filter($settings['data']['rules'][$episode['rule']]));
                if ($channels === []) {
                    continue;
                }
                $row = DB::table('reminder_notifications')->where('owner_id', $owner->id)->where('episode_key', $episode['key'])->first();
                if ($row === null && DB::table('reminder_notifications')->where('owner_id', $owner->id)->count() >= self::MAX_NOTIFICATIONS) {
                    $capacity = true;

                    continue;
                }
                if ($row === null) {
                    $id = DB::table('reminder_notifications')->insertGetId(['owner_id' => $owner->id, 'episode_key' => $episode['key'],
                        'rule' => $episode['rule'], 'source_key' => $episode['source'], 'due_date' => $episode['due']]);
                    $row = LedgerInput::owned('reminder_notifications', $owner, (string) $id);
                }
                foreach ($channels as $channel) {
                    $intent = DB::table('reminder_channels')->where('owner_id', $owner->id)->where('notification_id', $row->id)->where('channel', $channel)->first();
                    if ($intent === null) {
                        $id = DB::table('reminder_channels')->insertGetId(['owner_id' => $owner->id, 'notification_id' => $row->id, 'channel' => $channel]);
                        $intent = LedgerInput::owned('reminder_channels', $owner, (string) $id);
                    }
                    $ids[] = (string) $intent->id;
                }
            }
            $this->headroom($owner);

            return ['status' => $capacity ? 'capacity' : 'planned', 'ids' => $ids];
        });
    }

    /** ID-only durable recovery; queued hints can be lost or repeated.
     * @return array{next:?string,owners:int,capacity:int} */
    public function tick(string $after = '0'): array
    {
        $owners = DB::table('reminder_controls')->where('owner_id', '>', $after)->orderBy('owner_id')->limit(101)->pluck('owner_id');
        $page = $owners->take(100);
        $capacity = 0;
        foreach ($page as $ownerId) {
            $owner = User::whereKey($ownerId)->first();
            if ($owner === null) {
                continue;
            }
            try {
                $report = $this->plan($owner);
                $capacity += (int) ($report['status'] === 'capacity');
            } catch (ValidationException) {
                $capacity++;
            }
            $ids = DB::table('reminder_channels')->where('owner_id', $ownerId)->orderBy('id')->pluck('id');
            foreach ($ids as $id) {
                $intent = LedgerInput::owned('reminder_channels', $owner, (string) $id);
                $state = $this->delivery($owner, $intent);
                if (in_array($state['status'], ['pending', 'retryable'], true)) {
                    // Bounded synchronous jobs cannot accumulate terminal or lost queue hints.
                    DeliverReminder::dispatchSync((string) $id);
                }
            }
        }

        return ['next' => $owners->count() > 100 ? (string) $page->last() : null, 'owners' => $page->count(), 'capacity' => $capacity];
    }

    /** @return array{status:string,code:string} */
    public function dispatch(string $channelId): array
    {
        $intent = DB::table('reminder_channels')->where('id', LedgerInput::identifier($channelId))->first();
        if ($intent === null || ($owner = User::whereKey($intent->owner_id)->first()) === null) {
            return $this->outcome('missing');
        }
        $secret = bin2hex(random_bytes(32));
        $claim = null;
        $permit = null;
        try {
            $first = DB::transaction(function () use ($owner, $intent, $secret, &$claim, &$permit): array {
                LedgerInput::lock($owner);
                $owner = User::whereKey($owner->id)->lockForUpdate()->first();
                if ($owner === null) {
                    return $this->outcome('missing');
                }
                $existing = $this->delivery($owner, $intent);
                if (in_array($existing['status'], ['in_app', 'mock_smtp', 'uncertain', 'exhausted', 'deferred'], true)) {
                    return $existing;
                }
                $settings = $this->settings($owner);
                $reason = $this->blockReason($owner, $intent, $settings['data']);
                if ($reason !== null) {
                    return $this->outcome('held', $reason);
                }
                if ($intent->channel === 'email') {
                    $permit = app(Operations::class)->reserve($owner, Channel::Email, (string) Str::uuid());
                }
                $ordinal = DB::table('reminder_claims')->where('owner_id', $owner->id)->where('channel_id', $intent->id)->count() + 1;
                $id = DB::table('reminder_claims')->insertGetId(['owner_id' => $owner->id, 'channel_id' => $intent->id, 'ordinal' => $ordinal,
                    'permit_hash' => hash('sha256', $secret), 'destination_hash' => hash('sha256', $intent->channel === 'email' ? $owner->email : 'in_app')]);
                $claim = LedgerInput::owned('reminder_claims', $owner, (string) $id);
                $this->headroom($owner, 4096);
                if ($intent->channel === 'in_app') {
                    $this->result($owner, $claim, $settings['id'], 'in_app', 'published', '');
                    $this->headroom($owner);

                    return $this->outcome('in_app');
                }

                return $this->outcome('claimed');
            });
        } catch (OperationBlocked $error) {
            return $this->outcome('held', 'operations_'.$error->reason);
        } catch (ValidationException $error) {
            if (array_key_exists('capacity', $error->errors())) {
                return $this->outcome('held', 'capacity');
            }
            throw $error;
        }
        if ($first['status'] !== 'claimed' || $claim === null || $permit === null) {
            return $first;
        }

        // This stack alone owns the permit. An old queue job or restored row cannot acquire it.
        // Never auto-retry a transaction containing a mail handoff.
        try {
            $outcome = DB::transaction(function () use ($owner, $intent, $claim, $secret, $permit): array {
                LedgerInput::lock($owner);
                $fresh = User::whereKey($owner->id)->lockForUpdate()->first();
                if ($fresh === null) {
                    return $this->outcome('uncertain');
                }
                if (! hash_equals($claim->permit_hash, hash('sha256', $secret)) || DB::table('reminder_results')->where('claim_id', $claim->id)->exists()) {
                    return $this->outcome('uncertain');
                }
                $settings = $this->settings($fresh);
                $reason = $this->blockReason($fresh, $intent, $settings['data']);
                if ($reason !== null || ! hash_equals($claim->destination_hash, hash('sha256', $fresh->email))) {
                    $this->headroom($fresh, 4096);
                    $this->result($fresh, $claim, $settings['id'], 'retryable', 'policy_changed', '');

                    return $this->outcome('retryable', 'policy_changed');
                }
                $this->headroom($fresh, 4096);
                $notification = LedgerInput::owned('reminder_notifications', $fresh, (string) $intent->notification_id);
                $message = ReminderPolicy::message($settings['data']['detail'], $notification->rule, $notification->due_date);
                $mail = new ReminderMessage($message['subject'], $message['body'], hash('sha256', $notification->episode_key.':email'));
                try {
                    $handoff = $permit->call(fn () => $this->mail->send($fresh->email, $mail));
                } catch (OperationBlocked) {
                    $handoff = Handoff::BeforeHandoffFailure;
                } catch (Throwable) {
                    $handoff = Handoff::Uncertain;
                }
                $this->result($fresh, $claim, $settings['id'], $handoff->value, $handoff->code(), LedgerInput::hash($message));
                $this->headroom($fresh);

                return $this->outcome($handoff->value);
            });
            $permit->finish($outcome['status'] === 'mock_smtp' ? 'complete' : ($outcome['status'] === 'uncertain' ? 'uncertain' : 'held'));

            return $outcome;
        } catch (ValidationException $error) {
            $permit->finish($permit->hasSent() ? 'uncertain' : 'held');
            if (array_key_exists('capacity', $error->errors())) {
                return $this->outcome('uncertain', 'capacity_inspect_claim');
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function inbox(User $owner, ?string $before = null): array
    {
        return DB::transaction(function () use ($owner, $before): array {
            LedgerInput::lock($owner);
            $rows = DB::table('reminder_channels as c')->join('reminder_notifications as n', 'n.id', '=', 'c.notification_id')
                ->where('c.owner_id', $owner->id)->where('n.owner_id', $owner->id)
                ->when($before !== null, fn ($q) => $q->where('c.id', '<', LedgerInput::identifier($before)))
                ->orderByDesc('c.id')->limit(21)->get(['c.*', 'n.rule', 'n.due_date', 'n.created_at as admitted_at']);
            $settings = $this->settings($owner);
            $items = [];
            foreach ($rows->take(20) as $row) {
                $delivery = $this->delivery($owner, $row);
                $items[] = ['id' => (string) $row->id, 'notification_id' => (string) $row->notification_id, 'channel' => $row->channel,
                    'label' => ReminderPolicy::RULES[$row->rule], 'due_date' => $row->due_date, 'created_at' => LedgerInput::utc($row->admitted_at),
                    ...$delivery, 'held_reason' => $this->blockReason($owner, $row, $settings['data']),
                    'read' => DB::table('reminder_reads')->where('owner_id', $owner->id)->where('notification_id', $row->notification_id)->exists()];
            }
            $count = DB::table('reminder_notifications')->where('owner_id', $owner->id)->count();

            return ['settings' => $settings, 'items' => $items, 'next' => $rows->count() > 20 ? (string) $rows[19]->id : null,
                'capacity' => ['count' => $count, 'limit' => self::MAX_NOTIFICATIONS, 'full' => $count >= self::MAX_NOTIFICATIONS || $settings['version'] >= 128 || $this->exportBytes($owner) + 4096 > min(self::MAX_EXPORT_BYTES, $this->exportLimit)],
                'rules' => ReminderPolicy::RULES, 'email_mode' => $this->mail->enabled() ? 'synthetic_mock' : 'live_blocked'];
        });
    }

    public function read(User $owner, string $notificationId): void
    {
        DB::transaction(function () use ($owner, $notificationId): void {
            LedgerInput::lock($owner);
            LedgerInput::owned('reminder_notifications', $owner, LedgerInput::identifier($notificationId));
            if (! DB::table('reminder_reads')->where('owner_id', $owner->id)->where('notification_id', $notificationId)->exists()) {
                DB::table('reminder_reads')->insert(['owner_id' => $owner->id, 'notification_id' => $notificationId]);
                $this->headroom($owner);
            }
        });
    }

    /** Pure synthetic preview, never a real channel test or recipient lookup.
     * @return array{subject:string,body:string} */
    public function preview(string $detail): array
    {
        if (! in_array($detail, ['generic', 'review'], true)) {
            LedgerInput::fail('detail', 'Choose generic or review details.');
        }

        return ReminderPolicy::message($detail, 'contribution', '2030-01-15');
    }

    /** @param array<string,mixed> $data
     * @return list<array{key:string,rule:string,source:string,due:?string}> */
    private function episodes(User $owner, array $data): array
    {
        $episodes = [];
        $dashboardEnabled = false;
        foreach (['reconciliation', 'funding', 'evidence', 'allocation'] as $rule) {
            $dashboardEnabled = $dashboardEnabled || in_array(true, $data['rules'][$rule], true);
        }
        if ($dashboardEnabled) {
            foreach (app(DashboardSnapshot::class)->current($owner, Currency::QAR)['actions'] as $action) {
                if (! $action['dismissed']) {
                    $episodes[] = ['key' => $action['key'], 'rule' => $action['rule'], 'source' => $action['key'], 'due' => null];
                }
            }
        }
        if ($data['schedule_ids'] !== [] && in_array(true, $data['rules']['contribution'], true)) {
            $today = CarbonImmutable::now('Asia/Qatar')->startOfDay();
            // Three calendar months cover a full 30-day grace across short February.
            for ($month = 0; $month < 3; $month++) {
                $view = app(BudgetView::class)->month($owner, $today->startOfMonth()->subMonths($month)->format('Y-m'));
                foreach ($view['occurrences'] as $o) {
                    if (in_array($o['schedule_id'], $data['schedule_ids'], true) && $o['category']['direction'] === 'income'
                        && ! $o['superseded'] && ! $o['needs_review'] && $o['remaining']['minor'] !== null && bccomp($o['remaining']['minor'], '0') > 0
                        && CarbonImmutable::parse($o['due_date'], 'Asia/Qatar')->addDays($data['grace_days'])->lt($today)) {
                        $episodes[] = ['key' => hash('sha256', 'contribution:v1:'.$o['key']), 'rule' => 'contribution', 'source' => $o['key'], 'due' => $o['due_date']];
                    }
                }
            }
        }

        return $episodes;
    }

    /** @param array<string,mixed> $data */
    private function blockReason(User $owner, stdClass $intent, array $data): ?string
    {
        if (! $owner->hasVerifiedEmail()) {
            return 'unverified';
        }
        $notification = LedgerInput::owned('reminder_notifications', $owner, (string) $intent->notification_id);
        if (! $data['rules'][$notification->rule][$intent->channel]) {
            return 'opted_out';
        }
        $firstClaim = DB::table('reminder_claims')->where('owner_id', $owner->id)->where('channel_id', $intent->id)->orderBy('ordinal')->first();
        if ($intent->channel === 'email' && $firstClaim !== null && ! hash_equals($firstClaim->destination_hash, hash('sha256', $owner->email))) {
            return 'destination_changed';
        }
        if ($intent->channel === 'email' && $data['external_paused']) {
            return 'paused';
        }
        if ($intent->channel === 'email' && ! $this->mail->enabled()) {
            return 'live_blocked';
        }
        if (! ReminderPolicy::allowed($data, CarbonImmutable::now('UTC'))) {
            return 'quiet_or_not_due';
        }
        foreach ($this->episodes($owner, $data) as $episode) {
            if ($episode['key'] === $notification->episode_key) {
                return null;
            }
        }

        return 'no_longer_current';
    }

    /** @return array{status:string,code:string} */
    private function delivery(User $owner, stdClass $intent): array
    {
        $claim = DB::table('reminder_claims')->where('owner_id', $owner->id)->where('channel_id', $intent->id)->orderByDesc('ordinal')->first();
        if ($claim === null) {
            return $this->outcome('pending');
        }
        $result = DB::table('reminder_results')->where('owner_id', $owner->id)->where('claim_id', $claim->id)->first();
        if ($result === null) {
            return $this->outcome('uncertain', 'inspect_do_not_resend');
        }
        if ($result->outcome === 'retryable') {
            if ($claim->ordinal >= 3) {
                return $this->outcome('exhausted', 'attempt_limit');
            }
            if (CarbonImmutable::parse($result->retry_at)->isFuture()) {
                return $this->outcome('deferred', 'safe_retry_scheduled');
            }
        }

        return $this->outcome($result->outcome, $result->safe_code);
    }

    private function result(User $owner, stdClass $claim, ?string $preference, string $outcome, string $code, string $hash): void
    {
        DB::table('reminder_results')->insert(['owner_id' => $owner->id, 'claim_id' => $claim->id, 'preference_id' => $preference,
            'outcome' => $outcome, 'safe_code' => $code, 'message_hash' => $hash === '' ? hash('sha256', '') : $hash,
            'retry_at' => $outcome === 'retryable' ? now('UTC')->addSeconds($claim->ordinal === 1 ? 60 : 300) : null]);
    }

    private function exportBytes(User $owner): int
    {
        try {
            return app(OwnerExport::class)->bytes($owner);
        } catch (OwnerExportLimit) {
            // Older retained writers can exhaust the hard export bound too.
            // Keep inspection and unconditional pause available without an export.
            return self::MAX_EXPORT_BYTES + 1;
        }
    }

    private function headroom(User $owner, int $reserve = 0): void
    {
        if ($this->exportBytes($owner) + $reserve > min(self::MAX_EXPORT_BYTES, $this->exportLimit)) {
            LedgerInput::fail('capacity', 'Complete retained export capacity reached. No new delivery was committed. External pause remains available.');
        }
    }

    /** @return array{status:string,code:string} */
    private function outcome(string $status, string $code = ''): array
    {
        return ['status' => $status, 'code' => $code];
    }

    /** @param array<array-key,mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
