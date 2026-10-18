<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BudgetAmount from '@/components/BudgetAmount.vue';
import InputError from '@/components/InputError.vue';
import LedgerFeedback from '@/components/LedgerFeedback.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useGoalForm } from '@/composables/useGoalForm';
import type { Goal, GoalAccount } from '@/types/goal';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Goals & protection', href: '/goals' },
            { title: 'Goal plan', href: '#' },
        ],
    },
});
const props = defineProps<{
    prior: Goal | null;
    operation_goal: string | null;
    accounts: { rows: GoalAccount[]; next: string | null };
    q: string;
}>();
const { form, feedback, submit, recovered } = useGoalForm(
    String(usePage().props.auth.user.id) +
        ':plan:' +
        (props.operation_goal ?? 'new'),
    {
        name: props.prior?.name ?? '',
        account_id: props.prior?.account_id ?? '',
        currency: props.prior?.currency ?? '',
        mode: props.prior?.mode ?? 'reserved',
        target: props.prior?.target.amount ?? '',
        opening: props.prior?.opening.amount ?? '0',
        policy: props.prior?.policy ?? 'fixed_monthly',
        contribution: props.prior?.contribution.amount ?? '0',
        first_deposit: props.prior?.first_deposit ?? '',
        target_date: props.prior?.target_date ?? '',
        standing_order: props.prior?.standing_order ? '1' : '0',
        closed: props.prior?.closed ? '1' : '0',
        reason: '',
    },
);
const selected = computed(() =>
    props.accounts.rows.find((a) => a.id === form.account_id),
);
const choose = (a: GoalAccount) => {
    form.account_id = a.id;
    form.currency = a.currency;
    if (form.mode === 'dedicated') form.opening = a.cash?.amount ?? '';
};
</script>
<template>
    <Head :title="prior ? 'Revise goal' : 'Create a goal'" />
    <div
        class="mx-auto w-full max-w-3xl space-y-7 px-4 py-8 scheme-light sm:px-8 sm:py-12 dark:scheme-dark"
    >
        <Link
            :href="prior ? '/goals/' + prior.id : '/goals'"
            class="inline-flex min-h-10 items-center text-sm text-muted-foreground underline"
            >← Back to {{ prior ? 'goal' : 'goals' }}</Link
        >
        <header>
            <p
                class="text-xs font-semibold tracking-[0.18em] text-teal-800 uppercase dark:text-teal-200"
            >
                A purpose for existing cash
            </p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight">
                {{ prior ? 'Refine your plan.' : 'Build your next goal.' }}
            </h1>
            <p class="mt-3 leading-relaxed text-muted-foreground">
                A target is your intention. Backing comes from an existing bank
                or cash account; saving a plan never moves money.
            </p>
        </header>
        <section v-if="!prior" class="rounded-2xl border bg-card p-5 sm:p-7">
            <h2 class="text-lg font-semibold">1. Choose the source account</h2>
            <form action="/goals/new" class="mt-4 flex flex-wrap gap-2">
                <Label for="q" class="sr-only">Search accounts</Label
                ><Input
                    id="q"
                    name="q"
                    :default-value="q"
                    placeholder="Search account name"
                    class="min-w-0 flex-1"
                /><Button variant="outline" type="submit">Search</Button>
            </form>
            <p
                v-if="!accounts.rows.length"
                class="mt-4 text-sm text-muted-foreground"
            >
                No eligible active cash or bank accounts.
                <Link href="/accounts" class="underline"
                    >Create an account</Link
                >
                and post any opening balance first.
            </p>
            <div class="mt-4 space-y-2">
                <button
                    v-for="a in accounts.rows"
                    :key="a.id"
                    type="button"
                    class="flex min-h-16 w-full flex-wrap items-center justify-between gap-2 rounded-xl border p-3 text-left hover:bg-muted focus-visible:outline-2"
                    :class="
                        form.account_id === a.id
                            ? 'border-teal-700 bg-teal-50 dark:bg-teal-950/40'
                            : ''
                    "
                    :aria-pressed="form.account_id === a.id"
                    @click="choose(a)"
                >
                    <span class="min-w-0 font-medium break-words"
                        >{{ a.name
                        }}<span
                            class="mt-1 block text-xs font-normal text-muted-foreground"
                            >{{ a.currency }} · already reserved
                            <BudgetAmount :value="a.reserved" /></span></span
                    ><span class="text-sm"
                        ><BudgetAmount v-if="a.cash" :value="a.cash" /><span
                            v-else
                            >Cash unavailable</span
                        ></span
                    >
                </button>
            </div>
            <Link
                v-if="accounts.next"
                :href="
                    '/goals/new?q=' +
                    encodeURIComponent(q) +
                    '&before=' +
                    accounts.next
                "
                class="mt-4 inline-flex min-h-10 items-center underline"
                >More accounts →</Link
            >
        </section>
        <form
            class="rounded-2xl border bg-card p-5 shadow-sm sm:p-7"
            @submit.prevent="
                submit(
                    prior ? '/goals/' + operation_goal + '/revise' : '/goals',
                )
            "
        >
            <LedgerFeedback
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                history="/goals"
            />
            <p
                v-if="recovered"
                class="mb-5 rounded-lg bg-teal-50 p-4 text-sm text-teal-950 dark:bg-teal-950 dark:text-teal-100"
            >
                Your interrupted attempt is restored. Retry confirms the same
                request; check goal history before starting another.
            </p>
            <p
                v-if="prior && operation_goal !== prior.id && !recovered"
                class="mb-5 rounded-lg bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100"
            >
                This plan has a newer version.
                <Link :href="'/goals/' + prior.id + '/revise'" class="underline"
                    >Open the latest plan</Link
                >
                before saving another change.
            </p>
            <h2 class="text-lg font-semibold">
                {{ prior ? 'Goal details' : '2. Set your goal' }}
            </h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Source:
                {{
                    prior?.account_name ??
                    selected?.name ??
                    (form.account_id
                        ? 'Saved account selection'
                        : 'Choose an account above')
                }}
                {{ form.currency }}
            </p>
            <div class="mt-6 space-y-5">
                <div>
                    <Label for="name">Goal name</Label
                    ><Input
                        id="name"
                        v-model="form.name"
                        required
                        maxlength="180"
                        :aria-invalid="!!form.errors.name"
                    /><InputError :message="form.errors.name" />
                </div>
                <div>
                    <Label for="mode">Backing mode</Label
                    ><select
                        id="mode"
                        v-model="form.mode"
                        :disabled="!!prior"
                        class="mt-2 min-h-11 w-full rounded-lg border bg-background px-3"
                        @change="
                            form.opening =
                                form.mode === 'dedicated'
                                    ? (selected?.cash?.amount ?? '')
                                    : '0'
                        "
                    >
                        <option value="reserved">
                            Explicit reservations — earmark part of this account
                        </option>
                        <option value="dedicated">
                            Dedicated account — exclusively back this goal
                        </option>
                    </select>
                    <p
                        class="mt-2 text-xs leading-relaxed text-muted-foreground"
                    >
                        A dedicated account cannot back any other open goal. To
                        change source or mode, release reservations and close
                        this goal first.
                    </p>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <Label for="target"
                            >Target amount ·
                            {{ form.currency || 'account currency' }}</Label
                        ><Input
                            id="target"
                            v-model="form.target"
                            inputmode="decimal"
                            required
                            :aria-invalid="!!form.errors.target"
                        /><InputError :message="form.errors.target" />
                    </div>
                    <div>
                        <Label for="opening">{{
                            prior
                                ? 'Original backing snapshot'
                                : 'Opening backing amount'
                        }}</Label
                        ><Input
                            id="opening"
                            v-model="form.opening"
                            inputmode="decimal"
                            required
                            :readonly="!!prior"
                            :aria-invalid="!!form.errors.opening"
                        /><InputError :message="form.errors.opening" />
                        <p class="mt-2 text-xs text-muted-foreground">
                            {{
                                form.mode === 'dedicated'
                                    ? 'Must equal the current account balance when created.'
                                    : 'Reserves existing available cash now; zero is allowed.'
                            }}
                        </p>
                    </div>
                </div>
                <div>
                    <Label for="policy">Contribution policy</Label
                    ><select
                        id="policy"
                        v-model="form.policy"
                        class="mt-2 min-h-11 w-full rounded-lg border bg-background px-3"
                    >
                        <option value="fixed_monthly">
                            Fixed monthly plan
                        </option>
                        <option value="variable">
                            Variable deposits — no fixed completion projection
                        </option>
                    </select>
                </div>
                <div>
                    <Label for="contribution"
                        >Planned contribution · {{ form.currency }}</Label
                    ><Input
                        id="contribution"
                        v-model="form.contribution"
                        inputmode="decimal"
                        required
                        :aria-invalid="!!form.errors.contribution"
                    /><InputError :message="form.errors.contribution" />
                    <p class="mt-2 text-xs text-muted-foreground">
                        Zero is valid. A positive gap with zero fixed
                        contributions is unreachable until the plan changes.
                        Planned deposits are never cash.
                    </p>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <Label for="first"
                            >Next / first planned deposit · optional</Label
                        ><Input
                            id="first"
                            v-model="form.first_deposit"
                            type="date"
                            min="0001-01-01"
                            max="9998-12-31"
                            :aria-invalid="!!form.errors.first_deposit"
                        /><InputError :message="form.errors.first_deposit" />
                    </div>
                    <div>
                        <Label for="target-date">Target date · optional</Label
                        ><Input
                            id="target-date"
                            v-model="form.target_date"
                            type="date"
                            min="0001-01-01"
                            max="9998-12-31"
                        />
                    </div>
                </div>
                <p class="text-xs leading-relaxed text-muted-foreground">
                    Dates use Qatar time. Missed dates need a plan update; the
                    app never assumes a deposit happened. Month-end plans keep
                    their original day anchor.
                </p>
                <label class="flex min-h-11 items-center gap-3 text-sm"
                    ><input
                        v-model="form.standing_order"
                        type="checkbox"
                        true-value="1"
                        false-value="0"
                        class="size-4"
                    />I already have a standing order (information only)</label
                >
                <p
                    class="rounded-lg bg-muted/60 p-4 text-sm text-muted-foreground"
                >
                    Reminders are off. This plan creates no bank instruction and
                    sends no notifications.
                </p>
                <template v-if="prior"
                    ><label class="flex min-h-11 items-center gap-3 text-sm"
                        ><input
                            v-model="form.closed"
                            type="checkbox"
                            true-value="1"
                            false-value="0"
                            class="size-4"
                        />Close this goal after releasing all
                        reservations</label
                    >
                    <div>
                        <Label for="reason">Reason for this plan change</Label
                        ><Input
                            id="reason"
                            v-model="form.reason"
                            required
                            maxlength="180"
                        /></div
                ></template>
                <div class="flex flex-wrap items-center gap-3 border-t pt-5">
                    <Button
                        type="submit"
                        :disabled="
                            form.processing ||
                            !form.account_id ||
                            (!!prior &&
                                operation_goal !== prior.id &&
                                !recovered)
                        "
                        >{{
                            form.processing
                                ? 'Saving…'
                                : prior
                                  ? 'Save revised plan'
                                  : 'Create goal'
                        }}</Button
                    ><Button as-child variant="outline"
                        ><Link :href="prior ? '/goals/' + prior.id : '/goals'"
                            >Cancel</Link
                        ></Button
                    >
                </div>
            </div>
        </form>
    </div>
</template>
