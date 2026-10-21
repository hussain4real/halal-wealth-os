<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PurificationShell from '@/components/PurificationShell.vue';
import type { Obligation, Payment } from '@/types/purification';
import { statusLabels } from '@/types/purification';
defineProps<{
    obligations: Obligation[];
    payments: Payment[];
    next: string | null;
}>();
</script>
<template>
    <PurificationShell title="Explain each obligation. Keep payment history."
        ><template #intro
            >Obligations do not debit cash. Allocations link them to actual
            charity payments already recorded, in the same native currency.
            Missing instructions remain pending.</template
        >
        <nav
            class="flex flex-wrap gap-4 text-sm underline"
            aria-label="Purification actions"
        >
            <Link href="/purification/new" class="min-h-11 content-center"
                >Record sourced obligation</Link
            ><Link
                href="/purification/payments/new"
                class="min-h-11 content-center"
                >Record or link a payment already made</Link
            ><a href="/purification/export" class="min-h-11 content-center"
                >Export complete audit history</a
            >
        </nav>
        <section class="space-y-4">
            <h2 class="text-xl font-semibold">Current obligations</h2>
            <p v-if="!obligations.length" class="rounded-xl border p-6">
                No obligations on this page. Start from a recorded distribution
                and retain its source instruction; missing evidence is never
                inferred to mean zero due.
            </p>
            <article
                v-for="o in obligations"
                :key="o.id"
                class="rounded-xl border bg-card p-5"
            >
                <div class="flex flex-wrap justify-between gap-3">
                    <Link
                        :href="'/purification/obligations/' + o.id"
                        class="font-semibold underline"
                        >{{ o.method_reference }} · v{{ o.version }}</Link
                    ><span>{{ statusLabels[o.status] }}</span>
                </div>
                <p class="mt-3">
                    Due
                    {{ o.due ? o.currency + ' ' + o.due.amount : 'pending' }} ·
                    Linked {{ o.currency }} {{ o.paid.amount }} · Outstanding
                    {{
                        o.outstanding
                            ? o.currency + ' ' + o.outstanding.amount
                            : 'unknown'
                    }}
                </p>
                <p
                    v-if="o.source_changed"
                    class="mt-2 text-amber-800 dark:text-amber-200"
                >
                    Distribution reversed or replaced. Review and version the
                    instruction before another allocation.
                </p>
                <p
                    v-if="o.pending_explanation"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    {{ o.pending_explanation }}
                </p>
                <p v-if="o.excess.minor !== '0'" class="mt-2">
                    Excess linked {{ o.currency }} {{ o.excess.amount }}. No
                    refund or reallocation is assumed.
                </p>
            </article>
            <Link
                v-if="next"
                :href="'/purification?after=' + next"
                class="inline-flex min-h-11 items-center underline"
                >Older current obligations</Link
            >
        </section>
        <section class="space-y-4">
            <h2 class="text-xl font-semibold">Recorded charity payments</h2>
            <p class="text-sm text-muted-foreground">
                Latest 100 payment records. The complete export includes every
                payment and allocation. Ordinary expenses require explicit
                charity attribution.
            </p>
            <p v-if="!payments.length">No charity payments recorded.</p>
            <article
                v-for="p in payments"
                :key="p.id"
                class="rounded-xl border p-5"
            >
                <Link
                    :href="'/purification/payments/' + p.id"
                    class="font-semibold underline"
                    >{{ p.payment_reference }}</Link
                >
                <p class="mt-2">
                    {{ p.currency }} {{ p.amount.amount }} ·
                    {{ p.journal_status }} journal ·
                    {{ p.available.amount }} unallocated
                </p>
            </article>
        </section></PurificationShell
    >
</template>
