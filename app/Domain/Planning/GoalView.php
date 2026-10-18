<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Ledger\LedgerInput;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

final class GoalView
{
    /** @return array<string, mixed> */
    public function index(User $owner, string $month, ?string $before = null): array
    {
        return DB::transaction(function () use ($owner, $month, $before): array {
            LedgerInput::lock($owner);
            $query = DB::table('goal_plans as g')->where('g.owner_id', $owner->id)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('goal_plans as n')->whereColumn('n.supersedes_id', 'g.id'))->orderByDesc('g.id');
            if ($before !== null) {
                $query->where('g.id', '<', LedgerInput::identifier($before));
            }
            $rows = $query->limit(21)->get();
            $essential = app(EssentialRunRate::class)->month($owner, $month);
            $goals = [];
            $roots = [];
            $cashByAccount = [];
            $idsByAccount = [];
            $reservedByAccount = [];
            $backingService = app(GoalBacking::class);
            foreach ($rows->take(20) as $row) {
                $root = app(GoalService::class)->root($owner, $row);
                $roots[(string) $row->id] = $root;
                if ($row->mode === 'reserved' && ! $row->closed) {
                    $idsByAccount[(string) $row->account_id][] = (string) $root->id;
                }
            }
            foreach ($idsByAccount as $accountId => $ids) {
                $cashByAccount[$accountId] = $backingService->cash($backingService->account($owner, (string) $accountId));
                $reservedByAccount[$accountId] = $backingService->reserved($owner, (string) $accountId, $cashByAccount[$accountId]['cash'], $ids);
            }
            foreach ($rows->take(20) as $row) {
                $root = $roots[(string) $row->id];
                $backing = null;
                if ($row->mode === 'reserved' && ! $row->closed) {
                    $r = $reservedByAccount[(string) $row->account_id][(string) $root->id] ?? ['nominal' => '0', 'backed' => '0'];
                    $cash = $cashByAccount[(string) $row->account_id];
                    $backed = $cash['cash'] === null ? null : $r['backed'];
                    $backing = ['nominal' => $r['nominal'], 'backed' => $backed, 'shortfall' => $backed === null ? null : bcsub(PlanningInput::minor($r['nominal']), PlanningInput::minor($backed), 0), 'reason' => $cash['reason']];
                }
                $goals[] = $this->summary($owner, $row, $essential[$row->currency] ?? null, $root, $backing);
            }

            return ['goals' => $goals, 'month' => $month, 'next' => $rows->count() > 20 ? (string) $rows[19]->id : null, 'today' => BudgetCalendar::today()];
        });
    }

    /** @return array<string, mixed> */
    public function show(User $owner, string $id, string $month, ?string $before = null, ?string $reservationBefore = null, ?string $versionBefore = null): array
    {
        return DB::transaction(function () use ($owner, $id, $month, $before, $reservationBefore, $versionBefore): array {
            LedgerInput::lock($owner);
            $goal = LedgerInput::owned('goal_plans', $owner, $id);
            $latest = DB::table('goal_plans')->where('owner_id', $owner->id)->where('family_reference', $goal->family_reference)->orderByDesc('version')->firstOrFail();
            $root = app(GoalService::class)->root($owner, $goal);
            $essential = app(EssentialRunRate::class)->month($owner, $month);
            $rows = $this->movements($owner, (string) $root->id, (string) $root->account_id, $before);
            $reservations = DB::table('goal_reservations as r')->leftJoin('goal_releases as rel', 'rel.reservation_id', '=', 'r.id')->where('r.owner_id', $owner->id)->where('r.goal_id', $root->id);
            if ($reservationBefore !== null) {
                $reservations->where('r.id', '<', LedgerInput::identifier($reservationBefore));
            }
            $rs = $reservations->select('r.*', 'rel.reason as release_reason', 'rel.id as release_id')->orderByDesc('r.id')->limit(21)->get();
            $versionQuery = DB::table('goal_plans')->where('owner_id', $owner->id)->where('family_reference', $root->family_reference);
            if ($versionBefore !== null) {
                $versionQuery->where('id', '<', LedgerInput::identifier($versionBefore));
            }
            $versions = $versionQuery->orderByDesc('id')->limit(21)->get();

            return ['goal' => $this->summary($owner, $latest, $essential[$latest->currency] ?? null), 'month' => $month, 'today' => BudgetCalendar::today(),
                'movements' => $rows['rows'], 'movement_next' => $rows['next'],
                'reservations' => $rs->take(20)->map(fn ($r): array => ['id' => (string) $r->id, 'reference' => $r->reference, 'amount' => BudgetActivity::money($root->currency, (string) $r->amount_minor), 'reason' => $r->reason, 'released' => $r->release_id !== null, 'release_reason' => $r->release_reason])->all(),
                'reservation_next' => $rs->count() > 20 ? (string) $rs[19]->id : null,
                'version_next' => $versions->count() > 20 ? (string) $versions[19]->id : null, 'versions' => $versions->take(20)->map(fn ($v): array => ['id' => (string) $v->id, 'version' => (string) $v->version, 'reason' => $v->reason, 'closed' => $v->closed, 'created_at' => $v->created_at])->all()];
        });
    }

    /** @return array<string, mixed> */
    public function accounts(User $owner, string $search, ?string $before): array
    {
        return DB::transaction(function () use ($owner, $search, $before): array {
            LedgerInput::lock($owner);
            $q = DB::table('accounts')->where('owner_id', $owner->id)->whereIn('type', ['bank', 'cash'])->whereNull('archived_at')->where('name', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%')->orderByDesc('id');
            if ($before !== null) {
                $q->where('id', '<', LedgerInput::identifier($before));
            }
            $rows = $q->limit(21)->get();
            $list = [];
            foreach ($rows->take(20) as $a) {
                $account = app(GoalBacking::class)->account($owner, (string) $a->id);
                $cash = app(GoalBacking::class)->cash($account);
                $list[] = ['id' => (string) $a->id, 'name' => $a->name, 'currency' => $a->currency_code,
                    'cash' => $cash['cash'] === null ? null : BudgetActivity::money($a->currency_code, $cash['cash']), 'reason' => $cash['reason'],
                    'reserved' => BudgetActivity::money($a->currency_code, app(GoalBacking::class)->nominal($owner, (string) $a->id))];
            }

            return ['rows' => $list, 'next' => $rows->count() > 20 ? (string) $rows[19]->id : null];
        });
    }

    /** @param array{nominal: string, backed: ?string, shortfall: ?string, reason: ?string}|null $backing
     * @return array<string, mixed> */
    private function summary(User $owner, stdClass $goal, ?string $essential, ?stdClass $root = null, ?array $backing = null): array
    {
        $root ??= app(GoalService::class)->root($owner, $goal);
        $account = app(GoalBacking::class)->account($owner, (string) $root->account_id);
        $backing ??= app(GoalBacking::class)->goal($owner, $root, $goal);
        $projection = app(GoalProjection::class)->calculate($goal, $backing['backed'], $essential);

        return ['id' => (string) $goal->id, 'root_id' => (string) $root->id, 'reference' => $goal->reference, 'name' => $goal->name, 'currency' => $goal->currency,
            'mode' => $goal->mode, 'account_id' => (string) $account->id, 'account_name' => $account->name, 'account_archived' => $account->archived_at !== null,
            'target' => BudgetActivity::money($goal->currency, (string) $goal->target_minor), 'opening' => BudgetActivity::money($goal->currency, (string) $goal->opening_minor),
            'nominal' => BudgetActivity::money($goal->currency, $backing['nominal']), 'backed' => $backing['backed'] === null ? null : BudgetActivity::money($goal->currency, $backing['backed']),
            'shortfall' => $backing['shortfall'] === null ? null : BudgetActivity::money($goal->currency, $backing['shortfall']), 'backing_reason' => $backing['reason'],
            'essential' => $essential === null ? null : BudgetActivity::money($goal->currency, $essential),
            'contribution' => BudgetActivity::money($goal->currency, (string) $goal->contribution_minor), 'policy' => $goal->policy, 'first_deposit' => $goal->first_deposit,
            'target_date' => $goal->target_date, 'standing_order' => $goal->standing_order, 'reminders' => false, 'closed' => $goal->closed, 'version' => (string) $goal->version,
            'projection' => [...$projection, 'remaining' => $projection['remaining_minor'] === null ? null : BudgetActivity::money($goal->currency, $projection['remaining_minor'])]];
    }

    /** @return array{rows: array<int, array<string, mixed>>, next: ?string} */
    private function movements(User $owner, string $rootId, string $accountId, ?string $before): array
    {
        $q = DB::table('postings as p')->join('journal_entries as j', 'j.id', '=', 'p.journal_id')
            ->leftJoin('goal_funding_links as l', fn ($join) => $join->on('l.posting_id', '=', 'p.id')->where('l.goal_id', $rootId)->where('l.owner_id', $owner->id))
            ->where('p.owner_id', $owner->id)->where('p.account_id', $accountId)->where('j.owner_id', $owner->id)->whereIn('j.status', ['posted', 'reversed']);
        if ($before !== null) {
            $q->where('p.id', '<', LedgerInput::identifier($before));
        }
        $rows = $q->select('p.id', 'p.amount_minor', 'p.currency_code', 'j.id as journal_id', 'j.reference', 'j.description', 'j.kind', 'j.status', 'j.effective_date', 'j.reverses_journal_id', 'j.replaces_journal_id', 'l.id as link_id', 'l.reason as link_reason')->orderByDesc('p.id')->limit(21)->get();

        return ['rows' => $rows->take(20)->map(fn ($r): array => ['posting_id' => (string) $r->id, 'journal_id' => (string) $r->journal_id, 'reference' => $r->reference, 'description' => $r->description,
            'kind' => $r->kind, 'status' => $r->status, 'date' => $r->effective_date, 'amount' => BudgetActivity::money($r->currency_code, (string) $r->amount_minor),
            'linked' => $r->link_id !== null, 'link_reason' => $r->link_reason, 'reverses_journal_id' => $r->reverses_journal_id === null ? null : (string) $r->reverses_journal_id])->all(),
            'next' => $rows->count() > 20 ? (string) $rows[19]->id : null];
    }
}
