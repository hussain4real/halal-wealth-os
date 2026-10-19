<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import PortfolioShell from '@/components/PortfolioShell.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import type { PortfolioAccount } from '@/types/portfolio';
import type { InvestmentEvent } from '@/types/investment';
import { eventLabels } from '@/types/investment';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Portfolio', href: '/portfolio' },
            { title: 'Record activity', href: '#' },
        ],
    },
});
const props = defineProps<{
    account: PortfolioAccount;
    choices: { id: string; name: string; kind: string }[];
    applications: { id: string; name: string; cancelled: boolean }[];
    prior: InvestmentEvent | null;
    today: string;
    query: string;
    selected_asset: string;
}>();
const base = '/portfolio/' + props.account.portfolio_id + '/events';
const { form, feedback, locked, recovered, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) +
        ':investment:' +
        props.account.portfolio_id +
        ':' +
        (props.prior?.id ?? 'new'),
    {
        kind: props.prior?.kind ?? 'buy',
        asset_id: props.prior?.asset_id ?? props.selected_asset,
        effective_date: props.prior?.effective_date ?? props.today,
        quantity: props.prior?.quantity ?? '',
        unit_price: props.prior?.unit_price ?? '',
        amount: props.prior?.amount.amount ?? '',
        fee: props.prior?.fee.amount ?? '0',
        tax: props.prior?.tax.amount ?? '0',
        split_numerator: props.prior?.split_numerator ?? '',
        split_denominator: props.prior?.split_denominator ?? '',
        subscription_id: props.prior?.subscription_id ?? '',
        action_reference: props.prior?.action_reference ?? '',
        confirmed: '',
        replaces_id: props.prior?.id ?? '',
        source: props.prior?.source ?? '',
        reason: '',
    },
);
const reviewing = ref(false);
const assetQuery = ref(props.query);
function searchAssets() {
    router.get(
        base + '/new',
        {
            q: assetQuery.value,
            ...(props.prior ? { replace: props.prior.id } : {}),
            ...(form.asset_id ? { asset: form.asset_id } : {}),
        },
        {
            only: ['choices', 'query'],
            preserveState: true,
            preserveScroll: true,
        },
    );
}
const unitized = computed(() =>
    ['buy', 'sell', 'ipo_allot'].includes(form.kind),
);
const child = computed(
    () => form.kind.startsWith('ipo_') && form.kind !== 'ipo_intent',
);
const fields = computed(() => [
    ...(unitized.value
        ? [
              { key: 'quantity', label: 'Exact units', required: true },
              {
                  key: 'unit_price',
                  label: 'Recorded execution price (optional; not a quote)',
                  required: false,
              },
          ]
        : []),
    ...(['split', 'ipo_cancel'].includes(form.kind)
        ? []
        : [
              {
                  key: 'amount',
                  label:
                      form.kind === 'ipo_allot'
                          ? 'Confirmed cost allocated from receivable'
                          : form.kind === 'dividend'
                            ? 'Gross dividend'
                            : form.kind.startsWith('ipo_')
                              ? 'Recorded application amount'
                              : form.kind === 'fee'
                                ? 'Recorded fee amount'
                                : 'Actual gross settled consideration',
                  required: true,
              },
          ]),
    ...(['buy', 'sell', 'dividend'].includes(form.kind)
        ? [{ key: 'fee', label: 'Recorded fees', required: true }]
        : []),
    ...(form.kind === 'dividend'
        ? [
              {
                  key: 'tax',
                  label: 'Recorded tax withheld (amount only)',
                  required: true,
              },
          ]
        : []),
    ...(form.kind === 'split'
        ? [
              {
                  key: 'split_numerator',
                  label: 'New units in ratio',
                  required: true,
              },
              {
                  key: 'split_denominator',
                  label: 'Old units in ratio',
                  required: true,
              },
          ]
        : []),
]);
const retainedAsset = computed(
    () => form.asset_id && !props.choices.some((a) => a.id === form.asset_id),
);
const retainedApplication = computed(
    () =>
        form.subscription_id &&
        !props.applications.some((a) => a.id === form.subscription_id),
);
const selectedAsset = computed(
    () =>
        props.choices.find((a) => a.id === form.asset_id)?.name ??
        'Retained reference #' + form.asset_id,
);
function changeKind() {
    form.quantity = '';
    form.unit_price = '';
    form.fee = '0';
    form.tax = '0';
    form.subscription_id = '';
    form.confirmed = '';
    form.split_numerator = form.kind === 'split' ? '2' : '';
    form.split_denominator = form.kind === 'split' ? '1' : '';
    form.amount = ['split', 'ipo_cancel'].includes(form.kind) ? '0' : '';
}
function proceed() {
    if (reviewing.value || locked.value) submit(base);
    else reviewing.value = true;
}
watch(
    () => form.hasErrors,
    (errors) => {
        if (errors) reviewing.value = false;
    },
);
</script>
<template>
    <PortfolioShell
        :title="prior ? 'Correct a recorded fact.' : 'Record what happened.'"
        :back="base"
    >
        <template #intro
            >{{ account.name }} · {{ account.currency }} · UTC business dates.
            This records evidence; no order is placed. All cash and holdings
            changes save together, or none do.</template
        >
        <section
            class="max-w-4xl space-y-2 rounded-xl border bg-card p-4 text-sm"
            aria-label="Compliance evidence and factual recording"
        >
            <p>
                Review current assessment evidence before an investment
                decision. Missing, conflicting or overdue evidence remains
                unresolved. You can record an existing holding or actual event
                with any assessment status.
            </p>
            <Link
                v-if="form.asset_id"
                :href="'/assets/' + form.asset_id + '/assessments'"
                class="inline-flex min-h-10 items-center font-medium underline"
                >Review the selected asset’s assessment evidence →</Link
            >
        </section>
        <form
            class="flex max-w-4xl flex-wrap items-end gap-3"
            @submit.prevent="searchAssets"
        >
            <div class="min-w-0 grow">
                <Label for="asset-search">Find an asset reference</Label
                ><Input
                    id="asset-search"
                    v-model="assetQuery"
                    maxlength="120"
                    :disabled="locked || reviewing"
                />
            </div>
            <Button
                type="submit"
                variant="outline"
                :disabled="locked || reviewing"
                >Search references</Button
            >
        </form>
        <form class="max-w-4xl space-y-6" @submit.prevent="proceed">
            <PortfolioAttempt
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                :recovered="recovered"
                :history="base"
            />
            <p
                v-if="account.archived"
                role="status"
                class="rounded-xl border p-4"
            >
                This account is archived. Retained committed attempts remain
                recoverable; new facts cannot be saved.
            </p>
            <p
                v-if="prior"
                class="rounded-xl bg-muted/40 p-4 text-sm leading-relaxed"
            >
                The original fact and its cash journal will be cancelled at
                their original business date. The replacement inherits its
                replay order. Any invalid downstream position or cash history
                rejects the complete correction.
            </p>
            <fieldset
                :disabled="
                    form.processing || locked || reviewing || account.archived
                "
                class="space-y-6 rounded-2xl border bg-card p-5 sm:p-7"
            >
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <Label for="kind">Recorded fact</Label
                        ><select
                            id="kind"
                            :aria-invalid="!!form.errors.kind"
                            v-model="form.kind"
                            :disabled="!!prior"
                            class="mt-2 min-h-11 w-full rounded-lg border bg-background px-3"
                            @change="changeKind"
                        >
                            <option
                                v-for="(label, kind) in eventLabels"
                                v-show="kind !== 'reversal'"
                                :key="kind"
                                :value="kind"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <Label for="effective_date">UTC business date</Label
                        ><Input
                            id="effective_date"
                            :aria-invalid="!!form.errors.effective_date"
                            v-model="form.effective_date"
                            type="date"
                            required
                            :max="today"
                        />
                    </div>
                </div>
                <div>
                    <Label for="asset_id"
                        >Asset reference · {{ account.currency }}</Label
                    ><select
                        id="asset_id"
                        :aria-invalid="!!form.errors.asset_id"
                        v-model="form.asset_id"
                        required
                        class="mt-2 min-h-11 w-full rounded-lg border bg-background px-3"
                    >
                        <option value="" disabled>
                            Choose an equity or unitized fund
                        </option>
                        <option v-if="retainedAsset" :value="form.asset_id">
                            Retained selected reference #{{ form.asset_id }}
                        </option>
                        <option
                            v-for="asset in choices"
                            :key="asset.id"
                            :value="asset.id"
                        >
                            {{ asset.name }}
                        </option>
                    </select>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Search above without clearing entered details, or add a
                        reference in the
                        <Link href="/assets" class="underline"
                            >asset catalog</Link
                        >. Unsupported manual instruments have no invented
                        units.
                    </p>
                </div>
                <div v-if="child">
                    <Label for="subscription_id"
                        >Application in this account</Label
                    ><select
                        id="subscription_id"
                        :aria-invalid="!!form.errors.subscription_id"
                        v-model="form.subscription_id"
                        required
                        class="mt-2 min-h-11 w-full rounded-lg border bg-background px-3"
                    >
                        <option value="" disabled>
                            Choose the retained application
                        </option>
                        <option
                            v-if="retainedApplication"
                            :value="form.subscription_id"
                        >
                            Retained application #{{ form.subscription_id }}
                        </option>
                        <option
                            v-for="application in applications.filter(
                                (a) => !a.cancelled,
                            )"
                            :key="application.id"
                            :value="application.id"
                        >
                            #{{ application.id }} · {{ application.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div v-for="field in fields" :key="field.key">
                        <Label :for="field.key"
                            >{{ field.label
                            }}<span
                                v-if="
                                    field.key !== 'quantity' &&
                                    !field.key.startsWith('split_')
                                "
                            >
                                · {{ account.currency }}</span
                            ></Label
                        ><Input
                            :id="field.key"
                            v-model="form[field.key]"
                            inputmode="decimal"
                            autocomplete="off"
                            maxlength="32"
                            :required="field.required"
                            :aria-invalid="!!form.errors[field.key]"
                        />
                    </div>
                </div>
                <p
                    v-if="form.kind.startsWith('ipo_')"
                    class="rounded-xl bg-muted/40 p-4 text-sm leading-relaxed"
                >
                    IPO earmarks use posted cash in this investment account.
                    Record a transfer into it first when needed. Payment
                    consumes its own earmark and creates a receivable. Only
                    confirmed allotted units become shares; cancellation cannot
                    erase a paid receivable.
                </p>
                <label
                    v-if="form.kind === 'ipo_allot'"
                    class="flex items-start gap-3 text-sm"
                    ><input
                        id="confirmed"
                        :aria-invalid="!!form.errors.confirmed"
                        v-model="form.confirmed"
                        type="checkbox"
                        true-value="1"
                        false-value=""
                        required
                        class="mt-1 size-4"
                    /><span
                        >I have evidence of these actual allotted units and
                        their allocated cost. These are not intended or
                        estimated shares.</span
                    ></label
                >
                <div>
                    <Label for="action_reference"
                        >Unique statement / corporate-action reference</Label
                    ><Input
                        id="action_reference"
                        :aria-invalid="!!form.errors.action_reference"
                        v-model="form.action_reference"
                        required
                        maxlength="180"
                        :readonly="!!prior"
                    />
                    <p class="mt-2 text-xs text-muted-foreground">
                        Use one stable reference for each real fact. Reusing it
                        cannot apply a split, dividend or allotment twice.
                    </p>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <Label for="source">Evidence source</Label
                        ><Input
                            id="source"
                            :aria-invalid="!!form.errors.source"
                            v-model="form.source"
                            required
                            maxlength="180"
                        />
                    </div>
                    <div>
                        <Label for="reason">Record / correction reason</Label
                        ><Input
                            id="reason"
                            :aria-invalid="!!form.errors.reason"
                            v-model="form.reason"
                            required
                            maxlength="180"
                        />
                    </div>
                </div>
            </fieldset>
            <section
                v-if="reviewing || locked"
                aria-label="Review investment fact"
                class="rounded-2xl border border-primary/30 bg-primary/5 p-5 sm:p-7"
            >
                <h2 class="text-lg font-semibold">Review before saving</h2>
                <p class="mt-2 text-sm">
                    {{ eventLabels[form.kind] }} · {{ selectedAsset }} ·
                    {{ account.currency }} · UTC {{ form.effective_date }}
                </p>
                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                    <div v-for="field in fields" :key="field.key">
                        <dt class="text-muted-foreground">{{ field.label }}</dt>
                        <dd class="mt-1 font-mono break-all">
                            {{ form[field.key] || 'Not supplied' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            Statement/action reference
                        </dt>
                        <dd class="mt-1 break-all">
                            {{ form.action_reference }}
                        </dd>
                    </div>
                </dl>
                <p class="mt-5 text-xs leading-relaxed text-muted-foreground">
                    Actual gross settlement is authoritative; execution price is
                    retained separately. Purchase fees increase basis, sale fees
                    reduce proceeds. Book result is not current performance or
                    tax advice. Backdated changes replay all dependent history.
                </p>
            </section>
            <div class="flex flex-wrap gap-3">
                <Button
                    type="submit"
                    :disabled="form.processing || (account.archived && !locked)"
                    >{{
                        form.processing
                            ? 'Saving…'
                            : locked
                              ? 'Retry exact retained attempt'
                              : reviewing
                                ? 'Save recorded fact'
                                : 'Review record'
                    }}</Button
                ><Button
                    v-if="reviewing && !locked"
                    type="button"
                    variant="outline"
                    @click="reviewing = false"
                    >Edit details</Button
                ><Button as-child variant="ghost"
                    ><Link :href="base">Back to activity</Link></Button
                >
            </div>
        </form>
    </PortfolioShell>
</template>
