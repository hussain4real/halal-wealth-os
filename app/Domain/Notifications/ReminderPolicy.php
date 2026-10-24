<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Ledger\LedgerInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ReminderPolicy
{
    public const array RULES = ['reconciliation' => 'Reconciliation review', 'funding' => 'Goal plan review', 'evidence' => 'Evidence review', 'allocation' => 'Allocation review', 'contribution' => 'Scheduled contribution review'];

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return ['rules' => array_fill_keys(array_keys(self::RULES), ['in_app' => false, 'email' => false]),
            'schedule_ids' => [], 'timezone' => 'Asia/Qatar', 'send_after' => '08:00', 'quiet_enabled' => true,
            'quiet_start' => '22:00', 'quiet_end' => '07:00', 'grace_days' => 3, 'detail' => 'generic', 'external_paused' => true];
    }

    /** @param array<string,mixed> $raw
     * @return array<string,mixed> */
    public static function parse(array $raw): array
    {
        $rules = ['rules' => ['required', 'array:'.implode(',', array_keys(self::RULES))], 'schedule_ids' => ['present', 'array', 'list', 'max:32'],
            'schedule_ids.*' => ['required', 'string', 'regex:/^[1-9][0-9]{0,17}$/D', 'distinct'], 'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'send_after' => ['required', 'date_format:H:i'], 'quiet_enabled' => ['required', 'boolean:strict'],
            'quiet_start' => ['required', 'date_format:H:i'], 'quiet_end' => ['required', 'date_format:H:i'],
            'grace_days' => ['required', 'integer', 'between:0,30'], 'detail' => ['required', Rule::in(['generic', 'review'])], 'external_paused' => ['required', 'boolean:strict']];
        foreach (self::RULES as $name => $label) {
            $rules['rules.'.$name] = ['required', 'array:in_app,email'];
            foreach (['in_app', 'email'] as $channel) {
                $rules['rules.'.$name.'.'.$channel] = ['required', 'boolean:strict'];
            }
        }
        if (array_diff(array_keys($raw), array_keys(self::defaults())) !== []) {
            LedgerInput::fail('preferences', 'Unsupported reminder preferences.');
        }
        $data = Validator::make($raw, $rules)->validate();
        if ($data['quiet_enabled'] && $data['quiet_start'] === $data['quiet_end']) {
            LedgerInput::fail('quiet_end', 'Choose different quiet-hour boundaries or turn quiet hours off.');
        }
        $data['grace_days'] = (int) $data['grace_days'];
        sort($data['schedule_ids'], SORT_STRING);

        return $data;
    }

    /** @param array<string,mixed> $data */
    public static function allowed(array $data, CarbonImmutable $now): bool
    {
        $time = $now->setTimezone($data['timezone'])->format('H:i');
        if ($time < $data['send_after']) {
            return false;
        }
        if (! $data['quiet_enabled']) {
            return true;
        }
        $start = $data['quiet_start'];
        $end = $data['quiet_end'];
        $quiet = $start < $end ? $time >= $start && $time < $end : $time >= $start || $time < $end;

        return ! $quiet;
    }

    /** @return array{subject:string,body:string} */
    public static function message(string $detail, string $rule, ?string $due): array
    {
        $body = 'You have an item to review in HalalOS. Open your private reminders page.';
        if ($detail === 'review') {
            $body .= ' '.self::RULES[$rule].($due === null ? '.' : ' for '.$due.'.');
        }

        return ['subject' => 'HalalOS review reminder', 'body' => $body];
    }
}
