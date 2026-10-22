<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowLeftRight,
    ArrowUpRight,
    Plus,
    ReceiptText,
} from '@lucide/vue';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { Transaction } from '@/types/transactions';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Transactions', href: '/transactions' }] },
});
defineProps<{
    transactions: Transaction[];
    previous: string | null;
    next: string | null;
    has_accounts: boolean;
}>();
</script>

<template>
    <Head title="Transactions" />
    <div class="mx-auto w-full max-w-5xl space-y-8 px-4 py-8 sm:px-8 sm:py-12">
        <header class="flex flex-wrap items-end justify-between gap-5">
            <div>
                <p
                    class="mb-2 text-xs font-medium tracking-widest text-muted-foreground uppercase"
                >
                    Your money, clearly recorded
                </p>
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                    Transactions
                </h1>
                <p
                    class="mt-3 max-w-xl text-sm leading-relaxed text-muted-foreground"
                >
                    A lasting record of what came in, what went out, and what
                    moved between your accounts.
                </p>
            </div>
            <Button as-child class="min-h-11"
                ><Link href="/transactions/new"
                    ><Plus class="size-4" /> Record transaction</Link
                ></Button
            >
        </header>
        <section
            v-if="transactions.length === 0"
            class="rounded-2xl border border-dashed bg-muted/20 px-5 py-16 text-center"
            aria-labelledby="empty-heading"
        >
            <div
                class="mx-auto mb-5 flex size-12 items-center justify-center rounded-2xl border bg-background"
            >
                <ReceiptText class="size-5 text-muted-foreground" />
            </div>
            <h2 id="empty-heading" class="text-xl font-semibold">
                A clear picture starts with one record
            </h2>
            <p
                class="mx-auto mt-3 max-w-sm text-sm leading-relaxed text-muted-foreground"
            >
                {{
                    has_accounts
                        ? 'Record an income, expense or transfer. Your balances will come directly from your posted history.'
                        : 'Add a personal account first, then record your first transaction. No bank connection is needed.'
                }}
            </p>
            <Button as-child class="mt-6 min-h-11" variant="outline"
                ><Link
                    :href="has_accounts ? '/transactions/new' : '/accounts'"
                    >{{
                        has_accounts
                            ? 'Record your first transaction'
                            : 'Add an account'
                    }}</Link
                ></Button
            >
        </section>
        <section
            v-else
            aria-labelledby="history-heading"
            class="overflow-hidden rounded-2xl border bg-card shadow-sm"
        >
            <div
                class="flex items-center justify-between gap-3 border-b px-5 py-4 sm:px-6"
            >
                <h2 id="history-heading" class="font-medium">
                    Recorded activity
                </h2>
                <span class="text-xs text-muted-foreground"
                    >Newest recorded first</span
                >
            </div>
            <ol class="divide-y">
                <li v-for="transaction in transactions" :key="transaction.id">
                    <Link
                        :href="`/transactions/${transaction.id}`"
                        class="group flex min-w-0 items-start gap-3 px-4 py-5 transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] sm:items-center sm:gap-4 sm:px-6"
                    >
                        <span
                            class="mt-1 flex size-10 shrink-0 items-center justify-center rounded-xl border bg-muted/40 sm:mt-0"
                            ><component
                                :is="
                                    transaction.kind === 'income'
                                        ? ArrowDownLeft
                                        : transaction.kind === 'transfer'
                                          ? ArrowLeftRight
                                          : ArrowUpRight
                                "
                                class="size-4"
                        /></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="max-w-full min-w-0 font-medium [overflow-wrap:anywhere]"
                                    >{{ transaction.description }}</span
                                ><Badge
                                    variant="secondary"
                                    class="capitalize"
                                    >{{ transaction.status }}</Badge
                                >
                            </div>
                            <p
                                class="mt-1 text-xs leading-relaxed [overflow-wrap:anywhere] text-muted-foreground"
                            >
                                <span class="capitalize">{{
                                    transaction.kind
                                }}</span>
                                · {{ transaction.effective_date
                                }}<span v-if="transaction.primary">
                                    · {{ transaction.primary.name }}</span
                                >
                            </p>
                            <p
                                v-if="transaction.primary"
                                class="mt-2 text-base font-medium sm:hidden"
                            >
                                <CurrencyDisplay
                                    :amount="transaction.primary.amount"
                                    :currency="transaction.primary.currency"
                                />
                            </p>
                        </div>
                        <div
                            v-if="transaction.primary"
                            class="hidden shrink-0 text-right sm:block"
                        >
                            <CurrencyDisplay
                                :amount="transaction.primary.amount"
                                :currency="transaction.primary.currency"
                            />
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{
                                    transaction.kind === 'transfer'
                                        ? 'Internal transfer'
                                        : 'View record'
                                }}
                            </p>
                        </div>
                    </Link>
                </li>
            </ol>
        </section>
        <nav
            v-if="previous || next"
            aria-label="Transaction history pages"
            class="flex justify-between gap-3"
        >
            <Button v-if="previous" as-child variant="outline"
                ><Link :href="previous" preserve-scroll
                    >Newer records</Link
                ></Button
            ><span v-else /><Button v-if="next" as-child variant="outline"
                ><Link :href="next" preserve-scroll>Older records</Link></Button
            >
        </nav>
        <p class="flex gap-2 text-xs leading-relaxed text-muted-foreground">
            <ReceiptText class="size-4 shrink-0" />Records describe your ledger.
            They do not send money or connect to a bank.
        </p>
    </div>
</template>
