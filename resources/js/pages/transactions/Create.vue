<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Check, ShieldCheck } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Transaction, TransactionAccount } from '@/types/transactions';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Transactions', href: '/transactions' },
            { title: 'Record', href: '/transactions/new' },
        ],
    },
});
const props = defineProps<{
    accounts: TransactionAccount[];
    today: string;
    original: Transaction | null;
}>();
const page = usePage();
const personal =
    props.original?.movements.filter((movement) => !movement.internal) ?? [];
const form = useForm(
    `transaction:${page.props.auth.user.id}:${props.original?.id ?? 'new'}`,
    {
        idempotency_key: crypto.randomUUID(),
        kind: props.original?.kind ?? 'expense',
        account_id: personal[0]?.account_id ?? '',
        destination_account_id:
            props.original?.kind === 'transfer'
                ? (personal[1]?.account_id ?? '')
                : '',
        amount: props.original?.primary?.amount.replace(/^-/, '') ?? '',
        effective_date: props.original?.effective_date ?? props.today,
        description: props.original?.description ?? '',
        receipt_reference: props.original?.receipt_reference ?? '',
    },
);
const uncertain = ref(false);
const source = computed(() =>
    props.accounts.find((account) => account.id === form.account_id),
);
const destination = computed(() =>
    props.accounts.find(
        (account) => account.id === form.destination_account_id,
    ),
);
const destinations = computed(() =>
    props.accounts.filter(
        (account) =>
            account.id !== form.account_id &&
            account.currency === source.value?.currency,
    ),
);
watch(
    [() => form.kind, () => form.account_id],
    () => {
        if (
            form.kind !== 'transfer' ||
            !destinations.value.some(
                (account) => account.id === form.destination_account_id,
            )
        ) {
            form.destination_account_id = '';
        }
    },
    { immediate: true },
);
const failure = computed(
    () =>
        form.errors.idempotency_key ||
        (form.errors as Record<string, string>).journal,
);
const focusError = async () => {
    await nextTick();
    document
        .querySelector<HTMLElement>(
            '[aria-invalid="true"], [data-posting-error]',
        )
        ?.focus();
};
const ambiguous = () => {
    uncertain.value = true;
    return false;
};
const submit = () => {
    uncertain.value = false;
    form.post(
        props.original
            ? `/transactions/${props.original.id}/correct`
            : '/transactions',
        {
            onFinish: () => {
                if (uncertain.value || Object.keys(form.errors).length > 0)
                    void focusError();
            },
            onNetworkError: ambiguous,
            onHttpException: ambiguous,
            onSuccess: () => {
                form.reset();
                form.idempotency_key = crypto.randomUUID();
            },
        },
    );
};
</script>

<template>
    <Head :title="original ? 'Correct transaction' : 'Record transaction'" />
    <div class="mx-auto w-full max-w-3xl space-y-7 px-4 py-8 sm:px-8 sm:py-12">
        <Link
            :href="original ? `/transactions/${original.id}` : '/transactions'"
            class="inline-flex min-h-10 items-center gap-2 text-sm text-muted-foreground hover:text-foreground focus-visible:outline-2"
            ><ArrowLeft class="size-4" />{{
                original ? 'Back to original record' : 'Transaction history'
            }}</Link
        >
        <header>
            <p
                class="mb-2 text-xs font-medium tracking-widest text-muted-foreground uppercase"
            >
                {{
                    original
                        ? 'Correct with a clear trail'
                        : 'A little clarity, every day'
                }}
            </p>
            <h1 class="text-3xl font-semibold tracking-tight">
                {{ original ? 'Correct transaction' : 'Record a transaction' }}
            </h1>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                {{
                    original
                        ? 'The original is retained and cancelled on its original business date. Your replacement uses the date below. Both records save together.'
                        : 'Choose the movement, add the details, and keep a reliable record.'
                }}
            </p>
        </header>
        <section
            v-if="accounts.length === 0"
            class="rounded-2xl border bg-muted/30 p-7"
        >
            <h2 class="font-semibold">You need an active personal account</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Archived and internal ledger accounts cannot receive new
                transactions.
            </p>
            <Button as-child class="mt-5 min-h-11"
                ><Link href="/accounts">Manage accounts</Link></Button
            >
        </section>
        <form
            v-else
            @submit.prevent="submit"
            class="overflow-hidden rounded-2xl border bg-card shadow-sm"
        >
            <div
                v-if="failure || uncertain"
                tabindex="-1"
                data-posting-error
                role="alert"
                class="m-5 rounded-xl border border-destructive/40 bg-destructive/5 p-4 text-sm sm:m-7"
            >
                <p class="font-medium">
                    {{
                        uncertain
                            ? 'We could not confirm the result'
                            : 'This transaction needs attention'
                    }}
                </p>
                <p class="mt-2 leading-relaxed">
                    {{
                        uncertain
                            ? 'Your details and request identity are kept. Check history, or retry these same details safely. A connection problem does not mean the record failed.'
                            : failure
                    }}
                </p>
                <Link href="/transactions" class="mt-3 inline-block underline"
                    >Check transaction history</Link
                >
            </div>
            <fieldset
                :disabled="form.processing"
                class="space-y-7 p-5 disabled:opacity-70 sm:p-7"
            >
                <legend class="sr-only">Transaction details</legend>
                <div>
                    <Label for="transaction-kind">Movement</Label
                    ><select
                        id="transaction-kind"
                        v-model="form.kind"
                        class="mt-2 min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm focus-visible:outline-2"
                        :aria-invalid="Boolean(form.errors.kind)"
                        aria-describedby="kind-error"
                    >
                        <option value="expense">Expense · money out</option>
                        <option value="income">Income · money in</option>
                        <option value="transfer">
                            Transfer · between your accounts
                        </option></select
                    ><InputError id="kind-error" :message="form.errors.kind" />
                </div>
                <div
                    class="grid gap-6"
                    :class="form.kind === 'transfer' ? 'sm:grid-cols-2' : ''"
                >
                    <div>
                        <Label for="transaction-account">{{
                            form.kind === 'transfer'
                                ? 'From account'
                                : 'Account'
                        }}</Label
                        ><select
                            id="transaction-account"
                            v-model="form.account_id"
                            class="mt-2 min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm focus-visible:outline-2"
                            required
                            :aria-invalid="Boolean(form.errors.account_id)"
                            aria-describedby="account-error account-hint"
                        >
                            <option disabled value="">Choose an account</option>
                            <option
                                v-for="account in accounts"
                                :key="account.id"
                                :value="account.id"
                            >
                                {{ account.name }} · {{ account.currency }}
                            </option>
                        </select>
                        <p
                            id="account-hint"
                            class="mt-2 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{
                                source?.balance_reason === 'opening_pending'
                                    ? 'Opening balance is awaiting posting. Your complete balance is not yet available.'
                                    : 'Only active personal accounts are shown.'
                            }}
                        </p>
                        <InputError
                            id="account-error"
                            :message="form.errors.account_id"
                        />
                    </div>
                    <div v-if="form.kind === 'transfer'">
                        <Label for="destination-account">To account</Label
                        ><select
                            id="destination-account"
                            v-model="form.destination_account_id"
                            required
                            class="mt-2 min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm focus-visible:outline-2"
                            :aria-invalid="
                                Boolean(form.errors.destination_account_id)
                            "
                            aria-describedby="destination-error destination-hint"
                        >
                            <option disabled value="">
                                Choose a destination
                            </option>
                            <option
                                v-for="account in destinations"
                                :key="account.id"
                                :value="account.id"
                            >
                                {{ account.name }} · {{ account.currency }}
                            </option>
                        </select>
                        <p
                            id="destination-hint"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            A different account in the same currency. No FX
                            conversion.
                        </p>
                        <InputError
                            id="destination-error"
                            :message="form.errors.destination_account_id"
                        />
                    </div>
                </div>
                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <Label for="transaction-amount"
                            >Amount
                            <span v-if="source" class="text-muted-foreground"
                                >· {{ source.currency }}</span
                            ></Label
                        ><Input
                            id="transaction-amount"
                            v-model="form.amount"
                            class="mt-2 min-h-12 text-lg tabular-nums"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            required
                            maxlength="32"
                            placeholder="0.00"
                            :aria-invalid="Boolean(form.errors.amount)"
                            aria-describedby="amount-hint amount-error"
                        />
                        <p
                            id="amount-hint"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            {{
                                source
                                    ? `Use up to ${source.exponent} decimal places. No rounding is applied.`
                                    : 'Choose an account to see its currency precision.'
                            }}
                        </p>
                        <InputError
                            id="amount-error"
                            :message="form.errors.amount"
                        />
                    </div>
                    <div>
                        <Label for="transaction-date">Effective date</Label
                        ><Input
                            id="transaction-date"
                            v-model="form.effective_date"
                            class="mt-2 min-h-12"
                            type="date"
                            :max="today"
                            required
                            :aria-invalid="Boolean(form.errors.effective_date)"
                            aria-describedby="date-error date-hint"
                        />
                        <p
                            id="date-hint"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            When it happened, on or before today.
                        </p>
                        <InputError
                            id="date-error"
                            :message="form.errors.effective_date"
                        />
                    </div>
                </div>
                <div>
                    <Label for="transaction-description">Description</Label
                    ><Input
                        id="transaction-description"
                        v-model="form.description"
                        class="mt-2 min-h-11"
                        maxlength="180"
                        required
                        placeholder="What was this for?"
                        :aria-invalid="Boolean(form.errors.description)"
                        aria-describedby="description-error"
                    /><InputError
                        id="description-error"
                        :message="form.errors.description"
                    />
                </div>
                <div>
                    <Label for="receipt-reference"
                        >Receipt reference
                        <span class="font-normal text-muted-foreground"
                            >· optional</span
                        ></Label
                    ><Input
                        id="receipt-reference"
                        v-model="form.receipt_reference"
                        class="mt-2 min-h-11"
                        maxlength="180"
                        placeholder="A receipt number or a note for later"
                        :aria-invalid="Boolean(form.errors.receipt_reference)"
                        aria-describedby="receipt-hint receipt-error"
                    />
                    <p
                        id="receipt-hint"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        A reference only. Files are not uploaded and links are
                        not opened.
                    </p>
                    <InputError
                        id="receipt-error"
                        :message="form.errors.receipt_reference"
                    />
                </div>
            </fieldset>
            <div class="space-y-5 border-t bg-muted/25 p-5 sm:p-7">
                <div aria-label="Transaction preview" class="space-y-2 text-sm">
                    <p class="font-medium">Before you record</p>
                    <p class="leading-relaxed text-muted-foreground">
                        <span class="capitalize">{{ form.kind }}</span
                        ><span v-if="source"> · {{ source.name }}</span
                        ><template
                            v-if="form.kind === 'transfer' && destination"
                        >
                            <ArrowRight class="mx-1 inline size-3" />
                            {{ destination.name }}</template
                        >
                    </p>
                    <p
                        v-if="source && form.amount"
                        class="text-xl font-semibold"
                    >
                        <CurrencyDisplay
                            :amount="form.amount"
                            :currency="source.currency"
                        />
                    </p>
                    <p
                        v-if="form.kind === 'transfer'"
                        class="text-xs text-muted-foreground"
                    >
                        Moves money between accounts. It does not create income
                        or expense.
                    </p>
                </div>
                <p role="status" class="sr-only">
                    {{ form.processing ? 'Recording transaction' : '' }}
                </p>
                <Button
                    :aria-busy="form.processing"
                    type="submit"
                    :disabled="form.processing"
                    class="min-h-12 w-full gap-2"
                    ><Check class="size-4" /><span>{{
                        form.processing
                            ? 'Recording…'
                            : original
                              ? 'Reverse and record replacement'
                              : form.kind === 'transfer'
                                ? 'Record transfer'
                                : 'Post transaction'
                    }}</span></Button
                >
                <p
                    class="flex items-start justify-center gap-2 text-xs leading-relaxed text-muted-foreground"
                >
                    <ShieldCheck class="size-4 shrink-0" />Your record is saved
                    atomically. Corrections keep the original history.
                </p>
            </div>
        </form>
    </div>
</template>
