<?php

declare(strict_types=1);

namespace App\Domain\Portfolio;

use App\Domain\Ledger\FinancialSnapshot;
use App\Domain\Ledger\LedgerInput;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/** Rebuild from retained facts; no mutable balances, posting, notifications or cached projection truth. */
final class InvestmentReplay
{
    public const string POLICY = 'weighted-average-rational-minor-half-up-final-remainder;effective-UTC-date-stable-order;retrospective-cancellation-v1';

    /** @param array<string,array{cash:numeric-string,exposure:numeric-string,external:numeric-string|null}> $priorCashFrames
     * @param list<string> $checkDates
     * @return array<string,mixed> */
    public function capture(User $owner, string $id, string $cutoff, bool $validateCash = false, array $priorCashFrames = [], array $checkDates = []): array
    {
        $read = app(PortfolioRead::class);
        $account = $read->account($owner, $id);
        $date = substr($cutoff, 0, 10);
        $opening = $read->opening($owner, $id, $date);
        $positions = [];
        foreach ($read->rows($owner, $opening) as $row) {
            $positions[$row['asset_family']] = $row;
        }
        $events = DB::table('investment_events as e')->join('portfolio_assets as a', fn ($q) => $q->on('a.id', '=', 'e.asset_id')->on('a.owner_id', '=', 'e.owner_id'))
            ->where('e.owner_id', $owner->id)->where('e.portfolio_account_id', $id)->where('e.effective_date', '<=', $date)->where('e.kind', '<>', 'reversal')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('investment_events as r')->whereColumn('r.reverses_id', 'e.id')->where('r.owner_id', $owner->id))
            ->orderBy('e.effective_date')->orderBy('e.sort_id')->orderBy('e.id')->select(['e.*', 'a.name', 'a.identifier', 'a.market', 'a.source as asset_source', 'a.unsupported_terms', 'a.version as asset_version', 'a.geography', 'a.geography_source'])->get();
        $subscriptions = [];
        $results = [];
        $runningCash = '0';
        $realized = '0';
        $allGainsKnown = true;
        $baselines = [];
        $cashFrames = [];
        $endOfDay = [];
        foreach ($events as $e) {
            if ($opening !== null && $e->effective_date < $opening->as_of) {
                LedgerInput::fail('effective_date', 'An investment event precedes the opening statement. Correct the opening date/history before recording this fact.');
            }
            $family = $e->asset_family;
            $cash = self::cash($e);
            $gain = null;
            $allocated = null;
            if ($e->kind === 'ipo_intent') {
                $subscriptions[(string) $e->id] = $this->subscription($e);
            } elseif (str_starts_with($e->kind, 'ipo_')) {
                $sid = (string) $e->subscription_id;
                if (! isset($subscriptions[$sid]) || $subscriptions[$sid]['asset_family'] !== $family || $subscriptions[$sid]['cancelled']) {
                    LedgerInput::fail('subscription_id', 'Replay requires an earlier active application for this asset family in this account.');
                }
                $s = &$subscriptions[$sid];
                $amount = PortfolioInput::integer($e->amount_minor);
                if ($e->kind === 'ipo_reserve') {
                    if (bccomp(bcadd(bcadd($s['reserved_minor'], $s['paid_minor'], 0), $amount, 0), $s['planned_minor'], 0) > 0) {
                        LedgerInput::fail('amount', 'The reservation exceeds the remaining intended application.');
                    }
                    $s['reserved_minor'] = bcadd($s['reserved_minor'], $amount, 0);
                } elseif ($e->kind === 'ipo_release') {
                    if (bccomp($amount, $s['reserved_minor'], 0) > 0) {
                        LedgerInput::fail('amount', 'Cannot release more than the retained earmark.');
                    }
                    $s['reserved_minor'] = bcsub($s['reserved_minor'], $amount, 0);
                } elseif ($e->kind === 'ipo_pay') {
                    if (bccomp(bcadd($s['paid_minor'], $amount, 0), $s['planned_minor'], 0) > 0) {
                        LedgerInput::fail('amount', 'Payments exceed the intended application. Record a separate application for another subscription.');
                    }
                    $consumed = bccomp($s['reserved_minor'], $amount, 0) < 0 ? $s['reserved_minor'] : $amount;
                    $s['reserved_minor'] = bcsub($s['reserved_minor'], $consumed, 0);
                    $s['paid_minor'] = bcadd($s['paid_minor'], $amount, 0);
                    $s['receivable_minor'] = bcadd($s['receivable_minor'], $amount, 0);
                } elseif (in_array($e->kind, ['ipo_allot', 'ipo_refund'], true)) {
                    if (bccomp($amount, $s['receivable_minor'], 0) > 0) {
                        LedgerInput::fail('amount', 'Allotment or refund exceeds the paid application receivable. No shares or cash were added.');
                    }
                    $s['receivable_minor'] = bcsub($s['receivable_minor'], $amount, 0);
                    $field = $e->kind === 'ipo_allot' ? 'allotted_minor' : 'refunded_minor';
                    $s[$field] = bcadd($s[$field], $amount, 0);
                } elseif ($e->kind === 'ipo_cancel') {
                    if ($s['receivable_minor'] !== '0') {
                        LedgerInput::fail('subscription_id', 'A paid receivable cannot be erased by cancellation. Record confirmed allotment/refund or an eligible linked correction first.');
                    }
                    $s['reserved_minor'] = '0';
                    $s['cancelled'] = true;
                }
                unset($s);
            }
            if (in_array($e->kind, ['buy', 'sell', 'ipo_allot', 'split'], true)) {
                $row = $positions[$family] ?? $this->position($e);
                if ($e->kind === 'buy' || $e->kind === 'ipo_allot') {
                    $cost = bcadd(PortfolioInput::integer($e->amount_minor), PortfolioInput::integer($e->fee_minor), 0);
                    $row['quantity'] = PortfolioInput::decimal(bcadd($row['quantity'], PortfolioInput::decimal($e->quantity, 'quantity', true), 12), 'quantity');
                    if ($row['basis_known']) {
                        $row['cost_basis'] = $this->money($account->currency, bcadd($row['cost_basis']['minor'], $cost, 0));
                    }
                } elseif ($e->kind === 'sell') {
                    if (bccomp(PortfolioInput::decimal($e->quantity, 'quantity', true), $row['quantity'], 12) > 0) {
                        LedgerInput::fail('quantity', 'Replay would sell more units than held at an affected business date. Correct the dependent history first.');
                    }
                    if ($row['basis_known']) {
                        $allocated = BookCost::allocate($row['cost_basis']['minor'], (string) $e->quantity, $row['quantity']);
                        $gain = bcsub(bcsub(PortfolioInput::integer($e->amount_minor), PortfolioInput::integer($e->fee_minor), 0), $allocated, 0);
                        $this->money($account->currency, $gain);
                        $realized = bcadd($realized, $gain, 0);
                        $row['cost_basis'] = $this->money($account->currency, bcsub($row['cost_basis']['minor'], $allocated, 0));
                    } else {
                        $allGainsKnown = false;
                    }
                    $row['quantity'] = PortfolioInput::decimal(bcsub($row['quantity'], PortfolioInput::decimal($e->quantity, 'quantity', true), 12), 'quantity');
                    if (bccomp($row['quantity'], '0', 12) === 0) {
                        $row['basis_known'] = true;
                        $row['cost_basis'] = $this->money($account->currency, '0');
                    }
                } else {
                    if (bccomp($row['quantity'], '0', 12) === 0) {
                        LedgerInput::fail('quantity', 'A split requires an earlier positive holding.');
                    }
                    $row['quantity'] = BookCost::split($row['quantity'], (string) $e->split_numerator, (string) $e->split_denominator);
                }
                // Execution prices and split-adjusted quantities are not ongoing market observations.
                $row['unit_price'] = null;
                $row['valuation_reason'] = 'units_changed_since_opening_reference';
                $positions[$family] = $row;
            }
            $runningCash = bcadd($runningCash, $cash, 0);
            $reserved = $this->sum($subscriptions, 'reserved_minor');
            $endOfDay[$e->effective_date] = ['cash' => $runningCash, 'reserved' => $reserved];
            $cashFrames[$e->effective_date.':'.$e->sort_id] = ['cash' => $runningCash, 'exposure' => bcsub($reserved, $runningCash, 0), 'external' => null];
            $results[] = ['id' => (string) $e->id, 'kind' => $e->kind, 'effective_date' => $e->effective_date, 'sort_id' => (string) $e->sort_id,
                'asset_id' => (string) $e->asset_id, 'asset_family' => $family, 'subscription_id' => $e->subscription_id === null ? null : (string) $e->subscription_id,
                'cash_minor' => $cash, 'allocated_cost_minor' => $allocated, 'realized_gain_minor' => $gain];
        }

        // A removed income/refund can underfund generic ledger consumption even
        // when no negative investment event remains. Include every actual cash
        // date and the terminal date, so later deposits cannot hide that suffix.
        $days = DB::table('postings as p')->join('journal_entries as j', 'j.id', '=', 'p.journal_id')
            ->where('p.owner_id', $owner->id)->where('p.account_id', $account->account_id)->where('j.owner_id', $owner->id)
            ->whereIn('j.status', ['posted', 'reversed'])->where('j.effective_date', '<=', $date)->distinct()->pluck('j.effective_date')->all();
        $priorDates = array_map(fn (string $key): string => substr($key, 0, 10), array_keys($priorCashFrames));
        $days = array_unique([...$days, ...array_keys($endOfDay), ...$checkDates, ...$priorDates, $date]);
        sort($days, SORT_STRING);
        $cashAtEnd = '0';
        $reservedAtEnd = '0';
        foreach ($days as $day) {
            if (isset($endOfDay[$day])) {
                $cashAtEnd = $endOfDay[$day]['cash'];
                $reservedAtEnd = $endOfDay[$day]['reserved'];
            }
            if (! array_key_exists($day, $baselines)) {
                $baselines[$day] = $this->externalCash($owner, $account, $day);
            }
            $cashFrames[$day.':end'] = ['cash' => $cashAtEnd, 'exposure' => bcsub($reservedAtEnd, $cashAtEnd, 0), 'external' => $baselines[$day]];
        }
        foreach ($cashFrames as $key => &$frame) {
            $frame['external'] = $baselines[substr($key, 0, 10)];
        }
        unset($frame);
        $cashDeficits = $this->cashDeficits($cashFrames, $validateCash, $priorCashFrames);

        $rows = array_values(array_filter($positions, fn (array $r): bool => $r['quantity'] === null || bccomp($r['quantity'], '0', 12) > 0));
        if ($events->isNotEmpty()) {
            foreach ($rows as &$row) {
                $row['id'] = $row['asset_id'];
            }
            unset($row);
            usort($rows, fn (array $a, array $b): int => bccomp($a['id'], $b['id'], 0));
        }

        return ['policy' => self::POLICY, 'rows' => $rows, 'cash_deficits' => $cashDeficits, 'cash_frames' => $cashFrames,
            'source_event_ids' => DB::table('investment_events')->where('owner_id', $owner->id)->where('portfolio_account_id', $id)->where('effective_date', '<=', $date)->orderBy('id')->pluck('id')->map(fn ($v): string => (string) $v)->all(),
            'events' => $results, 'subscriptions' => array_values($subscriptions), 'receivable_minor' => $this->sum($subscriptions, 'receivable_minor'),
            'reserved_minor' => $this->sum($subscriptions, 'reserved_minor'), 'realized_gain' => $allGainsKnown ? $this->money($account->currency, $realized) : null,
            'realized_gain_reason' => $allGainsKnown ? null : 'unknown_opening_basis'];
    }

    /** Compare inclusive event prefixes and end-of-day states on the full old/new union.
     * Generic ledger cash is fixed by the owner lock; only investment cash and earmarks change.
     *
     * @param array<string,array{cash:numeric-string,exposure:numeric-string,external:numeric-string|null}> $frames
     * @param array<string,array{cash:numeric-string,exposure:numeric-string,external:numeric-string|null}> $priorFrames
     * @return array<string,numeric-string|null> */
    private function cashDeficits(array $frames, bool $validateCash, array $priorFrames): array
    {
        $keys = array_unique([...array_keys($frames), ...array_keys($priorFrames)]);
        usort($keys, function (string $a, string $b): int {
            $dateOrder = strcmp(substr($a, 0, 10), substr($b, 0, 10));
            if ($dateOrder !== 0) {
                return $dateOrder;
            }
            $left = substr($a, 11);
            $right = substr($b, 11);

            return $left === $right ? 0 : ($left === 'end' ? 1 : ($right === 'end' ? -1 : bccomp(PortfolioInput::integer($left), PortfolioInput::integer($right), 0)));
        });
        $current = $prior = ['cash' => '0', 'exposure' => '0', 'external' => null];
        $deficits = [];
        foreach ($keys as $key) {
            $current = $frames[$key] ?? $current;
            $prior = $priorFrames[$key] ?? $prior;
            $day = substr($key, 0, 10);
            // A missing event frame carries the actual preceding state. Availability
            // belongs to this date, not the preceding event's possibly different date.
            $external = $frames[$day.':end']['external'];
            $priorExternal = $priorFrames[$day.':end']['external'] ?? null;
            $deficit = $external === null ? null : $this->deficit($current['exposure'], $external);
            $deficits[$key] = $deficit;
            if (! $validateCash) {
                continue;
            }
            $worse = $external === null || $priorExternal === null
                ? bccomp($current['cash'], $prior['cash'], 0) < 0 || bccomp($current['exposure'], $prior['exposure'], 0) > 0
                : bccomp($deficit, $this->deficit($prior['exposure'], $priorExternal), 0) > 0;
            if ($worse) {
                LedgerInput::fail('amount', 'Replay would create or worsen unavailable cash at an affected UTC business date. Existing external shortfalls may be relieved; new outflows and earmarks cannot use protected or future cash.');
            }
        }

        return $deficits;
    }

    /** @param numeric-string $exposure
     * @param numeric-string $external
     * @return numeric-string */
    private function deficit(string $exposure, string $external): string
    {
        return bccomp($exposure, $external, 0) > 0 ? bcsub($exposure, $external, 0) : '0';
    }

    /** @return numeric-string */
    public static function cash(stdClass $e): string
    {
        return match ($e->kind) {
            'buy' => bcsub('0', bcadd(PortfolioInput::integer($e->amount_minor), PortfolioInput::integer($e->fee_minor), 0), 0),
            'sell' => bcsub(PortfolioInput::integer($e->amount_minor), PortfolioInput::integer($e->fee_minor), 0),
            'dividend' => bcsub(bcsub(PortfolioInput::integer($e->amount_minor), PortfolioInput::integer($e->fee_minor), 0), PortfolioInput::integer($e->tax_minor), 0),
            'fee', 'ipo_pay' => bcsub('0', PortfolioInput::integer($e->amount_minor), 0),
            'ipo_refund' => PortfolioInput::integer($e->amount_minor),
            default => '0',
        };
    }

    /** @return array{id:string,reference:string,asset_id:string,asset_family:string,name:string,planned_minor:numeric-string,paid_minor:numeric-string,reserved_minor:numeric-string,receivable_minor:numeric-string,allotted_minor:numeric-string,refunded_minor:numeric-string,cancelled:bool} */
    private function subscription(stdClass $e): array
    {
        return ['id' => (string) $e->id, 'reference' => $e->reference, 'asset_id' => (string) $e->asset_id, 'asset_family' => $e->asset_family,
            'name' => $e->name, 'planned_minor' => PortfolioInput::integer($e->amount_minor), 'paid_minor' => '0', 'reserved_minor' => '0',
            'receivable_minor' => '0', 'allotted_minor' => '0', 'refunded_minor' => '0', 'cancelled' => false];
    }

    /** @return array<string,mixed> */
    private function position(stdClass $e): array
    {
        return ['id' => (string) $e->id, 'asset_id' => (string) $e->asset_id, 'asset_family' => $e->asset_family, 'asset_version' => (string) $e->asset_version,
            'acquired_on' => null, 'geography' => $e->geography, 'geography_source' => $e->geography_source, 'name' => $e->name, 'kind' => $e->asset_kind, 'currency' => $e->currency, 'identifier' => $e->identifier, 'market' => $e->market,
            'asset_source' => $e->asset_source, 'unsupported_terms' => $e->unsupported_terms, 'quantity' => '0', 'unit_price' => null,
            'manual_value' => null, 'basis_known' => true, 'cost_basis' => $this->money($e->currency, '0')];
    }

    /** @param array<string,array<string,mixed>> $rows
     * @return numeric-string */
    private function sum(array $rows, string $field): string
    {
        return array_reduce($rows, fn (string $sum, array $row): string => bcadd(PortfolioInput::integer($sum), PortfolioInput::integer($row[$field]), 0), '0');
    }

    /** @return array{currency:string,minor:numeric-string,amount:string} */
    private function money(string $currency, string $minor): array
    {
        try {
            return Money::fromMinor(Currency::from($currency), $minor)->jsonSerialize();
        } catch (InvalidArgumentException) {
            LedgerInput::fail('amount', 'Replay exceeds the supported native cash range. No partial change was retained.');
        }
    }

    /** @return numeric-string|null */
    private function externalCash(User $owner, stdClass $account, string $date): ?string
    {
        $cutoff = $date === now('UTC')->toDateString() ? now('UTC')->format('Y-m-d\TH:i:s\Z') : $date.'T23:59:59Z';
        $snapshot = app(FinancialSnapshot::class)->capture($owner, Currency::from($account->currency), $cutoff, (string) $account->account_id);
        $native = $snapshot['components'][0]['native'];
        if ($native === null) {
            return null;
        }
        $linked = DB::table('postings as p')->join('investment_event_journals as m', 'm.journal_id', '=', 'p.journal_id')
            ->join('journal_entries as j', 'j.id', '=', 'p.journal_id')->where('p.owner_id', $owner->id)->where('p.account_id', $account->account_id)
            ->where('j.effective_date', '<=', $date)->selectRaw('coalesce(sum(p.amount_minor::numeric),0)::text as minor')->first()->minor;

        return bcsub($native['minor'], $linked, 0);
    }
}
