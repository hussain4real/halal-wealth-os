<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import type { Assessment } from '@/types/shariah';
import { classifications, provenanceLabels } from '@/types/shariah';
const props = defineProps<{ assessment: Assessment }>();
const safeUrl = computed(() => {
    try {
        const u = new URL(props.assessment.source_url ?? '');
        return u.protocol === 'https:' && !u.username && !u.password
            ? u.href
            : null;
    } catch {
        return null;
    }
});
</script>
<template>
    <article
        class="min-w-0 space-y-5 rounded-2xl border bg-card p-5 [overflow-wrap:anywhere] sm:p-7"
    >
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p
                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ provenanceLabels[assessment.provenance] }}
                </p>
                <h3 class="mt-2 text-lg font-semibold">
                    {{ assessment.provider }}
                </h3>
                <p class="mt-1 text-xs text-muted-foreground">
                    Version {{ assessment.version }} · recorded
                    {{ assessment.recorded_at }}
                </p>
            </div>
            <span class="rounded-full bg-muted px-3 py-1 text-sm">{{
                classifications[assessment.classification]
            }}</span>
        </header>
        <p class="text-sm text-muted-foreground">
            {{
                assessment.provenance === 'source_claim'
                    ? 'This is an attributed claim from the recorded source. The application has not independently verified or certified it.'
                    : 'This note is retained separately and does not establish source-backed compliance.'
            }}
        </p>
        <dl class="grid gap-4 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-xs text-muted-foreground">
                    Methodology version
                </dt>
                <dd class="mt-1">
                    {{ assessment.methodology_version || 'Not recorded' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">
                    Effective date · UTC
                </dt>
                <dd class="mt-1">{{ assessment.effective_on }}</dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Review due · UTC</dt>
                <dd class="mt-1">
                    {{ assessment.review_due_on || 'Not recorded'
                    }}<strong v-if="assessment.overdue" class="ml-2"
                        >· Overdue</strong
                    >
                </dd>
            </div>
        </dl>
        <div class="grid gap-5 border-t pt-4 text-sm sm:grid-cols-2">
            <div>
                <h4 class="font-medium">Business-activity findings</h4>
                <p class="mt-2 whitespace-pre-wrap">
                    {{
                        assessment.business_findings ||
                        'Not recorded; no business-activity conclusion is inferred.'
                    }}
                </p>
            </div>
            <div>
                <h4 class="font-medium">Financial-screening findings</h4>
                <p class="mt-2 whitespace-pre-wrap">
                    {{
                        assessment.financial_findings ||
                        'Not recorded; no financial thresholds are assumed.'
                    }}
                </p>
            </div>
        </div>
        <div class="border-t pt-4 text-sm">
            <h4 class="font-medium">Supporting evidence references</h4>
            <ul
                v-if="assessment.evidence_refs.length"
                class="mt-2 list-inside list-disc space-y-2"
            >
                <li
                    v-for="(reference, index) in assessment.evidence_refs"
                    :key="index"
                    class="whitespace-pre-wrap"
                >
                    {{ reference }}
                </li>
            </ul>
            <p v-else class="mt-2 text-muted-foreground">
                No supporting references recorded.
            </p>
            <a
                v-if="safeUrl"
                :href="safeUrl"
                target="_blank"
                rel="noopener noreferrer nofollow"
                class="mt-2 inline-flex min-h-11 items-center underline"
                >Open recorded source link ↗</a
            >
        </div>
        <div v-if="assessment.notes" class="rounded-xl bg-muted/40 p-4 text-sm">
            <h4 class="font-medium">Separate personal context</h4>
            <p class="mt-2 whitespace-pre-wrap">{{ assessment.notes }}</p>
        </div>
        <footer class="space-y-2 border-t pt-4 text-xs text-muted-foreground">
            <p>
                {{
                    assessment.actor_is_admin
                        ? 'Owner acting as administrator'
                        : 'Owner'
                }}
                #{{ assessment.actor_id }} · {{ assessment.reason }}
            </p>
            <Link
                :href="'/assets/' + assessment.asset_id"
                class="inline-flex min-h-10 items-center underline"
                >Exact asset reference #{{ assessment.asset_id }}</Link
            >
        </footer>
    </article>
</template>
