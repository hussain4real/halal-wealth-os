<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import InputError from '@/components/InputError.vue';
import LedgerFeedback from '@/components/LedgerFeedback.vue';
import LedgerNav from '@/components/LedgerNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    toLedgerUtc,
    useLedgerFeedback,
} from '@/composables/useLedgerFeedback';
import type { LedgerAccount, Reconciliation } from '@/types/ledger';
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Reconciliation', href: '/reconciliations' }],
    },
});
const props = defineProps<{
    sessions: Reconciliation[];
    prior: Reconciliation | null;
    accounts: LedgerAccount[];
    now_utc: string;
}>();
const page = usePage();
const form = useForm(
    `reconciliation:${page.props.auth.user.id}:${props.prior?.id ?? 'new'}`,
    {
        idempotency_key: crypto.randomUUID(),
        account_id: props.prior?.account_id ?? '',
        statement_amount: props.prior?.statement.amount ?? '',
        cutoff: (props.prior?.cutoff ?? props.now_utc).slice(0, -1),
        supersedes_id: props.prior?.id ?? '',
    },
);
const selected = computed(() =>
    props.accounts.find((account) => account.id === form.account_id),
);
const feedback = useLedgerFeedback(
    () => form.errors,
    () => form.clearErrors(),
);
const submit = () =>
    form
        .transform((data) => ({ ...data, cutoff: toLedgerUtc(data.cutoff) }))
        .post(
            '/reconciliations',
            feedback.options(() => {
                form.idempotency_key = crypto.randomUUID();
            }),
        );
</script>
<template>
    <Head title="Reconciliation" />
    <main class="mx-auto w-full max-w-5xl space-y-7 px-4 py-8 sm:px-8 sm:py-12">
        <LedgerNav />
        <header class="max-w-2xl">
            <p
                class="text-xs font-medium tracking-[0.18em] text-muted-foreground uppercase"
            >
                STATEMENT MEETS LEDGER
            </p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight">
                Find the difference. Keep the history.
            </h1>
            <p class="mt-3 leading-relaxed text-muted-foreground">
                Compare a statement's native balance with recorded movements at
                a UTC cutoff. A comparison saves evidence; any adjustment is a
                separate, explicit decision.
            </p>
        </header>
        <div class="grid gap-7 lg:grid-cols-2">
            <form
                class="min-w-0 rounded-2xl border bg-card p-5 shadow-sm sm:p-7"
                @submit.prevent="submit"
            >
                <h2 class="text-lg font-medium">
                    {{
                        prior ? 'Save a new comparison' : 'Compare a statement'
                    }}
                </h2>
                <p
                    v-if="prior"
                    class="mt-2 text-xs [overflow-wrap:anywhere] text-muted-foreground"
                >
                    Earlier comparison {{ prior.reference }} is retained
                    unchanged.
                </p>
                <fieldset :disabled="form.processing" class="mt-6 space-y-5">
                    <legend class="sr-only">Statement details</legend>
                    <LedgerFeedback
                        :errors="form.errors"
                        :uncertain="feedback.uncertain.value"
                        history="/reconciliations"
                    />
                    <div>
                        <Label for="recon-account">Account</Label
                        ><select
                            id="recon-account"
                            v-model="form.account_id"
                            required
                            :disabled="!!prior"
                            class="mt-2 min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm"
                            :aria-invalid="!!form.errors.account_id"
                            aria-describedby="recon-account-error"
                        >
                            <option value="" disabled>Choose an account</option>
                            <option
                                v-for="account in accounts"
                                :key="account.id"
                                :value="account.id"
                            >
                                {{ account.name }} · {{ account.currency
                                }}{{ account.archived ? ' · archived' : '' }}
                            </option></select
                        ><InputError
                            id="recon-account-error"
                            :message="form.errors.account_id"
                        />
                    </div>
                    <div>
                        <Label for="recon-statement"
                            >Statement balance{{
                                selected ? ` · ${selected.currency}` : ''
                            }}</Label
                        ><Input
                            id="recon-statement"
                            v-model="form.statement_amount"
                            required
                            inputmode="decimal"
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.statement_amount"
                            aria-describedby="recon-statement-help recon-statement-error"
                        />
                        <p
                            id="recon-statement-help"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Enter the exact native amount, including a minus
                            sign if applicable. Currency comes from the account.
                        </p>
                        <InputError
                            id="recon-statement-error"
                            :message="form.errors.statement_amount"
                        />
                    </div>
                    <div>
                        <Label for="recon-cutoff">Statement cutoff · UTC</Label
                        ><Input
                            id="recon-cutoff"
                            v-model="form.cutoff"
                            required
                            type="datetime-local"
                            step="1"
                            :max="now_utc.slice(0, -1)"
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.cutoff"
                            aria-describedby="recon-cutoff-help recon-cutoff-error"
                        />
                        <p
                            id="recon-cutoff-help"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Uses visible movements effective on/before this UTC
                            business date. Future backdated entries require a
                            new comparison.
                        </p>
                        <InputError
                            id="recon-cutoff-error"
                            :message="form.errors.cutoff"
                        />
                    </div>
                </fieldset>
                <p v-if="!accounts.length" class="mt-5 text-sm">
                    Add an account before comparing a statement.
                    <Link href="/accounts" class="underline"
                        >Open accounts</Link
                    >
                </p>
                <p class="sr-only" role="status">
                    {{ form.processing ? 'Saving statement comparison' : '' }}
                </p>
                <Button
                    type="submit"
                    :disabled="form.processing || !accounts.length"
                    :aria-busy="form.processing"
                    class="mt-6 min-h-11 w-full"
                    >{{
                        form.processing ? 'Saving…' : 'Save comparison'
                    }}</Button
                ><Link
                    v-if="prior"
                    href="/reconciliations"
                    class="mt-3 inline-flex min-h-10 items-center text-sm underline"
                    >Cancel revision</Link
                >
            </form>
            <section class="min-w-0 rounded-2xl border p-5 sm:p-7">
                <h2 class="font-medium">
                    Saved comparisons
                    <span class="text-xs font-normal text-muted-foreground"
                        >Latest 30</span
                    >
                </h2>
                <p
                    v-if="!sessions.length"
                    class="mt-5 text-sm leading-relaxed text-muted-foreground"
                >
                    No statements compared yet. Your first comparison will
                    retain its native balance, cutoff and included ledger
                    evidence.
                </p>
                <ol v-else class="mt-4 divide-y">
                    <li
                        v-for="session in sessions"
                        :key="session.id"
                        class="min-w-0 py-4"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <Link
                                :href="`/reconciliations/${session.id}`"
                                class="inline-flex min-h-10 items-center font-medium [overflow-wrap:anywhere] underline"
                                ><CurrencyDisplay
                                    :amount="session.statement.amount"
                                    :currency="session.currency" /></Link
                            ><span
                                class="rounded-full bg-muted px-3 py-1 text-xs capitalize"
                                >{{ session.status }}</span
                            >
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ session.cutoff }}
                        </p>
                        <p class="mt-2 text-xs [overflow-wrap:anywhere]">
                            {{ session.reference }}
                        </p>
                    </li>
                </ol>
            </section>
        </div>
    </main>
</template>
