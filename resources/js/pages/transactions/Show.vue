<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Check, FileText, RotateCcw } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Transaction } from '@/types/transactions';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Transactions', href: '/transactions' },
            { title: 'Record', href: '#' },
        ],
    },
});
const props = defineProps<{
    transaction: Transaction;
    related: Transaction[];
}>();
const page = usePage();
const open = ref(false);
const uncertain = ref(false);
const form = useForm(
    `reversal:${page.props.auth.user.id}:${props.transaction.id}`,
    { idempotency_key: crypto.randomUUID(), reason: '' },
);
const error = computed(
    () =>
        (form.errors as Record<string, string>).journal ||
        form.errors.idempotency_key,
);
const focusError = async () => {
    await nextTick();
    document
        .querySelector<HTMLElement>(
            '[data-reversal-error], #reversal-reason[aria-invalid="true"]',
        )
        ?.focus();
};
const ambiguous = () => {
    uncertain.value = true;
    return false;
};
const reverse = () => {
    uncertain.value = false;
    form.post(`/transactions/${props.transaction.id}/reverse`, {
        onFinish: () => {
            if (uncertain.value || Object.keys(form.errors).length > 0)
                void focusError();
        },
        onNetworkError: ambiguous,
        onHttpException: ambiguous,
        onSuccess: () => {
            open.value = false;
            form.reset();
            form.idempotency_key = crypto.randomUUID();
        },
    });
};
</script>

<template>
    <Head title="Transaction record" />
    <div class="mx-auto w-full max-w-3xl space-y-7 px-4 py-8 sm:px-8 sm:py-12">
        <Link
            href="/transactions"
            class="inline-flex min-h-10 items-center gap-2 text-sm text-muted-foreground hover:text-foreground focus-visible:outline-2"
            ><ArrowLeft class="size-4" />Transaction history</Link
        >
        <header class="space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="text-xs font-medium tracking-widest text-muted-foreground uppercase"
                    >Transaction record</span
                ><Badge variant="secondary" class="capitalize">{{
                    transaction.status
                }}</Badge>
            </div>
            <h1
                class="text-3xl leading-tight font-semibold tracking-tight [overflow-wrap:anywhere]"
            >
                {{ transaction.description }}
            </h1>
            <p class="text-sm text-muted-foreground">
                <span class="capitalize">{{ transaction.kind }}</span> ·
                {{ transaction.effective_date }}
            </p>
        </header>
        <section
            class="overflow-hidden rounded-2xl border bg-card shadow-sm"
            aria-labelledby="movement-heading"
        >
            <div class="border-b bg-muted/20 p-5 sm:p-7">
                <div class="mb-3 flex items-center gap-2 text-sm font-medium">
                    <span
                        class="flex size-6 items-center justify-center rounded-full bg-primary text-primary-foreground"
                        ><Check class="size-3" /></span
                    >{{
                        transaction.status === 'pending'
                            ? 'Not yet posted'
                            : transaction.status === 'reversed'
                              ? 'Original retained · reversal recorded'
                              : 'Recorded in your ledger'
                    }}
                </div>
                <p
                    v-if="transaction.primary"
                    class="text-2xl font-semibold tracking-tight break-all sm:text-3xl"
                >
                    <CurrencyDisplay
                        :amount="transaction.primary.amount"
                        :currency="transaction.primary.currency"
                    />
                </p>
                <p
                    v-if="transaction.kind === 'transfer'"
                    class="mt-3 text-sm text-muted-foreground"
                >
                    An internal transfer changes neither household income nor
                    expense.
                </p>
                <p
                    v-if="transaction.kind === 'reversal'"
                    class="mt-3 text-sm text-muted-foreground"
                >
                    These opposite movements cancel the original. Both records
                    remain in your history.
                </p>
            </div>
            <div class="space-y-5 p-5 sm:p-7">
                <h2 id="movement-heading" class="font-medium">
                    Account movements
                </h2>
                <dl class="space-y-4">
                    <div
                        v-for="movement in transaction.movements"
                        :key="movement.account_id"
                        class="flex flex-col justify-between gap-2 border-b pb-4 last:border-0 last:pb-0 sm:flex-row sm:items-start"
                    >
                        <dt class="min-w-0 text-sm">
                            <Link
                                :href="`/accounts/${movement.account_id}`"
                                class="[overflow-wrap:anywhere] underline decoration-border underline-offset-4 hover:decoration-foreground"
                                >{{ movement.name }}</Link
                            ><span
                                v-if="movement.internal"
                                class="mt-1 block text-xs text-muted-foreground"
                                >Internal ledger · excluded from net worth</span
                            >
                        </dt>
                        <dd class="shrink-0 font-medium tabular-nums">
                            <CurrencyDisplay
                                :amount="movement.amount"
                                :currency="movement.currency"
                            />
                        </dd>
                    </div>
                </dl>
            </div>
        </section>
        <section
            aria-labelledby="receipt-heading"
            class="rounded-2xl border p-5 sm:p-7"
        >
            <h2
                id="receipt-heading"
                class="flex items-center gap-2 font-medium"
            >
                <FileText class="size-4" />Reference &amp; receipt
            </h2>
            <dl class="mt-5 space-y-4 text-sm">
                <div>
                    <dt class="text-muted-foreground">Record reference</dt>
                    <dd class="mt-1 font-mono text-xs break-all">
                        {{ transaction.reference }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Receipt reference</dt>
                    <dd class="mt-1 [overflow-wrap:anywhere]">
                        {{
                            transaction.receipt_reference ||
                            'No receipt reference added'
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Recorded at · UTC</dt>
                    <dd class="mt-1 break-all">
                        {{ transaction.recorded_at }}
                    </dd>
                </div>
            </dl>
        </section>
        <section
            v-if="related.length"
            aria-labelledby="related-heading"
            class="rounded-2xl border p-5 sm:p-7"
        >
            <h2 id="related-heading" class="font-medium">Linked history</h2>
            <ul class="mt-4 space-y-3">
                <li v-for="record in related" :key="record.id">
                    <Link
                        :href="`/transactions/${record.id}`"
                        class="flex flex-wrap justify-between gap-2 rounded-lg bg-muted/40 p-3 text-sm underline focus-visible:outline-2"
                        ><span class="min-w-0 [overflow-wrap:anywhere]">{{
                            record.description
                        }}</span
                        ><span class="capitalize"
                            >{{ record.kind }} · {{ record.status }}</span
                        ></Link
                    >
                </li>
            </ul>
        </section>
        <section
            v-if="transaction.fx_operation_id"
            class="rounded-2xl border p-5 sm:p-7"
        >
            <h2 class="font-medium">Part of a currency exchange</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Both currencies and the recorded fee are corrected together.
            </p>
            <Button as-child variant="outline" class="mt-4 min-h-11"
                ><Link :href="`/fx/${transaction.fx_operation_id}`"
                    >Open complete conversion</Link
                ></Button
            >
        </section>
        <section
            v-if="transaction.investment"
            class="rounded-2xl border p-5 sm:p-7"
        >
            <h2 class="font-medium">Part of an investment record</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Correct or cancel the investment fact and its cash movement
                together.
            </p>
            <Button as-child variant="outline" class="mt-4 min-h-11"
                ><Link
                    :href="`/portfolio/${transaction.investment.portfolio_account_id}/events/${transaction.investment.event_id}`"
                    >Open complete investment record</Link
                ></Button
            >
        </section>
        <Dialog v-model:open="open"
            ><section
                v-if="
                    transaction.status === 'posted' &&
                    transaction.kind !== 'reversal' &&
                    !transaction.fx_operation_id &&
                    !transaction.investment
                "
                class="rounded-2xl border border-dashed p-5 sm:p-7"
                aria-labelledby="correction-heading"
            >
                <h2 id="correction-heading" class="font-medium">
                    Need to correct this record?
                </h2>
                <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                    Posted values stay unchanged. Add a reversal, or reverse and
                    replace the transaction in one step.
                </p>
                <div class="mt-5 flex flex-wrap gap-3">
                    <Button
                        v-if="
                            ['income', 'expense', 'transfer'].includes(
                                transaction.kind,
                            )
                        "
                        as-child
                        variant="outline"
                        class="min-h-11"
                        ><Link :href="`/transactions/${transaction.id}/correct`"
                            >Correct transaction</Link
                        ></Button
                    ><DialogTrigger as-child
                        ><Button
                            type="button"
                            variant="secondary"
                            class="min-h-11"
                            ><RotateCcw class="size-4" />Record reversal</Button
                        ></DialogTrigger
                    >
                </div>
            </section>
            <Button as-child variant="outline" class="min-h-11"
                ><Link href="/transactions/new"
                    >Record another transaction</Link
                ></Button
            >
            <DialogContent class="max-h-[85dvh] overflow-y-auto sm:max-w-lg"
                ><DialogHeader
                    ><DialogTitle>Record a reversal?</DialogTitle
                    ><DialogDescription
                        >The opposite account movements will be recorded. The
                        original transaction and its receipt reference stay in
                        history. This does not send money.</DialogDescription
                    ></DialogHeader
                >
                <div class="rounded-xl border bg-muted/30 p-4 text-sm">
                    <p class="font-medium [overflow-wrap:anywhere]">
                        {{ transaction.description }}
                    </p>
                    <p v-if="transaction.primary" class="mt-2">
                        <CurrencyDisplay
                            :amount="transaction.primary.amount"
                            :currency="transaction.primary.currency"
                        />
                    </p>
                </div>
                <form @submit.prevent="reverse" class="space-y-5">
                    <div
                        v-if="error || uncertain"
                        tabindex="-1"
                        data-reversal-error
                        role="alert"
                        class="rounded-lg border border-destructive/40 p-3 text-sm"
                    >
                        {{
                            uncertain
                                ? 'We could not confirm the result. Check history or retry this same request safely; its identity is kept.'
                                : error
                        }}
                        <Link href="/transactions" class="underline"
                            >Check history</Link
                        >
                    </div>
                    <div>
                        <Label for="reversal-reason">Reason for reversal</Label
                        ><Input
                            id="reversal-reason"
                            v-model="form.reason"
                            class="mt-2 min-h-11"
                            required
                            maxlength="180"
                            :disabled="form.processing"
                            :aria-invalid="Boolean(form.errors.reason)"
                            aria-describedby="reason-error"
                        /><InputError
                            id="reason-error"
                            :message="form.errors.reason"
                        />
                    </div>
                    <p role="status" class="sr-only">
                        {{ form.processing ? 'Recording reversal' : '' }}
                    </p>
                    <DialogFooter class="gap-2"
                        ><DialogClose as-child
                            ><Button
                                variant="outline"
                                type="button"
                                class="min-h-11"
                                :disabled="form.processing"
                                >Cancel</Button
                            ></DialogClose
                        ><Button
                            type="submit"
                            :aria-busy="form.processing"
                            class="min-h-11"
                            :disabled="form.processing"
                            ><span>{{
                                form.processing
                                    ? 'Recording…'
                                    : 'Record reversal'
                            }}</span></Button
                        ></DialogFooter
                    >
                </form></DialogContent
            ></Dialog
        >
    </div>
</template>
