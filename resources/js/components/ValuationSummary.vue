<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { ExactMoney } from '@/types/accounts';
import type { ValuationResult } from '@/types/valuation';
defineProps<{ result: ValuationResult }>();
const dimension = ref('asset');
const money = (m: ExactMoney | null | undefined) =>
    m ? `${m.currency} ${m.amount}` : 'Unavailable';
const reason = (s: string) => s.replaceAll('_', ' ');
</script>
<template>
    <section
        class="overflow-hidden rounded-3xl border bg-card [overflow-wrap:anywhere] shadow-sm"
    >
        <div class="border-b bg-muted/30 p-5 sm:p-8">
            <p v-if="result.ending.total_reason" class="mb-3 text-sm">
                {{ reason(result.ending.total_reason) }}. Exact component
                amounts remain below.
            </p>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p
                    class="text-xs font-semibold tracking-widest text-muted-foreground uppercase"
                >
                    Dated manual valuation · {{ result.base_currency }}
                </p>
                <span class="rounded-full border px-3 py-1 text-sm">{{
                    result.ending.state === 'complete'
                        ? 'Complete coverage'
                        : 'Partial · evidence needed'
                }}</span>
            </div>
            <p
                class="mt-5 text-3xl font-semibold tracking-tight [overflow-wrap:anywhere] sm:text-5xl"
            >
                {{ money(result.ending.total) }}
            </p>
            <p v-if="!result.ending.total" class="mt-3 text-sm">
                Known covered subtotal:
                {{ money(result.ending.covered_subtotal) }}. Missing components
                remain unavailable.
            </p>
            <p class="mt-4 text-sm text-muted-foreground">
                {{ result.ending.covered_components }} of
                {{ result.ending.component_count }} selected components valued ·
                as of {{ result.cutoff }}
            </p>
        </div>
        <div
            v-if="result.performance"
            class="grid gap-6 p-5 sm:grid-cols-2 sm:p-8"
        >
            <div>
                <h2 class="text-sm font-medium text-muted-foreground">
                    Simple value return · {{ result.base_currency }}
                </h2>
                <p class="mt-2 text-3xl font-semibold">
                    {{
                        result.performance.base_return
                            ? result.performance.base_return.percent + '%'
                            : 'Unavailable'
                    }}
                </p>
                <p class="mt-2 text-sm">
                    Monetary change
                    {{ money(result.performance.monetary_change) }}
                </p>
            </div>
            <div>
                <h2 class="text-sm font-medium text-muted-foreground">
                    Native return ·
                    {{
                        result.performance.native_currency ?? 'mixed currencies'
                    }}
                </h2>
                <p class="mt-2 text-3xl font-semibold">
                    {{
                        result.performance.native_return
                            ? result.performance.native_return.percent + '%'
                            : 'Unavailable'
                    }}
                </p>
                <p class="mt-2 text-sm">
                    Native monetary change
                    {{ money(result.performance.native_monetary_change) }}
                </p>
            </div>
            <div class="space-y-2 text-sm sm:col-span-2">
                <p>
                    Period: {{ result.start }} → {{ result.cutoff }}.
                    Business-date movements use (start date, end date]; prices
                    and FX use the exact cutoffs.
                </p>
                <p v-if="result.performance.eligible">
                    Supported period with no external flows. Income and fees
                    already affect ending cash and are not added again.
                </p>
                <ul v-else class="list-disc space-y-1 pl-5">
                    <li v-for="r in result.performance.reasons" :key="r">
                        {{ reason(r) }}
                    </li>
                </ul>
                <p v-if="result.performance.native_movement_at_start_FX">
                    Native movement at starting FX:
                    {{ money(result.performance.native_movement_at_start_FX) }}
                    · FX and settlement effect:
                    {{ money(result.performance.FX_and_settlement_effect) }}.
                </p>
                <p class="text-muted-foreground">
                    {{ result.performance.cash_flow_adjusted_metrics }}
                </p>
                <details v-if="result.performance.external_movements.length">
                    <summary class="cursor-pointer py-2 font-medium">
                        External / unsupported movements ({{
                            result.performance.external_movements.length
                        }})
                    </summary>
                    <p
                        v-for="f in result.performance.external_movements"
                        :key="f.journal_id"
                        class="py-2"
                    >
                        Journal {{ f.journal_id }} · {{ f.effective_date }} ·
                        {{ f.kind
                        }}<span
                            v-for="leg in f.legs"
                            :key="leg.account_id"
                            class="block text-muted-foreground"
                            >Account {{ leg.account_id }} · {{ leg.currency }}
                            {{ leg.minor }} minor units</span
                        >
                    </p>
                </details>
            </div>
        </div>
    </section>
    <section
        v-if="result.ending.financial_results?.length"
        class="rounded-2xl border bg-card p-5 [overflow-wrap:anywhere] sm:p-7"
    >
        <h2 class="text-xl font-semibold">Recorded gains, income and fees</h2>
        <p class="mt-2 text-sm text-muted-foreground">
            {{
                result.start
                    ? 'Selected period only'
                    : 'Retained lifetime history'
            }}. Net dividend income includes deductions. These cash effects are
            already present in ending value.
        </p>
        <article
            v-for="a in result.ending.financial_results"
            :key="a.account_id"
            class="mt-5 space-y-2 border-t pt-4 text-sm"
        >
            <h3 class="font-medium">{{ a.account }}</h3>
            <p>
                Security-sale realized gain:
                {{ money(a.results.native_realized_gain) }} native ·
                {{ money(a.results.realized_gain) }} at transaction FX
            </p>
            <p>
                Net income: {{ money(a.results.native_net_income) }} native ·
                {{ money(a.results.net_income) }} at transaction FX
            </p>
            <p>
                Standalone fees:
                {{ money(a.results.native_standalone_fees) }} native ·
                {{ money(a.results.standalone_fees) }} at transaction FX
            </p>
            <p>
                Paid receivable refund FX gain/loss:
                {{ money(a.results.receivable_refund_FX_gain) }}
            </p>
            <p class="text-xs text-muted-foreground">
                Negative fee amounts represent cash outflow. Missing transaction
                evidence remains unavailable; all input references are retained
                in complete export.
            </p>
        </article>
    </section>
    <section
        class="rounded-2xl border bg-card p-5 [overflow-wrap:anywhere] sm:p-7"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold">Allocation with context</h2>
            <label class="flex items-center gap-2 text-sm"
                >Group by<select
                    v-model="dimension"
                    class="min-h-11 rounded-lg border bg-background px-3"
                >
                    <option
                        v-for="d in [
                            'asset',
                            'currency',
                            'geography',
                            'account',
                        ]"
                        :key="d"
                        :value="d"
                    >
                        {{ d }}
                    </option>
                </select></label
            >
        </div>
        <p class="mt-3 text-sm text-muted-foreground">
            {{ result.ending.allocation.coverage }} coverage. Denominator:
            {{ result.ending.allocation.denominator_minor }}
            {{ result.base_currency }} minor units —
            {{ result.ending.allocation.denominator }}. Unknown geography and
            whole-account look-through stay explicit.
        </p>
        <div class="mt-5 divide-y">
            <div
                v-for="(g, i) in result.ending.allocation.dimensions[dimension]"
                :key="i"
                class="grid grid-cols-[minmax(0,1fr)_auto] gap-3 py-4"
            >
                <div class="min-w-0">
                    <p class="font-medium [overflow-wrap:anywhere]">
                        {{ g.label }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ g.minor }} {{ result.base_currency }} minor units ·
                        {{ g.known_components }} covered<span
                            v-if="g.missing_components"
                        >
                            · {{ g.missing_components }} missing</span
                        >
                    </p>
                </div>
                <p class="self-center font-mono text-sm">
                    {{ g.weight ? g.weight.percent + '%' : 'No percentage' }}
                </p>
            </div>
        </div>
        <p class="mt-4 text-xs text-muted-foreground">
            {{ result.ending.allocation.percentage_policy }}. This signed
            breakdown preserves liabilities and negative cash.
        </p>
    </section>
    <section
        v-if="result.ending.components"
        class="space-y-4 [overflow-wrap:anywhere]"
    >
        <div>
            <h2 class="text-xl font-semibold">
                Selected values and their evidence
            </h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Missing value differs from zero. Book cost differs from market
                value. Manual observations are not live quotes.
            </p>
        </div>
        <article
            v-for="(c, i) in result.ending.components"
            :key="i"
            class="min-w-0 rounded-2xl border bg-card p-5 sm:p-6"
        >
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <p
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        {{ c.kind }} · {{ c.account }}
                    </p>
                    <h3
                        class="mt-2 text-lg font-semibold [overflow-wrap:anywhere]"
                    >
                        {{ c.asset }}
                    </h3>
                    <p
                        v-if="c.quantity"
                        class="mt-1 font-mono text-xs break-all"
                    >
                        {{ c.quantity }} units
                    </p>
                </div>
                <div class="text-left sm:text-right">
                    <p class="text-lg font-semibold">
                        {{ money(c.converted) }}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Native {{ money(c.native) }}
                    </p>
                </div>
            </div>
            <p
                v-if="c.reason"
                class="mt-3 rounded-lg border border-dashed p-3 text-sm"
            >
                {{ reason(c.reason) }}. Add or correct the relevant price, FX or
                account evidence.
            </p>
            <dl
                v-if="c.kind === 'holding'"
                class="mt-5 grid gap-4 border-t pt-4 text-sm sm:grid-cols-2"
            >
                <div>
                    <dt class="text-muted-foreground">
                        Native book cost / unrealized gain
                    </dt>
                    <dd class="mt-1">
                        {{ money(c.native_cost) }} /
                        {{ money(c.native_unrealized_gain) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">
                        Historical FX book cost / unrealized gain
                    </dt>
                    <dd class="mt-1">
                        {{ money(c.base_cost) }} /
                        {{ money(c.base_unrealized_gain) }}
                    </dd>
                    <dd
                        v-if="c.cost_reason"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        {{ reason(c.cost_reason) }}
                    </dd>
                </div>
            </dl>
            <Link
                v-if="c.kind === 'holding' && c.portfolio_account_id"
                :href="'/portfolio/' + c.portfolio_account_id + '/prices'"
                class="mt-3 inline-flex min-h-11 items-center text-sm underline"
                >Add or review manual price evidence →</Link
            >
            <details class="mt-4 text-sm">
                <summary class="min-h-11 cursor-pointer py-3 font-medium">
                    Inspect price, FX and classification evidence
                </summary>
                <div class="space-y-3 break-words text-muted-foreground">
                    <p v-if="c.price">
                        Manual price {{ c.price.value }} {{ c.currency }} ·
                        {{ c.price.source }} ·
                        {{
                            c.price.id
                                ? 'observation ' +
                                  c.price.id +
                                  ' / version ' +
                                  c.price.version
                                : 'opening ' + c.price.opening_id
                        }}<span class="block"
                            >Effective
                            {{ c.price.effective_at ?? c.price.effective_date }}
                            · observed
                            {{
                                c.price.observed_at ?? 'opening statement date'
                            }}</span
                        >
                    </p>
                    <p v-else>
                        No unit-price observation applies to this component.
                    </p>
                    <p v-if="c.fx">
                        FX {{ c.fx.value }} · {{ c.fx.source }} · observation
                        {{ c.fx.id }} / version {{ c.fx.version
                        }}<span class="block"
                            >Effective {{ c.fx.effective_at }} · observed
                            {{ c.fx.observed_at }}</span
                        >
                    </p>
                    <p v-else>
                        Same-currency / zero conversion, or FX unavailable.
                    </p>
                    <p>
                        Geography: {{ c.geography
                        }}<span v-if="c.geography_source">
                            · {{ c.geography_source }}</span
                        >
                    </p>
                </div>
            </details>
        </article>
    </section>
</template>
