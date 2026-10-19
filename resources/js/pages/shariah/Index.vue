<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PortfolioShell from '@/components/PortfolioShell.vue';
import AssessmentSummary from '@/components/AssessmentSummary.vue';
import AssessmentEvidence from '@/components/AssessmentEvidence.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Assessment, AssessmentSummary as Summary } from '@/types/shariah';
import { classifications, provenanceLabels } from '@/types/shariah';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Asset catalog', href: '/assets' },
            { title: 'Assessment evidence', href: '#' },
        ],
    },
});
const props = defineProps<{
    asset: { id: string; name: string; version: string };
    cutoff: string;
    summary: Summary;
    current: Assessment[];
    history: { items: Assessment[]; next: string | null };
}>();
const base = '/assets/' + props.asset.id + '/assessments';
const cutoffInput = ref(props.cutoff);
const queryString = (params: Record<string, string>) =>
    new URLSearchParams(params).toString();
function inspect() {
    router.get(base, { cutoff: cutoffInput.value }, { preserveScroll: true });
}
</script>
<template>
    <PortfolioShell
        title="Shariah evidence, with its history."
        eyebrow="Attributed assessments · no automatic certification"
        back="/assets"
    >
        <template #intro
            >{{ asset.name }} · asset reference v{{ asset.version }}. Keep
            source claims, methodology and uncertainty visible before making an
            investment decision.</template
        >
        <div class="flex flex-wrap gap-3">
            <Button as-child
                ><Link :href="base + '/new'"
                    >Record assessment evidence</Link
                ></Button
            ><Button as-child variant="outline"
                ><Link :href="'/assets/' + asset.id"
                    >Asset reference</Link
                ></Button
            ><a
                :href="base + '/export?' + queryString({ cutoff })"
                class="inline-flex min-h-11 items-center px-3 text-sm underline"
                >Export complete evidence</a
            >
        </div>
        <AssessmentSummary :summary="summary" :asset-id="asset.id" />
        <section class="rounded-2xl border bg-card p-5 sm:p-7">
            <h2 class="text-lg font-semibold">Review what was known then</h2>
            <p class="mt-2 max-w-3xl text-sm text-muted-foreground">
                Only evidence recorded on or before this UTC instant and
                effective by that date is included. Later backdated revisions do
                not replace what was known then. Overdue means the review date
                has passed at this cutoff.
            </p>
            <form
                class="mt-4 flex flex-wrap items-end gap-3"
                @submit.prevent="inspect"
            >
                <div class="min-w-0 grow">
                    <Label for="assessment-cutoff"
                        >UTC cutoff · YYYY-MM-DDTHH:MM:SSZ</Label
                    ><Input
                        id="assessment-cutoff"
                        v-model="cutoffInput"
                        class="mt-2"
                        required
                        maxlength="20"
                    />
                </div>
                <Button type="submit" variant="outline">Inspect as of</Button
                ><Link
                    :href="base"
                    class="inline-flex min-h-11 items-center px-2 text-sm underline"
                    >Return to current evidence</Link
                >
            </form>
            <p
                v-if="$page.props.errors.cutoff"
                class="mt-3 text-sm text-destructive"
                role="alert"
            >
                {{ $page.props.errors.cutoff }}
            </p>
        </section>
        <section class="space-y-4" aria-label="Current evidence at cutoff">
            <h2 class="text-xl font-semibold">Evidence at {{ cutoff }}</h2>
            <div
                v-if="!current.length"
                class="rounded-2xl border border-dashed bg-card p-7"
            >
                <h3 class="font-semibold">
                    No assessment evidence for this reference yet
                </h3>
                <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                    The default is pending review. An asset name, platform label
                    or existing holding does not establish compliance. You can
                    keep recording the facts you already own.
                </p>
            </div>
            <div
                v-for="assessment in current"
                :key="assessment.id"
                class="space-y-2"
            >
                <AssessmentEvidence :assessment="assessment" /><Link
                    :href="base + '/' + assessment.id + '/revise'"
                    class="inline-flex min-h-11 items-center px-2 text-sm font-medium underline"
                    >Revise this source with retained history →</Link
                >
            </div>
        </section>
        <section class="rounded-2xl border bg-card p-5 sm:p-7">
            <h2 class="text-lg font-semibold">Retained family history</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Includes other asset reference versions known and effective by
                this cutoff. Their evidence does not automatically carry to this
                reference.
            </p>
            <p v-if="!history.items.length" class="mt-5 text-sm">
                No retained evidence at this cutoff.
            </p>
            <ul v-else class="mt-4 divide-y">
                <li
                    v-for="record in history.items"
                    :key="record.id"
                    class="min-w-0 py-4 [overflow-wrap:anywhere]"
                >
                    <Link
                        :href="
                            '/assets/' +
                            record.asset_id +
                            '/assessments/' +
                            record.id
                        "
                        class="inline-flex min-h-10 items-center font-medium underline"
                        >{{ record.provider }} · v{{ record.version }}</Link
                    >
                    <p class="text-sm">
                        {{ provenanceLabels[record.provenance] }} ·
                        {{ classifications[record.classification] }} · asset #{{
                            record.asset_id
                        }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Effective {{ record.effective_on }} · recorded
                        {{ record.recorded_at }} · owner #{{ record.actor_id }}
                    </p>
                </li>
            </ul>
            <Link
                v-if="history.next"
                :href="
                    base + '?' + queryString({ cutoff, after: history.next })
                "
                class="inline-flex min-h-11 items-center text-sm underline"
                >Earlier retained records →</Link
            >
        </section>
    </PortfolioShell>
</template>
