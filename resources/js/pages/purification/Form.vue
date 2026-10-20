<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PurificationShell from '@/components/PurificationShell.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import { purificationAttemptScope } from '@/composables/purificationAttemptScope';
import type { Assessment } from '@/types/shariah';
import type { Distribution, Obligation } from '@/types/purification';
const props = defineProps<{
    distribution: Distribution;
    prior: Obligation | null;
    context: string;
    source_changed: boolean;
    stale: boolean;
    assessments: Assessment[];
}>();
const { form, feedback, locked, recovered, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) +
        ':' +
        purificationAttemptScope(
            props.prior ? 'obligation-revise' : 'obligation-create',
            props.prior?.id ?? props.distribution.id,
        ),
    {
        context: props.context,
        distribution_id: props.distribution.id,
        supersedes_id: props.prior?.id ?? '',
        assessment_id: props.prior?.assessment_id ?? '',
        method_reference: props.prior?.method_reference ?? '',
        method_version: props.prior?.method_version ?? '',
        source_instruction: props.prior?.source_instruction ?? '',
        base_kind: props.prior?.base_kind ?? 'missing',
        custom_base:
            props.prior?.base_kind === 'custom'
                ? (props.prior.base?.amount ?? '')
                : '',
        base_explanation: props.prior?.base_explanation ?? '',
        calculation: props.prior?.calculation ?? 'missing',
        rate_percent: props.prior?.rate_percent ?? '',
        fixed_amount: props.prior?.fixed?.amount ?? '',
        reason: '',
    },
    { source_instruction: 2000 },
);
const reviewing = ref(false);
const fixed = computed(
    () =>
        locked.value ||
        form.processing ||
        reviewing.value ||
        props.source_changed ||
        props.stale,
);
const selected = computed(() =>
    props.assessments.find((a) => a.id === form.assessment_id),
);
watch(
    () => form.base_kind,
    (v) => {
        if (!locked.value && v !== 'custom') form.custom_base = '';
    },
);
watch(
    () => form.calculation,
    (v) => {
        if (!locked.value) {
            if (v !== 'percentage') form.rate_percent = '';
            if (v !== 'fixed') form.fixed_amount = '';
        }
    },
);
watch(
    () => form.hasErrors,
    (v) => {
        if (v) reviewing.value = false;
    },
);
function proceed() {
    if (locked.value || reviewing.value) submit('/purification/obligations');
    else reviewing.value = true;
}
const textFields = [
    {
        key: 'method_reference',
        label: 'Stable sourced method reference',
        required: true,
    },
    {
        key: 'method_version',
        label: 'Source method version · blank if missing',
        required: false,
    },
    {
        key: 'base_explanation',
        label: 'Applicable base explanation / source section',
        required: false,
    },
    {
        key: 'reason',
        label: 'Reason for this attributed version',
        required: true,
    },
];
</script>
<template>
    <PurificationShell
        :title="
            prior
                ? 'Revise the instruction. Retain what was paid.'
                : 'Record the sourced purification instruction.'
        "
        ><template #intro
            >Distribution {{ distribution.reference }} ·
            {{ distribution.effective_date }} · Gross
            {{ distribution.currency }} {{ distribution.amount.amount }}; fees
            {{ distribution.fee.amount }}, withholding
            {{ distribution.tax.amount }}. Saving an obligation never debits
            cash.</template
        >
        <nav class="flex flex-wrap gap-4 text-sm underline">
            <a
                :href="
                    '/portfolio/' +
                    distribution.portfolio_account_id +
                    '/events/' +
                    distribution.id
                "
                target="_blank"
                rel="noopener noreferrer"
                class="min-h-11 content-center"
                >Read distribution evidence in a new tab</a
            ><a
                :href="'/assets/' + distribution.asset_id + '/assessments'"
                target="_blank"
                rel="noopener noreferrer"
                class="min-h-11 content-center"
                >Read source assessments in a new tab</a
            >
        </nav>
        <p
            v-if="source_changed || stale"
            role="alert"
            class="rounded-xl border border-amber-500 p-5"
        >
            {{
                stale
                    ? 'This obligation has a newer version.'
                    : 'This distribution was reversed and has no current replacement.'
            }}
            Check saved history before recording new details. A restored exact
            attempt can still recover its committed receipt.
        </p>
        <form class="max-w-3xl space-y-6" @submit.prevent="proceed">
            <PortfolioAttempt
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                :recovered="recovered"
                history="/purification"
            />
            <fieldset
                :disabled="fixed"
                class="space-y-5 rounded-xl border bg-card p-5"
            >
                <div>
                    <Label for="assessment_id"
                        >Exact source assessment version · optional while
                        pending</Label
                    ><select
                        id="assessment_id"
                        v-model="form.assessment_id"
                        class="mt-2 min-h-11 w-full rounded-md border bg-background px-3"
                    >
                        <option value="">Missing source assessment</option>
                        <option
                            v-for="a in assessments"
                            :key="a.id"
                            :value="a.id"
                        >
                            {{ a.provider }} · v{{ a.version }} ·
                            {{ a.methodology_version }} · {{ a.classification }}
                        </option>
                    </select>
                </div>
                <div v-if="selected" class="rounded-lg bg-muted p-4 text-sm">
                    <p>
                        {{ selected.provider }} ·
                        {{ selected.classification }} · review due
                        {{ selected.review_due_on ?? 'missing'
                        }}<span v-if="selected.overdue"> · overdue</span>
                    </p>
                    <p v-for="e in selected.evidence_refs" :key="e">{{ e }}</p>
                    <a
                        :href="
                            '/assets/' +
                            distribution.asset_id +
                            '/assessments/' +
                            selected.id
                        "
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex min-h-11 items-center underline"
                        >Read this retained assessment version in a new tab</a
                    >
                    <p>
                        Other source claims may conflict. Selecting this version
                        attributes an instruction; it does not certify the
                        asset.
                    </p>
                </div>
                <div v-for="f in textFields" :key="f.key">
                    <Label :for="f.key">{{ f.label }}</Label
                    ><Input
                        :id="f.key"
                        v-model="form[f.key]"
                        :required="f.required"
                        :readonly="f.key === 'method_reference' && !!prior"
                        maxlength="180"
                        class="mt-2"
                    />
                </div>
                <div>
                    <Label for="source_instruction"
                        >Exact source instruction / retained reference · blank
                        if missing</Label
                    ><textarea
                        id="source_instruction"
                        v-model="form.source_instruction"
                        rows="4"
                        maxlength="2000"
                        class="mt-2 w-full rounded-md border bg-background p-3"
                    />
                </div>
                <div>
                    <Label for="base_kind">Applicable income base</Label
                    ><select
                        id="base_kind"
                        v-model="form.base_kind"
                        class="mt-2 min-h-11 w-full rounded-md border bg-background px-3"
                    >
                        <option value="missing">
                            Missing / not established
                        </option>
                        <option value="gross">
                            Recorded gross distribution
                        </option>
                        <option value="net">
                            Recorded net distribution after fees and withholding
                        </option>
                        <option value="custom">
                            Separately sourced custom income base
                        </option>
                    </select>
                </div>
                <div v-if="form.base_kind === 'custom'">
                    <Label for="custom_base"
                        >Source income base · {{ distribution.currency }} ·
                        blank if missing</Label
                    ><Input
                        id="custom_base"
                        v-model="form.custom_base"
                        inputmode="decimal"
                        maxlength="32"
                        class="mt-2"
                    />
                </div>
                <div>
                    <Label for="calculation"
                        >Recorded calculation instruction</Label
                    ><select
                        id="calculation"
                        v-model="form.calculation"
                        class="mt-2 min-h-11 w-full rounded-md border bg-background px-3"
                    >
                        <option value="missing">Missing instructions</option>
                        <option value="percentage">Sourced percentage</option>
                        <option value="fixed">Sourced fixed amount</option>
                    </select>
                </div>
                <div v-if="form.calculation === 'percentage'">
                    <Label for="rate_percent"
                        >Percentage exactly as sourced · blank if missing</Label
                    ><Input
                        id="rate_percent"
                        v-model="form.rate_percent"
                        inputmode="decimal"
                        maxlength="32"
                        class="mt-2"
                    />
                    <p class="mt-2 text-sm text-muted-foreground">
                        No default percentage or religious threshold is
                        supplied. Native cash settles HALF_UP once.
                    </p>
                </div>
                <div v-if="form.calculation === 'fixed'">
                    <Label for="fixed_amount"
                        >Fixed native amount · {{ distribution.currency }} ·
                        blank if missing</Label
                    ><Input
                        id="fixed_amount"
                        v-model="form.fixed_amount"
                        inputmode="decimal"
                        maxlength="32"
                        class="mt-2"
                    />
                </div>
            </fieldset>
            <section
                v-if="reviewing"
                class="space-y-3 rounded-xl border border-teal-700 p-5"
                aria-live="polite"
            >
                <h2 class="text-lg font-semibold">
                    Review the attributed instruction
                </h2>
                <p>
                    {{ form.method_reference }} ·
                    {{ form.method_version || 'version missing' }} ·
                    {{ form.base_kind }} income base · {{ form.calculation }}
                    {{
                        form.rate_percent ||
                        form.fixed_amount ||
                        'value missing'
                    }}
                </p>
                <p class="whitespace-pre-wrap">
                    {{
                        form.source_instruction ||
                        'Source instruction missing: obligation stays pending.'
                    }}
                </p>
                <p>
                    Saving retains a new attributed version, calculates from
                    this recorded income base when complete, and moves no cash.
                    Paid history is retained.
                </p>
            </section>
            <div class="flex flex-wrap gap-3">
                <Button
                    type="submit"
                    :disabled="
                        form.processing ||
                        ((source_changed || stale) && !locked)
                    "
                    >{{
                        locked
                            ? 'Retry exact saved attempt'
                            : reviewing
                              ? 'Confirm obligation · no cash movement'
                              : 'Review instruction'
                    }}</Button
                ><Button
                    v-if="reviewing && !locked"
                    type="button"
                    variant="outline"
                    @click="reviewing = false"
                    >Edit details</Button
                >
            </div>
        </form></PurificationShell
    >
</template>
