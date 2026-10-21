<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Portfolio\PortfolioInput;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final class DashboardSnapshot
{
    public const string POLICY = 'dashboard-v1;selected-valuation-once;current-planning;complete-retained-basis;repeatable-read;no-financial-writes';

    /** @return array<string,mixed> */
    public function current(User $owner, Currency $base): array
    {
        return PortfolioRead::coherent(fn (): array => $this->compose($owner, $base));
    }

    /** @return array<string,mixed> */
    private function compose(User $owner, Currency $base): array
    {
        LedgerInput::lock($owner);
        $facts = app(DashboardFacts::class)->capture($owner, $base);
        $settings = $this->settings($owner);
        $actions = DashboardRules::evaluate($facts, $settings['data']);
        $dismissals = DB::table('dashboard_dismissals')->where('owner_id', $owner->id)->orderBy('id')->get()->keyBy('action_key');
        foreach ($actions as &$action) {
            $dismissal = $dismissals->get($action['key']);
            $action['dismissed'] = $dismissal !== null;
            $action['dismissal'] = $dismissal === null ? null : ['id' => (string) $dismissal->id, 'reason' => $dismissal->reason, 'recorded_at' => LedgerInput::utc($dismissal->recorded_at)];
        }
        unset($action);

        return ['schema' => '1', 'policy' => self::POLICY, 'rules_policy' => DashboardRules::POLICY,
            'captured_at' => $facts['cutoff'], 'settings' => $settings, 'facts' => $facts, 'actions' => $actions];
    }

    /** @return array{id:?string,version:string,data:array<string,mixed>} */
    public function settings(User $owner): array
    {
        $row = DB::table('dashboard_settings')->where('owner_id', $owner->id)->orderByDesc('version')->first();

        return ['id' => $row === null ? null : (string) $row->id, 'version' => (string) ($row->version ?? 0), 'data' => $row === null ? DashboardRules::defaults() : json_decode($row->data, true, flags: JSON_THROW_ON_ERROR)];
    }

    /** @param array<string,mixed> $input */
    public function save(User $owner, string $key, array $input): stdClass
    {
        return $this->write($owner, function () use ($owner, $key, $input): stdClass {
            if ($cached = LedgerInput::cached('dashboard_snapshots', $owner, $key, $input)) {
                return $cached;
            }
            $base = PortfolioInput::currency($input['base_currency'] ?? '');
            $this->prior($owner, $input['settings_id'] ?? '');
            $evidence = $this->compose($owner, $base);

            return $this->insert('dashboard_snapshots', $owner, $key, $input,
                ['evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'evidence_hash' => self::digest($evidence)]);
        });
    }

    /** @param array<string,mixed> $input */
    public function configure(User $owner, string $key, array $input): stdClass
    {
        return $this->write($owner, function () use ($owner, $key, $input): stdClass {
            if ($cached = LedgerInput::cached('dashboard_settings', $owner, $key, $input)) {
                return $cached;
            }
            $prior = $this->prior($owner, $input['settings_id'] ?? '');
            $data = DashboardRules::settings($input);

            return $this->insert('dashboard_settings', $owner, $key, $input, ['version' => (int) $prior['version'] + 1,
                'supersedes_id' => $prior['id'], 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'reason' => LedgerInput::text($input['reason'] ?? '', 'reason')]);
        });
    }

    /** @param array<string,mixed> $input */
    public function dismiss(User $owner, string $key, array $input): stdClass
    {
        return $this->write($owner, function () use ($owner, $key, $input): stdClass {
            if ($cached = LedgerInput::cached('dashboard_dismissal_requests', $owner, $key, $input)) {
                return $cached;
            }
            $snapshotId = $input['snapshot_id'] ?? '';
            $evidence = $snapshotId === '' ? $this->compose($owner, PortfolioInput::currency($input['base_currency'] ?? ''))
                : $this->open($owner, LedgerInput::identifier($snapshotId));
            $actionKey = $input['action_key'] ?? '';
            $action = null;
            foreach ($evidence['actions'] as $candidate) {
                if ($candidate['key'] === $actionKey) {
                    $action = $candidate;
                }
            }
            if ($action === null && $snapshotId === '') {
                LedgerInput::fail('action_key', 'This review episode changed before the dismissal could write. No dismissal was recorded for this request. Review the current actions; an exact committed retry still recovers.');
            }
            abort_unless($action !== null, 404);
            $reason = LedgerInput::text($input['reason'] ?? '', 'reason');
            $row = DB::table('dashboard_dismissals')->where('owner_id', $owner->id)->where('action_key', $actionKey)->first();
            if ($row === null) {
                $id = DB::table('dashboard_dismissals')->insertGetId(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(), 'action_key' => $actionKey,
                    'basis' => json_encode($action['basis'], JSON_THROW_ON_ERROR), 'reason' => $reason]);
                $row = LedgerInput::owned('dashboard_dismissals', $owner, (string) $id);
            }

            return $this->insert('dashboard_dismissal_requests', $owner, $key, $input, ['dismissal_id' => $row->id]);
        });
    }

    /** @return array<string,mixed> */
    public function open(User $owner, string $id): array
    {
        $row = LedgerInput::owned('dashboard_snapshots', $owner, $id);
        $evidence = json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(hash_equals($row->evidence_hash, self::digest($evidence)), 409, 'Snapshot evidence failed its integrity check.');

        return $evidence;
    }

    /** @param array<string,mixed> $evidence */
    public static function digest(array $evidence): string
    {
        return LedgerInput::hash(self::canonical($evidence));
    }

    /** @param array<array-key,mixed> $value
     * @return array<array-key,mixed> */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }
        unset($item);

        return $value;
    }

    /** @return array{id:?string,version:string,data:array<string,mixed>} */
    private function prior(User $owner, mixed $expected): array
    {
        $prior = $this->settings($owner);
        if (! is_string($expected) || $expected !== ($prior['id'] ?? '')) {
            LedgerInput::fail('settings_id', 'Rule settings changed before this request could write. Refresh this page to start a new request; an exact committed retry still recovers.');
        }

        return $prior;
    }

    /** @param array<string,mixed> $input
     * @param array<string,mixed> $data */
    private function insert(string $table, User $owner, string $key, array $input, array $data): stdClass
    {
        $id = DB::table($table)->insertGetId(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(), 'idempotency_key' => $key,
            'payload_hash' => LedgerInput::hash($input), ...$data]);

        return LedgerInput::owned($table, $owner, (string) $id);
    }

    private function write(User $owner, callable $operation): stdClass
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return PortfolioRead::coherent(function () use ($owner, $operation): stdClass {
                    LedgerInput::lock($owner);

                    return $operation();
                });
            } catch (QueryException $error) {
                $state = $error->errorInfo[0] ?? '';
                $requestRace = $state === '23505' && preg_match('/dashboard_((snapshot|settings|dismissal)_request|dismissal_episode|settings_version|settings_supersedes_id_key)/', $error->getMessage());
                if ($attempt >= 3 || (! in_array($state, ['40001', '40P01'], true) && ! $requestRace) || DB::transactionLevel() > 0) {
                    throw $error;
                }
            }
        }
    }
}
