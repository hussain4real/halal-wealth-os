<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Notifications\ReminderPolicy;
use App\Domain\Notifications\Reminders;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationController extends Controller
{
    public function index(Request $request, Reminders $reminders): Response
    {
        $before = $request->query('before');

        $owner = $this->owner($request);
        $page = $reminders->inbox($owner, $before === null ? null : LedgerInput::identifier($before));
        $prefs = $page['settings']['data'];
        $defaults = ['prior' => $page['settings']['token'], 'timezone' => $prefs['timezone'], 'send_after' => $prefs['send_after'],
            'quiet_enabled' => $prefs['quiet_enabled'] ? '1' : '0', 'quiet_start' => $prefs['quiet_start'], 'quiet_end' => $prefs['quiet_end'],
            'grace_days' => (string) $prefs['grace_days'], 'detail' => $prefs['detail'], 'external_paused' => $prefs['external_paused'] ? '1' : '0',
            'schedules' => implode(',', $prefs['schedule_ids'])];
        foreach ($prefs['rules'] as $rule => $channels) {
            foreach ($channels as $channel => $value) {
                $defaults[$rule.'_'.$channel] = $value ? '1' : '0';
            }
        }
        $query = $request->query('q', '');
        if (! is_string($query) || mb_strlen($query) > 120) {
            LedgerInput::fail('q', 'Use a search up to 120 characters.');
        }
        $schedules = DB::table('planning_schedules as s')->join('planning_categories as c', 'c.id', '=', 's.category_id')
            ->where('s.owner_id', $owner->id)->where('c.owner_id', $owner->id)->where('c.direction', 'income')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('planning_schedules as n')->whereColumn('n.supersedes_id', 's.id'));
        if ($query !== '') {
            $schedules->where('s.name', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%');
        }
        if (($after = $request->query('schedule_after')) !== null) {
            $schedules->where('s.id', '<', LedgerInput::identifier($after));
        }
        $choices = $schedules->orderByDesc('s.id')->limit(21)->get(['s.id', 's.name', 's.standing_order']);
        $next = $choices->count() > 20 ? (string) $choices[19]->id : null;
        $choices = $choices->take(20);
        foreach ($prefs['schedule_ids'] as $id) {
            if (! $choices->contains('id', $id)) {
                $choices->push(LedgerInput::owned('planning_schedules', $owner, $id));
            }
        }

        return Inertia::render('notifications/Index', [...$page, 'defaults' => $defaults, 'query' => $query, 'schedule_next' => $next,
            'schedules' => $choices->map(fn ($r) => ['id' => (string) $r->id, 'name' => $r->name, 'standing_order' => (bool) $r->standing_order])->values()->all()]);
    }

    public function configure(Request $request, Reminders $reminders): RedirectResponse|JsonResponse
    {
        $fields = ['prior', 'timezone', 'send_after', 'quiet_enabled', 'quiet_start', 'quiet_end', 'grace_days', 'detail', 'external_paused', 'schedules'];
        foreach (ReminderPolicy::RULES as $rule => $label) {
            foreach (['in_app', 'email'] as $channel) {
                $fields[] = $rule.'_'.$channel;
            }
        }
        $rules = array_fill_keys($fields, ['present', 'nullable', 'string', 'max:640']);
        $rules['idempotency_key'] = ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,120}$/D'];
        $data = $request->validate($rules);
        $boolean = function (string $field) use ($data): bool {
            if (! in_array($data[$field], ['0', '1'], true)) {
                LedgerInput::fail($field, 'Choose explicitly on or off.');
            }

            return $data[$field] === '1';
        };
        $input = array_intersect_key($data, array_flip(['timezone', 'send_after', 'quiet_start', 'quiet_end', 'grace_days', 'detail']));
        $input['quiet_enabled'] = $boolean('quiet_enabled');
        $input['external_paused'] = $boolean('external_paused');
        $input['schedule_ids'] = ($data['schedules'] ?? '') === '' ? [] : explode(',', $data['schedules']);
        foreach (ReminderPolicy::RULES as $rule => $label) {
            $input['rules'][$rule] = ['in_app' => $boolean($rule.'_in_app'), 'email' => $boolean($rule.'_email')];
        }
        $row = $reminders->configure($this->owner($request), $data['idempotency_key'], $data['prior'] ?? '', $input);

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json(['id' => (string) $row->id], 201) : to_route('notifications.index');
    }

    public function pause(Request $request, Reminders $reminders): RedirectResponse
    {
        $reminders->pause($this->owner($request));

        return to_route('notifications.index');
    }

    public function read(Request $request, string $notification, Reminders $reminders): RedirectResponse
    {
        $reminders->read($this->owner($request), $notification);

        return to_route('notifications.index');
    }

    public function preview(Request $request, Reminders $reminders): JsonResponse
    {
        $data = $request->validate(['detail' => ['required', 'string']]);

        return response()->json([...$reminders->preview($data['detail']), 'mode' => 'synthetic_preview_no_send']);
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 401);

        return $owner;
    }
}
