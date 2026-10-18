<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BudgetAmount from '@/components/BudgetAmount.vue';
import BudgetRelease from '@/components/BudgetRelease.vue';
import InputError from '@/components/InputError.vue';
import LedgerFeedback from '@/components/LedgerFeedback.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { planningRequestIdentity } from '@/composables/planningRequestIdentity';
import { useLedgerFeedback } from '@/composables/useLedgerFeedback';
import type { BudgetActual, BudgetOccurrence } from '@/types/budget';
const props = defineProps<{
    occurrence: BudgetOccurrence;
    receipts: BudgetActual[];
    month: string;
}>();
const open = ref(false);
const page = usePage();
const form = useForm(
    'budget-match:' + page.props.auth.user.id + ':' + props.occurrence.key,
    {
        idempotency_key: crypto.randomUUID(),
        schedule_id: props.occurrence.schedule_id,
        due_date: props.occurrence.due_date,
        journal_id: '',
        amount: '',
        month: props.month,
    },
);
const eligible = computed(() =>
    props.receipts.filter(
        (r) =>
            r.direction === props.occurrence.category.direction &&
            r.value.currency === props.occurrence.expected.currency &&
            (r.family === null ||
                r.family === props.occurrence.category.family) &&
            r.remaining.available &&
            r.remaining.minor !== '0' &&
            !r.remaining.minor?.startsWith('-'),
    ),
);
const chosen = computed(() =>
    eligible.value.find((r) => r.id === form.journal_id),
);
const identity = planningRequestIdentity(
    'budget-match:' + page.props.auth.user.id + ':' + props.occurrence.key,
    form.idempotency_key,
);
form.defaults('idempotency_key', identity.current);
form.idempotency_key = identity.current;
const feedback = useLedgerFeedback(
    () => form.errors,
    () => form.clearErrors(),
);
const submit = () =>
    form.post(
        '/budgets/allocations',
        feedback.options(() => {
            open.value = false;
            form.idempotency_key = identity.complete();
            form.defaults('idempotency_key', form.idempotency_key);
            form.reset('journal_id', 'amount');
        }),
    );
</script>
<template>
    <article
        class="min-w-0 rounded-2xl border bg-card p-5 shadow-sm sm:p-6"
        :aria-label="occurrence.name"
    >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 flex-1">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground"
                >
                    {{ occurrence.due_date }} · Qatar
                </p>
                <h3 class="mt-2 text-lg font-semibold [overflow-wrap:anywhere]">
                    {{ occurrence.name }}
                </h3>
                <p
                    class="mt-1 text-sm [overflow-wrap:anywhere] text-muted-foreground"
                >
                    {{ occurrence.category.name }} · v{{
                        occurrence.category.version
                    }}
                </p>
            </div>
            <span
                class="rounded-full px-3 py-1 text-xs font-medium capitalize"
                :class="
                    occurrence.status === 'matched'
                        ? 'bg-teal-50 text-teal-900 dark:bg-teal-950 dark:text-teal-100'
                        : 'bg-muted text-muted-foreground'
                "
                >{{
                    occurrence.status === 'partial'
                        ? 'Partially matched'
                        : occurrence.status
                }}</span
            >
        </div>
        <div class="mt-5 grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <p class="text-xs text-muted-foreground">
                    Expected
                    {{
                        occurrence.salary
                            ? 'salary'
                            : occurrence.category.direction
                    }}
                </p>
                <BudgetAmount
                    class="mt-1 font-medium"
                    :value="occurrence.expected"
                />
            </div>
            <div>
                <p class="text-xs text-muted-foreground">Matched actuals</p>
                <BudgetAmount class="mt-1" :value="occurrence.matched" />
            </div>
            <div>
                <p class="text-xs text-muted-foreground">Still unmatched</p>
                <BudgetAmount class="mt-1" :value="occurrence.remaining" />
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2 text-xs">
            <span class="rounded border px-2 py-1">{{
                occurrence.category.normal_budget
                    ? 'In normal budget'
                    : 'Outside normal budget'
            }}</span>
            <span class="rounded border px-2 py-1">{{
                occurrence.category.emergency_essential
                    ? 'Emergency essential'
                    : 'Not emergency essential'
            }}</span>
            <span
                v-if="occurrence.standing_order"
                class="rounded border px-2 py-1"
                >Standing order · information only</span
            >
            <span
                v-if="occurrence.overdue"
                class="rounded border border-amber-400/50 px-2 py-1 text-amber-800 dark:text-amber-200"
                >Past due · no payment assumed</span
            >
        </div>
        <p
            v-if="occurrence.needs_review"
            class="mt-4 rounded-lg border border-amber-400/40 bg-amber-50/40 p-3 text-sm dark:bg-amber-950/25"
            role="status"
        >
            A linked actual was reversed. Its match remains in history and no
            longer settles this plan. Review it, then match the replacement or
            release the old link.
        </p>
        <p
            v-if="occurrence.superseded"
            class="mt-4 text-sm text-muted-foreground"
        >
            A newer rule replaced this future forecast. Its prior matching
            history is retained and it is excluded from current planned totals.
        </p>
        <Dialog v-model:open="open">
            <DialogTrigger
                v-if="!occurrence.superseded && occurrence.status !== 'matched'"
                as-child
                ><Button variant="outline" class="mt-5 min-h-11"
                    >Match a posted actual</Button
                ></DialogTrigger
            >
            <DialogContent class="max-h-[90dvh] overflow-y-auto">
                <DialogHeader
                    ><DialogTitle>Match {{ occurrence.name }}</DialogTitle
                    ><DialogDescription
                        >Allocate part or all of an existing receipt. Multiple
                        receipts can settle one plan; a receipt can split across
                        plans. Matching creates no payment or ledger
                        entry.</DialogDescription
                    ></DialogHeader
                >
                <form class="space-y-5" @submit.prevent="submit">
                    <LedgerFeedback
                        :errors="form.errors"
                        :uncertain="feedback.uncertain.value"
                        :history="'/budgets?month=' + month"
                    />
                    <p class="text-sm">
                        Plan remaining:
                        <BudgetAmount :value="occurrence.remaining" />
                    </p>
                    <fieldset :disabled="form.processing" class="space-y-5">
                        <div>
                            <Label :for="'match-actual-' + occurrence.key"
                                >Posted actual ·
                                {{ occurrence.expected.currency }}</Label
                            >
                            <select
                                :id="'match-actual-' + occurrence.key"
                                v-model="form.journal_id"
                                required
                                class="mt-2 min-h-11 w-full min-w-0 rounded-lg border border-input bg-background px-3 text-sm"
                                :aria-invalid="!!form.errors.journal_id"
                                :aria-describedby="
                                    'match-journal-error-' + occurrence.key
                                "
                            >
                                <option value="" disabled>
                                    Choose an eligible receipt
                                </option>
                                <option
                                    v-for="receipt in eligible"
                                    :key="receipt.id + receipt.value.currency"
                                    :value="receipt.id"
                                >
                                    {{ receipt.date }} ·
                                    {{ receipt.description }} · remaining
                                    {{ receipt.remaining.amount }}
                                    {{ receipt.value.currency }}
                                </option></select
                            ><InputError
                                :id="'match-journal-error-' + occurrence.key"
                                :message="form.errors.journal_id"
                            />
                        </div>
                        <div
                            v-if="chosen"
                            class="rounded-lg bg-muted/60 p-3 text-sm [overflow-wrap:anywhere]"
                        >
                            <Link
                                :href="'/transactions/' + chosen.id"
                                class="underline"
                                >Review receipt {{ chosen.reference }}</Link
                            >
                            <p class="mt-2">
                                Actual remaining:
                                <BudgetAmount :value="chosen.remaining" />
                            </p>
                            <p>
                                Ledger date {{ chosen.date }} · UTC business
                                date
                            </p>
                        </div>
                        <div>
                            <Label :for="'match-amount-' + occurrence.key"
                                >Allocation amount ·
                                {{ occurrence.expected.currency }}</Label
                            ><Input
                                :id="'match-amount-' + occurrence.key"
                                v-model="form.amount"
                                inputmode="decimal"
                                required
                                class="mt-2 min-h-11"
                                :aria-invalid="!!form.errors.amount"
                                :aria-describedby="
                                    'match-amount-error-' + occurrence.key
                                "
                            /><InputError
                                :id="'match-amount-error-' + occurrence.key"
                                :message="form.errors.amount"
                            />
                        </div>
                    </fieldset>
                    <p
                        v-if="!eligible.length"
                        class="text-sm text-muted-foreground"
                    >
                        No eligible receipt is available.
                        <Link href="/transactions/new" class="underline"
                            >Record an actual transaction</Link
                        >, or review its classification below. Transfers and FX
                        principal cannot settle expenses.
                    </p>
                    <DialogFooter
                        ><Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            @click="open = false"
                            >Cancel</Button
                        ><Button
                            type="submit"
                            :disabled="form.processing || !eligible.length"
                            :aria-busy="form.processing"
                            >{{
                                form.processing ? 'Matching…' : 'Save match'
                            }}</Button
                        ></DialogFooter
                    >
                </form>
            </DialogContent>
        </Dialog>
        <details
            v-if="occurrence.allocations.length"
            class="mt-5 border-t pt-4"
        >
            <summary class="min-h-10 cursor-pointer text-sm font-medium">
                Matching history · {{ occurrence.allocations.length }}
            </summary>
            <ul class="mt-3 divide-y">
                <li
                    v-for="allocation in occurrence.allocations"
                    :key="allocation.id"
                    class="min-w-0 py-3 text-sm"
                >
                    <Link
                        :href="'/transactions/' + allocation.journal_id"
                        class="font-medium [overflow-wrap:anywhere] underline"
                        >{{ allocation.journal_reference }}</Link
                    >
                    <p class="mt-1">
                        <BudgetAmount :value="allocation.amount" /> ·
                        {{ allocation.journal_date }} UTC
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{
                            allocation.released
                                ? 'Released · ' + allocation.release_reason
                                : allocation.effective
                                  ? 'Effective allocation'
                                  : 'Actual reversed · no longer settles this plan'
                        }}
                    </p>
                    <BudgetRelease
                        v-if="!allocation.released"
                        :allocation="allocation"
                        :month="month"
                    />
                </li>
            </ul>
        </details>
    </article>
</template>
