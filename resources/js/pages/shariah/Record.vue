<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PortfolioShell from '@/components/PortfolioShell.vue';
import AssessmentEvidence from '@/components/AssessmentEvidence.vue';
import { Button } from '@/components/ui/button';
import type { Assessment } from '@/types/shariah';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Asset catalog', href: '/assets' },
            { title: 'Retained assessment', href: '#' },
        ],
    },
});
defineProps<{ assessment: Assessment; latest_id: string }>();
</script>
<template>
    <PortfolioShell
        title="An attributed record, retained."
        eyebrow="Assessment history"
        back="/assets"
        ><template #intro
            >This exact revision preserves its source, evidence, author and
            dates. A later revision never overwrites this record.</template
        >
        <div class="flex flex-wrap gap-3">
            <Button as-child variant="outline"
                ><Link :href="'/assets/' + assessment.asset_id + '/assessments'"
                    >Current evidence and history</Link
                ></Button
            ><Button as-child
                ><Link
                    :href="
                        '/assets/' +
                        assessment.asset_id +
                        '/assessments/' +
                        latest_id +
                        '/revise'
                    "
                    >Revise latest version of this source</Link
                ></Button
            >
        </div>
        <p
            v-if="assessment.id !== latest_id"
            class="rounded-xl border p-4 text-sm"
        >
            This is a retained earlier revision. The revision action starts from
            the latest record in its source series.
        </p>
        <AssessmentEvidence :assessment="assessment" />
    </PortfolioShell>
</template>
