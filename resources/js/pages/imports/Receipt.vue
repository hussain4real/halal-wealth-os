<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import ImportCorrection from '@/components/ImportCorrection.vue';
import type {
    ImportPreviewChoice,
    ImportReceipt,
    ImportTarget,
} from '@/types/imports';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Statement imports', href: '/imports' },
            { title: 'Import receipt', href: '#' },
        ],
    },
});
defineProps<{
    receipt: ImportReceipt;
    previews: ImportPreviewChoice[];
}>();
const targetUrl = (target: ImportTarget) =>
    target.event_id !== null
        ? '/portfolio/' + target.portfolio_id + '/events/' + target.event_id
        : '/transactions/' + target.journal_id;
</script>
<template>
    <Head title="Import receipt" />
    <main class="mx-auto w-full max-w-4xl space-y-5 p-4 md:p-8">
        <header class="space-y-2">
            <Link href="/imports" class="text-sm underline"
                >Statement imports</Link
            >
            <h1 class="text-2xl font-semibold">Import receipt</h1>
            <p class="text-sm break-all text-muted-foreground">
                {{ receipt.reference }}
            </p>
            <p class="text-sm">
                The selected batch committed atomically. Duplicate and matched
                evidence creates no additional financial entry.
            </p>
            <p v-if="receipt.reason" class="text-sm break-words">
                Correction reason: {{ receipt.reason }}
            </p>
        </header>
        <div class="flex flex-wrap gap-4 text-sm">
            <Link :href="'/imports/' + receipt.snapshot_id" class="underline"
                >Open source preview and original evidence</Link
            ><Link
                v-if="receipt.corrects_receipt_id"
                :href="'/imports/receipts/' + receipt.corrects_receipt_id"
                class="underline"
                >Original correction receipt</Link
            ><a
                :href="'/imports/' + receipt.snapshot_id + '/export'"
                class="underline"
                >Download safe CSV evidence</a
            >
        </div>
        <article
            v-for="outcome in receipt.outcomes"
            :key="outcome.token"
            class="min-w-0 space-y-4 rounded-xl border p-5"
        >
            <div class="flex flex-wrap justify-between gap-3">
                <div>
                    <h2 class="font-medium">
                        Row {{ outcome.row }} · {{ outcome.type }}
                    </h2>
                    <p class="mt-1 break-words">
                        {{ outcome.data.description }}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ outcome.data.kind }} ·
                        {{ outcome.data.effective_date }}
                    </p>
                </div>
                <CurrencyDisplay
                    :amount="outcome.data.amount"
                    :currency="outcome.data.currency"
                />
            </div>
            <div class="flex flex-wrap gap-4 text-sm">
                <Link :href="targetUrl(outcome.target)" class="underline"
                    >Complete financial receipt</Link
                ><Link
                    v-if="outcome.original_target"
                    :href="targetUrl(outcome.original_target)"
                    class="underline"
                    >Original financial operation</Link
                ><Link
                    v-if="outcome.origin?.receipt_id"
                    :href="'/imports/receipts/' + outcome.origin.receipt_id"
                    class="underline"
                    >Linked source receipt</Link
                ><Link
                    v-if="outcome.correction_receipt_id"
                    :href="'/imports/receipts/' + outcome.correction_receipt_id"
                    class="underline"
                    >Later correction history</Link
                >
            </div>
            <ImportCorrection
                v-if="outcome.correctable"
                :receipt-id="receipt.id"
                :token="outcome.token"
                :previews="previews"
            />
            <p
                v-else-if="outcome.type === 'matched'"
                class="text-sm text-muted-foreground"
            >
                This is an evidence match. Correct the original financial
                operation through its own workflow; matching does not grant
                reversal authority.
            </p>
        </article>
    </main>
</template>
