<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Check, RotateCcw } from '@lucide/vue';
import { ref } from 'vue';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
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
    DialogClose,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLedgerFeedback } from '@/composables/useLedgerFeedback';
import type { FxOperation } from '@/types/ledger';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Currency exchange', href: '/fx' },
            { title: 'Receipt', href: '/fx' },
        ],
    },
});
const props = defineProps<{ operation: FxOperation }>();
const page = usePage();
const open = ref(false);
const form = useForm(
    `fx-reverse:${page.props.auth.user.id}:${props.operation.id}`,
    { idempotency_key: crypto.randomUUID(), reason: '' },
);
const feedback = useLedgerFeedback(
    () => form.errors,
    () => form.clearErrors(),
);
const reverse = () =>
    form.post(
        `/fx/${props.operation.id}/reverse`,
        feedback.options(() => {
            open.value = false;
            form.reset();
            form.idempotency_key = crypto.randomUUID();
        }),
    );
</script>
<template>
    <Head title="Exchange receipt" />
    <main class="mx-auto w-full max-w-3xl space-y-7 px-4 py-8 sm:px-8 sm:py-12">
        <Link
            href="/fx"
            class="inline-flex min-h-10 items-center gap-2 text-sm text-muted-foreground underline"
            ><ArrowLeft class="size-4" />Exchange history</Link
        >
        <header>
            <p
                class="text-xs font-medium tracking-[0.18em] text-muted-foreground uppercase"
            >
                EXCHANGE RECEIPT · {{ operation.status }}
            </p>
            <h1
                class="mt-3 text-3xl font-semibold tracking-tight [overflow-wrap:anywhere]"
            >
                {{ operation.description }}
            </h1>
            <p class="mt-3 text-sm text-muted-foreground">
                Effective {{ operation.effective_date }} · Recorded
                {{ operation.recorded_at }}
            </p>
            <p
                class="mt-2 text-xs [overflow-wrap:anywhere] text-muted-foreground"
            >
                Reference {{ operation.reference }}
            </p>
        </header>
        <section
            class="rounded-2xl border bg-card p-5 shadow-sm sm:p-7"
            aria-label="Recorded principal amounts"
        >
            <div class="grid gap-6 sm:grid-cols-[1fr_auto_1fr]">
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">Principal paid</p>
                    <p
                        class="mt-2 text-2xl font-semibold [overflow-wrap:anywhere]"
                    >
                        <CurrencyDisplay
                            :amount="operation.source.money.amount"
                            :currency="operation.source.money.currency"
                        />
                    </p>
                    <p class="mt-2 text-sm [overflow-wrap:anywhere]">
                        {{ operation.source.name }}
                    </p>
                </div>
                <ArrowRight class="size-5 self-center text-muted-foreground" />
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">
                        Gross destination principal
                    </p>
                    <p
                        class="mt-2 text-2xl font-semibold [overflow-wrap:anywhere]"
                    >
                        <CurrencyDisplay
                            :amount="operation.destination.money.amount"
                            :currency="operation.destination.money.currency"
                        />
                    </p>
                    <p class="mt-2 text-sm [overflow-wrap:anywhere]">
                        {{ operation.destination.name }}
                    </p>
                </div>
            </div>
            <div
                class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t pt-5 text-sm"
            >
                <div>
                    <p class="font-medium">Separate fee</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{
                            operation.fee.side === 'source'
                                ? 'Additional source outflow'
                                : 'Withheld from gross destination cash'
                        }}
                        · expense once
                    </p>
                </div>
                <CurrencyDisplay
                    :amount="operation.fee.money.amount"
                    :currency="operation.fee.money.currency"
                />
            </div>
        </section>
        <section class="rounded-2xl border p-5 sm:p-7">
            <h2 class="font-medium">Your transaction-rate evidence</h2>
            <p class="mt-3 text-lg font-medium [overflow-wrap:anywhere]">
                {{ operation.actual_rate.value }}
                {{ operation.destination.money.currency }} per
                {{ operation.source.money.currency }}
            </p>
            <p
                class="mt-2 text-sm [overflow-wrap:anywhere] text-muted-foreground"
            >
                {{ operation.actual_rate.source }}
            </p>
            <p class="mt-2 text-xs text-muted-foreground">
                Observed {{ operation.actual_rate.observed_at }} · Effective
                {{ operation.actual_rate.effective_at }}
            </p>
            <details class="mt-5 text-sm">
                <summary class="min-h-10 cursor-pointer py-2 font-medium">
                    Compare the implied cash ratio
                </summary>
                <p
                    class="mt-2 leading-relaxed [overflow-wrap:anywhere] text-muted-foreground"
                >
                    {{ operation.implied_rate.display }}
                    {{ operation.destination.money.currency }}/{{
                        operation.source.money.currency
                    }}{{
                        operation.implied_rate.rounded
                            ? ' (approximate 12-decimal display)'
                            : ' (exact)'
                    }}. The original paid and received cash amounts remain
                    authoritative.
                </p>
                <p
                    class="mt-2 text-xs [overflow-wrap:anywhere] text-muted-foreground"
                >
                    Exact ratio: {{ operation.implied_rate.numerator }} /
                    {{ operation.implied_rate.denominator }}. Reporting quotes
                    do not rewrite this receipt.
                </p>
            </details>
        </section>
        <section class="rounded-2xl border p-5 sm:p-7">
            <h2 class="font-medium">Linked movements</h2>
            <ul class="mt-4 divide-y">
                <li v-for="journal in operation.journals" :key="journal.id">
                    <Link
                        :href="`/transactions/${journal.id}`"
                        class="flex min-w-0 flex-wrap items-center justify-between gap-2 py-3 text-sm underline focus-visible:outline-2"
                        ><span class="capitalize">{{
                            journal.role.replaceAll('_', ' ')
                        }}</span
                        ><span
                            class="min-w-0 text-xs [overflow-wrap:anywhere] text-muted-foreground"
                            >{{ journal.reference }} ·
                            {{ journal.status }}</span
                        ></Link
                    >
                </li>
            </ul>
            <p class="mt-3 text-xs leading-relaxed text-muted-foreground">
                Principal currencies balance independently through internal
                clearing. Clearing adds no displayed wealth.
            </p>
        </section>
        <section
            v-if="operation.reversal"
            class="rounded-2xl border bg-muted/30 p-5 sm:p-7"
        >
            <h2 class="font-medium">Complete exchange reversed</h2>
            <p class="mt-2 text-sm [overflow-wrap:anywhere]">
                {{ operation.reversal.reason }}
            </p>
            <p class="mt-2 text-xs text-muted-foreground">
                {{ operation.reversal.recorded_at }}
            </p>
        </section>
        <div class="flex flex-wrap gap-3">
            <Button
                v-if="operation.replaces_id"
                as-child
                variant="outline"
                class="min-h-11"
                ><Link :href="`/fx/${operation.replaces_id}`"
                    >Retained original exchange</Link
                ></Button
            ><Button
                v-if="operation.replacement_id"
                as-child
                variant="outline"
                class="min-h-11"
                ><Link :href="`/fx/${operation.replacement_id}`"
                    >Open replacement exchange</Link
                ></Button
            >
        </div>
        <Dialog v-model:open="open"
            ><section
                v-if="operation.status === 'posted'"
                class="rounded-2xl border border-dashed p-5 sm:p-7"
            >
                <h2 class="font-medium">Correct with a complete trail</h2>
                <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                    All principal movements and the recorded fee are reversed
                    together. A replacement can be recorded in the same step.
                </p>
                <div class="mt-4 flex flex-wrap gap-3">
                    <Button as-child variant="outline" class="min-h-11"
                        ><Link :href="`/fx/${operation.id}/correct`"
                            >Correct complete exchange</Link
                        ></Button
                    ><DialogTrigger as-child
                        ><Button variant="secondary" class="min-h-11"
                            ><RotateCcw class="size-4" />Reverse complete
                            exchange</Button
                        ></DialogTrigger
                    >
                </div>
            </section>
            <DialogContent class="max-h-[85dvh] overflow-y-auto"
                ><DialogHeader
                    ><DialogTitle>Reverse this complete exchange?</DialogTitle
                    ><DialogDescription
                        >Both currency principals and the recorded fee will be
                        reversed today. This corrects bookkeeping; it does not
                        initiate a bank refund. The original stays
                        visible.</DialogDescription
                    ></DialogHeader
                >
                <form class="space-y-5" @submit.prevent="reverse">
                    <LedgerFeedback
                        :errors="form.errors"
                        :uncertain="feedback.uncertain.value"
                        history="/fx"
                    />
                    <fieldset :disabled="form.processing">
                        <Label for="fx-reversal-reason"
                            >Reason for this correction</Label
                        ><Input
                            id="fx-reversal-reason"
                            v-model="form.reason"
                            required
                            maxlength="180"
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.reason"
                            aria-describedby="fx-reversal-error"
                        /><InputError
                            id="fx-reversal-error"
                            :message="form.errors.reason"
                        />
                    </fieldset>
                    <p class="sr-only" role="status">
                        {{
                            form.processing ? 'Recording complete reversal' : ''
                        }}
                    </p>
                    <DialogFooter
                        ><DialogClose as-child
                            ><Button
                                type="button"
                                variant="outline"
                                :disabled="form.processing"
                                class="min-h-11"
                                >Cancel</Button
                            ></DialogClose
                        ><Button
                            type="submit"
                            :disabled="form.processing"
                            :aria-busy="form.processing"
                            class="min-h-11"
                            ><Check class="size-4" /><span>{{
                                form.processing
                                    ? 'Recording…'
                                    : 'Record complete reversal'
                            }}</span></Button
                        ></DialogFooter
                    >
                </form></DialogContent
            ></Dialog
        >
        <Button as-child variant="outline" class="min-h-11"
            ><Link href="/fx/new">Record another exchange</Link></Button
        >
    </main>
</template>
