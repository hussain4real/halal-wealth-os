<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import FinancialEvidenceView from '@/components/FinancialEvidence.vue';
import InputError from '@/components/InputError.vue';
import LedgerFeedback from '@/components/LedgerFeedback.vue';
import LedgerNav from '@/components/LedgerNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLedgerFeedback } from '@/composables/useLedgerFeedback';
import type { FinancialEvidence, Reconciliation } from '@/types/ledger';
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Reconciliation', href: '/reconciliations' }],
    },
});
const props = defineProps<{
    session: Reconciliation;
    evidence: FinancialEvidence;
    adjustment: {
        original_id: string;
        result_id: string;
        journal_id: string;
        current_status: string;
        reason: string;
    } | null;
}>();
const page = usePage();
const form = useForm(
    `adjustment:${page.props.auth.user.id}:${props.session.id}`,
    { idempotency_key: crypto.randomUUID(), offset: '', reason: '' },
);
const feedback = useLedgerFeedback(
    () => form.errors,
    () => form.clearErrors(),
);
const submit = () =>
    form.post(
        `/reconciliations/${props.session.id}/adjust`,
        feedback.options(() => {
            form.idempotency_key = crypto.randomUUID();
        }),
    );
</script>
<template>
    <Head title="Statement comparison" />
    <div class="mx-auto w-full max-w-4xl space-y-7 px-4 py-8 sm:px-8 sm:py-12">
        <LedgerNav />
        <header>
            <p
                class="text-xs font-medium tracking-[0.18em] text-muted-foreground uppercase"
            >
                RETAINED STATEMENT COMPARISON
            </p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight">
                {{
                    session.status === 'matched'
                        ? 'Your statement matches.'
                        : session.status === 'difference'
                          ? 'A difference to explain.'
                          : 'The ledger balance is unavailable.'
                }}
            </h1>
            <p
                class="mt-3 text-sm [overflow-wrap:anywhere] text-muted-foreground"
            >
                {{ session.reference }} · {{ session.cutoff }}
            </p>
            <p class="mt-2 text-xs text-muted-foreground">
                Captured {{ session.recorded_at }}. This saved comparison does
                not change after later ledger activity.
            </p>
        </header>
        <section
            class="grid gap-5 rounded-2xl border bg-card p-5 shadow-sm sm:grid-cols-3 sm:p-7"
            aria-label="Saved comparison amounts"
        >
            <div class="min-w-0">
                <h2 class="text-xs text-muted-foreground">Statement</h2>
                <p class="mt-2 text-xl font-semibold [overflow-wrap:anywhere]">
                    <CurrencyDisplay
                        :amount="session.statement.amount"
                        :currency="session.currency"
                    />
                </p>
            </div>
            <div class="min-w-0">
                <h2 class="text-xs text-muted-foreground">Ledger at cutoff</h2>
                <p
                    v-if="session.ledger"
                    class="mt-2 text-xl font-semibold [overflow-wrap:anywhere]"
                >
                    <CurrencyDisplay
                        :amount="session.ledger.amount"
                        :currency="session.currency"
                    />
                </p>
                <p v-else class="mt-2">Unavailable</p>
            </div>
            <div class="min-w-0">
                <h2 class="text-xs text-muted-foreground">
                    Statement minus ledger
                </h2>
                <p
                    v-if="session.difference_amount !== null"
                    class="mt-2 text-xl font-semibold [overflow-wrap:anywhere]"
                >
                    <CurrencyDisplay
                        :amount="session.difference_amount"
                        :currency="session.currency"
                    />
                </p>
                <p v-else class="mt-2">Unavailable</p>
            </div>
        </section>
        <div
            v-if="adjustment"
            class="space-y-3 rounded-xl border bg-muted/30 p-5"
        >
            <h2 class="font-medium">Linked adjustment</h2>
            <p class="text-sm [overflow-wrap:anywhere]">
                {{ adjustment.reason }}
            </p>
            <p class="text-xs text-muted-foreground">
                Current journal status: {{ adjustment.current_status }}. This
                live link is separate from the saved evidence above.
            </p>
            <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                <Link
                    :href="`/transactions/${adjustment.journal_id}`"
                    class="inline-flex min-h-10 items-center underline"
                    >Open adjustment and reversal history</Link
                ><Link
                    :href="`/reconciliations/${adjustment.original_id === session.id ? adjustment.result_id : adjustment.original_id}`"
                    class="inline-flex min-h-10 items-center underline"
                    >{{
                        adjustment.original_id === session.id
                            ? 'Open resulting comparison'
                            : 'Open original difference'
                    }}</Link
                >
            </div>
        </div>
        <form
            v-else-if="session.status === 'difference'"
            class="rounded-2xl border bg-card p-5 sm:p-7"
            @submit.prevent="submit"
        >
            <h2 class="text-lg font-medium">Post an explicit adjustment</h2>
            <p
                class="mt-2 max-w-2xl text-sm leading-relaxed text-muted-foreground"
            >
                Investigate missing or incorrect entries first. If this
                discrepancy is an equity correction, explain why below. The
                adjustment posts on the statement's UTC business date and saves
                a linked new comparison. Changed ledger evidence requires a new
                comparison.
            </p>
            <fieldset :disabled="form.processing" class="mt-6 space-y-5">
                <legend class="sr-only">Adjustment decision</legend>
                <LedgerFeedback
                    :errors="form.errors"
                    :uncertain="feedback.uncertain.value"
                    history="/reconciliations"
                />
                <div>
                    <Label for="adjust-offset">Offset classification</Label
                    ><select
                        id="adjust-offset"
                        v-model="form.offset"
                        required
                        class="mt-2 min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm"
                        :aria-invalid="!!form.errors.offset"
                        aria-describedby="adjust-offset-error"
                    >
                        <option value="" disabled>
                            Choose an explicit offset
                        </option>
                        <option value="equity">
                            Equity correction
                        </option></select
                    ><InputError
                        id="adjust-offset-error"
                        :message="form.errors.offset"
                    />
                </div>
                <div>
                    <Label for="adjust-reason"
                        >Reason and supporting reference</Label
                    ><Input
                        id="adjust-reason"
                        v-model="form.reason"
                        required
                        maxlength="180"
                        class="mt-2 min-h-11"
                        :aria-invalid="!!form.errors.reason"
                        aria-describedby="adjust-reason-error"
                    /><InputError
                        id="adjust-reason-error"
                        :message="form.errors.reason"
                    />
                </div>
            </fieldset>
            <p class="sr-only" role="status">
                {{ form.processing ? 'Posting attributed adjustment' : '' }}
            </p>
            <Button
                type="submit"
                :disabled="form.processing"
                :aria-busy="form.processing"
                class="mt-6 min-h-11"
                >{{
                    form.processing
                        ? 'Posting…'
                        : 'Post adjustment and save comparison'
                }}</Button
            >
        </form>
        <p
            v-else-if="session.status === 'matched'"
            class="rounded-xl border p-5 text-sm"
        >
            The native difference is zero. No adjustment is needed.
        </p>
        <p v-else class="rounded-xl border p-5 text-sm">
            Resolve the unavailable opening or balance evidence before posting
            an adjustment, then save a new comparison.
        </p>
        <FinancialEvidenceView :evidence="evidence" />
        <nav
            class="flex flex-wrap gap-x-6 gap-y-2 text-sm"
            aria-label="Comparison history"
        >
            <Link
                :href="`/reconciliations?supersedes=${session.id}`"
                class="inline-flex min-h-11 items-center underline"
                >Save a new comparison for this account</Link
            ><Link
                v-if="session.supersedes_id"
                :href="`/reconciliations/${session.supersedes_id}`"
                class="inline-flex min-h-11 items-center underline"
                >Open earlier comparison</Link
            >
        </nav>
    </div>
</template>
