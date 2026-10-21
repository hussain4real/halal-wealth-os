<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import DecisionShell from '@/components/DecisionShell.vue';
import type { Decision } from '@/types/decisions';
import { decisionLabels } from '@/types/decisions';
defineProps<{
    opportunities: Decision[];
    next: string | null;
    status: string;
}>();
const filters = [
    { value: '', label: 'All' },
    { value: 'watchlist', label: 'Watchlist' },
    { value: 'planned', label: 'Proposals' },
    { value: 'closed', label: 'Closed' },
];
</script>
<template>
    <DecisionShell title="Give each opportunity a clear thesis."
        ><template #intro
            >Keep the rationale, risks and review triggers behind a decision. A
            planned allocation never creates a holding or a cash
            posting.</template
        >
        <nav
            class="flex flex-wrap items-center gap-4"
            aria-label="Opportunity actions"
        >
            <Link
                href="/opportunities/new"
                class="inline-flex min-h-11 items-center rounded-lg bg-teal-800 px-5 font-medium text-white dark:bg-teal-200 dark:text-teal-950"
                >Add opportunity</Link
            ><a
                href="/opportunities/export"
                class="min-h-11 content-center text-sm underline"
                >Export complete history</a
            >
        </nav>
        <nav class="flex flex-wrap gap-2" aria-label="Opportunity status">
            <Link
                v-for="f in filters"
                :key="f.value"
                :href="'/opportunities?status=' + f.value"
                :aria-current="status === f.value ? 'page' : undefined"
                class="min-h-11 rounded-lg border px-4 py-3 text-sm"
                :class="status === f.value ? 'bg-muted font-semibold' : ''"
                >{{ f.label }}</Link
            >
        </nav>
        <section
            v-if="!opportunities.length"
            class="rounded-2xl border border-dashed p-8"
        >
            <h2 class="text-xl font-semibold">Room for your next decision</h2>
            <p class="mt-3 max-w-xl text-muted-foreground">
                Start a watchlist entry with what you know. Leave uncertain
                terms blank, then add a proposal and conditional tranches when
                your thesis is ready.
            </p>
            <Link
                href="/opportunities/new"
                class="mt-4 inline-flex min-h-11 items-center underline"
                >Create a watchlist entry →</Link
            >
        </section>
        <section
            v-else
            class="grid gap-4 md:grid-cols-2"
            aria-label="Current opportunities"
        >
            <article
                v-for="o in opportunities"
                :key="o.id"
                class="flex flex-col rounded-2xl border bg-card p-6"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <Link
                        :href="'/opportunities/' + o.id"
                        class="text-xl font-semibold underline"
                        >{{ o.name }}</Link
                    ><span class="rounded-full bg-muted px-3 py-1 text-xs">{{
                        decisionLabels[o.status]
                    }}</span>
                </div>
                <p class="mt-5 text-2xl font-semibold">
                    {{
                        o.target
                            ? o.currency + ' ' + o.target.amount
                            : 'Target unknown'
                    }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Maximum
                    {{
                        o.maximum
                            ? o.currency + ' ' + o.maximum.amount
                            : 'not set'
                    }}
                    · {{ o.tranches.length }} conditional tranches
                </p>
                <p class="mt-4 line-clamp-3 text-sm">
                    {{ o.attributes.rationale ?? 'Thesis not yet recorded.' }}
                </p>
                <div class="mt-auto space-y-2 pt-5 text-sm">
                    <p
                        v-if="o.review_overdue"
                        class="font-medium text-amber-800 dark:text-amber-200"
                    >
                        Thesis review overdue · {{ o.attributes.review_on }}
                    </p>
                    <p v-else>
                        Review {{ o.attributes.review_on ?? 'not scheduled' }}
                    </p>
                    <p>
                        Offer evidence:
                        {{
                            Object.values(o.attributes.evidence).every(
                                (e) => e.verified,
                            )
                                ? 'owner verified sources'
                                : 'unverified or incomplete'
                        }}
                    </p>
                    <p>
                        Currency limit:
                        {{ o.concentration.state.replaceAll('_', ' ') }}
                    </p>
                </div>
            </article>
        </section>
        <Link
            v-if="next"
            :href="'/opportunities?status=' + status + '&before=' + next"
            class="inline-flex min-h-11 items-center underline"
            >Older current opportunities →</Link
        >
    </DecisionShell>
</template>
