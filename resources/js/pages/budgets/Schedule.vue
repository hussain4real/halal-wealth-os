<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import LedgerFeedback from '@/components/LedgerFeedback.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { planningRequestIdentity } from '@/composables/planningRequestIdentity';
import { useLedgerFeedback } from '@/composables/useLedgerFeedback';
import type { BudgetCategory, BudgetRule } from '@/types/budget';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Monthly plan', href: '/budgets' },
            { title: 'Plan', href: '#' },
        ],
    },
});
const props = defineProps<{
    prior: BudgetRule | null;
    categories: BudgetCategory[];
    currencies: string[];
    today: string;
}>();
const page = usePage();
const form = useForm(
    'budget-rule:' + page.props.auth.user.id + ':' + (props.prior?.id ?? 'new'),
    {
        idempotency_key: crypto.randomUUID(),
        name: props.prior?.name ?? '',
        category_id: props.prior?.category_id ?? '',
        currency: props.prior?.currency ?? 'QAR',
        amount: props.prior?.amount.amount ?? '',
        cadence: props.prior?.cadence ?? 'monthly',
        interval_months:
            props.prior?.cadence === 'term' ? props.prior.interval_months : '',
        first_due_date: '',
        provision_start_date: '',
        end_date: props.prior?.end_date ?? '',
        cutover_date: '',
        reason: '',
        salary: props.prior?.salary ?? false,
        standing_order: props.prior?.standing_order ?? false,
    },
);
const selected = computed(() =>
    props.categories.find((c) => c.id === form.category_id),
);
const provision = computed(
    () => form.cadence === 'annual' || form.cadence === 'term',
);
const identity = planningRequestIdentity(
    'budget-schedule:' +
        page.props.auth.user.id +
        ':' +
        (props.prior?.id ?? 'new'),
    form.idempotency_key,
);
form.defaults('idempotency_key', identity.current);
form.idempotency_key = identity.current;
const feedback = useLedgerFeedback(
    () => form.errors,
    () => form.clearErrors(),
);
const submit = () =>
    form
        .transform((data) => ({
            ...data,
            interval_months:
                data.cadence === 'term' ? data.interval_months : '',
            provision_start_date: provision.value
                ? data.provision_start_date
                : '',
            salary: data.salary ? '1' : '0',
            standing_order: data.standing_order ? '1' : '0',
        }))
        .post(
            props.prior
                ? '/budgets/schedules/' + props.prior.id + '/revise'
                : '/budgets/schedules',
            feedback.options(() => {
                form.defaults('idempotency_key', identity.complete());
                form.reset();
            }),
        );
</script>
<template>
    <Head :title="prior ? 'Revise future plan' : 'Add a plan'" />
    <div
        class="mx-auto w-full max-w-4xl space-y-7 px-4 py-8 scheme-light sm:px-8 sm:py-12 dark:scheme-dark"
    >
        <Link
            href="/budgets"
            class="inline-flex min-h-10 items-center text-sm text-muted-foreground underline"
            >← Back to monthly plan</Link
        >
        <header class="max-w-2xl">
            <p
                class="text-xs font-medium tracking-[0.18em] text-teal-800 uppercase dark:text-teal-200"
            >
                Give tomorrow a little structure
            </p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight">
                {{
                    prior
                        ? 'Revise the future plan.'
                        : 'Plan an income or commitment.'
                }}
            </h1>
            <p class="mt-3 leading-relaxed text-muted-foreground">
                Set an explicit first date and the amount due each time.
                Expected salary, school costs and an existing standing order
                remain forecasts until actual receipts are recorded.
            </p>
        </header>
        <div
            v-if="!categories.length"
            class="rounded-2xl border border-dashed p-7"
        >
            <h2 class="font-medium">Start with a category</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Choose normal-budget and emergency-essential inclusion
                independently before adding a plan.
            </p>
            <Button as-child class="mt-5 min-h-11"
                ><Link href="/budgets/categories/new"
                    >Add category</Link
                ></Button
            >
        </div>
        <form
            v-else
            class="rounded-2xl border bg-card p-5 shadow-sm sm:p-8"
            @submit.prevent="submit"
        >
            <LedgerFeedback
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                history="/budgets"
            />
            <p
                v-if="prior"
                class="mb-6 rounded-xl bg-muted/60 p-4 text-sm leading-relaxed"
            >
                Rule v{{ prior.version }} and its matched history are retained.
                Use a new first date on/after an explicit future cutover. A
                cutover that would replace effective future matches is refused
                until you release those matches.
            </p>
            <fieldset :disabled="form.processing" class="space-y-6">
                <legend class="sr-only">
                    Forecast details and Qatar calendar rule
                </legend>
                <div>
                    <Label for="rule-name">Plan name</Label
                    ><Input
                        id="rule-name"
                        v-model="form.name"
                        required
                        maxlength="180"
                        class="mt-2 min-h-11"
                        :aria-invalid="!!form.errors.name"
                        aria-describedby="rule-name-error"
                    /><InputError
                        id="rule-name-error"
                        :message="form.errors.name"
                    />
                </div>
                <div>
                    <Label for="rule-category">Category version</Label
                    ><select
                        id="rule-category"
                        v-model="form.category_id"
                        required
                        class="mt-2 min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm"
                        :aria-invalid="!!form.errors.category_id"
                        aria-describedby="rule-category-help rule-category-error"
                    >
                        <option value="" disabled>Choose a category</option>
                        <option
                            v-for="category in categories"
                            :key="category.id"
                            :value="category.id"
                        >
                            {{ category.name }} · v{{ category.version }} ·
                            {{ category.direction }}
                        </option>
                    </select>
                    <p
                        id="rule-category-help"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        {{
                            selected
                                ? (selected.normal_budget
                                      ? 'Included in normal budget.'
                                      : 'Outside normal budget.') +
                                  ' ' +
                                  (selected.emergency_essential
                                      ? 'Emergency essential for expenses.'
                                      : 'Not emergency essential.')
                                : 'This plan retains the selected category flags even if the category is later revised.'
                        }}
                    </p>
                    <InputError
                        id="rule-category-error"
                        :message="form.errors.category_id"
                    />
                </div>
                <div
                    class="grid gap-6 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]"
                >
                    <div>
                        <Label for="rule-currency">Native currency</Label
                        ><select
                            id="rule-currency"
                            v-model="form.currency"
                            required
                            class="mt-2 min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm"
                            :aria-invalid="!!form.errors.currency"
                            aria-describedby="rule-currency-error"
                        >
                            <option
                                v-for="currency in currencies"
                                :key="currency"
                                :value="currency"
                            >
                                {{ currency }}
                            </option></select
                        ><InputError
                            id="rule-currency-error"
                            :message="form.errors.currency"
                        />
                    </div>
                    <div>
                        <Label for="rule-amount"
                            >Amount due each time · {{ form.currency }}</Label
                        ><Input
                            id="rule-amount"
                            v-model="form.amount"
                            inputmode="decimal"
                            required
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.amount"
                            aria-describedby="rule-amount-help rule-amount-error"
                        />
                        <p
                            id="rule-amount-help"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Exact positive amount in the selected currency. A
                            term or annual amount is the whole bill.
                        </p>
                        <InputError
                            id="rule-amount-error"
                            :message="form.errors.amount"
                        />
                    </div>
                </div>
                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <Label for="rule-cadence">How often is it due?</Label
                        ><select
                            id="rule-cadence"
                            v-model="form.cadence"
                            required
                            class="mt-2 min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm"
                            :aria-invalid="!!form.errors.cadence"
                            aria-describedby="rule-cadence-error"
                        >
                            <option value="monthly">Monthly</option>
                            <option value="term">Configured term</option>
                            <option value="annual">Annual</option>
                            <option value="once">
                                Once · dated commitment
                            </option></select
                        ><InputError
                            id="rule-cadence-error"
                            :message="form.errors.cadence"
                        />
                    </div>
                    <div v-if="form.cadence === 'term'">
                        <Label for="rule-interval"
                            >Months between term bills</Label
                        ><Input
                            id="rule-interval"
                            v-model="form.interval_months"
                            type="text"
                            inputmode="numeric"
                            required
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.interval_months"
                            aria-describedby="rule-interval-help rule-interval-error"
                        />
                        <p
                            id="rule-interval-help"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Choose 2–24 calendar months explicitly.
                        </p>
                        <InputError
                            id="rule-interval-error"
                            :message="form.errors.interval_months"
                        />
                    </div>
                </div>
                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <Label for="rule-first"
                            >{{
                                form.salary
                                    ? 'First expected payday'
                                    : 'First due date'
                            }}
                            · Qatar</Label
                        ><Input
                            id="rule-first"
                            v-model="form.first_due_date"
                            type="date"
                            min="0001-01-01"
                            max="9998-12-31"
                            required
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.first_due_date"
                            aria-describedby="rule-first-help rule-first-error"
                        />
                        <p
                            id="rule-first-help"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            This configures the salary/due day. Missing days
                            clamp to month end without drifting or assuming a
                            bank-day shift.
                        </p>
                        <InputError
                            id="rule-first-error"
                            :message="form.errors.first_due_date"
                        />
                    </div>
                    <div>
                        <Label for="rule-end">Last due date · optional</Label
                        ><Input
                            id="rule-end"
                            v-model="form.end_date"
                            type="date"
                            min="0001-01-01"
                            max="9998-12-31"
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.end_date"
                            aria-describedby="rule-end-error"
                        /><InputError
                            id="rule-end-error"
                            :message="form.errors.end_date"
                        />
                    </div>
                </div>
                <div v-if="provision" class="rounded-xl border bg-muted/35 p-4">
                    <Label for="rule-provision-start"
                        >Start monthly provision · Qatar</Label
                    ><Input
                        id="rule-provision-start"
                        v-model="form.provision_start_date"
                        type="date"
                        min="0001-01-01"
                        max="9998-12-31"
                        required
                        class="mt-2 min-h-11"
                        :aria-invalid="!!form.errors.provision_start_date"
                        aria-describedby="rule-provision-help rule-provision-error"
                    />
                    <p
                        id="rule-provision-help"
                        class="mt-2 text-xs leading-relaxed text-muted-foreground"
                    >
                        Choose a start on/before the first bill, and on/after
                        any revision cutover. The monthly equivalent divides the
                        bill by its cadence and rounds up to a minor unit. It
                        creates no cash or funded balance and does not project
                        enough savings by the first bill.
                    </p>
                    <InputError
                        id="rule-provision-error"
                        :message="form.errors.provision_start_date"
                    />
                </div>
                <div class="space-y-4">
                    <label
                        for="rule-salary"
                        class="flex cursor-pointer items-start gap-3 rounded-xl border p-4"
                        ><input
                            id="rule-salary"
                            v-model="form.salary"
                            type="checkbox"
                            class="mt-1 size-4 accent-teal-800"
                            :aria-invalid="!!form.errors.salary"
                            aria-describedby="rule-salary-error"
                        /><span
                            ><span class="font-medium">Expected salary</span
                            ><span
                                class="mt-1 block text-sm text-muted-foreground"
                                >Monthly income forecast. Actual cash comes only
                                from a posted receipt.</span
                            ></span
                        ></label
                    ><InputError
                        id="rule-salary-error"
                        :message="form.errors.salary"
                    />
                    <label
                        for="rule-standing"
                        class="flex cursor-pointer items-start gap-3 rounded-xl border p-4"
                        ><input
                            id="rule-standing"
                            v-model="form.standing_order"
                            type="checkbox"
                            class="mt-1 size-4 accent-teal-800"
                            :aria-invalid="!!form.errors.standing_order"
                            aria-describedby="rule-standing-error"
                        /><span
                            ><span class="font-medium"
                                >Standing order already configured
                                elsewhere</span
                            ><span
                                class="mt-1 block text-sm text-muted-foreground"
                                >Information only. This app creates no bank
                                instruction, assumed payment or default
                                reminder.</span
                            ></span
                        ></label
                    ><InputError
                        id="rule-standing-error"
                        :message="form.errors.standing_order"
                    />
                </div>
                <div
                    v-if="prior"
                    class="grid gap-6 border-t pt-6 sm:grid-cols-2"
                >
                    <div>
                        <Label for="rule-cutover"
                            >Revision cutover · Qatar</Label
                        ><Input
                            id="rule-cutover"
                            v-model="form.cutover_date"
                            type="date"
                            :min="today"
                            max="9998-12-31"
                            required
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.cutover_date"
                            aria-describedby="rule-cutover-error"
                        /><InputError
                            id="rule-cutover-error"
                            :message="form.errors.cutover_date"
                        />
                    </div>
                    <div>
                        <Label for="rule-reason">Reason for revision</Label
                        ><Input
                            id="rule-reason"
                            v-model="form.reason"
                            required
                            maxlength="180"
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.reason"
                            aria-describedby="rule-reason-error"
                        /><InputError
                            id="rule-reason-error"
                            :message="form.errors.reason"
                        />
                    </div>
                </div>
            </fieldset>
            <div class="mt-8 flex flex-wrap gap-3">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    :aria-busy="form.processing"
                    class="min-h-11"
                    >{{
                        form.processing
                            ? 'Saving…'
                            : prior
                              ? 'Save future revision'
                              : 'Save plan'
                    }}</Button
                ><Button as-child variant="outline" class="min-h-11"
                    ><Link href="/budgets">Cancel</Link></Button
                >
            </div>
        </form>
    </div>
</template>
