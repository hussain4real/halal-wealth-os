<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import PortfolioShell from '@/components/PortfolioShell.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import type { Assessment } from '@/types/shariah';
import { classifications, provenanceLabels } from '@/types/shariah';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Asset catalog', href: '/assets' },
            { title: 'Record assessment evidence', href: '#' },
        ],
    },
});
const props = defineProps<{
    asset: { id: string; name: string; version: string };
    prior: Assessment | null;
    context: string;
    today: string;
    stale: boolean;
}>();
const base = '/assets/' + props.asset.id + '/assessments';
const { form, feedback, recovered, locked, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) +
        ':assessment:' +
        props.asset.id +
        ':' +
        (props.prior?.id ?? 'new'),
    {
        context: props.context,
        supersedes_id: props.prior?.id ?? '',
        provenance: props.prior?.provenance ?? 'source_claim',
        provider: props.prior?.provider ?? '',
        methodology_version: props.prior?.methodology_version ?? '',
        classification: props.prior?.classification ?? 'pending_review',
        effective_on: props.prior?.effective_on ?? props.today,
        review_due_on: props.prior?.review_due_on ?? '',
        evidence_refs: props.prior?.evidence_refs.join('\n') ?? '',
        source_url: props.prior?.source_url ?? '',
        business_findings: props.prior?.business_findings ?? '',
        financial_findings: props.prior?.financial_findings ?? '',
        notes: props.prior?.notes ?? '',
        reason: '',
    },
    {
        evidence_refs: 13000,
        source_url: 2000,
        business_findings: 4000,
        financial_findings: 4000,
        notes: 4000,
    },
);
form.transform((data) => ({
    ...data,
    evidence_refs: data.evidence_refs
        .split('\n')
        .map((r) => r.trim())
        .filter(Boolean),
}));
const reviewing = ref(false);
const fixed = computed(
    () => locked.value || form.processing || reviewing.value || props.stale,
);
const textFields = [
    {
        key: 'business_findings',
        title: 'Business-activity findings',
        hint: 'Record the source’s activity findings. Leave unknown findings blank.',
    },
    {
        key: 'financial_findings',
        title: 'Financial-screening findings',
        hint: 'Retain the recorded methodology and findings. No default thresholds are supplied.',
    },
    {
        key: 'notes',
        title: 'Separate personal context',
        hint: 'Your notes remain distinct from the source’s claims.',
    },
];
watch(
    () => form.provenance,
    (value) => {
        if (!locked.value && value !== 'source_claim')
            form.classification = 'pending_review';
    },
);
watch(
    () => form.hasErrors,
    (value) => {
        if (value) reviewing.value = false;
    },
);
function proceed() {
    if (reviewing.value || locked.value) submit(base);
    else reviewing.value = true;
}
</script>
<template>
    <PortfolioShell
        :title="
            prior
                ? 'Revise evidence. Preserve the history.'
                : 'Record the evidence behind an assessment.'
        "
        eyebrow="Shariah evidence · attributed, not certified"
        back="/assets"
    >
        <template #intro
            >{{ asset.name }} · exact asset reference v{{ asset.version }}. Each
            save retains an attributed revision. It does not certify the asset
            or change a financial record.</template
        >
        <Link
            :href="base"
            class="inline-flex min-h-11 items-center text-sm underline"
            >← Assessment evidence and history</Link
        >
        <div
            v-if="stale"
            class="rounded-xl border border-amber-500 p-5"
            role="alert"
        >
            This source already has a newer revision.
            <span v-if="locked"
                >Your restored request can still be retried to recover its saved
                receipt. Its fields remain fixed.</span
            >
            <Link v-else :href="base" class="underline"
                >Open history and revise the latest version.</Link
            >
        </div>
        <form class="max-w-4xl space-y-6" @submit.prevent="proceed">
            <PortfolioAttempt
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                :recovered="recovered"
                :history="base"
            />
            <p v-if="form.errors.context" class="rounded-xl border p-4 text-sm">
                <a :href="$page.url" class="underline">Reload this form</a>
                after checking saved history. A new reference version requires
                renewed confirmation.
            </p>
            <fieldset :disabled="fixed" class="space-y-6">
                <section
                    class="space-y-5 rounded-2xl border bg-card p-5 sm:p-7"
                >
                    <h2 class="text-xl font-semibold">
                        1. Attribute the claim
                    </h2>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <Label for="provenance">Evidence type</Label
                            ><select
                                id="provenance"
                                v-model="form.provenance"
                                :disabled="!!prior"
                                class="mt-2 min-h-11 w-full rounded-md border bg-background px-3"
                            >
                                <option
                                    v-for="(label, value) in provenanceLabels"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <Label for="provider">Source / provider name</Label
                            ><Input
                                id="provider"
                                v-model="form.provider"
                                :readonly="!!prior"
                                required
                                maxlength="180"
                                class="mt-2"
                            />
                        </div>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        A source claim records its stated authority; the
                        application does not independently verify it. Personal
                        notes and retained AI suggestions stay pending and do
                        not establish compliance. No AI is generated here.
                    </p>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <Label for="methodology_version"
                                >Methodology and version</Label
                            ><Input
                                id="methodology_version"
                                v-model="form.methodology_version"
                                maxlength="180"
                                class="mt-2"
                                placeholder="As identified in the supporting evidence"
                            />
                        </div>
                        <div>
                            <Label for="classification"
                                >Recorded classification</Label
                            ><select
                                id="classification"
                                v-model="form.classification"
                                :disabled="form.provenance !== 'source_claim'"
                                class="mt-2 min-h-11 w-full rounded-md border bg-background px-3"
                            >
                                <option
                                    v-for="(label, value) in classifications"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </option>
                            </select>
                        </div>
                    </div>
                </section>
                <section
                    class="space-y-5 rounded-2xl border bg-card p-5 sm:p-7"
                >
                    <h2 class="text-xl font-semibold">
                        2. Retain the dates and evidence
                    </h2>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <Label for="effective_on"
                                >Effective date · UTC</Label
                            ><Input
                                id="effective_on"
                                v-model="form.effective_on"
                                type="date"
                                required
                                :max="today"
                                :min="prior?.effective_on"
                                class="mt-2"
                            />
                        </div>
                        <div>
                            <Label for="review_due_on"
                                >Review due date · UTC (optional)</Label
                            ><Input
                                id="review_due_on"
                                v-model="form.review_due_on"
                                type="date"
                                :min="form.effective_on"
                                max="9998-12-31"
                                class="mt-2"
                            />
                        </div>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Use the recorded source’s date; no review interval is
                        assumed. A missing review date stays visible, and a
                        passed date becomes overdue without renewing the claim.
                    </p>
                    <div>
                        <Label for="evidence_refs"
                            >Supporting evidence references · one per
                            line</Label
                        ><textarea
                            id="evidence_refs"
                            v-model="form.evidence_refs"
                            rows="4"
                            maxlength="13000"
                            class="mt-2 w-full rounded-md border bg-background p-3 text-sm"
                            aria-describedby="evidence-help"
                        />
                        <p
                            id="evidence-help"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Up to 12 references, 1,000 characters each. Record
                            document names, sections or retained evidence
                            identifiers. References display as text; nothing is
                            fetched or uploaded automatically. A non-pending
                            claim needs supporting references and a methodology
                            version.
                        </p>
                    </div>
                    <div>
                        <Label for="source_url"
                            >Source link (optional, HTTPS)</Label
                        ><Input
                            id="source_url"
                            v-model="form.source_url"
                            type="url"
                            maxlength="2000"
                            class="mt-2"
                            placeholder="https://"
                        />
                    </div>
                </section>
                <section
                    class="space-y-5 rounded-2xl border bg-card p-5 sm:p-7"
                >
                    <h2 class="text-xl font-semibold">
                        3. Keep the findings distinct
                    </h2>
                    <div v-for="field in textFields" :key="field.key">
                        <Label :for="field.key">{{ field.title }}</Label
                        ><textarea
                            :id="field.key"
                            v-model="form[field.key]"
                            rows="4"
                            maxlength="4000"
                            class="mt-2 w-full rounded-md border bg-background p-3 text-sm"
                        />
                        <p class="mt-2 text-xs text-muted-foreground">
                            {{ field.hint }}
                        </p>
                    </div>
                    <div>
                        <Label for="reason"
                            >Reason for this retained record</Label
                        ><Input
                            id="reason"
                            v-model="form.reason"
                            required
                            maxlength="180"
                            class="mt-2"
                        />
                    </div>
                </section>
            </fieldset>
            <section
                v-if="reviewing || locked"
                class="space-y-3 rounded-2xl border border-teal-700 bg-card p-5 sm:p-7"
                aria-label="Confirm assessment evidence"
            >
                <h2 class="text-lg font-semibold">Review before recording</h2>
                <p class="text-sm">
                    {{ provenanceLabels[form.provenance] }} ·
                    {{ form.provider }} ·
                    {{ classifications[form.classification] }}
                </p>
                <p class="text-sm">
                    Effective {{ form.effective_on }} · review due
                    {{ form.review_due_on || 'not recorded' }} · methodology
                    {{ form.methodology_version || 'not recorded' }}
                </p>
                <p class="text-sm text-muted-foreground">
                    This retains your attribution and exact asset reference.
                    Other sources remain visible. No certification, trade or
                    cash movement is created.
                </p>
            </section>
            <div class="flex flex-wrap gap-3">
                <Button
                    type="submit"
                    :disabled="form.processing || (stale && !locked)"
                    >{{
                        form.processing
                            ? 'Recording…'
                            : locked
                              ? 'Retry the same request'
                              : reviewing
                                ? 'Confirm and record evidence'
                                : 'Review assessment evidence'
                    }}</Button
                ><Button
                    v-if="reviewing && !locked"
                    type="button"
                    variant="outline"
                    @click="reviewing = false"
                    >Back to edit</Button
                ><Link
                    :href="base"
                    class="inline-flex min-h-11 items-center px-3 text-sm underline"
                    >Cancel and review history</Link
                >
            </div>
        </form>
    </PortfolioShell>
</template>
