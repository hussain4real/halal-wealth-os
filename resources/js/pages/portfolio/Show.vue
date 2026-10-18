<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PortfolioShell from '@/components/PortfolioShell.vue';
import PortfolioValue from '@/components/PortfolioValue.vue';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import { Button } from '@/components/ui/button';
import type {
    Page,
    PortfolioAccount,
    Position,
    OpeningEvidence,
} from '@/types/portfolio';
import { modeLabel, kindLabel } from '@/types/portfolio';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Portfolio', href: '/portfolio' },
            { title: 'Account evidence', href: '#' },
        ],
    },
});
defineProps<{
    account: PortfolioAccount;
    positions: Page<Position>;
    mode_history: Page<{
        id: string;
        version: string;
        mode: string;
        effective_date: string;
        platform_label: string;
        source: string;
        reason: string;
    }>;
    opening_history: Page<OpeningEvidence & { row_count: number }>;
}>();
</script>
<template>
    <PortfolioShell :title="account.name"
        ><template #intro
            >{{ account.platform_label }} · {{ account.currency }} ·
            {{
                account.archived
                    ? 'Archived, retained evidence'
                    : 'Manual statement workspace'
            }}. Source: {{ account.source }}.</template
        >
        <div class="flex flex-wrap gap-3">
            <template v-if="!account.archived"
                ><Button as-child
                    ><Link
                        :href="
                            '/portfolio/' + account.portfolio_id + '/opening'
                        "
                        >{{
                            account.opening
                                ? 'Correct opening statement'
                                : 'Record opening positions'
                        }}</Link
                    ></Button
                ><Button as-child variant="outline"
                    ><Link
                        :href="'/portfolio/' + account.portfolio_id + '/mode'"
                        >{{
                            account.mode
                                ? 'Review valuation mode'
                                : 'Choose valuation mode'
                        }}</Link
                    ></Button
                ></template
            ><Button as-child variant="outline"
                ><a :href="'/portfolio/' + account.portfolio_id + '/export'"
                    >Export exact evidence</a
                ></Button
            ><Button as-child variant="outline"
                ><Link :href="'/accounts/' + account.account_id"
                    >Ledger account</Link
                ></Button
            >
        </div>
        <div class="grid gap-5 md:grid-cols-2">
            <PortfolioValue :value="account.contribution" />
            <section class="rounded-2xl border bg-muted/25 p-6">
                <h2 class="text-lg font-semibold">Posted ledger cash</h2>
                <p class="mt-3 text-2xl font-semibold [overflow-wrap:anywhere]">
                    <CurrencyDisplay
                        v-if="account.ledger_cash"
                        :amount="account.ledger_cash.amount"
                        :currency="account.currency"
                    /><span v-else>Unavailable</span>
                </p>
                <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                    {{
                        account.mode === 'aggregate'
                            ? 'Retained for reconciliation. This cash is already included in the whole-account value and is excluded from the selected contribution.'
                            : 'Detailed mode includes this signed cash once, together with the opening positions.'
                    }}
                </p>
                <p class="mt-4 text-xs text-muted-foreground">
                    Opening prices and manual values are reference values for
                    their recorded UTC date only. Older evidence is retained and
                    a complete current value is unavailable. Current-price
                    updates belong to a later phase.
                </p>
            </section>
        </div>
        <section class="overflow-hidden rounded-2xl border bg-card">
            <header class="border-b p-5">
                <h2 class="text-lg font-semibold">
                    Opening positions
                    <span class="text-sm font-normal text-muted-foreground"
                        >· {{ account.position_count }} total</span
                    >
                </h2>
                <p class="mt-2 text-xs text-muted-foreground">
                    {{
                        account.opening
                            ? 'UTC ' +
                              account.opening.as_of +
                              ' · ' +
                              account.opening.source +
                              ' · statement v' +
                              account.opening.version
                            : 'No opening statement recorded. The position book is empty.'
                    }}
                </p>
                <p
                    v-if="account.mode === 'aggregate'"
                    class="mt-2 text-sm font-medium"
                >
                    Retained evidence · all positions excluded from net worth in
                    whole-account mode.
                </p>
            </header>
            <p
                v-if="!positions.items.length"
                class="p-6 text-sm text-muted-foreground"
            >
                No positions on this page. An explicitly empty opening statement
                is allowed.
            </p>
            <article
                v-for="row in positions.items"
                :key="row.id"
                class="min-w-0 space-y-3 border-b p-5 last:border-0"
            >
                <div class="flex flex-wrap justify-between gap-3">
                    <div class="min-w-0">
                        <Link
                            :href="'/assets/' + row.asset_id"
                            class="inline-flex min-h-10 items-center font-semibold [overflow-wrap:anywhere] underline"
                            >{{ row.name }}</Link
                        >
                        <p class="text-xs text-muted-foreground">
                            {{ kindLabel(row.kind) }} · {{ row.currency }} ·
                            asset v{{ row.asset_version }}
                        </p>
                    </div>
                    <span
                        class="self-start rounded-full bg-muted px-3 py-1 text-xs"
                        >{{
                            row.basis_known
                                ? 'Known cost basis'
                                : 'Unknown cost basis'
                        }}</span
                    >
                </div>
                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <template v-if="row.kind !== 'manual_investment'"
                        ><div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">
                                Exact units
                            </dt>
                            <dd class="mt-1 font-mono [overflow-wrap:anywhere]">
                                {{ row.quantity }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">
                                Reference unit price · {{ row.currency }}
                            </dt>
                            <dd class="mt-1 font-mono [overflow-wrap:anywhere]">
                                {{ row.unit_price ?? 'Unknown' }}
                            </dd>
                        </div></template
                    >
                    <div v-else>
                        <dt class="text-xs text-muted-foreground">
                            Manual reference value
                        </dt>
                        <dd class="mt-1">
                            <CurrencyDisplay
                                v-if="row.manual_value"
                                :amount="row.manual_value.amount"
                                :currency="row.currency"
                            /><span v-else>Unknown</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Opening cost basis
                        </dt>
                        <dd class="mt-1">
                            <CurrencyDisplay
                                v-if="row.basis_known && row.cost_basis"
                                :amount="row.cost_basis.amount"
                                :currency="row.currency"
                            /><span v-else>Unknown · no profit calculated</span>
                        </dd>
                    </div>
                </dl>
                <p
                    class="text-xs [overflow-wrap:anywhere] text-muted-foreground"
                >
                    Reference source: {{ row.asset_source
                    }}{{ row.identifier ? ' · ' + row.identifier : ''
                    }}{{ row.market ? ' · ' + row.market : '' }}
                </p>
                <p
                    v-if="row.unsupported_terms"
                    class="text-sm text-amber-900 dark:text-amber-200"
                >
                    Unsupported terms: {{ row.unsupported_terms }}
                </p>
            </article>
            <Link
                v-if="positions.next"
                :href="
                    '/portfolio/' +
                    account.portfolio_id +
                    '?positions_after=' +
                    positions.next
                "
                class="m-5 inline-flex min-h-11 items-center underline"
                >More positions →</Link
            >
        </section>
        <div class="grid gap-5 md:grid-cols-2">
            <section class="min-w-0 rounded-2xl border bg-card p-5">
                <h2 class="text-lg font-semibold">Valuation decisions</h2>
                <p
                    v-if="!mode_history.items.length"
                    class="mt-4 text-sm text-muted-foreground"
                >
                    No mode confirmed yet. Review a preview to complete setup.
                </p>
                <ol class="mt-3 divide-y">
                    <li
                        v-for="m in mode_history.items"
                        :key="m.id"
                        class="space-y-1 py-3 text-sm [overflow-wrap:anywhere]"
                    >
                        <p class="font-medium">
                            v{{ m.version }} · {{ modeLabel(m.mode) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            UTC {{ m.effective_date }} · {{ m.platform_label }}
                        </p>
                        <p>{{ m.reason }}</p>
                        <p class="text-xs text-muted-foreground">
                            Source: {{ m.source }}
                        </p>
                    </li>
                </ol>
                <Link
                    v-if="mode_history.next"
                    :href="
                        '/portfolio/' +
                        account.portfolio_id +
                        '?modes_after=' +
                        mode_history.next
                    "
                    class="inline-flex min-h-11 items-center underline"
                    >Earlier decisions →</Link
                >
            </section>
            <section class="min-w-0 rounded-2xl border bg-card p-5">
                <h2 class="text-lg font-semibold">Opening statement history</h2>
                <p
                    v-if="!opening_history.items.length"
                    class="mt-4 text-sm text-muted-foreground"
                >
                    No opening statement recorded.
                </p>
                <ol class="mt-3 divide-y">
                    <li
                        v-for="b in opening_history.items"
                        :key="b.id"
                        class="space-y-1 py-3 text-sm [overflow-wrap:anywhere]"
                    >
                        <p class="font-medium">
                            v{{ b.version }} · {{ b.row_count }} positions · UTC
                            {{ b.as_of }}
                        </p>
                        <p>{{ b.reason }}</p>
                        <p class="text-xs text-muted-foreground">
                            Source: {{ b.source }}
                        </p>
                    </li>
                </ol>
                <Link
                    v-if="opening_history.next"
                    :href="
                        '/portfolio/' +
                        account.portfolio_id +
                        '?openings_after=' +
                        opening_history.next
                    "
                    class="inline-flex min-h-11 items-center underline"
                    >Earlier statements →</Link
                >
            </section>
        </div>
    </PortfolioShell>
</template>
