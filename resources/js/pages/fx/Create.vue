<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Check } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import LedgerFeedback from '@/components/LedgerFeedback.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    toLedgerUtc,
    useLedgerFeedback,
} from '@/composables/useLedgerFeedback';
import type { FxOperation, LedgerAccount } from '@/types/ledger';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Currency exchange', href: '/fx' },
            { title: 'Record', href: '/fx/new' },
        ],
    },
});
const props = defineProps<{
    accounts: LedgerAccount[];
    original: FxOperation | null;
    today: string;
    now_utc: string;
}>();
const page = usePage();
const form = useForm(
    `fx:${page.props.auth.user.id}:${props.original?.id ?? 'new'}`,
    {
        idempotency_key: crypto.randomUUID(),
        source_account_id: props.original?.source.account_id ?? '',
        destination_account_id: props.original?.destination.account_id ?? '',
        source_amount: props.original?.source.money.amount ?? '',
        destination_amount: props.original?.destination.money.amount ?? '',
        fee_amount: props.original?.fee.money.amount ?? '0',
        fee_side: props.original?.fee.side ?? 'source',
        rate_value: props.original?.actual_rate.value ?? '',
        rate_source: props.original?.actual_rate.source ?? '',
        observed_at: (
            props.original?.actual_rate.observed_at ??
            `${props.today}T00:00:00Z`
        ).slice(0, -1),
        effective_date: props.original?.effective_date ?? props.today,
        description: props.original?.description ?? '',
    },
);
const feedback = useLedgerFeedback(
    () => form.errors,
    () => form.clearErrors(),
);
const source = computed(() =>
    props.accounts.find((a) => a.id === form.source_account_id),
);
const destination = computed(() =>
    props.accounts.find((a) => a.id === form.destination_account_id),
);
const destinations = computed(() =>
    props.accounts.filter((a) => a.currency !== source.value?.currency),
);
watch(
    () => form.source_account_id,
    () => {
        if (
            !destinations.value.some(
                (a) => a.id === form.destination_account_id,
            )
        )
            form.destination_account_id = '';
    },
);
const submit = () =>
    form
        .transform((data) => ({
            ...data,
            observed_at: toLedgerUtc(data.observed_at),
        }))
        .post(
            props.original ? `/fx/${props.original.id}/correct` : '/fx',
            feedback.options(() => {
                form.reset();
                form.idempotency_key = crypto.randomUUID();
            }),
        );
const fieldClass =
    'mt-2 min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm focus-visible:outline-2';
</script>
<template>
    <Head
        :title="
            original ? 'Correct currency exchange' : 'Record currency exchange'
        "
    />
    <main class="mx-auto w-full max-w-3xl space-y-7 px-4 py-8 sm:px-8 sm:py-12">
        <Link
            :href="original ? `/fx/${original.id}` : '/fx'"
            class="inline-flex min-h-10 items-center gap-2 text-sm text-muted-foreground underline"
            ><ArrowLeft class="size-4" />{{
                original ? 'Original exchange' : 'Exchange history'
            }}</Link
        >
        <header>
            <p
                class="text-xs font-medium tracking-[0.18em] text-muted-foreground uppercase"
            >
                TWO CURRENCIES · ONE RECORD
            </p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight">
                {{
                    original
                        ? 'Correct the complete exchange'
                        : 'Record a currency exchange'
                }}
            </h1>
            <p class="mt-3 leading-relaxed text-muted-foreground">
                {{
                    original
                        ? 'The original and its fee are reversed, and your replacement is recorded together. All history is retained.'
                        : 'Enter the actual principal amounts from your receipt. Fees remain separate and never change those original amounts.'
                }}
            </p>
        </header>
        <section
            v-if="new Set(accounts.map((account) => account.currency)).size < 2"
            class="rounded-2xl border border-dashed p-7"
        >
            <h2 class="font-medium">Accounts in two currencies are needed</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Add active owned accounts, then return to record the exchange.
            </p>
            <Button as-child variant="outline" class="mt-4 min-h-11"
                ><Link href="/accounts">Manage accounts</Link></Button
            >
        </section>
        <form
            v-else
            class="overflow-hidden rounded-2xl border bg-card shadow-sm"
            @submit.prevent="submit"
        >
            <fieldset :disabled="form.processing" class="space-y-7 p-5 sm:p-7">
                <legend class="sr-only">Exchange details</legend>
                <LedgerFeedback
                    :errors="form.errors"
                    :uncertain="feedback.uncertain.value"
                    history="/fx"
                />
                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <Label for="fx-source">From account</Label
                        ><select
                            id="fx-source"
                            v-model="form.source_account_id"
                            required
                            :class="fieldClass"
                            :aria-invalid="!!form.errors.source_account_id"
                            aria-describedby="fx-source-error"
                        >
                            <option value="" disabled>
                                Choose source account
                            </option>
                            <option
                                v-for="a in accounts"
                                :key="a.id"
                                :value="a.id"
                            >
                                {{ a.name }} · {{ a.currency }}
                            </option></select
                        ><InputError
                            id="fx-source-error"
                            :message="form.errors.source_account_id"
                        />
                    </div>
                    <div>
                        <Label for="fx-destination">To account</Label
                        ><select
                            id="fx-destination"
                            v-model="form.destination_account_id"
                            required
                            :class="fieldClass"
                            :aria-invalid="!!form.errors.destination_account_id"
                            aria-describedby="fx-destination-error"
                        >
                            <option value="" disabled>
                                Choose different currency
                            </option>
                            <option
                                v-for="a in destinations"
                                :key="a.id"
                                :value="a.id"
                            >
                                {{ a.name }} · {{ a.currency }}
                            </option></select
                        ><InputError
                            id="fx-destination-error"
                            :message="form.errors.destination_account_id"
                        />
                    </div>
                </div>
                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <Label for="fx-sent"
                            >Principal paid
                            <span class="text-muted-foreground">{{
                                source?.currency
                            }}</span></Label
                        ><Input
                            id="fx-sent"
                            v-model="form.source_amount"
                            inputmode="decimal"
                            required
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.source_amount"
                            aria-describedby="fx-sent-help fx-sent-error"
                        />
                        <p
                            id="fx-sent-help"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Excludes any extra source fee.
                        </p>
                        <InputError
                            id="fx-sent-error"
                            :message="form.errors.source_amount"
                        />
                    </div>
                    <div>
                        <Label for="fx-received"
                            >Gross destination principal
                            <span class="text-muted-foreground">{{
                                destination?.currency
                            }}</span></Label
                        ><Input
                            id="fx-received"
                            v-model="form.destination_amount"
                            inputmode="decimal"
                            required
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.destination_amount"
                            aria-describedby="fx-received-help fx-received-error"
                        />
                        <p
                            id="fx-received-help"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Before any withheld destination fee.
                        </p>
                        <InputError
                            id="fx-received-error"
                            :message="form.errors.destination_amount"
                        />
                    </div>
                </div>
                <div class="rounded-xl bg-muted/40 p-4">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <Label for="fx-fee-side"
                                >How was the fee paid?</Label
                            ><select
                                id="fx-fee-side"
                                v-model="form.fee_side"
                                :class="fieldClass"
                            >
                                <option value="source">
                                    Extra source outflow ·
                                    {{ source?.currency ?? 'source' }}
                                </option>
                                <option value="destination">
                                    Withheld from destination ·
                                    {{ destination?.currency ?? 'destination' }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <Label for="fx-fee"
                                >Separate fee ·
                                {{
                                    form.fee_side === 'source'
                                        ? source?.currency
                                        : destination?.currency
                                }}</Label
                            ><Input
                                id="fx-fee"
                                v-model="form.fee_amount"
                                inputmode="decimal"
                                required
                                class="mt-2 min-h-11"
                                :aria-invalid="!!form.errors.fee_amount"
                                aria-describedby="fx-fee-error"
                            /><InputError
                                id="fx-fee-error"
                                :message="form.errors.fee_amount"
                            />
                        </div>
                    </div>
                    <p
                        class="mt-3 text-xs leading-relaxed text-muted-foreground"
                    >
                        Enter zero if no fee. The fee is recorded as expense
                        once.
                    </p>
                </div>
                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <Label for="fx-rate"
                            >Recorded rate ·
                            {{ destination?.currency ?? 'quote' }} per
                            {{ source?.currency ?? 'base' }}</Label
                        ><Input
                            id="fx-rate"
                            v-model="form.rate_value"
                            inputmode="decimal"
                            required
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.rate_value"
                            aria-describedby="fx-rate-help fx-rate-error"
                        />
                        <p
                            id="fx-rate-help"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Exact receipt rate, up to 12 decimal places.
                            Paid/received cash stays authoritative.
                        </p>
                        <InputError
                            id="fx-rate-error"
                            :message="form.errors.rate_value"
                        />
                    </div>
                    <div>
                        <Label for="fx-rate-source"
                            >Rate source or receipt reference</Label
                        ><Input
                            id="fx-rate-source"
                            v-model="form.rate_source"
                            required
                            maxlength="180"
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.rate_source"
                            aria-describedby="fx-rate-source-error"
                        /><InputError
                            id="fx-rate-source-error"
                            :message="form.errors.rate_source"
                        />
                    </div>
                </div>
                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <Label for="fx-date">Effective UTC business date</Label
                        ><Input
                            id="fx-date"
                            v-model="form.effective_date"
                            type="date"
                            :max="today"
                            required
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.effective_date"
                            aria-describedby="fx-date-error"
                        /><InputError
                            id="fx-date-error"
                            :message="form.errors.effective_date"
                        />
                    </div>
                    <div>
                        <Label for="fx-observed">Rate observed at · UTC</Label
                        ><Input
                            id="fx-observed"
                            v-model="form.observed_at"
                            type="datetime-local"
                            step="1"
                            :max="now_utc.slice(0, -1)"
                            required
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.observed_at"
                            aria-describedby="fx-observed-error"
                        /><InputError
                            id="fx-observed-error"
                            :message="form.errors.observed_at"
                        />
                    </div>
                </div>
                <div>
                    <Label for="fx-description">Description</Label
                    ><Input
                        id="fx-description"
                        v-model="form.description"
                        required
                        maxlength="180"
                        class="mt-2 min-h-11"
                        :aria-invalid="!!form.errors.description"
                        aria-describedby="fx-description-error"
                    /><InputError
                        id="fx-description-error"
                        :message="form.errors.description"
                    />
                </div>
            </fieldset>
            <footer class="space-y-4 border-t bg-muted/20 p-5 sm:p-7">
                <p class="text-sm leading-relaxed text-muted-foreground">
                    Both currency movements and the fee are saved together.
                    {{
                        original
                            ? 'This also reverses all recorded amounts in the original exchange.'
                            : 'Conversion principal is not income.'
                    }}
                </p>
                <p class="sr-only" role="status">
                    {{ form.processing ? 'Saving complete exchange' : '' }}
                </p>
                <Button
                    type="submit"
                    :disabled="form.processing"
                    :aria-busy="form.processing"
                    class="min-h-12 w-full"
                    ><Check class="size-4" /><span>{{
                        form.processing
                            ? 'Saving exchange…'
                            : original
                              ? 'Reverse and record replacement'
                              : 'Record complete exchange'
                    }}</span
                    ><ArrowRight class="size-4"
                /></Button>
            </footer>
        </form>
    </main>
</template>
