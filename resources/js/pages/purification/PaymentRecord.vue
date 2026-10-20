<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PurificationShell from '@/components/PurificationShell.vue';
import type { Payment } from '@/types/purification';
defineProps<{ payment: Payment }>();
</script>
<template>
    <PurificationShell title="Saved charity payment record."
        ><template #intro
            >{{ payment.payment_reference }} · {{ payment.currency }}
            {{ payment.amount.amount }} · actual journal
            {{ payment.journal_status }}. This record attributes an existing
            cash expense; it does not send money.</template
        >
        <section class="space-y-3 rounded-xl border bg-card p-5">
            <h2 class="text-xl font-semibold">Payment and audit</h2>
            <p>
                Payment ID {{ payment.id }}. To allocate an older payment, open
                an obligation and enter this ID in its allocation form.
            </p>
            <p>
                Allocated {{ payment.currency }}
                {{ payment.allocated.amount }} · Unallocated
                {{ payment.currency }} {{ payment.available.amount }}
            </p>
            <p v-if="payment.journal_status !== 'posted'" role="alert">
                The linked actual journal was reversed. Its allocations remain
                in history and no longer settle obligations. A corrected payment
                needs explicit new attribution.
            </p>
            <p>
                Actual date {{ payment.effective_date }} · Recorded
                {{ payment.recorded_at }} · Actor {{ payment.actor_id }}
            </p>
            <p>{{ payment.evidence }}</p>
            <p>{{ payment.reason }}</p>
            <Link
                :href="'/transactions/' + payment.journal_id"
                class="inline-flex min-h-11 items-center underline"
                >Open actual journal and reversal history</Link
            >
        </section>
        <Link
            href="/purification"
            class="inline-flex min-h-11 items-center underline"
            >Choose an obligation to allocate this payment</Link
        ></PurificationShell
    >
</template>
