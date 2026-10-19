<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { AssessmentSummary } from '@/types/shariah';
import { classifications } from '@/types/shariah';
defineProps<{ summary: AssessmentSummary; assetId: string }>();
</script>
<template>
    <section
        class="space-y-4 rounded-2xl border bg-card p-5 sm:p-7"
        aria-label="Assessment guidance"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">Evidence before decisions</h2>
            <span class="rounded-full bg-muted px-3 py-1 text-sm font-medium">{{
                classifications[summary.classification]
            }}</span>
        </div>
        <div
            v-if="
                summary.conflict ||
                summary.overdue ||
                summary.review_date_missing
            "
            class="flex flex-wrap gap-2 text-sm font-medium"
        >
            <span
                v-if="summary.conflict"
                class="rounded-lg border border-amber-500 px-3 py-2"
                >Conflicting sources</span
            >
            <span
                v-if="summary.overdue"
                class="rounded-lg border border-amber-500 px-3 py-2"
                >Review overdue</span
            >
            <span
                v-if="summary.review_date_missing"
                class="rounded-lg border px-3 py-2"
                >Review date not recorded</span
            >
        </div>
        <p class="max-w-3xl text-sm leading-relaxed">{{ summary.guidance }}</p>
        <p
            v-if="summary.other_reference_evidence"
            class="text-sm text-muted-foreground"
        >
            Evidence exists for another version of this asset reference. It is
            retained in history and does not certify this version.
        </p>
        <p class="max-w-3xl text-sm text-muted-foreground">
            {{ summary.factual_recording }}
        </p>
        <Link
            :href="'/assets/' + assetId + '/assessments'"
            class="inline-flex min-h-11 items-center text-sm font-medium underline"
            >Review sources and assessment history →</Link
        >
    </section>
</template>
