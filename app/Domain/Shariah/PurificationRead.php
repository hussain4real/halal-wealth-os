<?php

declare(strict_types=1);

namespace App\Domain\Shariah;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Portfolio\PortfolioInput;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class PurificationRead
{
    /** @return numeric-string */
    public function paid(User $owner, string $series): string
    {
        return PortfolioInput::integer($this->active($owner)->join('purification_obligations as o', 'o.id', '=', 'x.obligation_id')
            ->join('purification_payments as p', 'p.id', '=', 'x.payment_id')->join('journal_entries as j', 'j.id', '=', 'p.journal_id')
            ->where('o.series_id', $series)->where('j.status', 'posted')->sum(DB::raw('x.amount_minor::numeric')));
    }

    /** @return numeric-string */
    public function used(User $owner, string $paymentId): string
    {
        return PortfolioInput::integer($this->active($owner)->where('x.payment_id', $paymentId)->sum(DB::raw('x.amount_minor::numeric')));
    }

    /** @return array<string,mixed> */
    public function obligation(User $owner, stdClass $row): array
    {
        $paid = $this->paid($owner, $row->series_id);
        $outstanding = $row->due_minor === null ? null : bcsub(PortfolioInput::integer($row->due_minor), $paid, 0);
        $excess = $outstanding !== null && bccomp($outstanding, '0', 0) < 0 ? bcsub('0', $outstanding, 0) : '0';
        if ($outstanding !== null && bccomp($outstanding, '0', 0) < 0) {
            $outstanding = '0';
        }
        $changed = DB::table('investment_events')->where('owner_id', $owner->id)->where('reverses_id', $row->distribution_id)->exists();
        $assessment = $row->assessment_id === null ? null : LedgerInput::owned('shariah_assessments', $owner, (string) $row->assessment_id);
        $data = $this->row($row);
        $data['status'] = $outstanding === null ? 'pending' : ($outstanding === '0' ? 'settled' : ($paid === '0' ? 'outstanding' : 'partially_paid'));
        $data['paid'] = $this->money($row->currency, $paid);
        $data['outstanding'] = $this->money($row->currency, $outstanding);
        $data['excess'] = $this->money($row->currency, $excess);
        $data['source_changed'] = $changed;
        $data['pending_explanation'] = $row->due_minor === null ? 'Missing source assessment, instruction/method version, applicable income base or rate/fixed amount. No zero obligation is inferred.' : null;
        $data['assessment'] = $assessment === null ? null : app(AssessmentRead::class)->row($assessment, now('UTC')->toDateString());
        $data['assessment_superseded'] = $assessment !== null && DB::table('shariah_assessments')->where('supersedes_id', $assessment->id)->exists();

        return $data;
    }

    /** @return array<string,mixed> */
    public function payment(User $owner, stdClass $row): array
    {
        $j = LedgerInput::owned('journal_entries', $owner, (string) $row->journal_id);
        $used = $this->used($owner, (string) $row->id);

        return [...$this->row($row), 'journal_status' => $j->status, 'effective_date' => $j->effective_date,
            'allocated' => $this->money($row->currency, $used), 'available' => $this->money($row->currency, bcsub(PortfolioInput::integer($row->amount_minor), $used, 0))];
    }

    /** @return array<string,mixed> */
    public function detail(User $owner, string $id, ?string $after = null): array
    {
        return PortfolioRead::coherent(function () use ($owner, $id, $after): array {
            $requested = LedgerInput::owned('purification_obligations', $owner, $id);
            $current = DB::table('purification_obligations')->where('owner_id', $owner->id)->where('series_id', $requested->series_id)->orderByDesc('version')->firstOrFail();
            $history = DB::table('purification_obligations')->where('owner_id', $owner->id)->where('series_id', $current->series_id)->orderByDesc('id');
            if ($after !== null) {
                $history->where('id', '<', LedgerInput::identifier($after));
            }
            $versions = $history->limit(21)->get();
            $allocations = $this->allocations($owner, $current->series_id)->get()->map(fn ($r): array => $this->allocation($r))->all();
            $payments = DB::table('purification_payments')->where('owner_id', $owner->id)->where('currency', $current->currency)->orderByDesc('id')->limit(100)->get();
            $d = LedgerInput::owned('investment_events', $owner, (string) $current->distribution_id);

            return ['obligation' => $this->obligation($owner, $current), 'requested_id' => $id,
                'distribution' => $this->row($d), 'history' => ['items' => $versions->take(20)->map(fn ($v): array => $this->row($v))->all(), 'next' => $versions->count() > 20 ? (string) $versions[19]->id : null],
                'allocations' => $allocations, 'payments' => $payments->map(fn ($p): array => $this->payment($owner, $p))->all()];
        });
    }

    /** @return array<string,mixed> */
    public function index(User $owner, ?string $after = null): array
    {
        return PortfolioRead::coherent(function () use ($owner, $after): array {
            $q = DB::table('purification_obligations as o')->where('o.owner_id', $owner->id)->whereNotExists(fn (Builder $n) => $n->selectRaw('1')->from('purification_obligations as n')->whereColumn('n.supersedes_id', 'o.id'));
            if ($after !== null) {
                $q->where('o.id', '<', LedgerInput::identifier($after));
            }
            $rows = $q->orderByDesc('o.id')->limit(21)->get();

            return ['obligations' => $rows->take(20)->map(fn ($o): array => $this->obligation($owner, $o))->all(), 'next' => $rows->count() > 20 ? (string) $rows[19]->id : null,
                'payments' => DB::table('purification_payments')->where('owner_id', $owner->id)->orderByDesc('id')->limit(100)->get()->map(fn ($p): array => $this->payment($owner, $p))->all()];
        });
    }

    /** @return array<string,mixed> */
    public function export(User $owner): array
    {
        return PortfolioRead::coherent(fn (): array => [
            'obligations' => DB::table('purification_obligations')->where('owner_id', $owner->id)->orderBy('id')->get()->map(fn ($r): array => $this->obligation($owner, $r))->all(),
            'payments' => DB::table('purification_payments')->where('owner_id', $owner->id)->orderBy('id')->get()->map(fn ($r): array => $this->payment($owner, $r))->all(),
            'allocations' => $this->allocations($owner)->get()->map(fn ($r): array => $this->allocation($r))->all(),
            'releases' => DB::table('purification_releases')->where('owner_id', $owner->id)->orderBy('id')->get()->map(fn ($r): array => $this->row($r))->all(),
        ]);
    }

    /** @return array<string,mixed> */
    public function row(stdClass $r): array
    {
        $data = [];
        foreach ((array) $r as $k => $v) {
            if (in_array($k, ['idempotency_key', 'payload_hash', 'request_payload', 'owner_id'], true)) {
                continue;
            }
            $data[$k] = is_int($v) ? (string) $v : $v;
            if ($k === 'recorded_at') {
                $data[$k] = LedgerInput::utc($v);
            }
        }
        if (isset($r->currency)) {
            foreach (['due', 'base', 'fixed', 'amount', 'fee', 'tax'] as $prefix) {
                $key = $prefix.'_minor';
                if (property_exists($r, $key)) {
                    $data[$prefix] = $this->money($r->currency, $r->$key === null ? null : (string) $r->$key);
                    $data[$key] = $r->$key === null ? null : (string) $r->$key;
                }
            }
        }

        return $data;
    }

    private function active(User $owner): Builder
    {
        return DB::table('purification_allocations as x')->where('x.owner_id', $owner->id)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('purification_releases as r')->whereColumn('r.allocation_id', 'x.id'));
    }

    private function allocations(User $owner, ?string $series = null): Builder
    {
        $q = DB::table('purification_allocations as x')->join('purification_obligations as o', 'o.id', '=', 'x.obligation_id')
            ->join('purification_payments as p', 'p.id', '=', 'x.payment_id')->join('journal_entries as j', 'j.id', '=', 'p.journal_id')
            ->leftJoin('purification_releases as r', 'r.allocation_id', '=', 'x.id')->where('x.owner_id', $owner->id)
            ->select(['x.*', 'o.version as obligation_version', 'j.status as journal_status', 'p.journal_id', 'r.id as release_id'])->orderByDesc('x.id');
        if ($series !== null) {
            $q->where('o.series_id', $series);
        }

        return $q;
    }

    /** @return array<string,mixed> */
    private function allocation(stdClass $r): array
    {
        return [...$this->row($r), 'active' => $r->release_id === null && $r->journal_status === 'posted'];
    }

    /** @return array{currency:string,minor:string,amount:string}|null */
    private function money(string $currency, ?string $minor): ?array
    {
        return $minor === null ? null : Money::fromMinor(Currency::from($currency), $minor)->jsonSerialize();
    }
}
