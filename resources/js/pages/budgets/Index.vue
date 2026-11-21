<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowUpRight,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Plus,
} from '@lucide/vue';
import { computed, watch } from 'vue';
import BudgetAmount from '@/components/BudgetAmount.vue';
import BudgetClassification from '@/components/BudgetClassification.vue';
import BudgetOccurrence from '@/components/BudgetOccurrence.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BudgetMonth } from '@/types/budget';
defineOptions({
    layout: { breadcrumbs: [{ title: 'Monthly plan', href: '/budgets' }] },
});
const props = defineProps<{ budget: BudgetMonth }>();
const monthTitle = computed(() =>
    new Intl.DateTimeFormat('en-GB', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(props.budget.month + '-01T00:00:00Z')),
);
const monthForm = useForm({ month: props.budget.month });
watch(
    () => props.budget.month,
    (value) => {
        monthForm.month = value;
    },
);
const latestCategories = computed(() =>
    props.budget.categories.filter(
        (c) => !props.budget.categories.some((n) => n.supersedes_id === c.id),
    ),
);
const latestRules = computed(() => props.budget.rules.filter((r) => r.latest));
const changeMonth = () =>
    monthForm.get('/budgets', { preserveState: true, preserveScroll: true });
</script>
<template>
    <Head title="Monthly plan" />
    <div
        class="mx-auto w-full max-w-6xl space-y-7 px-4 py-8 scheme-light sm:px-8 dark:scheme-dark"
    >
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="display-title text-4xl sm:text-5xl">Monthly plan</h1>
                <p
                    class="mt-2 max-w-xl text-sm leading-relaxed text-muted-foreground"
                >
                    Compare planned income and expenses with what you’ve
                    recorded.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    as-child
                    :variant="budget.categories.length ? 'outline' : 'default'"
                    class="min-h-11"
                >
                    <Link href="/budgets/categories/new">Add category</Link>
                </Button>
                <Button
                    v-if="budget.categories.length"
                    as-child
                    class="min-h-11"
                    ><Link href="/budgets/schedules/new"
                        ><Plus aria-hidden="true" />Add a plan</Link
                    ></Button
                >
            </div>
        </header>
        <section
            class="overflow-hidden rounded-2xl border bg-card"
            aria-labelledby="selected-month"
        >
            <div
                class="flex flex-wrap items-end justify-between gap-5 bg-sidebar px-5 py-6 text-sidebar-foreground sm:px-7"
            >
                <div>
                    <p
                        class="flex items-center gap-2 text-sm text-sidebar-primary"
                    >
                        <CalendarDays
                            aria-hidden="true"
                            class="size-4"
                        />Planning month · Qatar
                    </p>
                    <h2
                        id="selected-month"
                        class="display-title mt-2 text-4xl sm:text-5xl"
                    >
                        {{ monthTitle }}
                    </h2>
                </div>
                <nav aria-label="Month navigation" class="flex gap-2">
                    <Link
                        v-if="budget.previous_month"
                        :href="'/budgets?month=' + budget.previous_month"
                        aria-label="Previous month"
                        class="inline-flex min-h-11 items-center gap-1 rounded-lg border border-sidebar-border px-3 text-sm hover:bg-sidebar-accent"
                        ><ChevronLeft
                            class="size-4"
                            aria-hidden="true"
                        />Previous</Link
                    >
                    <Link
                        v-if="budget.next_month"
                        :href="'/budgets?month=' + budget.next_month"
                        aria-label="Next month"
                        class="inline-flex min-h-11 items-center gap-1 rounded-lg border border-sidebar-border px-3 text-sm hover:bg-sidebar-accent"
                        >Next<ChevronRight class="size-4" aria-hidden="true"
                    /></Link>
                </nav>
            </div>
            <div
                class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 sm:px-7"
            >
                <nav
                    aria-label="Review this month"
                    class="flex flex-wrap gap-x-4 text-sm font-medium text-primary"
                >
                    <a
                        href="#budget-summary"
                        class="inline-flex min-h-11 items-center hover:underline"
                        >Plan and actual</a
                    >
                    <a
                        href="#due-heading"
                        class="inline-flex min-h-11 items-center hover:underline"
                        >Due dates</a
                    >
                    <a
                        href="#activity-heading"
                        class="inline-flex min-h-11 items-center hover:underline"
                        >Posted activity</a
                    >
                </nav>
                <details class="max-w-full">
                    <summary
                        class="min-h-11 cursor-pointer content-center text-sm font-medium"
                    >
                        Jump to month
                    </summary>
                    <form
                        class="flex flex-wrap items-end gap-3 pb-2"
                        @submit.prevent="changeMonth"
                    >
                        <div>
                            <Label for="budget-month"
                                >Planning month · Asia/Qatar</Label
                            ><Input
                                id="budget-month"
                                v-model="monthForm.month"
                                type="month"
                                min="0001-01"
                                max="9998-12"
                                required
                                class="mt-2 min-h-11 w-48"
                                :aria-invalid="!!monthForm.errors.month"
                                aria-describedby="budget-month-error"
                            /><InputError
                                id="budget-month-error"
                                :message="monthForm.errors.month"
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="outline"
                            class="min-h-11"
                            :disabled="monthForm.processing"
                            >View month</Button
                        >
                    </form>
                </details>
            </div>
        </section>
        <section aria-labelledby="budget-summary" class="space-y-4">
            <h2
                id="budget-summary"
                tabindex="-1"
                class="display-title scroll-mt-4 text-3xl"
            >
                Plan and actual
            </h2>
            <p class="max-w-2xl text-sm text-muted-foreground">
                These totals include categories marked for normal budgeting.
                Each currency has its own comparison.
            </p>
            <div
                v-if="!budget.totals.length"
                class="rounded-2xl border bg-card p-6"
            >
                <p class="font-medium">No amounts to compare this month</p>
                <p class="mt-2 max-w-xl text-sm text-muted-foreground">
                    {{
                        budget.categories.length
                            ? 'Add a plan with a due date in this month to see its forecast here.'
                            : 'Add a category, then give your first plan an amount and a due date.'
                    }}
                </p>
                <Link
                    :href="
                        budget.categories.length
                            ? '/budgets/schedules/new'
                            : '/budgets/categories/new'
                    "
                    class="mt-3 inline-flex min-h-11 items-center gap-1 text-sm font-medium text-primary underline"
                    >{{
                        budget.categories.length
                            ? 'Add a plan'
                            : 'Add a category'
                    }}</Link
                >
            </div>
            <section
                v-for="total in budget.totals"
                :key="total.currency"
                class="min-w-0 rounded-2xl border bg-card p-5 sm:p-6"
                :aria-label="total.currency + ' normal budget'"
            >
                <table class="w-full table-fixed text-left text-sm">
                    <caption class="pb-3 text-left font-semibold">
                        {{
                            total.currency
                        }}
                        · normal budget
                    </caption>
                    <thead class="border-b text-xs text-muted-foreground">
                        <tr>
                            <th scope="col" class="w-1/4 py-2 font-medium">
                                Flow
                            </th>
                            <th
                                scope="col"
                                class="px-1 py-2 text-right font-medium"
                            >
                                Planned
                            </th>
                            <th scope="col" class="pl-1 text-right font-medium">
                                Posted · classified
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr>
                            <th scope="row" class="py-4 font-medium">
                                <span class="flex flex-wrap items-center gap-2"
                                    ><ArrowDownLeft
                                        class="hidden size-4 text-primary sm:block"
                                        aria-hidden="true"
                                    />Income</span
                                >
                            </th>
                            <td
                                class="px-1 text-right text-base tabular-nums sm:text-2xl"
                            >
                                <BudgetAmount
                                    :value="total.planned_income"
                                    group-thousands
                                />
                            </td>
                            <td
                                class="pl-1 text-right text-base font-semibold tabular-nums sm:text-2xl"
                            >
                                <BudgetAmount
                                    :value="total.actual_income"
                                    group-thousands
                                />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" class="py-4 font-medium">
                                <span class="flex flex-wrap items-center gap-2"
                                    ><ArrowUpRight
                                        class="hidden size-4 text-primary sm:block"
                                        aria-hidden="true"
                                    />Expenses</span
                                >
                            </th>
                            <td
                                class="px-1 text-right text-base tabular-nums sm:text-2xl"
                            >
                                <BudgetAmount
                                    :value="total.planned_expense"
                                    group-thousands
                                />
                            </td>
                            <td
                                class="pl-1 text-right text-base font-semibold tabular-nums sm:text-2xl"
                            >
                                <BudgetAmount
                                    :value="total.actual_expense"
                                    group-thousands
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div
                    v-if="
                        total.unclassified_income.minor !== '0' ||
                        total.unclassified_expense.minor !== '0'
                    "
                    class="my-3 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-secondary px-4 py-3 text-sm"
                >
                    <div>
                        <p class="font-medium">Outside the classified totals</p>
                        <p v-if="total.unclassified_income.minor !== '0'">
                            Unclassified income ·
                            <BudgetAmount
                                :value="total.unclassified_income"
                                group-thousands
                            />
                        </p>
                        <p v-if="total.unclassified_expense.minor !== '0'">
                            Unclassified expenses ·
                            <BudgetAmount
                                :value="total.unclassified_expense"
                                group-thousands
                            />
                        </p>
                    </div>
                    <a
                        href="#activity-heading"
                        class="inline-flex min-h-11 items-center font-medium text-primary underline"
                        >Review posted activity</a
                    >
                </div>
                <details class="border-t pt-2">
                    <summary
                        class="min-h-10 cursor-pointer text-sm font-medium"
                    >
                        Unclassified activity and essential run-rate
                    </summary>
                    <dl class="mt-4 grid gap-5 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Unclassified income
                            </dt>
                            <dd class="mt-2">
                                <BudgetAmount
                                    :value="total.unclassified_income"
                                />
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Unclassified expenses
                            </dt>
                            <dd class="mt-2">
                                <BudgetAmount
                                    :value="total.unclassified_expense"
                                />
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Month-end essential run-rate
                            </dt>
                            <dd class="mt-2">
                                <BudgetAmount
                                    :value="total.essential_monthly"
                                />
                            </dd>
                        </div>
                    </dl>
                    <p
                        class="mt-4 text-xs leading-relaxed text-muted-foreground"
                    >
                        Emergency-essential inclusion is independent of normal
                        budgeting. This run-rate uses recurring expense rules
                        active at month end; a rule that ended earlier is
                        excluded. It is a monthly planning input, not funded
                        reserves or coverage months.
                    </p>
                </details>
            </section>
        </section>
        <div
            class="grid items-start gap-8 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]"
        >
            <section class="min-w-0 space-y-4" aria-labelledby="due-heading">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2
                        id="due-heading"
                        tabindex="-1"
                        class="display-title scroll-mt-4 text-3xl"
                    >
                        Due dates
                    </h2>
                    <span class="text-sm text-muted-foreground"
                        >{{ budget.month }} · Qatar due dates</span
                    >
                </div>
                <div
                    v-if="!budget.occurrences.length"
                    class="rounded-2xl border bg-card p-6"
                >
                    <h3 class="font-medium">Nothing due in this month</h3>
                    <p
                        class="mt-2 text-sm leading-relaxed text-muted-foreground"
                    >
                        A term or annual plan appears only when it is due. Add a
                        plan with an explicit first date, or use the month
                        selector to review earlier unpaid items.
                    </p>
                    <Link
                        :href="
                            budget.categories.length
                                ? '/budgets/schedules/new'
                                : '/budgets/categories/new'
                        "
                        class="mt-4 inline-flex min-h-10 items-center text-sm font-medium underline"
                        >{{
                            budget.categories.length
                                ? 'Add a plan'
                                : 'Add a category'
                        }}</Link
                    >
                </div>
                <BudgetOccurrence
                    v-for="occurrence in budget.occurrences"
                    :key="occurrence.key"
                    :occurrence="occurrence"
                    :month="budget.month"
                />
            </section>
            <aside class="min-w-0 space-y-6">
                <section class="rounded-2xl border bg-secondary p-5 sm:p-6">
                    <h2 class="text-lg font-semibold">
                        Set aside in your plan
                    </h2>
                    <p
                        class="mt-2 text-sm leading-relaxed text-muted-foreground"
                    >
                        Term and annual costs have a cadence-normalized monthly
                        provision. It is separate from this month's due cash and
                        does not represent money already saved or enough funding
                        by the first bill.
                    </p>
                    <p
                        v-if="!budget.provisions.length"
                        class="mt-4 text-sm text-muted-foreground"
                    >
                        No term or annual provision is active at this month end.
                    </p>
                    <ul v-else class="mt-4 divide-y">
                        <li
                            v-for="provision in budget.provisions"
                            :key="provision.schedule_id"
                            class="min-w-0 py-4"
                        >
                            <p class="font-medium [overflow-wrap:anywhere]">
                                {{ provision.name }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Due every {{ provision.months }} months ·
                                rounded up to a minor unit
                            </p>
                            <BudgetAmount
                                class="mt-2"
                                :value="provision.amount"
                            />
                        </li>
                    </ul>
                </section>
                <section class="rounded-2xl border bg-card p-5 sm:p-6">
                    <h2 class="text-lg font-semibold">Category comparison</h2>
                    <p
                        class="mt-2 text-xs leading-relaxed text-muted-foreground"
                    >
                        All classified categories, including those outside the
                        normal budget. Plans retain their category version;
                        actuals use the latest explicit classification. Variance
                        is actual less planned.
                    </p>
                    <p
                        v-if="!budget.comparisons.length"
                        class="mt-4 text-sm text-muted-foreground"
                    >
                        Category comparisons appear with planned or explicitly
                        classified activity.
                    </p>
                    <ul class="mt-3 divide-y">
                        <li
                            v-for="comparison in budget.comparisons"
                            :key="
                                comparison.family + comparison.planned.currency
                            "
                            class="min-w-0 py-4"
                        >
                            <p class="font-medium [overflow-wrap:anywhere]">
                                {{ comparison.name }}
                                <span
                                    class="text-xs font-normal text-muted-foreground"
                                    >· {{ comparison.direction }}</span
                                >
                            </p>
                            <dl class="mt-3 space-y-2 text-sm">
                                <div
                                    class="flex flex-wrap justify-between gap-2"
                                >
                                    <dt class="text-muted-foreground">
                                        Planned
                                    </dt>
                                    <dd>
                                        <BudgetAmount
                                            :value="comparison.planned"
                                        />
                                    </dd>
                                </div>
                                <div
                                    class="flex flex-wrap justify-between gap-2"
                                >
                                    <dt class="text-muted-foreground">
                                        Actual
                                    </dt>
                                    <dd>
                                        <BudgetAmount
                                            :value="comparison.actual"
                                        />
                                    </dd>
                                </div>
                                <div
                                    class="flex flex-wrap justify-between gap-2"
                                >
                                    <dt class="text-muted-foreground">
                                        Variance
                                    </dt>
                                    <dd>
                                        <BudgetAmount
                                            :value="comparison.variance"
                                        />
                                    </dd>
                                </div>
                            </dl>
                        </li>
                    </ul>
                </section>
            </aside>
        </div>
        <section class="space-y-4" aria-labelledby="activity-heading">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2
                        id="activity-heading"
                        tabindex="-1"
                        class="display-title scroll-mt-4 text-3xl"
                    >
                        Posted activity
                    </h2>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Posted dates follow the UTC ledger month, even when a
                        receipt is matched to a different Qatar due date.
                        Transfers, openings and exchange principal stay outside
                        income and expenses. Fees appear once.
                    </p>
                </div>
                <Link
                    href="/transactions"
                    class="inline-flex min-h-10 items-center text-sm font-medium underline"
                    >View ledger history →</Link
                >
            </div>
            <p
                v-if="!budget.activity.length"
                class="rounded-2xl border bg-card p-6 text-sm text-muted-foreground"
            >
                {{
                    budget.activity_before
                        ? 'No older activity on this page. Use Latest activity to return.'
                        : 'No posted income or expense in this UTC ledger month. Expected salary and plans do not create actual activity.'
                }}
            </p>
            <ul v-else class="divide-y rounded-2xl border bg-card px-5 sm:px-6">
                <li
                    v-for="actual in budget.activity"
                    :key="actual.id + actual.value.currency"
                    class="flex min-w-0 flex-col items-start justify-between gap-4 py-5 sm:flex-row"
                >
                    <div class="w-full min-w-0 sm:w-auto sm:flex-1">
                        <Link
                            :href="'/transactions/' + actual.id"
                            class="font-medium [overflow-wrap:anywhere] underline"
                            >{{ actual.description }}</Link
                        >
                        <p class="mt-2 text-xs text-muted-foreground">
                            {{ actual.date }} UTC · {{ actual.direction
                            }}{{
                                actual.kind === 'reversal' ? ' reversal' : ''
                            }}
                        </p>
                        <p class="mt-2 text-sm [overflow-wrap:anywhere]">
                            {{
                                actual.category_name
                                    ? actual.category_name +
                                      ' · v' +
                                      actual.category_version
                                    : 'Unclassified · excluded from classified normal totals'
                            }}
                        </p>
                        <p
                            v-if="actual.category_id && !actual.normal_budget"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            Outside normal budget by selected category version
                        </p>
                    </div>
                    <div class="w-full min-w-0 space-y-3 sm:w-auto">
                        <BudgetAmount
                            class="block font-medium"
                            :value="actual.period_value ?? actual.value"
                        /><BudgetClassification
                            v-if="actual.kind !== 'reversal'"
                            :actual="actual"
                            :categories="budget.categories"
                            :month="budget.month"
                        />
                    </div>
                </li>
            </ul>
            <p class="text-xs text-muted-foreground">
                Totals include the whole month. Posted activity is shown in
                pages of 20.
            </p>
            <div class="flex flex-wrap gap-4">
                <Link
                    v-if="budget.activity_before"
                    :href="'/budgets?month=' + budget.month"
                    class="inline-flex min-h-10 items-center text-sm underline"
                    >Latest posted activity</Link
                >
                <Link
                    v-if="budget.activity_next_cursor"
                    :href="
                        '/budgets?month=' +
                        budget.month +
                        '&activity_before=' +
                        encodeURIComponent(budget.activity_next_cursor)
                    "
                    class="inline-flex min-h-10 items-center text-sm underline"
                    >Older posted activity →</Link
                >
            </div>
        </section>
        <section class="grid gap-6 lg:grid-cols-2">
            <details class="min-w-0 rounded-2xl border bg-card p-5 sm:p-6">
                <summary class="min-h-10 cursor-pointer text-lg font-semibold">
                    Categories and flags
                </summary>
                <p class="mt-3 text-sm text-muted-foreground">
                    A revision creates a retained version. Existing schedules
                    and classifications keep their selected version.
                </p>
                <ul class="mt-4 divide-y">
                    <li
                        v-for="category in latestCategories"
                        :key="category.id"
                        class="py-4"
                    >
                        <p class="font-medium [overflow-wrap:anywhere]">
                            {{ category.name }} · v{{ category.version }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ category.direction }} ·
                            {{
                                category.normal_budget
                                    ? 'normal budget'
                                    : 'outside normal budget'
                            }}
                            ·
                            {{
                                category.emergency_essential
                                    ? 'emergency essential'
                                    : 'not emergency essential'
                            }}
                        </p>
                        <Link
                            :href="
                                '/budgets/categories/' + category.id + '/revise'
                            "
                            class="mt-2 inline-flex min-h-10 items-center text-sm underline"
                            >Revise category</Link
                        >
                    </li>
                </ul>
                <Link
                    href="/budgets/categories/new"
                    class="mt-3 inline-flex min-h-10 items-center text-sm font-medium underline"
                    >Add category →</Link
                >
            </details>
            <details class="min-w-0 rounded-2xl border bg-card p-5 sm:p-6">
                <summary class="min-h-10 cursor-pointer text-lg font-semibold">
                    Recurring rules and future changes
                </summary>
                <p class="mt-3 text-sm text-muted-foreground">
                    Choose a future cutover explicitly. Posted dates and matched
                    history are retained; no due date creates an assumed
                    payment.
                </p>
                <ul class="mt-4 divide-y">
                    <li v-for="rule in latestRules" :key="rule.id" class="py-4">
                        <p class="font-medium [overflow-wrap:anywhere]">
                            {{ rule.name }} · v{{ rule.version }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{
                                rule.cadence === 'once'
                                    ? 'Once'
                                    : 'Every ' +
                                      rule.interval_months +
                                      ' month(s)'
                            }}
                            · first due {{ rule.first_due_date }} · Qatar
                        </p>
                        <p
                            v-if="rule.salary"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            Salary day {{ rule.first_due_date.slice(-2) }};
                            missing days clamp without drift.
                        </p>
                        <Link
                            :href="'/budgets/schedules/' + rule.id + '/revise'"
                            class="mt-2 inline-flex min-h-10 items-center text-sm underline"
                            >Revise future rule</Link
                        >
                    </li>
                </ul>
                <Link
                    href="/budgets/schedules/new"
                    class="mt-3 inline-flex min-h-10 items-center text-sm font-medium underline"
                    >Add a plan →</Link
                >
            </details>
        </section>
    </div>
</template>
