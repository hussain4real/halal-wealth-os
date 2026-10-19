<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PortfolioShell from '@/components/PortfolioShell.vue';
import AssessmentSummary from '@/components/AssessmentSummary.vue';
import { Button } from '@/components/ui/button';
import type { Asset, Page } from '@/types/portfolio';
import { kindLabel } from '@/types/portfolio';
import type { AssessmentSummary as Summary } from '@/types/shariah';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Asset catalog', href: '/assets' },
            { title: 'Reference evidence', href: '#' },
        ],
    },
});
defineProps<{
    asset: Asset;
    current_id: string;
    history: Page<Asset>;
    assessment_summary: Summary;
}>();
</script>
<template>
    <PortfolioShell :title="asset.name" back="/assets"
        ><template #intro
            >{{ kindLabel(asset.kind) }} · {{ asset.currency }} · reference
            version {{ asset.version }}. Holdings keep the exact reference
            version originally selected.</template
        >
        <div class="flex flex-wrap gap-3">
            <Button as-child
                ><Link :href="'/assets/' + current_id + '/revise'"
                    >Revise latest reference</Link
                ></Button
            ><Button as-child variant="outline"
                ><Link href="/portfolio">Open portfolio</Link></Button
            >
        </div>
        <section
            class="max-w-3xl space-y-4 rounded-2xl border bg-card p-6 [overflow-wrap:anywhere]"
        >
            <h2 class="text-lg font-semibold">Recorded reference</h2>
            <dl class="grid gap-5 sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-muted-foreground">Identifier</dt>
                    <dd>{{ asset.identifier || 'Not recorded' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Market label</dt>
                    <dd>{{ asset.market || 'Not recorded' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Source</dt>
                    <dd>{{ asset.source }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Reason</dt>
                    <dd>{{ asset.reason }}</dd>
                </div>
            </dl>
            <p
                v-if="asset.unsupported_terms"
                class="rounded-xl bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100"
            >
                Unsupported terms: {{ asset.unsupported_terms }}
            </p>
            <p v-if="asset.id !== current_id" class="text-sm">
                This is a retained version.
                <Link :href="'/assets/' + current_id" class="underline"
                    >View latest reference</Link
                >.
            </p>
        </section>
        <AssessmentSummary :summary="assessment_summary" :asset-id="asset.id" />
        <section class="max-w-3xl rounded-2xl border bg-card p-6">
            <h2 class="text-lg font-semibold">Reference history</h2>
            <ul class="mt-4 divide-y">
                <li v-for="entry in history.items" :key="entry.id" class="py-3">
                    <Link
                        :href="'/assets/' + entry.id"
                        class="inline-flex min-h-10 items-center underline"
                        >v{{ entry.version }} · {{ entry.name }}</Link
                    >
                    <p
                        class="text-xs [overflow-wrap:anywhere] text-muted-foreground"
                    >
                        {{ entry.reason }} · {{ entry.source }}
                    </p>
                </li>
            </ul>
            <Link
                v-if="history.next"
                :href="'/assets/' + asset.id + '?after=' + history.next"
                class="inline-flex min-h-11 items-center underline"
                >Earlier versions →</Link
            >
        </section>
    </PortfolioShell>
</template>
