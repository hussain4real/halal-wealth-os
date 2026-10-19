<?php

declare(strict_types=1);

namespace App\Domain\Portfolio;

use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** A separate translated pool; native accounting remains InvestmentReplay's retained truth. */
final readonly class TranslatedBookCost
{
    public function __construct(private ValuationFx $fx) {}

    /** @param array<string,mixed> $state
     * @return array<string,mixed> */
    public function capture(User $owner, array $state, Currency $base, ?string $start): array
    {
        $native = Currency::from($state['currency']);
        $pools = [];
        $evidence = [];
        $opening = app(PortfolioRead::class)->opening($owner, $state['portfolio_id'], $state['date']);
        foreach (app(PortfolioRead::class)->rows($owner, $opening) as $row) {
            $translation = $row['cost_basis'] === null ? ['value' => null, 'reason' => 'basis_unknown']
                : ($native !== $base && $row['cost_basis']['minor'] !== '0' && $row['acquired_on'] === null ? ['value' => null, 'reason' => 'opening_acquisition_date_unknown']
                    : $this->fx->convert($owner, $row['cost_basis']['minor'], $native, $base, min(($row['acquired_on'] ?? $opening->as_of).'T23:59:59Z', $state['cutoff']), 'transaction'));
            $pools[$row['asset_family']] = ['quantity' => $row['quantity'], 'minor' => $translation['value']['minor'] ?? null];
            $evidence[] = ['opening_row_id' => $row['id'], 'acquired_on' => $row['acquired_on'], 'translation' => $translation];
        }
        $subscriptions = [];
        $realized = '0';
        $nativeRealized = '0';
        $income = '0';
        $nativeIncome = '0';
        $fees = '0';
        $nativeFees = '0';
        $refundFx = '0';
        $events = DB::table('investment_events')->where('owner_id', $owner->id)->whereIn('id', array_column($state['investment']['events'], 'id'))->get()->keyBy('id');
        foreach ($state['investment']['events'] as $result) {
            $e = $events->get($result['id']);
            $f = $e->asset_family;
            $pool = $pools[$f] ?? ['quantity' => '0', 'minor' => '0'];
            $during = $start === null || $e->effective_date > substr($start, 0, 10);
            $translate = fn (string $minor): array => $this->fx->convert($owner, $minor, $native, $base, min($e->effective_date.'T23:59:59Z', $state['cutoff']), 'transaction');
            $used = [];
            if ($e->kind === 'ipo_pay') {
                $s = $subscriptions[(string) $e->subscription_id] ?? ['native' => '0', 'base' => '0'];
                $used['payment'] = $translate(PortfolioInput::integer($e->amount_minor));
                $s['native'] = bcadd($s['native'], PortfolioInput::integer($e->amount_minor), 0);
                $s['base'] = $this->add($s['base'], $used['payment']['value']['minor'] ?? null);
                $subscriptions[(string) $e->subscription_id] = $s;
            }
            $allotted = null;
            if (in_array($e->kind, ['ipo_allot', 'ipo_refund'], true)) {
                $s = $subscriptions[(string) $e->subscription_id];
                $allotted = $s['base'] === null ? null : ValuationMath::allocate($s['base'], PortfolioInput::integer($e->amount_minor), $s['native']);
                $used['paid_pool_allocation_minor'] = $allotted;
                if ($e->kind === 'ipo_refund') {
                    $used['refund_receipt'] = $translate(PortfolioInput::integer($e->amount_minor));
                    $used['receivable_refund_FX_gain_minor'] = $allotted === null || $used['refund_receipt']['value'] === null ? null : bcsub($used['refund_receipt']['value']['minor'], $allotted, 0);
                    if ($during) {
                        $refundFx = $this->add($refundFx, $used['receivable_refund_FX_gain_minor']);
                    }
                }
                $s['native'] = bcsub($s['native'], PortfolioInput::integer($e->amount_minor), 0);
                $s['base'] = $s['base'] === null || $allotted === null ? null : bcsub($s['base'], $allotted, 0);
                if ($s['native'] === '0') {
                    $s['base'] = '0';
                }
                $subscriptions[(string) $e->subscription_id] = $s;
            }
            if (in_array($e->kind, ['buy', 'ipo_allot'], true)) {
                $used['acquisition'] = $translate(bcadd(PortfolioInput::integer($e->amount_minor), PortfolioInput::integer($e->fee_minor), 0));
                $cost = $used['acquisition']['value']['minor'] ?? null;
                if ($e->kind === 'ipo_allot') {
                    $used['allotment_fee'] = $translate(PortfolioInput::integer($e->fee_minor));
                    $cost = $this->add($allotted, $used['allotment_fee']['value']['minor'] ?? null);
                }
                $pool['minor'] = $this->add($pool['minor'], $cost);
                $pool['quantity'] = bcadd($pool['quantity'], PortfolioInput::decimal($e->quantity, 'quantity', true), 12);
            } elseif ($e->kind === 'sell') {
                $allocated = $pool['minor'] === null ? null : BookCost::allocate($pool['minor'], (string) $e->quantity, $pool['quantity']);
                $used['proceeds'] = $translate(bcsub(PortfolioInput::integer($e->amount_minor), PortfolioInput::integer($e->fee_minor), 0));
                $gain = $allocated === null || $used['proceeds']['value'] === null ? null : bcsub($used['proceeds']['value']['minor'], $allocated, 0);
                $used['allocated_base_cost_minor'] = $allocated;
                $used['realized_base_gain_minor'] = $gain;
                if ($during) {
                    $realized = $this->add($realized, $gain);
                    $nativeRealized = $this->add($nativeRealized, $result['realized_gain_minor']);
                }
                $pool['quantity'] = bcsub($pool['quantity'], PortfolioInput::decimal($e->quantity, 'quantity', true), 12);
                $pool['minor'] = $allocated === null ? null : bcsub($pool['minor'], $allocated, 0);
                if (bccomp($pool['quantity'], '0', 12) === 0) {
                    $pool['minor'] = '0';
                }
            } elseif ($e->kind === 'split') {
                $pool['quantity'] = BookCost::split($pool['quantity'], (string) $e->split_numerator, (string) $e->split_denominator);
            } elseif ($during && in_array($e->kind, ['dividend', 'fee'], true)) {
                $used['net_cash'] = $translate(InvestmentReplay::cash($e));
                if ($e->kind === 'dividend') {
                    $income = $this->add($income, $used['net_cash']['value']['minor'] ?? null);
                    $nativeIncome = bcadd($nativeIncome, InvestmentReplay::cash($e), 0);
                } else {
                    $fees = $this->add($fees, $used['net_cash']['value']['minor'] ?? null);
                    $nativeFees = bcadd($nativeFees, InvestmentReplay::cash($e), 0);
                }
            }
            $pools[$f] = $pool;
            if ($used !== []) {
                $evidence[] = ['event_id' => $result['id'], 'effective_date' => $e->effective_date, 'kind' => $e->kind, 'inputs' => $used];
            }
        }

        return ['receivable_refund_FX_gain' => ValuationMath::money($base, $refundFx), 'pools' => $pools, 'evidence' => $evidence, 'range' => $start === null ? 'lifetime' : '(start_UTC_date,end_UTC_date]',
            'realized_gain' => ValuationMath::money($base, $realized), 'native_realized_gain' => ValuationMath::money($native, $nativeRealized),
            'net_income' => ValuationMath::money($base, $income), 'native_net_income' => ValuationMath::money($native, $nativeIncome),
            'standalone_fees' => ValuationMath::money($base, $fees), 'native_standalone_fees' => ValuationMath::money($native, $nativeFees)];
    }

    private function add(?string $a, ?string $b): ?string
    {
        return $a === null || $b === null ? null : bcadd(PortfolioInput::integer($a), PortfolioInput::integer($b), 0);
    }
}
