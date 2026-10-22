<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeftRight, ArrowUpRight, Plus } from '@lucide/vue';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import LedgerNav from '@/components/LedgerNav.vue';
import { Button } from '@/components/ui/button';
import type { LedgerMoney } from '@/types/ledger';
defineOptions({
    layout: { breadcrumbs: [{ title: 'Currency exchange', href: '/fx' }] },
});
defineProps<{
    operations: {
        id: string;
        reference: string;
        status: string;
        description: string;
        sent: LedgerMoney;
        received: LedgerMoney;
        effective_date: string;
    }[];
}>();
</script>
<template>
    <Head title="Currency exchange" />
    <div class="mx-auto w-full max-w-5xl space-y-7 px-4 py-8 sm:px-8 sm:py-12">
        <LedgerNav />
        <header class="flex flex-wrap items-end justify-between gap-5">
            <div class="max-w-xl">
                <p
                    class="text-xs font-medium tracking-[0.18em] text-muted-foreground uppercase"
                >
                    MONEY ACROSS CURRENCIES
                </p>
                <h1
                    class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl"
                >
                    Every exchange, clearly recorded.
                </h1>
                <p class="mt-3 leading-relaxed text-muted-foreground">
                    Keep what you paid, what you received and the fee together
                    in one auditable record.
                </p>
            </div>
            <Button as-child class="min-h-11"
                ><Link href="/fx/new"
                    ><Plus class="size-4" />Record exchange</Link
                ></Button
            >
        </header>
        <section
            v-if="!operations.length"
            class="rounded-2xl border border-dashed p-8 text-center sm:p-14"
        >
            <ArrowLeftRight class="mx-auto size-9 text-muted-foreground" />
            <h2 class="mt-5 text-xl font-medium">
                Your first exchange starts here
            </h2>
            <p
                class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-muted-foreground"
            >
                Record a manual exchange between two owned accounts in different
                currencies. Both sides and any fee are saved together.
            </p>
            <Button as-child variant="outline" class="mt-6 min-h-11"
                ><Link href="/fx/new">Record a currency exchange</Link></Button
            >
        </section>
        <section
            v-else
            class="overflow-hidden rounded-2xl border bg-card shadow-sm"
        >
            <h2 class="border-b px-5 py-4 font-medium">
                Recorded exchanges
                <span class="ml-2 text-xs font-normal text-muted-foreground"
                    >Latest 30</span
                >
            </h2>
            <Link
                v-for="operation in operations"
                :key="operation.id"
                :href="`/fx/${operation.id}`"
                class="grid min-w-0 gap-4 border-b p-5 last:border-0 hover:bg-muted/30 focus-visible:outline-2 sm:grid-cols-[1fr_auto] sm:items-center"
                ><div class="min-w-0">
                    <p class="font-medium [overflow-wrap:anywhere]">
                        {{ operation.description }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ operation.effective_date }} ·
                        <span class="capitalize">{{ operation.status }}</span>
                    </p>
                </div>
                <div
                    class="flex min-w-0 flex-wrap items-center gap-3 font-medium [overflow-wrap:anywhere]"
                >
                    <CurrencyDisplay
                        :amount="operation.sent.amount"
                        :currency="operation.sent.currency"
                    /><ArrowUpRight
                        class="size-4 shrink-0 text-muted-foreground"
                    /><CurrencyDisplay
                        :amount="operation.received.amount"
                        :currency="operation.received.currency"
                    /></div
            ></Link>
        </section>
        <p class="text-xs leading-relaxed text-muted-foreground">
            Manual bookkeeping. Reporting rates are managed separately from your
            actual paid and received amounts.
        </p>
    </div>
</template>
