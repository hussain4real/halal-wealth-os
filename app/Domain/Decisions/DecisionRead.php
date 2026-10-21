<?php

declare(strict_types=1);

namespace App\Domain\Decisions;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Planning\GoalBacking;
use App\Domain\Portfolio\PortfolioInput;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Shariah\AssessmentRead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

final class DecisionRead
{
    /** @return array{reserved:numeric-string,spent:numeric-string} */
    public function totals(User $owner, string $series, string $tranche): array
    {
        $r = DB::selectOne(<<<'SQL'
SELECT
 (SELECT coalesce(sum(r.amount_minor::numeric),0)::text FROM decision_reservations d JOIN goal_reservations r ON r.id=d.reservation_id
  WHERE d.owner_id=? AND d.series_id=? AND d.tranche_key=? AND NOT EXISTS(SELECT 1 FROM goal_releases rel WHERE rel.reservation_id=r.id)) AS reserved,
 (SELECT coalesce(sum(e.amount_minor::numeric+e.fee_minor::numeric+e.tax_minor::numeric),0)::text FROM decision_event_links d JOIN investment_events e ON e.id=d.event_id
  WHERE d.owner_id=? AND d.series_id=? AND d.tranche_key=? AND NOT EXISTS(SELECT 1 FROM investment_events rev WHERE rev.reverses_id=e.id)) AS spent
SQL, [$owner->id, $series, $tranche, $owner->id, $series, $tranche]);

        return ['reserved' => PortfolioInput::integer($r->reserved), 'spent' => PortfolioInput::integer($r->spent)];
    }

    /** @return array<string,mixed> */
    public function index(User $owner, ?string $before = null, string $status = ''): array
    {
        return PortfolioRead::coherent(function () use ($owner, $before, $status): array {
            $q = DB::table('decision_opportunities as o')->where('o.owner_id', $owner->id)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('decision_opportunities as n')->whereColumn('n.supersedes_id', 'o.id'));
            if ($before !== null) {
                $q->where('o.id', '<', $before);
            }
            if ($status !== '') {
                $q->where('o.status', $status);
            }
            $rows = $q->orderByDesc('o.id')->limit(21)->get();

            return ['opportunities' => $rows->take(20)->map(fn (stdClass $p): array => $this->row($p))->all(), 'next' => $rows->count() > 20 ? (string) $rows[19]->id : null, 'status' => $status];
        });
    }

    /** @return array<string,mixed> */
    public function detail(User $owner, string $id, ?string $historyBefore = null, ?string $reservationBefore = null, ?string $linkBefore = null): array
    {
        return PortfolioRead::coherent(function () use ($owner, $id, $historyBefore, $reservationBefore, $linkBefore): array {
            $p = LedgerInput::owned('decision_opportunities', $owner, $id);
            $current = DB::table('decision_opportunities')->where('owner_id', $owner->id)->where('series_id', $p->series_id)->orderByDesc('version')->firstOrFail();
            $currency = Currency::from($p->currency);
            $history = DB::table('decision_opportunities')->where('owner_id', $owner->id)->where('series_id', $p->series_id);
            if ($historyBefore !== null) {
                $history->where('id', '<', $historyBefore);
            }
            $versions = $history->orderByDesc('id')->limit(21)->get();
            $reservations = DB::table('decision_reservations as d')->join('goal_reservations as r', 'r.id', '=', 'd.reservation_id')->leftJoin('goal_releases as rel', 'rel.reservation_id', '=', 'r.id')
                ->where('d.owner_id', $owner->id)->where('d.series_id', $p->series_id)->select(['d.*', 'r.amount_minor', 'r.goal_id', 'r.account_id', 'rel.id as release_id', 'rel.reason as release_reason']);
            if ($reservationBefore !== null) {
                $reservations->where('d.id', '<', $reservationBefore);
            }
            $rs = $reservations->orderByDesc('d.id')->limit(21)->get();
            $links = DB::table('decision_event_links as d')->join('investment_events as e', 'e.id', '=', 'd.event_id')->where('d.owner_id', $owner->id)->where('d.series_id', $p->series_id)
                ->selectRaw('d.*,e.amount_minor::text,e.fee_minor::text,e.tax_minor::text,e.kind,e.reference AS event_reference,e.portfolio_account_id::text,EXISTS(SELECT 1 FROM investment_events rev WHERE rev.reverses_id=e.id) AS source_changed');
            if ($linkBefore !== null) {
                $links->where('d.id', '<', $linkBefore);
            }
            $ls = $links->orderByDesc('d.id')->limit(21)->get();
            $row = $this->row($p);
            $row['tranches'] = array_map(function (array $t) use ($owner, $p, $currency): array {
                $v = $this->totals($owner, $p->series_id, $t['key']);
                $remainder = bcsub(bcsub(PortfolioInput::integer($t['amount_minor']), $v['reserved'], 0), $v['spent'], 0);

                return [...$t, 'amount' => Money::fromMinor($currency, $t['amount_minor'])->jsonSerialize(), 'reserved_minor' => $v['reserved'], 'spent_minor' => $v['spent'], 'remaining_minor' => $remainder,
                    'reserved_amount' => $this->display($currency, $v['reserved']), 'spent_amount' => $this->display($currency, $v['spent']), 'remaining_amount' => $this->display($currency, $remainder)];
            }, json_decode($p->tranches, true, flags: JSON_THROW_ON_ERROR));
            $assessment = $p->asset_id === null ? null : app(AssessmentRead::class)->view($owner, (string) $p->asset_id, now('UTC')->format('Y-m-d\TH:i:s\Z'));

            return ['opportunity' => $row, 'current_id' => (string) $current->id, 'is_current' => (string) $p->id === (string) $current->id,
                'assessment' => $assessment === null ? null : ['asset' => $assessment['asset'], 'summary' => $assessment['summary']],
                'history' => $versions->take(20)->map(fn (stdClass $v): array => ['id' => (string) $v->id, 'version' => (string) $v->version, 'name' => $v->name, 'reason' => $v->reason, 'recorded_at' => LedgerInput::utc($v->recorded_at)])->all(),
                'history_next' => $versions->count() > 20 ? (string) $versions[19]->id : null,
                'reservations' => $rs->take(20)->map(fn (stdClass $r): array => ['id' => (string) $r->id, 'reference' => $r->reference, 'opportunity_id' => (string) $r->opportunity_id, 'tranche_key' => $r->tranche_key,
                    'reservation_id' => (string) $r->reservation_id, 'goal_id' => (string) $r->goal_id, 'account_id' => (string) $r->account_id, 'amount' => Money::fromMinor($currency, PortfolioInput::integer($r->amount_minor))->jsonSerialize(),
                    'backing' => $this->backing($owner, $r), 'released' => $r->release_id !== null, 'reason' => $r->reason, 'release_reason' => $r->release_reason])->all(),
                'reservations_next' => $rs->count() > 20 ? (string) $rs[19]->id : null,
                'event_links' => $ls->take(20)->map(fn (stdClass $l): array => ['id' => (string) $l->id, 'reference' => $l->reference, 'opportunity_id' => (string) $l->opportunity_id, 'tranche_key' => $l->tranche_key,
                    'event_id' => (string) $l->event_id, 'event_reference' => $l->event_reference, 'portfolio_account_id' => $l->portfolio_account_id, 'kind' => $l->kind, 'source_changed' => (bool) $l->source_changed,
                    'amount' => $this->display($currency, bcadd(bcadd($l->amount_minor, $l->fee_minor, 0), $l->tax_minor, 0)), 'reason' => $l->reason])->all(),
                'links_next' => $ls->count() > 20 ? (string) $ls[19]->id : null];
        });
    }

    /** @return array<string,mixed> */
    public function row(stdClass $p): array
    {
        $c = Currency::from($p->currency);
        $attrs = json_decode($p->attributes, true, flags: JSON_THROW_ON_ERROR);
        $today = now('Asia/Qatar')->toDateString();
        foreach ($attrs['evidence'] as &$e) {
            $e['verified'] = $e['value'] !== null && $e['url'] !== null && $e['official'] && $e['verified_on'] !== null && $e['verified_on'] <= $today && $e['review_on'] !== null && $e['review_on'] >= $today;
        }
        unset($e);

        return ['id' => (string) $p->id, 'reference' => $p->reference, 'series_id' => $p->series_id, 'version' => (string) $p->version,
            'supersedes_id' => $p->supersedes_id === null ? null : (string) $p->supersedes_id, 'name' => $p->name, 'status' => $p->status,
            'asset_id' => $p->asset_id === null ? null : (string) $p->asset_id, 'currency' => $p->currency,
            'target' => $p->target_minor === null ? null : Money::fromMinor($c, PortfolioInput::integer($p->target_minor))->jsonSerialize(),
            'maximum' => $p->maximum_minor === null ? null : Money::fromMinor($c, PortfolioInput::integer($p->maximum_minor))->jsonSerialize(),
            'tranches' => json_decode($p->tranches, true, flags: JSON_THROW_ON_ERROR), 'attributes' => $attrs,
            'concentration' => DecisionMath::concentration($p->target_minor === null ? null : PortfolioInput::integer($p->target_minor), $attrs['concentration'], $today),
            'review_overdue' => $attrs['review_on'] !== null && $attrs['review_on'] < $today,
            'recorded_at' => LedgerInput::utc($p->recorded_at), 'reason' => $p->reason, 'actor_id' => (string) $p->actor_id, 'actor_is_admin' => (bool) $p->actor_is_admin];
    }

    /** @return array<string,string> */
    public function draft(stdClass $p): array
    {
        $r = $this->row($p);
        $a = $r['attributes'];
        $c = Currency::from($p->currency);
        $input = ['prior_id' => (string) $p->id, 'name' => $p->name, 'status' => $p->status, 'currency' => $p->currency, 'asset_id' => $r['asset_id'] ?? '',
            'target' => $r['target']['amount'] ?? '', 'maximum' => $r['maximum']['amount'] ?? '', 'reason' => '',
            'tranches' => json_encode(array_map(fn (array $t): array => ['key' => $t['key'], 'label' => $t['label'], 'condition' => $t['condition'], 'amount' => Money::fromMinor($c, $t['amount_minor'])->amount()], $r['tranches']), JSON_THROW_ON_ERROR),
            'currency_threshold' => $a['concentration']['threshold'] ?? '', 'portfolio_total' => $a['concentration']['total_minor'] === null ? '' : Money::fromMinor($c, $a['concentration']['total_minor'])->amount(),
            'currency_exposure' => $a['concentration']['exposure_minor'] === null ? '' : Money::fromMinor($c, $a['concentration']['exposure_minor'])->amount(),
            'reference_source' => $a['concentration']['source'] ?? '', 'reference_on' => $a['concentration']['on'] ?? '', 'reference_review_on' => $a['concentration']['review_on'] ?? ''];
        foreach (['rationale', 'risks', 'horizon', 'review_triggers', 'review_on'] as $field) {
            $input[$field] = $a[$field] ?? '';
        }
        foreach (['status', 'price', 'eligibility'] as $field) {
            $e = $a['evidence'][$field];
            $input['offer_'.$field] = $e['value'] ?? '';
            $input[$field.'_url'] = $e['url'] ?? '';
            $input[$field.'_verified_on'] = $e['verified_on'] ?? '';
            $input[$field.'_review_on'] = $e['review_on'] ?? '';
            $input[$field.'_official'] = $e['official'] ? '1' : '0';
        }

        return $input;
    }

    /** @return array<string,mixed> */
    public function export(User $owner): array
    {
        return PortfolioRead::coherent(function () use ($owner): array {
            $reservationFacts = DB::table('decision_reservations as d')->join('goal_reservations as r', 'r.id', '=', 'd.reservation_id')->leftJoin('goal_releases as rel', 'rel.reservation_id', '=', 'r.id')
                ->where('d.owner_id', $owner->id)->where('r.owner_id', $owner->id)->select(['r.*', 'd.reservation_id', 'd.currency', 'rel.id as release_id', 'rel.reason as release_reason', 'rel.created_at as released_at'])->orderBy('r.id')->get();
            $eventFacts = DB::table('decision_event_links as d')->join('investment_events as e', 'e.id', '=', 'd.event_id')->where('d.owner_id', $owner->id)->where('e.owner_id', $owner->id)
                ->selectRaw('e.*,(SELECT id FROM investment_events rev WHERE rev.reverses_id=e.id)::text AS reversed_by,(SELECT id FROM investment_events rep WHERE rep.replaces_id=e.id)::text AS replacement_id')->orderBy('e.id')->get();

            return ['opportunities' => DB::table('decision_opportunities')->where('owner_id', $owner->id)->orderBy('id')->get()->map(fn (stdClass $p): array => $this->row($p))->all(),
                'reservations' => DB::table('decision_reservations')->where('owner_id', $owner->id)->orderBy('id')->get()->map(fn (stdClass $r): array => $this->association($r, 'reservation_id'))->all(),
                'actual_event_links' => DB::table('decision_event_links')->where('owner_id', $owner->id)->orderBy('id')->get()->map(fn (stdClass $r): array => $this->association($r, 'event_id'))->all(),
                'reservation_facts' => $reservationFacts->map(fn (stdClass $r): array => ['id' => (string) $r->id, 'reference' => $r->reference, 'goal_id' => (string) $r->goal_id, 'account_id' => (string) $r->account_id,
                    'amount' => Money::fromMinor(Currency::from($r->currency), PortfolioInput::integer($r->amount_minor))->jsonSerialize(), 'reason' => $r->reason, 'recorded_at' => LedgerInput::utc($r->created_at),
                    'released' => $r->release_id !== null, 'release_id' => $r->release_id === null ? null : (string) $r->release_id, 'release_reason' => $r->release_reason, 'released_at' => $r->released_at === null ? null : LedgerInput::utc($r->released_at), 'backing' => $this->backing($owner, $r)])->all(),
                'actual_event_facts' => $eventFacts->map(function (stdClass $e): array {
                    $c = Currency::from($e->currency);
                    $gross = bcadd(bcadd(PortfolioInput::integer($e->amount_minor), PortfolioInput::integer($e->fee_minor), 0), PortfolioInput::integer($e->tax_minor), 0);

                    return ['id' => (string) $e->id, 'reference' => $e->reference, 'portfolio_account_id' => (string) $e->portfolio_account_id, 'asset_id' => (string) $e->asset_id, 'kind' => $e->kind, 'currency' => $e->currency,
                        'amount' => Money::fromMinor($c, PortfolioInput::integer($e->amount_minor))->jsonSerialize(), 'fee' => Money::fromMinor($c, PortfolioInput::integer($e->fee_minor))->jsonSerialize(), 'tax' => Money::fromMinor($c, PortfolioInput::integer($e->tax_minor))->jsonSerialize(),
                        'gross_attributed_amount' => $this->display($c, $gross), 'current_attributed_amount' => $this->display($c, $e->reversed_by === null ? $gross : '0'), 'reversed_by' => $e->reversed_by, 'replacement_id' => $e->replacement_id,
                        'effective_date' => $e->effective_date, 'source' => $e->source, 'reason' => $e->reason, 'recorded_at' => LedgerInput::utc($e->created_at)];
                })->all(),
                'reservation_releases' => DB::table('goal_releases')->where('owner_id', $owner->id)->whereIn('reservation_id', DB::table('decision_reservations')->where('owner_id', $owner->id)->select('reservation_id'))->orderBy('id')->get()->map(fn (stdClass $r): array => ['id' => (string) $r->id, 'reference' => $r->reference, 'reservation_id' => (string) $r->reservation_id, 'reason' => $r->reason, 'recorded_at' => LedgerInput::utc($r->created_at)])->all(),
                'attribution_basis' => 'Original gross purchase or paid-subscription cash including fees/tax, zero on reversal. Later sale proceeds or subscription refunds remain separate investment facts; this is not net invested capital.'];
        });
    }

    /** @return array<string,mixed> */
    private function association(stdClass $r, string $member): array
    {
        return ['id' => (string) $r->id, 'reference' => $r->reference, 'owner_id' => (string) $r->owner_id, 'actor_id' => (string) $r->actor_id, 'actor_is_admin' => (bool) $r->actor_is_admin,
            'opportunity_id' => (string) $r->opportunity_id, 'series_id' => $r->series_id, 'tranche_key' => $r->tranche_key, 'currency' => $r->currency, $member => (string) $r->{$member}, 'reason' => $r->reason, 'recorded_at' => LedgerInput::utc($r->recorded_at)];
    }

    /** @return array{amount:?string,shortfall:?string,reason:?string} */
    private function backing(User $owner, stdClass $r): array
    {
        $c = Currency::from($r->currency);
        if ($r->release_id !== null) {
            return ['amount' => $this->display($c, '0'), 'shortfall' => $this->display($c, '0'), 'reason' => 'released'];
        }
        $service = app(GoalBacking::class);
        $cash = $service->cash($service->account($owner, (string) $r->account_id));
        if ($cash['cash'] === null) {
            return ['amount' => null, 'shortfall' => null, 'reason' => $cash['reason']];
        }
        $row = DB::selectOne(<<<'SQL'
WITH active AS (
 SELECT r.id,r.amount_minor::numeric AS amount,
 coalesce(sum(r.amount_minor::numeric) OVER(ORDER BY r.id ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING),0) AS earlier
 FROM goal_reservations r WHERE r.owner_id=? AND r.account_id=?
 AND NOT EXISTS(SELECT 1 FROM goal_releases rel WHERE rel.reservation_id=r.id)
)
SELECT least(amount,greatest(0,?::numeric-earlier))::text AS backed FROM active WHERE id=?
SQL, [$owner->id, $r->account_id, $cash['cash'], $r->reservation_id]);
        $backed = PortfolioInput::integer($row->backed);

        return ['amount' => $this->display($c, $backed), 'shortfall' => $this->display($c, bcsub(PortfolioInput::integer($r->amount_minor), $backed, 0)), 'reason' => $cash['reason']];
    }

    private function display(Currency $c, string $minor): string
    {
        return bcdiv(PortfolioInput::integer($minor), bcpow('10', (string) $c->exponent(), 0), $c->exponent());
    }
}
