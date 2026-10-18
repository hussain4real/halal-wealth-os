<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PortfolioShell from '@/components/PortfolioShell.vue';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import { Button } from '@/components/ui/button';
import type { ExactMoney } from '@/types/accounts';
import type { PortfolioAccount, Page } from '@/types/portfolio';
import type { InvestmentEvent, IpoApplication } from '@/types/investment';
import { eventLabels } from '@/types/investment';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Portfolio', href: '/portfolio' },
            { title: 'Investment activity', href: '#' },
        ],
    },
});
defineProps<{
    account: PortfolioAccount;
    events: Page<InvestmentEvent>;
    book: {
        available: ExactMoney | null;
        reserved: ExactMoney | null;
        receivable: ExactMoney | null;
        range_issues: string[];
        earmark_shortfall: ExactMoney | null;
        realized_gain: ExactMoney | null;
        realized_gain_reason: string | null;
        subscription_count: number;
        subscriptions: Page<IpoApplication>;
    };
}>();
</script>
<template>
    <PortfolioShell
        title="Every fact. One history."
        :back="'/portfolio/' + account.portfolio_id"
    >
        <p
            v-if="book.range_issues.length"
            role="status"
            class="rounded-xl border border-amber-500/40 p-4 text-sm"
        >
            An account-wide total exceeds the supported money display range.
            Review the individual retained receipts; the authenticated export
            preserves the complete exact totals.
        </p>
        <template #intro
            >{{ account.name }} · {{ account.currency }}. Record investment
            facts and follow paid subscriptions through to confirmed shares or
            refunds. Platform labels imply no broker connection.</template
        >
        <div class="flex flex-wrap gap-3">
            <Button v-if="!account.archived" as-child
                ><Link
                    :href="'/portfolio/' + account.portfolio_id + '/events/new'"
                    >Record investment activity</Link
                ></Button
            >
            <Button as-child variant="outline"
                ><a
                    :href="
                        '/portfolio/' + account.portfolio_id + '/events/export'
                    "
                    >Export complete history</a
                ></Button
            >
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <section
                v-for="card in [
                    {
                        title: 'Cash available to record',
                        value: book.available,
                        note: 'Posted investment cash less active IPO earmarks.',
                    },
                    {
                        title: 'IPO earmarks',
                        value: book.reserved,
                        note: 'Cash protection only. No shares or wealth added.',
                    },
                    {
                        title: 'Paid application receivables',
                        value: book.receivable,
                        note: 'Paid, awaiting confirmed allotment or recorded refund.',
                    },
                    {
                        title: 'Realized book result',
                        value: book.realized_gain,
                        note: 'Replayed known-basis sales. Not current market performance or tax advice.',
                    },
                ]"
                :key="card.title"
                class="min-w-0 rounded-2xl border bg-card p-5"
            >
                <h2 class="text-sm text-muted-foreground">{{ card.title }}</h2>
                <p class="mt-3 text-2xl font-semibold [overflow-wrap:anywhere]">
                    <CurrencyDisplay
                        v-if="card.value"
                        :amount="card.value.amount"
                        :currency="account.currency"
                    /><span v-else>Unavailable</span>
                </p>
                <p class="mt-3 text-xs leading-relaxed text-muted-foreground">
                    {{ card.note
                    }}<span
                        v-if="
                            card.title === 'Realized book result' &&
                            book.realized_gain_reason
                        "
                    >
                        Opening basis is unknown for a recorded sale.</span
                    >
                </p>
            </section>
        </div>
        <p
            v-if="
                book.earmark_shortfall?.minor !== '0' && book.earmark_shortfall
            "
            role="status"
            class="rounded-xl border border-amber-500/40 p-4 text-sm"
        >
            Existing cash movements have underfunded IPO earmarks by
            <CurrencyDisplay
                :amount="book.earmark_shortfall.amount"
                :currency="account.currency"
            />. Restore cash or release a supported earmark before recording an
            outflow.
        </p>
        <p class="rounded-xl bg-muted/40 p-4 text-sm leading-relaxed">
            {{
                account.mode === 'aggregate'
                    ? 'Whole-account mode includes its selected total once. Cash, holdings and receivables below are retained facts, excluded from that total. New investment facts require a fresh whole-account value review.'
                    : 'Detailed mode includes signed cash, positions and paid receivables once. Execution prices and book cost are not current market values; changed positions remain explicitly unvalued.'
            }}
        </p>
        <section class="rounded-2xl border bg-card">
            <header class="border-b p-5">
                <h2 class="text-lg font-semibold">
                    IPO applications
                    <span class="text-sm font-normal text-muted-foreground"
                        >· {{ book.subscription_count }} total</span
                    >
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Intention → earmark → paid receivable → confirmed allotment
                    / refund.
                </p>
            </header>
            <p
                v-if="!book.subscriptions.items.length"
                class="p-6 text-sm text-muted-foreground"
            >
                No applications on this page. An intention alone never becomes
                owned shares.
            </p>
            <div
                v-for="application in book.subscriptions.items"
                :key="application.id"
                class="border-b p-5 last:border-b-0"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <Link
                        class="font-medium underline-offset-4 hover:underline"
                        :href="
                            '/portfolio/' +
                            account.portfolio_id +
                            '/events/' +
                            application.id
                        "
                        >{{ application.name }}</Link
                    ><span class="rounded-full bg-muted px-3 py-1 text-xs">{{
                        application.cancelled
                            ? 'Cancelled · history retained'
                            : application.receivable.minor !== '0'
                              ? 'Paid receivable pending'
                              : application.paid.minor !== '0'
                                ? 'Settled amounts retained'
                                : 'Intention / earmark only'
                    }}</span>
                </div>
                <dl
                    class="mt-4 grid gap-4 text-sm sm:grid-cols-3 lg:grid-cols-6"
                >
                    <div
                        v-for="[label, value] in [
                            ['Intended', application.planned],
                            ['Earmarked', application.reserved],
                            ['Paid', application.paid],
                            ['Allotted cost', application.allotted],
                            ['Refunded', application.refunded],
                            ['Remaining receivable', application.receivable],
                        ] as const"
                        :key="label"
                    >
                        <dt class="text-xs text-muted-foreground">
                            {{ label }}
                        </dt>
                        <dd class="mt-1 font-medium break-all">
                            <CurrencyDisplay
                                :amount="value.amount"
                                :currency="account.currency"
                            />
                        </dd>
                    </div>
                </dl>
            </div>
            <Link
                v-if="book.subscriptions.next"
                class="m-5 inline-block text-sm underline"
                :href="
                    '/portfolio/' +
                    account.portfolio_id +
                    '/events?applications_after=' +
                    book.subscriptions.next
                "
                >Older applications</Link
            >
        </section>
        <section class="overflow-hidden rounded-2xl border bg-card">
            <header class="border-b p-5">
                <h2 class="text-lg font-semibold">Retained activity</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Business-date replay uses all applicable history.
                    Corrections retain their original order; this list shows
                    recording history.
                </p>
            </header>
            <p
                v-if="!events.items.length"
                class="p-6 text-sm text-muted-foreground"
            >
                No activity recorded yet. Add a fact when you have supporting
                evidence.
            </p>
            <Link
                v-for="event in events.items"
                :key="event.id"
                class="flex flex-wrap items-center justify-between gap-3 border-b p-5 transition last:border-b-0 hover:bg-muted/40"
                :href="
                    '/portfolio/' + account.portfolio_id + '/events/' + event.id
                "
                ><div class="min-w-0">
                    <p class="font-medium [overflow-wrap:anywhere]">
                        {{ eventLabels[event.kind] }} · {{ event.asset_name }}
                    </p>
                    <p
                        class="mt-1 text-xs [overflow-wrap:anywhere] text-muted-foreground"
                    >
                        UTC {{ event.effective_date }} ·
                        {{ event.action_reference
                        }}<span v-if="event.reversed_by">
                            · Reversed, retained</span
                        >
                    </p>
                </div>
                <p class="text-sm font-medium">
                    <CurrencyDisplay
                        :amount="event.cash_effect.amount"
                        :currency="account.currency"
                    /><span
                        class="ml-2 text-xs font-normal text-muted-foreground"
                        >cash effect</span
                    >
                </p></Link
            >
            <Link
                v-if="events.next"
                class="m-5 inline-block text-sm underline"
                :href="
                    '/portfolio/' +
                    account.portfolio_id +
                    '/events?after=' +
                    events.next
                "
                >Older activity</Link
            >
        </section>
    </PortfolioShell>
</template>
