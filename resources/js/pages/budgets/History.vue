<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import BudgetAmount from '@/components/BudgetAmount.vue';
import BudgetRelease from '@/components/BudgetRelease.vue';
import type { BudgetHistoryPage } from '@/types/budget';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Monthly plan', href: '/budgets' },
            { title: 'Matching history', href: '#' },
        ],
    },
});
defineProps<{ history: BudgetHistoryPage }>();
</script>
<template>
    <Head title="Matching history" />
    <div
        class="mx-auto w-full max-w-3xl space-y-7 px-4 py-8 scheme-light sm:px-8 sm:py-12 dark:scheme-dark"
    >
        <Link
            :href="'/budgets?month=' + history.month"
            class="inline-flex min-h-10 items-center text-sm underline"
            >← Back to monthly plan</Link
        >
        <header>
            <p
                class="text-xs font-medium tracking-widest text-teal-800 uppercase dark:text-teal-200"
            >
                Retained matching history
            </p>
            <h1
                class="mt-3 text-3xl font-semibold tracking-tight [overflow-wrap:anywhere]"
            >
                {{ history.occurrence.name }}
            </h1>
            <p class="mt-3 text-sm text-muted-foreground">
                Due {{ history.occurrence.due_date }} · Qatar ·
                {{ history.occurrence.allocation_count }} retained links
            </p>
        </header>
        <dl class="grid gap-4 rounded-2xl border p-5 sm:grid-cols-3">
            <div>
                <dt class="text-xs text-muted-foreground">Expected</dt>
                <dd class="mt-2 font-medium">
                    <BudgetAmount :value="history.occurrence.expected" />
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">
                    Effectively matched
                </dt>
                <dd class="mt-2 font-medium">
                    <BudgetAmount :value="history.occurrence.matched" />
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Remaining</dt>
                <dd class="mt-2 font-medium">
                    <BudgetAmount :value="history.occurrence.remaining" />
                </dd>
            </div>
        </dl>
        <p class="text-sm text-muted-foreground">
            These totals include every allocation. History is shown newest
            first, in pages of 20. Releasing a link retains its evidence and
            changes no ledger entry.
        </p>
        <p
            v-if="history.occurrence.needs_review"
            role="status"
            class="rounded-xl border p-4 text-sm"
        >
            A retained receipt was reversed. Review its links before matching a
            replacement.
        </p>
        <ul class="divide-y rounded-2xl border px-5">
            <li
                v-for="allocation in history.occurrence.allocations"
                :key="allocation.id"
                class="min-w-0 space-y-2 py-4 text-sm"
            >
                <Link
                    :href="'/transactions/' + allocation.journal_id"
                    class="font-medium [overflow-wrap:anywhere] underline"
                    >{{ allocation.journal_reference }}</Link
                >
                <p>
                    <BudgetAmount :value="allocation.amount" /> ·
                    {{ allocation.journal_date }} UTC
                </p>
                <p class="text-muted-foreground">
                    {{
                        allocation.released
                            ? 'Released · ' + allocation.release_reason
                            : allocation.effective
                              ? 'Effective allocation'
                              : 'Actual reversed · no longer settles this plan'
                    }}
                </p>
                <BudgetRelease
                    v-if="!allocation.released"
                    :allocation="allocation"
                    :month="history.month"
                />
            </li>
        </ul>
        <div class="flex flex-wrap gap-4">
            <Link
                v-if="history.before"
                :href="'/budgets/occurrences/' + history.occurrence.id"
                class="inline-flex min-h-10 items-center text-sm underline"
                >Latest matching history</Link
            >
            <Link
                v-if="history.next_cursor"
                :href="
                    '/budgets/occurrences/' +
                    history.occurrence.id +
                    '?before=' +
                    history.next_cursor
                "
                class="inline-flex min-h-10 items-center text-sm underline"
                >Older matching history →</Link
            >
        </div>
    </div>
</template>
