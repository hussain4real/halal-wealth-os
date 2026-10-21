<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import DecisionShell from '@/components/DecisionShell.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import { decisionTranches } from '@/lib/decisionTranches';
import type { Decision, TrancheDraft } from '@/types/decisions';
const props = defineProps<{
    prior: Decision | null;
    draft: Record<string, string> | null;
    stale: boolean;
    assets: { id: string; name: string; currency: string; version: string }[];
    today: string;
}>();
const evidenceFields = [
    { key: 'status', label: 'Offer status' },
    { key: 'price', label: 'Offer price per unit' },
    { key: 'eligibility', label: 'Eligibility' },
];
const thesisFields = [
    { key: 'rationale', label: 'Why this opportunity?' },
    { key: 'risks', label: 'Risks and reasons to decline' },
    { key: 'horizon', label: 'Time horizon' },
    { key: 'review_triggers', label: 'What would change your decision?' },
];
const defaults: Record<string, string> = {
    prior_id: '',
    name: '',
    status: 'watchlist',
    currency: 'NGN',
    asset_id: '',
    target: '',
    maximum: '',
    tranches: '[]',
    rationale: '',
    risks: '',
    horizon: '',
    review_triggers: '',
    review_on: '',
    currency_threshold: '',
    portfolio_total: '',
    currency_exposure: '',
    reference_source: '',
    reference_on: '',
    reference_review_on: '',
    reason: '',
};
const limits: Record<string, number> = {
    tranches: 64000,
    rationale: 2000,
    risks: 2000,
    horizon: 2000,
    review_triggers: 2000,
};
for (const e of evidenceFields) {
    defaults['offer_' + e.key] = '';
    defaults[e.key + '_url'] = '';
    defaults[e.key + '_verified_on'] = '';
    defaults[e.key + '_review_on'] = '';
    defaults[e.key + '_official'] = '0';
    limits[e.key + '_url'] = 1000;
}
const { form, locked, recovered, feedback, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) +
        ':decision-plan:' +
        (props.prior?.id ?? 'new'),
    { ...defaults, ...props.draft },
    limits,
);
const fixed = computed(() => locked.value || form.processing || props.stale);
const parsed = computed(() => decisionTranches(form.tranches));
const tranches = computed(() => parsed.value ?? []);
function change(index: number, field: keyof TrancheDraft, value: string) {
    if (fixed.value || parsed.value === null) return;
    form.tranches = JSON.stringify(
        tranches.value.map((t, i) =>
            i === index ? { ...t, [field]: value } : t,
        ),
    );
}
function inputValue(event: Event): string {
    return event.target instanceof HTMLInputElement ? event.target.value : '';
}
function add() {
    if (!fixed.value && parsed.value !== null && tranches.value.length < 24)
        form.tranches = JSON.stringify([
            ...tranches.value,
            { key: crypto.randomUUID(), label: '', amount: '', condition: '' },
        ]);
}
function remove(index: number) {
    if (!fixed.value)
        form.tranches = JSON.stringify(
            tranches.value.filter((_, i) => i !== index),
        );
}
</script>
<template>
    <DecisionShell
        :title="
            prior
                ? 'Revise your thesis. Keep its history.'
                : 'Start with a thesis, then set a limit.'
        "
        ><template #intro
            >Leave unknown terms blank. A proposal needs a target, matching
            tranches, rationale, risks, horizon and review triggers. Every saved
            revision retains the previous reasoning and source dates.</template
        >
        <p
            v-if="stale"
            role="alert"
            class="rounded-xl border border-amber-500 p-5"
        >
            This version changed or closed. Open the latest history before a new
            action. An exact restored retry can still recover its receipt.
        </p>
        <form class="space-y-7" @submit.prevent="submit('/opportunities')">
            <PortfolioAttempt
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                :recovered="recovered"
                history="/opportunities"
            />
            <fieldset
                :disabled="fixed"
                class="space-y-5 rounded-2xl border bg-card p-5 sm:p-7"
            >
                <legend class="px-2 text-lg font-semibold">
                    Opportunity &amp; allocation
                </legend>
                <div class="grid gap-5 sm:grid-cols-2">
                    <label class="space-y-2"
                        ><span>Name</span
                        ><input
                            v-model="form.name"
                            required
                            maxlength="180"
                            class="decision-input"
                            :aria-invalid="!!form.errors.name" /></label
                    ><label class="space-y-2"
                        ><span>Status</span
                        ><select v-model="form.status" class="decision-input">
                            <option value="watchlist">
                                Watchlist · exploring
                            </option>
                            <option value="planned">
                                Proposal · intent only
                            </option>
                            <option v-if="prior" value="closed">
                                Closed · reservations must be released
                            </option>
                        </select></label
                    >
                    <label class="space-y-2"
                        ><span>Native currency</span
                        ><select
                            v-model="form.currency"
                            :disabled="!!prior"
                            class="decision-input"
                        >
                            <option
                                v-for="c in [
                                    'QAR',
                                    'NGN',
                                    'USD',
                                    'EUR',
                                    'GBP',
                                    'JPY',
                                    'KWD',
                                ]"
                                :key="c"
                            >
                                {{ c }}
                            </option>
                        </select></label
                    ><label class="space-y-2"
                        ><span>Exact asset reference ID · optional</span
                        ><input
                            v-model="form.asset_id"
                            list="decision-assets"
                            :readonly="!!prior?.asset_id"
                            class="decision-input"
                            inputmode="numeric"
                        /><small class="block text-muted-foreground"
                            >Latest 100 references suggested. An older owned ID
                            is also accepted. Once identified, this reference
                            stays fixed.</small
                        ></label
                    ><datalist id="decision-assets">
                        <option v-for="a in assets" :key="a.id" :value="a.id">
                            {{ a.name }} · {{ a.currency }} · v{{ a.version }}
                        </option>
                    </datalist>
                    <label class="space-y-2"
                        ><span>Target · blank if unknown</span
                        ><input
                            v-model="form.target"
                            class="decision-input"
                            inputmode="decimal"
                            :aria-invalid="!!form.errors.target" /></label
                    ><label class="space-y-2"
                        ><span>Maximum allocation · blank if unset</span
                        ><input
                            v-model="form.maximum"
                            class="decision-input"
                            inputmode="decimal"
                    /></label>
                </div>
                <p class="text-sm text-muted-foreground">
                    Amounts stay in this currency. Conditional tranches must
                    total the target exactly. Stable tranche references retain
                    earlier reservations and actual links through revisions.
                </p>
                <p v-if="parsed === null" role="alert">
                    Saved tranche details could not be read. Check history or
                    retry the saved request.
                </p>
                <article
                    v-for="(t, i) in tranches"
                    :key="t.key"
                    class="space-y-3 rounded-xl border p-4"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-semibold">Tranche {{ i + 1 }}</h3>
                        <button
                            type="button"
                            class="min-h-11 px-3 text-sm underline"
                            @click="remove(i)"
                        >
                            Remove tranche {{ i + 1 }}
                        </button>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="space-y-2"
                            ><span>Tranche {{ i + 1 }} label</span
                            ><input
                                :value="t.label"
                                required
                                maxlength="180"
                                class="decision-input"
                                @input="
                                    change(i, 'label', inputValue($event))
                                " /></label
                        ><label class="space-y-2"
                            ><span
                                >Tranche {{ i + 1 }} amount ·
                                {{ form.currency }}</span
                            ><input
                                :value="t.amount"
                                required
                                inputmode="decimal"
                                class="decision-input"
                                @input="
                                    change(i, 'amount', inputValue($event))
                                " /></label
                        ><label class="space-y-2 sm:col-span-2"
                            ><span>Tranche {{ i + 1 }} condition</span
                            ><input
                                :value="t.condition"
                                required
                                maxlength="180"
                                class="decision-input"
                                @input="
                                    change(i, 'condition', inputValue($event))
                                "
                        /></label>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Stable reference: {{ t.key }}
                    </p>
                </article>
                <button
                    type="button"
                    :disabled="tranches.length >= 24 || parsed === null"
                    class="min-h-11 rounded-lg border px-4 text-sm"
                    @click="add"
                >
                    + Add conditional tranche
                </button>
            </fieldset>
            <fieldset
                :disabled="fixed"
                class="grid gap-5 rounded-2xl border bg-card p-5 sm:grid-cols-2 sm:p-7"
            >
                <legend class="px-2 text-lg font-semibold">
                    Decision thesis
                </legend>
                <label v-for="f in thesisFields" :key="f.key" class="space-y-2"
                    ><span>{{ f.label }}</span
                    ><textarea
                        v-model="form[f.key]"
                        :required="form.status === 'planned'"
                        rows="4"
                        maxlength="2000"
                        class="decision-input"
                        :aria-invalid="!!form.errors[f.key]"
                    /></label
                ><label class="space-y-2"
                    ><span>Thesis review date</span
                    ><input
                        v-model="form.review_on"
                        type="date"
                        :required="form.status === 'planned'"
                        class="decision-input"
                /></label>
            </fieldset>
            <fieldset
                :disabled="fixed"
                class="space-y-5 rounded-2xl border bg-card p-5 sm:p-7"
            >
                <legend class="px-2 text-lg font-semibold">
                    Offer evidence · each claim needs its own source
                </legend>
                <p class="text-sm text-muted-foreground">
                    A link alone does not verify a claim. Record what you
                    checked, the date, when it needs review, and whether you
                    checked an official source. Offer evidence does not
                    establish Shariah compliance.
                </p>
                <section
                    v-for="e in evidenceFields"
                    :key="e.key"
                    class="space-y-4 border-t pt-5"
                >
                    <h3 class="font-semibold">{{ e.label }}</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="space-y-2"
                            ><span>{{ e.label }} · blank if unknown</span
                            ><input
                                v-model="form['offer_' + e.key]"
                                maxlength="180"
                                class="decision-input" /></label
                        ><label class="space-y-2"
                            ><span>{{ e.label }} source URL</span
                            ><input
                                v-model="form[e.key + '_url']"
                                type="url"
                                maxlength="1000"
                                placeholder="https://"
                                class="decision-input" /></label
                        ><label class="space-y-2"
                            ><span>{{ e.label }} verified on</span
                            ><input
                                v-model="form[e.key + '_verified_on']"
                                type="date"
                                :max="today"
                                class="decision-input" /></label
                        ><label class="space-y-2"
                            ><span>{{ e.label }} review by</span
                            ><input
                                v-model="form[e.key + '_review_on']"
                                type="date"
                                class="decision-input"
                        /></label>
                    </div>
                    <label class="flex min-h-11 items-center gap-3"
                        ><input
                            v-model="form[e.key + '_official']"
                            type="checkbox"
                            true-value="1"
                            false-value="0"
                            class="size-5"
                        /><span
                            >I checked an official source for
                            {{ e.label.toLowerCase() }}.</span
                        ></label
                    >
                </section>
            </fieldset>
            <fieldset
                :disabled="fixed"
                class="grid gap-5 rounded-2xl border bg-card p-5 sm:grid-cols-2 sm:p-7"
            >
                <legend class="px-2 text-lg font-semibold">
                    Currency concentration · optional reference
                </legend>
                <p class="text-sm text-muted-foreground sm:col-span-2">
                    Enter a sourced portfolio reference in
                    {{ form.currency }} and your own warning threshold. The
                    comparison is (existing currency exposure + this target) ÷
                    total portfolio reference. Missing or expired evidence stays
                    unresolved. No FX conversion is inferred.
                </p>
                <label
                    v-for="f in [
                        {
                            key: 'currency_threshold',
                            label: 'Warning threshold · %',
                        },
                        {
                            key: 'portfolio_total',
                            label:
                                'Total portfolio reference · ' + form.currency,
                        },
                        {
                            key: 'currency_exposure',
                            label: 'Existing exposure to ' + form.currency,
                        },
                        {
                            key: 'reference_source',
                            label: 'Reference source / method',
                        },
                    ]"
                    :key="f.key"
                    class="space-y-2"
                    ><span>{{ f.label }}</span
                    ><input
                        v-model="form[f.key]"
                        class="decision-input"
                        maxlength="180" /></label
                ><label class="space-y-2"
                    ><span>Reference observed on</span
                    ><input
                        v-model="form.reference_on"
                        type="date"
                        :max="today"
                        class="decision-input" /></label
                ><label class="space-y-2"
                    ><span>Reference review by</span
                    ><input
                        v-model="form.reference_review_on"
                        type="date"
                        class="decision-input"
                /></label>
            </fieldset>
            <label class="block max-w-3xl space-y-2"
                ><span>Reason for this version</span
                ><input
                    v-model="form.reason"
                    :disabled="fixed"
                    required
                    maxlength="180"
                    class="decision-input"
                    :aria-invalid="!!form.errors.reason"
            /></label>
            <p class="text-sm text-muted-foreground">
                Saving retains the proposal and evidence only. Reserve cash or
                link an existing trade from the saved opportunity.
            </p>
            <button
                :disabled="
                    form.processing || (stale && !locked) || parsed === null
                "
                class="min-h-12 rounded-lg bg-teal-800 px-6 font-medium text-white disabled:opacity-50 dark:bg-teal-200 dark:text-teal-950"
            >
                {{
                    locked
                        ? 'Retry exact saved request'
                        : prior
                          ? 'Save thesis revision'
                          : 'Save opportunity'
                }}
            </button>
        </form>
    </DecisionShell>
</template>
<style scoped>
@reference '../../../css/app.css';
.decision-input {
    @apply block min-h-11 w-full rounded-lg border bg-background px-3 py-2 text-base disabled:opacity-60;
}
</style>
