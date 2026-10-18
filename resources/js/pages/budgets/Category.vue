<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import LedgerFeedback from '@/components/LedgerFeedback.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { planningRequestIdentity } from '@/composables/planningRequestIdentity';
import { useLedgerFeedback } from '@/composables/useLedgerFeedback';
import type { BudgetCategory } from '@/types/budget';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Monthly plan', href: '/budgets' },
            { title: 'Category', href: '#' },
        ],
    },
});
const props = defineProps<{ prior: BudgetCategory | null }>();
const page = usePage();
const form = useForm(
    'budget-category:' +
        page.props.auth.user.id +
        ':' +
        (props.prior?.id ?? 'new'),
    {
        idempotency_key: crypto.randomUUID(),
        name: props.prior?.name ?? '',
        direction: props.prior?.direction ?? 'expense',
        normal_budget: props.prior?.normal_budget ?? true,
        emergency_essential: props.prior?.emergency_essential ?? false,
        reason: '',
    },
);
const identity = planningRequestIdentity(
    'budget-category:' +
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
            normal_budget: data.normal_budget ? '1' : '0',
            emergency_essential: data.emergency_essential ? '1' : '0',
        }))
        .post(
            props.prior
                ? '/budgets/categories/' + props.prior.id + '/revise'
                : '/budgets/categories',
            feedback.options(() => {
                form.defaults('idempotency_key', identity.complete());
                form.reset();
            }),
        );
</script>
<template>
    <Head :title="prior ? 'Revise category' : 'Add category'" />
    <div
        class="mx-auto w-full max-w-3xl space-y-7 px-4 py-8 scheme-light sm:px-8 sm:py-12 dark:scheme-dark"
    >
        <Link
            href="/budgets"
            class="inline-flex min-h-10 items-center text-sm text-muted-foreground underline"
            >← Back to monthly plan</Link
        >
        <header>
            <p
                class="text-xs font-medium tracking-[0.18em] text-teal-800 uppercase dark:text-teal-200"
            >
                Choose what belongs
            </p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight">
                {{ prior ? 'Refine a category.' : 'Give it a category.' }}
            </h1>
            <p class="mt-3 leading-relaxed text-muted-foreground">
                Normal budgeting and emergency essentials answer different
                questions. Set each flag independently; family support can
                belong in your normal plan without becoming an emergency
                essential.
            </p>
        </header>
        <form
            class="rounded-2xl border bg-card p-5 shadow-sm sm:p-8"
            @submit.prevent="submit"
        >
            <LedgerFeedback
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                history="/budgets"
            />
            <p v-if="prior" class="mb-5 rounded-lg bg-muted/60 p-4 text-sm">
                Version {{ prior.version }} stays unchanged. This revision will
                be available to new rules and explicit reclassifications;
                existing plans and actuals keep their selected version.
            </p>
            <fieldset :disabled="form.processing" class="space-y-6">
                <legend class="sr-only">
                    Category details and independent flags
                </legend>
                <div>
                    <Label for="category-name">Category name</Label
                    ><Input
                        id="category-name"
                        v-model="form.name"
                        required
                        maxlength="180"
                        autocomplete="off"
                        class="mt-2 min-h-11"
                        :aria-invalid="!!form.errors.name"
                        aria-describedby="category-name-error"
                    /><InputError
                        id="category-name-error"
                        :message="form.errors.name"
                    />
                </div>
                <div>
                    <Label for="category-direction">Activity</Label
                    ><select
                        id="category-direction"
                        v-model="form.direction"
                        :disabled="!!prior"
                        required
                        class="mt-2 min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm"
                        :aria-invalid="!!form.errors.direction"
                        aria-describedby="category-direction-error"
                    >
                        <option value="expense">Expense</option>
                        <option value="income">Income</option></select
                    ><InputError
                        id="category-direction-error"
                        :message="form.errors.direction"
                    />
                </div>
                <div class="space-y-4">
                    <label
                        class="flex cursor-pointer items-start gap-3 rounded-xl border p-4"
                        for="normal-budget"
                        ><input
                            id="normal-budget"
                            v-model="form.normal_budget"
                            type="checkbox"
                            class="mt-1 size-4 accent-teal-800"
                            :aria-invalid="!!form.errors.normal_budget"
                            aria-describedby="normal-help normal-error"
                        /><span
                            ><span class="font-medium"
                                >Include in normal budget</span
                            ><span
                                id="normal-help"
                                class="mt-1 block text-sm leading-relaxed text-muted-foreground"
                                >Include this category's selected plans and
                                classified actuals in normal monthly
                                totals.</span
                            ></span
                        ></label
                    ><InputError
                        id="normal-error"
                        :message="form.errors.normal_budget"
                    />
                    <label
                        class="flex cursor-pointer items-start gap-3 rounded-xl border p-4"
                        for="emergency-essential"
                        ><input
                            id="emergency-essential"
                            v-model="form.emergency_essential"
                            type="checkbox"
                            class="mt-1 size-4 accent-teal-800"
                            :aria-invalid="!!form.errors.emergency_essential"
                            aria-describedby="essential-help essential-error"
                        /><span
                            ><span class="font-medium">Emergency essential</span
                            ><span
                                id="essential-help"
                                class="mt-1 block text-sm leading-relaxed text-muted-foreground"
                                >For expense categories, include recurring costs
                                in the separate month-end essential run-rate.
                                This does not create or fund a reserve.</span
                            ></span
                        ></label
                    ><InputError
                        id="essential-error"
                        :message="form.errors.emergency_essential"
                    />
                </div>
                <div v-if="prior">
                    <Label for="category-reason">Reason for revision</Label
                    ><Input
                        id="category-reason"
                        v-model="form.reason"
                        required
                        maxlength="180"
                        class="mt-2 min-h-11"
                        :aria-invalid="!!form.errors.reason"
                        aria-describedby="category-reason-error"
                    /><InputError
                        id="category-reason-error"
                        :message="form.errors.reason"
                    />
                </div>
            </fieldset>
            <div class="mt-7 flex flex-wrap gap-3">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    :aria-busy="form.processing"
                    class="min-h-11"
                    >{{
                        form.processing
                            ? 'Saving…'
                            : prior
                              ? 'Save category revision'
                              : 'Save category'
                    }}</Button
                ><Button as-child variant="outline" class="min-h-11"
                    ><Link href="/budgets">Cancel</Link></Button
                >
            </div>
        </form>
    </div>
</template>
