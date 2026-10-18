<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import LedgerFeedback from '@/components/LedgerFeedback.vue';
import { reconcileCashRequirements } from '@/composables/cashSettingsDraft';
import { useCashSettingsForm } from '@/composables/useCashSettingsForm';
import type { CashOverlap, CashSnapshot } from '@/types/cash';
const props = defineProps<{
    cash: CashSnapshot;
    currencies: string[];
    goals: { id: string; name: string; currency: string; closed: boolean }[];
    goal_next_cursor: string | null;
    reservation_next_cursor: string | null;
    settings_history: {
        id: number;
        version: number;
        currency: string;
        reason: string;
        created_at: string;
    }[];
    settings_history_next: string | null;
    reservations: {
        id: string;
        goal_id: string;
        name: string;
        currency: string;
        reference: string;
    }[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Cash clarity', href: '/dashboard' },
            { title: 'Planning settings', href: '/cash/settings' },
        ],
    },
});
const page = usePage();
const owner = String((page.props.auth as { user: { id: number } }).user.id);
const initial = props.cash.settings;
const requirementFamilies = new Map(
    (initial?.requirements ?? []).map((row) => [row.schedule_id, row.family]),
);
const initialRequirements = reconcileCashRequirements(
    (initial?.requirements ?? []).map((row) => ({
        schedule_id: row.schedule_id,
        required: row.required ? '1' : '0',
    })),
    props.cash.required_choices,
    requirementFamilies,
);
const { form, feedback, recovered, submit } = useCashSettingsForm(owner, {
    prior_id: initial?.id ?? '',
    currency: initial?.currency ?? 'QAR',
    buffer: initial?.buffer ?? '',
    next_income_date: initial?.next_income_date ?? '',
    investment_target: initial?.investment_target ?? '',
    requirements: JSON.stringify(initialRequirements),
    investment_goals: JSON.stringify(initial?.investment_goals ?? []),
    overlaps: JSON.stringify(
        initial?.overlaps.map((r) => ({
            reservation_id: r.reservation_id,
            schedule_id: r.schedule_id,
            due_date: r.due_date,
            amount: r.amount,
        })) ?? [],
    ),
    reason: '',
});
const locked = computed(
    () => form.processing || recovered.value || feedback.uncertain.value,
);
type Requirement = { schedule_id: string; required: string };
const requirements = computed<Requirement[]>(() =>
    JSON.parse(form.requirements),
);
const overlaps = computed<CashOverlap[]>(() => JSON.parse(form.overlaps));
const investment = computed<string[]>(() => JSON.parse(form.investment_goals));
const overlapPage = ref(0);
const visibleOverlaps = computed(() =>
    overlaps.value.slice(overlapPage.value * 20, (overlapPage.value + 1) * 20),
);
watch(
    () => props.cash.required_choices,
    (choices) => {
        if (locked.value) return;
        form.requirements = JSON.stringify(
            reconcileCashRequirements(
                requirements.value,
                choices,
                requirementFamilies,
            ),
        );
    },
);
function nextSettingsPage(key: string, cursor: string) {
    const params = new URLSearchParams(window.location.search);
    params.set(key, cursor);
    return '/cash/settings?' + params.toString();
}
function setRequired(id: string, value: string) {
    const rows = [...requirements.value];
    const index = rows.findIndex((r) => r.schedule_id === id);
    if (index >= 0) rows[index] = { ...rows[index], required: value };
    else rows.push({ schedule_id: id, required: value });
    form.requirements = JSON.stringify(rows);
}
function setInvestment(id: string, checked: boolean) {
    form.investment_goals = JSON.stringify(
        checked
            ? [...investment.value, id]
            : investment.value.filter((item) => item !== id),
    );
}
function updateOverlap(index: number, field: keyof CashOverlap, value: string) {
    const rows = [...overlaps.value];
    rows[index] = { ...rows[index], [field]: value };
    form.overlaps = JSON.stringify(rows);
}
function removeOverlap(index: number) {
    form.overlaps = JSON.stringify(
        overlaps.value.filter((_, i) => i !== index),
    );
}
const inputClass =
    'mt-2 min-h-11 w-full min-w-0 rounded-xl border bg-background px-3 py-2 text-sm focus-visible:outline-2';
type PlanChoice = { schedule_id: string; name: string; currency: string };
const rememberedPlans = reactive(
    new Map<string, PlanChoice>(
        props.cash.required_choices.map((row) => [row.schedule_id, row]),
    ),
);
const rememberedReservations = reactive(
    new Map(props.reservations.map((row) => [row.id, row])),
);
watch(
    () => props.cash.required_choices,
    (rows) => {
        for (const row of rows) rememberedPlans.set(row.schedule_id, row);
    },
);
watch(
    () => props.reservations,
    (rows) => {
        for (const row of rows) rememberedReservations.set(row.id, row);
    },
);
const linkChoices = computed(() => {
    const rows: PlanChoice[] = [...props.cash.required_choices];
    for (const bill of props.cash.occurrences)
        if (!rows.some((r) => r.schedule_id === bill.schedule_id))
            rows.push({
                schedule_id: bill.schedule_id,
                name: bill.name + ' · due ' + bill.due,
                currency: bill.currency,
            });
    for (const row of visibleOverlaps.value)
        if (
            !rows.some((s) => s.schedule_id === row.schedule_id) &&
            row.schedule_id
        )
            rows.push(
                rememberedPlans.get(row.schedule_id) ?? {
                    schedule_id: row.schedule_id,
                    name: 'Retained plan version #' + row.schedule_id,
                    currency: '',
                },
            );
    return rows;
});
const reservationChoices = computed(() => {
    const rows = [...props.reservations];
    for (const row of visibleOverlaps.value)
        if (
            row.reservation_id &&
            !rows.some((r) => r.id === row.reservation_id)
        )
            rows.push(
                rememberedReservations.get(row.reservation_id) ?? {
                    id: row.reservation_id,
                    goal_id: '',
                    name: 'Retained selection; verify activity',
                    currency: '',
                    reference: '',
                },
            );
    return rows;
});
</script>

<template>
    <Head title="Cash planning settings" />
    <section class="mx-auto w-full max-w-4xl space-y-7 p-4 sm:p-8">
        <header>
            <p
                class="text-xs font-semibold tracking-[0.18em] text-teal-700 uppercase dark:text-teal-300"
            >
                Your assumptions
            </p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight">
                Cash planning settings
            </h1>
            <p
                class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground"
            >
                Confirm what needs protecting before the next income date. These
                settings explain availability; they do not move money or change
                your goal reservations.
            </p>
        </header>
        <LedgerFeedback
            :errors="form.errors"
            :uncertain="feedback.uncertain.value"
            history="/cash/settings"
        />
        <p
            v-if="recovered"
            role="status"
            class="rounded-xl border border-teal-700/30 bg-teal-50 p-4 text-sm leading-relaxed dark:bg-teal-950/30"
        >
            Your previous attempted settings were restored. Retry the same
            details to confirm their saved result. An interrupted request may
            already have completed.
        </p>
        <p v-if="initial" class="text-xs text-muted-foreground">
            Current saved version {{ initial.version }}. Saved reason:
            {{ initial.reason }}. A new save retains the previous version.
        </p>
        <details v-if="settings_history.length" class="rounded-2xl border p-5">
            <summary class="min-h-11 cursor-pointer text-sm font-semibold">
                Saved settings history
            </summary>
            <ol class="divide-y">
                <li
                    v-for="entry in settings_history"
                    :key="entry.id"
                    class="space-y-1 py-3 text-sm"
                >
                    <p class="font-medium">
                        Version {{ entry.version }} · {{ entry.currency }}
                    </p>
                    <p class="break-words">{{ entry.reason }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ entry.created_at }}
                    </p>
                </li>
            </ol>
            <Link
                v-if="settings_history_next && !locked"
                :href="
                    nextSettingsPage('settings_before', settings_history_next)
                "
                preserve-state
                preserve-scroll
                class="inline-flex min-h-11 items-center text-sm underline"
                >Older saved versions</Link
            >
        </details>
        <p
            v-if="
                (initial?.requirements.length ?? 0) + cash.unclassified_count >
                1000
            "
            role="status"
            class="rounded-xl border p-4 text-sm leading-relaxed"
        >
            This phase supports up to 1,000 classified expense families per cash
            plan. Availability stays incomplete above that limit; no earlier
            plan is silently discarded.
        </p>
        <form class="space-y-6" @submit.prevent="submit">
            <fieldset
                :disabled="locked"
                class="grid min-w-0 gap-5 rounded-2xl border p-5 sm:grid-cols-2 sm:p-7"
            >
                <legend class="px-2 text-lg font-semibold">
                    Horizon and minimum buffer
                </legend>
                <label class="min-w-0 text-sm font-medium"
                    >Calculation currency<select
                        v-model="form.currency"
                        name="currency"
                        :class="inputClass"
                        :aria-invalid="!!form.errors.currency"
                    >
                        <option
                            v-for="code in currencies"
                            :key="code"
                            :value="code"
                        >
                            {{ code }}
                        </option>
                    </select></label
                >
                <label class="min-w-0 text-sm font-medium"
                    >Minimum buffer · {{ form.currency
                    }}<input
                        v-model="form.buffer"
                        name="buffer"
                        inputmode="decimal"
                        autocomplete="off"
                        required
                        :class="inputClass"
                        :aria-invalid="!!form.errors.buffer"
                    /><span
                        class="mt-2 block text-xs leading-relaxed font-normal text-muted-foreground"
                        >Use an explicit amount, including zero. This is
                        separate from actual goal protection.</span
                    ></label
                >
                <label class="min-w-0 text-sm font-medium sm:col-span-2"
                    >Next expected income date · Qatar<input
                        v-model="form.next_income_date"
                        name="next_income_date"
                        type="date"
                        required
                        :min="cash.today"
                        max="9998-12-31"
                        :class="inputClass"
                        :aria-invalid="!!form.errors.next_income_date"
                    /><span
                        class="mt-2 block text-xs leading-relaxed font-normal text-muted-foreground"
                        >An owner-confirmed planning date. All overdue unpaid
                        required bills and same-day bills are included. Salary
                        is never added to actual-now cash.</span
                    ></label
                >
            </fieldset>

            <fieldset
                :disabled="locked"
                class="min-w-0 space-y-4 rounded-2xl border p-5 sm:p-7"
            >
                <legend class="px-2 text-lg font-semibold">
                    Required expense plans
                </legend>
                <p class="text-sm leading-relaxed text-muted-foreground">
                    Choose explicitly for every expense family. Plan revisions
                    keep this designation; new families need confirmation.
                    Unanswered plans stay unknown, not automatically optional.
                    Save confirmed inputs and continue through every page.
                </p>
                <p v-if="!cash.required_choices.length" class="text-sm">
                    There are no expense plans on this page. Any unclassified
                    plans keep availability incomplete.
                </p>
                <div
                    v-for="(choice, index) in cash.required_choices"
                    :key="choice.family"
                    class="grid min-w-0 gap-2 border-t pt-4 sm:grid-cols-[1fr_14rem] sm:items-center"
                >
                    <label
                        :for="'required-' + index"
                        class="text-sm font-medium break-words"
                        >{{ choice.name
                        }}<span
                            class="mt-1 block text-xs font-normal text-muted-foreground"
                            >{{ choice.currency }} · version #{{
                                choice.schedule_id
                            }}</span
                        ></label
                    >
                    <select
                        :id="'required-' + index"
                        :value="
                            requirements.find(
                                (r) => r.schedule_id === choice.schedule_id,
                            )?.required ?? ''
                        "
                        name="requirements"
                        :class="inputClass"
                        :aria-invalid="!!form.errors.requirements"
                        @change="
                            setRequired(
                                choice.schedule_id,
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="" disabled>Choose explicitly</option>
                        <option value="1">Required before income</option>
                        <option value="0">Not required</option>
                    </select>
                </div>
                <p
                    v-if="cash.unclassified_count"
                    class="text-xs text-amber-800 dark:text-amber-200"
                >
                    {{ cash.unclassified_count }} expense families still need
                    confirmation in the saved plan.
                </p>
                <Link
                    v-if="cash.required_next_cursor && !locked"
                    :href="
                        nextSettingsPage(
                            'plan_after',
                            cash.required_next_cursor,
                        )
                    "
                    preserve-state
                    preserve-scroll
                    class="inline-flex min-h-11 items-center text-sm font-medium underline"
                    >Next expense plans</Link
                >
                <Link
                    href="/budgets"
                    class="inline-flex min-h-11 items-center text-sm underline"
                    >Manage expense plans and paid matches</Link
                >
            </fieldset>

            <fieldset
                :disabled="locked"
                class="min-w-0 space-y-4 rounded-2xl border p-5 sm:p-7"
            >
                <legend class="px-2 text-lg font-semibold">
                    Investment target membership
                </legend>
                <label class="block text-sm font-medium"
                    >Desired total protection · {{ form.currency
                    }}<input
                        v-model="form.investment_target"
                        name="investment_target"
                        inputmode="decimal"
                        required
                        :class="inputClass"
                        :aria-invalid="!!form.errors.investment_target"
                /></label>
                <p class="text-sm leading-relaxed text-muted-foreground">
                    For the forecast only. Select reservations already inside
                    this target; only its unbacked remainder becomes an
                    additional forecast earmark. Contributions for these
                    selected goals are excluded from the separate contribution
                    assumption.
                </p>
                <label
                    v-for="goal in goals"
                    :key="goal.id"
                    class="flex min-h-11 items-center gap-3 text-sm"
                    ><input
                        type="checkbox"
                        name="investment_goals"
                        :checked="investment.includes(goal.id)"
                        class="size-4 shrink-0 accent-teal-700"
                        @change="
                            setInvestment(
                                goal.id,
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    /><span class="break-words"
                        >{{ goal.name }} · {{ goal.currency
                        }}{{ goal.closed ? ' · closed, no backing' : '' }}</span
                    ></label
                >
                <p class="text-xs text-muted-foreground">
                    {{ investment.length }} selected identities are retained
                    across pages.
                </p>
                <Link
                    v-if="goal_next_cursor && !locked"
                    :href="nextSettingsPage('goal_after', goal_next_cursor)"
                    preserve-state
                    preserve-scroll
                    class="inline-flex min-h-11 items-center text-sm underline"
                    >Next investment goal choices</Link
                >
                <p v-if="!goals.length" class="text-sm text-muted-foreground">
                    No reserved goals yet. An explicit zero target is valid.
                </p>
            </fieldset>

            <fieldset
                :disabled="locked"
                class="min-w-0 space-y-4 rounded-2xl border p-5 sm:p-7"
            >
                <legend class="px-2 text-lg font-semibold">
                    Bills already covered by reservations
                </legend>
                <p class="text-sm leading-relaxed text-muted-foreground">
                    Link exact identities to avoid subtracting the same cash
                    twice. Only still-backed capacity counts. Paid, released or
                    superseded links are recalculated. Investment-target
                    reservations cannot also cover these bills.
                </p>
                <p v-if="!overlaps.length" class="text-sm">
                    No overlap links. Required bills will be treated as
                    unfunded.
                </p>
                <div
                    v-for="(link, localIndex) in visibleOverlaps"
                    :key="overlapPage * 20 + localIndex"
                    class="grid min-w-0 gap-4 rounded-xl border bg-muted/20 p-4 sm:grid-cols-2"
                >
                    <label class="min-w-0 text-sm font-medium"
                        >Reservation<select
                            :value="link.reservation_id"
                            name="overlaps"
                            required
                            :class="inputClass"
                            :aria-invalid="!!form.errors.overlaps"
                            @change="
                                updateOverlap(
                                    overlapPage * 20 + localIndex,
                                    'reservation_id',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="" disabled>
                                Select reservation
                            </option>
                            <option
                                v-for="r in reservationChoices"
                                :key="r.id"
                                :value="r.id"
                            >
                                #{{ r.id }} · {{ r.name }} · {{ r.currency }}
                            </option>
                        </select></label
                    >
                    <label class="min-w-0 text-sm font-medium"
                        >Required expense plan<select
                            :value="link.schedule_id"
                            required
                            :class="inputClass"
                            @change="
                                updateOverlap(
                                    overlapPage * 20 + localIndex,
                                    'schedule_id',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="" disabled>
                                Select exact version
                            </option>
                            <option
                                v-for="choice in linkChoices"
                                :key="choice.schedule_id"
                                :value="choice.schedule_id"
                            >
                                {{ choice.name }} · #{{ choice.schedule_id }}
                            </option>
                        </select></label
                    >
                    <label class="min-w-0 text-sm font-medium"
                        >Bill due date · Qatar<input
                            :value="link.due_date"
                            type="date"
                            required
                            max="9998-12-31"
                            :class="inputClass"
                            @input="
                                updateOverlap(
                                    overlapPage * 20 + localIndex,
                                    'due_date',
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                    /></label>
                    <label class="min-w-0 text-sm font-medium"
                        >Linked amount · bill currency<input
                            :value="link.amount"
                            inputmode="decimal"
                            required
                            :class="inputClass"
                            @input="
                                updateOverlap(
                                    overlapPage * 20 + localIndex,
                                    'amount',
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                    /></label>
                    <button
                        type="button"
                        class="min-h-11 justify-self-start text-sm font-medium underline"
                        @click="removeOverlap(overlapPage * 20 + localIndex)"
                    >
                        Remove this link
                    </button>
                </div>
                <div class="flex flex-wrap gap-4">
                    <button
                        v-if="overlapPage > 0"
                        type="button"
                        class="min-h-11 text-sm underline"
                        @click="overlapPage--"
                    >
                        Previous links</button
                    ><button
                        v-if="(overlapPage + 1) * 20 < overlaps.length"
                        type="button"
                        class="min-h-11 text-sm underline"
                        @click="overlapPage++"
                    >
                        Next links</button
                    ><Link
                        v-if="reservation_next_cursor && !locked"
                        :href="
                            nextSettingsPage(
                                'reservation_after',
                                reservation_next_cursor,
                            )
                        "
                        preserve-state
                        preserve-scroll
                        class="inline-flex min-h-11 items-center text-sm underline"
                        >Next reservation choices</Link
                    >
                </div>
                <button
                    type="button"
                    class="min-h-11 rounded-full border px-5 text-sm font-medium hover:bg-muted"
                    @click="
                        form.overlaps = JSON.stringify([
                            ...overlaps,
                            {
                                reservation_id: '',
                                schedule_id: '',
                                due_date: '',
                                amount: '',
                            },
                        ])
                    "
                >
                    Add an explicit link
                </button>
            </fieldset>
            <fieldset :disabled="locked">
                <label class="block text-sm font-medium"
                    >Reason for this settings version<input
                        v-model="form.reason"
                        name="reason"
                        required
                        maxlength="180"
                        :class="inputClass"
                        :aria-invalid="!!form.errors.reason"
                /></label>
            </fieldset>
            <div class="flex flex-wrap items-center gap-4">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="min-h-11 rounded-full bg-teal-800 px-6 py-3 text-sm font-semibold text-white hover:bg-teal-900 focus-visible:outline-2 disabled:opacity-50"
                >
                    {{
                        form.processing
                            ? 'Saving…'
                            : recovered || feedback.uncertain.value
                              ? 'Retry original settings'
                              : 'Save planning settings'
                    }}</button
                ><Link
                    href="/dashboard"
                    class="inline-flex min-h-11 items-center text-sm underline"
                    >Back to actual now</Link
                >
            </div>
            <p class="text-xs leading-relaxed text-muted-foreground">
                Attempt recovery uses this tab’s browser storage, with a
                current-session fallback when storage is blocked. Nothing here
                creates a transfer, bank instruction or reminder.
            </p>
        </form>
    </section>
</template>
