<?php

declare(strict_types=1);

namespace App\Domain\Portfolio;

use App\Domain\Ledger\FinancialSnapshot;
use App\Domain\Ledger\LedgerInput;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\ExactDecimal;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

final class PortfolioRead
{
    /** Establish isolation before the first domain query. Writers nested here hold the shared owner lock.
     * @template T
     *
     * @param Closure():T $read
     * @return T */
    public static function coherent(Closure $read): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $read();
        }

        return DB::transaction(function () use ($read): mixed {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            return $read();
        }, 3);
    }

    public function account(User $owner, string $id, bool $lock = false): stdClass
    {
        $p = LedgerInput::owned('portfolio_accounts', $owner, $id);
        $a = LedgerInput::owned('accounts', $owner, (string) $p->account_id, $lock);
        $p->name = $a->name;
        $p->archived = $a->archived_at !== null;

        return $p;
    }

    public function mode(User $owner, string $id, ?string $date = null): ?stdClass
    {
        return DB::table('portfolio_modes')->where('owner_id', $owner->id)->where('portfolio_account_id', $id)
            ->when($date !== null, fn ($q) => $q->where('effective_date', '<=', $date))->orderByDesc('version')->first();
    }

    public function opening(User $owner, string $id, ?string $date = null): ?stdClass
    {
        return DB::table('portfolio_openings')->where('owner_id', $owner->id)->where('portfolio_account_id', $id)
            ->when($date !== null, fn ($q) => $q->where('as_of', '<=', $date))->orderByDesc('version')->first();
    }

    /** @return list<array<string,mixed>> */
    public function rows(User $owner, ?stdClass $opening): array
    {
        if ($opening === null) {
            return [];
        }

        return array_values(DB::table('portfolio_opening_rows as r')->join('portfolio_assets as a', fn ($j) => $j->on('a.id', '=', 'r.asset_id')->on('a.owner_id', '=', 'r.owner_id'))
            ->where('r.owner_id', $owner->id)->where('r.opening_id', $opening->id)->orderBy('r.id')
            ->select(['r.*', 'a.name', 'a.identifier', 'a.market', 'a.source as asset_source', 'a.unsupported_terms', 'a.version as asset_version'])
            ->get()->map(function (stdClass $r): array {
                $currency = Currency::from($r->currency);

                return ['id' => (string) $r->id, 'asset_id' => (string) $r->asset_id, 'asset_family' => $r->asset_family, 'asset_version' => (string) $r->asset_version,
                    'name' => $r->name, 'kind' => $r->kind, 'currency' => $r->currency, 'identifier' => $r->identifier, 'market' => $r->market,
                    'asset_source' => $r->asset_source, 'unsupported_terms' => $r->unsupported_terms,
                    'quantity' => $r->quantity === null ? null : ExactDecimal::fromString((string) $r->quantity)->value,
                    'unit_price' => $r->unit_price === null ? null : ExactDecimal::fromString((string) $r->unit_price)->value,
                    'manual_value' => $r->manual_minor === null ? null : Money::fromMinor($currency, (string) $r->manual_minor)->jsonSerialize(),
                    'basis_known' => $r->basis_known, 'cost_basis' => $r->basis_minor === null ? null : Money::fromMinor($currency, (string) $r->basis_minor)->jsonSerialize()];
            })->all());
    }

    /** @return array<string,mixed> */
    public function state(User $owner, string $id, string $cutoff): array
    {
        $p = $this->account($owner, $id);
        $date = substr($cutoff, 0, 10);
        $mode = $this->mode($owner, $id, $date);
        $opening = $this->opening($owner, $id, $date);
        $rows = $this->rows($owner, $opening);
        $ledger = app(FinancialSnapshot::class)->capture($owner, Currency::from($p->currency), $cutoff, (string) $p->account_id);
        $component = $ledger['components'][0];
        $actual = ['portfolio_id' => (string) $p->id, 'account_id' => (string) $p->account_id, 'name' => $p->name, 'currency' => $p->currency,
            'archived' => $p->archived, 'configured_date' => $p->configured_date, 'platform_label' => $mode->platform_label ?? $p->platform_label,
            'source' => $mode->source ?? $p->source, 'mode' => $mode?->mode, 'mode_id' => $mode === null ? null : (string) $mode->id,
            'mode_version' => $mode === null ? null : (string) $mode->version, 'mode_effective_date' => $mode?->effective_date,
            'aggregate_value' => $mode?->aggregate_minor === null ? null : Money::fromMinor(Currency::from($p->currency), (string) $mode->aggregate_minor)->jsonSerialize(),
            'aggregate_as_of' => $mode?->aggregate_as_of,
            'opening' => $opening === null ? null : ['id' => (string) $opening->id, 'reference' => $opening->reference, 'version' => (string) $opening->version,
                'as_of' => $opening->as_of, 'source' => $opening->source, 'reason' => $opening->reason],
            'rows' => $rows, 'ledger_cash' => $component['native'], 'ledger_reason' => $component['native_reason'], 'ledger_evidence' => $component,
            'cutoff' => $cutoff, 'date' => $date, 'manual_reference_policy' => 'same-UTC-date-only;not-current-quotes'];
        $actual['contribution'] = $this->contribution($actual);

        return $actual;
    }

    /** @param array<string,mixed> $state
     * @param array<string,mixed>|null $proposed
     * @return array<string,mixed> */
    public function contribution(array $state, ?array $proposed = null): array
    {
        $mode = $proposed['mode'] ?? $state['mode'];
        $currency = Currency::from($state['currency']);
        $missing = [];
        $subtotal = '0';
        $selected = [];
        if ($state['date'] < $state['configured_date']) {
            $mode = 'legacy_ledger';
        }
        if ($mode === null) {
            $missing[] = 'mode_not_confirmed';
        } elseif ($mode === 'aggregate') {
            $minor = $proposed === null ? ($state['aggregate_value']['minor'] ?? null) : $proposed['aggregate_minor'];
            $asOf = $proposed === null ? $state['aggregate_as_of'] : $proposed['aggregate_as_of'];
            if ($minor === null || $asOf !== $state['date']) {
                $missing[] = 'aggregate_value_missing_for_date';
            } else {
                $subtotal = $minor;
            }
            $selected[] = 'whole_account_value_including_cash';
        } else {
            if ($state['ledger_cash'] === null) {
                $missing[] = 'ledger_cash:'.($state['ledger_reason'] ?? 'unavailable');
            } else {
                $subtotal = $state['ledger_cash']['minor'];
            }
            $selected[] = 'ledger_cash';
            if ($mode !== 'legacy_ledger') {
                foreach ($state['rows'] as $row) {
                    if (($state['opening']['as_of'] ?? null) !== $state['date']) {
                        $missing[] = 'position:'.$row['id'].':valuation_missing_for_date';

                        continue;
                    }
                    try {
                        $value = $row['kind'] === 'manual_investment'
                            ? ($row['manual_value'] === null ? null : Money::fromMinor($currency, $row['manual_value']['minor']))
                            : ($row['unit_price'] === null ? null : PortfolioInput::product($currency, $row['quantity'], $row['unit_price']));
                        if ($value === null) {
                            $missing[] = 'position:'.$row['id'].':valuation_unknown';
                        } else {
                            $subtotal = bcadd($subtotal, $value->minor, 0);
                        }
                    } catch (InvalidArgumentException) {
                        $missing[] = 'position:'.$row['id'].':out_of_range';
                    }
                }
                $selected[] = 'opening_positions';
            }
        }
        try {
            $total = Money::fromMinor($currency, $subtotal)->jsonSerialize();
        } catch (InvalidArgumentException) {
            $missing[] = 'aggregate_out_of_range';
            $total = null;
        }

        return ['mode' => $mode, 'state' => $missing === [] ? 'complete' : 'incomplete', 'value' => $missing === [] ? $total : null,
            'missing' => $missing, 'selected' => $selected, 'excluded' => $mode === 'aggregate' ? ['ledger_cash', 'opening_positions'] : ['whole_account_value'],
            'as_of' => $state['date'], 'source' => 'manual_reference_and_posted_cash;not_live_market'];
    }

    /** @param array<string,mixed> $state */
    public function fingerprint(array $state): string
    {
        unset($state['cutoff'], $state['ledger_evidence']['name']);

        return LedgerInput::hash($state);
    }

    /** @return list<array<string,mixed>> */
    public function modes(User $owner, string $id): array
    {
        return array_values(DB::table('portfolio_modes')->where('owner_id', $owner->id)->where('portfolio_account_id', $id)->orderByDesc('id')->limit(20)->get()
            ->map(fn (stdClass $r): array => ['id' => (string) $r->id, 'version' => (string) $r->version, 'mode' => $r->mode, 'effective_date' => $r->effective_date,
                'platform_label' => $r->platform_label, 'source' => $r->source, 'reason' => $r->reason, 'preview_id' => (string) $r->preview_id])->all());
    }
}
