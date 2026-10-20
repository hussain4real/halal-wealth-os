<script setup lang="ts">
import { computed } from 'vue';
import type { OpeningGuidanceState } from '@/composables/openingAssessmentGuidance';
import { classifications } from '@/types/shariah';
const props = defineProps<{ assetId: string; state: OpeningGuidanceState }>();
const summary = computed(() => props.state.items.get(props.assetId));
</script>
<template>
    <section
        class="mt-4 min-w-0 space-y-3 rounded-xl border bg-muted/20 p-4 text-sm [overflow-wrap:anywhere]"
        :aria-label="'Assessment evidence for exact reference ' + assetId"
    >
        <h3 class="font-semibold">Current assessment evidence</h3>
        <template v-if="summary">
            <p class="font-medium">
                {{ classifications[summary.classification] }}
            </p>
            <ul
                v-if="
                    summary.conflict ||
                    summary.overdue ||
                    summary.review_date_missing
                "
                class="space-y-1 font-medium"
            >
                <li v-if="summary.conflict">Conflicting sources</li>
                <li v-if="summary.overdue">Review overdue</li>
                <li v-if="summary.review_date_missing">
                    Review date not recorded
                </li>
            </ul>
            <p>{{ summary.guidance }}</p>
            <p v-if="summary.other_reference_evidence">
                Other reference versions have retained evidence; their
                classification does not certify this exact version.
            </p>
            <p>{{ summary.factual_recording }}</p>
            <p class="text-xs text-muted-foreground">
                Evidence checked at {{ state.cutoff }}.
            </p>
        </template>
        <template v-else>
            <p>
                {{
                    state.status === 'loading'
                        ? 'Checking current evidence. Assessment status remains unresolved while it loads.'
                        : 'Current evidence is unavailable here. Assessment status remains unresolved until you review its sources.'
                }}
            </p>
            <p>
                Missing, conflicting or overdue evidence needs review before an
                investment decision. You can still record an existing holding
                with any assessment status.
            </p>
        </template>
        <a
            :href="'/assets/' + assetId + '/assessments'"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-flex min-h-11 max-w-full items-center font-medium whitespace-normal underline"
            >Review this exact reference’s assessment evidence (opens in a new
            tab) →</a
        >
    </section>
</template>
