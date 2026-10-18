<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch, onBeforeUnmount } from 'vue';
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
import { planningMatchDraft } from '@/composables/planningMatchDraft';
import { useLedgerFeedback } from '@/composables/useLedgerFeedback';
import { useReceiptPicker } from '@/composables/useReceiptPicker';
import type { BudgetOccurrence } from '@/types/budget';
const props = defineProps<{
    occurrence: BudgetOccurrence;
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
const picker = useReceiptPicker(
    props.occurrence.schedule_id,
    () => form.journal_id,
);
const eligible = picker.choices;
watch(open, (value) => {
    if (value) void picker.start();
    else picker.cancel();
});
onBeforeUnmount(picker.cancel);
const chosen = computed(() =>
    eligible.value.find((r) => r.id === form.journal_id),
);
const identity = planningRequestIdentity(
    'budget-match:' + page.props.auth.user.id + ':' + props.occurrence.key,
    form.idempotency_key,
);
form.defaults('idempotency_key', identity.current);
form.idempotency_key = identity.current;
const pendingMatch = planningMatchDraft(
    'budget-match:' + page.props.auth.user.id + ':' + props.occurrence.key,
);
const pendingDraft = pendingMatch.read(identity.current);
if (pendingDraft) {
    form.journal_id = pendingDraft.journal_id;
    form.amount = pendingDraft.amount;
}
const feedback = useLedgerFeedback(
    () => form.errors,
    () => form.clearErrors(),
);
const submit = () => {
    pendingMatch.save(form.idempotency_key, {
        journal_id: form.journal_id,
        amount: form.amount,
    });
    form.post(
        '/budgets/allocations',
        feedback.options(() => {
            open.value = false;
            pendingMatch.clear(form.idempotency_key);
            form.idempotency_key = identity.complete();
            form.defaults('idempotency_key', form.idempotency_key);
            form.reset('journal_id', 'amount');
        }),
    );
};
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
                v-if="
                    !occurrence.superseded &&
                    (occurrence.status !== 'matched' || form.journal_id)
                "
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
                    <p
                        v-if="
                            occurrence.status === 'matched' && form.journal_id
                        "
                        role="status"
                        class="rounded-lg border p-3 text-sm"
                    >
                        This plan is fully matched. Review its matching history,
                        or retry your pending attempt unchanged to check its
                        saved result.
                    </p>
                    <LedgerFeedback
                        :errors="form.errors"
                        :uncertain="feedback.uncertain.value"
                        :history="'/budgets?month=' + month"
                    />
                    <p class="text-sm">
                        Plan remaining:
                        <BudgetAmount :value="occurrence.remaining" />
                    </p>
                    <div class="space-y-2 rounded-lg border p-3">
                        <Label :for="'receipt-search-' + occurrence.key"
                            >Find a posted receipt</Label
                        >
                        <Input
                            :id="'receipt-search-' + occurrence.key"
                            v-model="picker.search.value"
                            maxlength="120"
                            placeholder="Description or receipt reference"
                            @keydown.enter.prevent="picker.start"
                        />
                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="picker.loading.value"
                                @click="picker.start"
                                >Search receipts</Button
                            >
                            <Button
                                v-if="picker.cursors.value.length > 1"
                                type="button"
                                variant="ghost"
                                :disabled="picker.loading.value"
                                @click="picker.newer"
                                >Newer receipts</Button
                            >
                            <Button
                                v-if="picker.next.value"
                                type="button"
                                variant="ghost"
                                :disabled="picker.loading.value"
                                @click="picker.older"
                                >Older receipts</Button
                            >
                        </div>
                        <p class="text-xs text-muted-foreground">
                            All posted dates are searchable. Each page shows up
                            to 20 eligible receipts; your selected receipt stays
                            available for retry.
                        </p>
                        <p
                            v-if="picker.loading.value"
                            role="status"
                            class="text-sm"
                        >
                            Loading posted receipts…
                        </p>
                        <p
                            v-if="picker.error.value"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ picker.error.value }}
                            <Button
                                type="button"
                                variant="ghost"
                                @click="picker.retry"
                                >Retry loading receipts</Button
                            >
                        </p>
                    </div>
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
                                :disabled="picker.loading.value"
                                :aria-busy="picker.loading.value"
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
                        v-if="
                            !picker.loading.value &&
                            !picker.error.value &&
                            !eligible.length
                        "
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
                Matching history · {{ occurrence.allocation_count }}
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
            <Link
                v-if="occurrence.history_more"
                :href="'/budgets/occurrences/' + occurrence.id"
                class="mt-3 inline-flex min-h-10 items-center text-sm underline"
                >View older matching history →</Link
            >
        </details>
    </article>
</template>
