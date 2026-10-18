<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Account } from '@/types/accounts';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Accounts', href: '/accounts' }] },
});
defineProps<{
    accounts: Account[];
    currencies: { code: string; exponent: number }[];
    types: string[];
}>();
const form = useForm({
    name: '',
    type: 'bank',
    currency_code: 'QAR',
    opening_balance: '',
});
const submit = () => form.post('/accounts');
</script>

<template>
    <Head title="Accounts" />
    <section class="mx-auto flex w-full max-w-4xl flex-col gap-8 p-6">
        <header>
            <h1 class="text-2xl font-semibold">Accounts</h1>
            <p class="mt-2 text-muted-foreground">
                Keep each account in its original currency. Balances come from
                posted activity and cannot be edited.
            </p>
        </header>
        <section aria-labelledby="account-list-heading">
            <h2 id="account-list-heading" class="text-lg font-medium">
                Your accounts
            </h2>
            <p v-if="accounts.length === 0" class="mt-3 text-muted-foreground">
                No accounts yet. Create one below.
            </p>
            <ul v-else class="mt-3 space-y-3">
                <li
                    v-for="account in accounts"
                    :key="account.id"
                    class="rounded-lg border p-4"
                >
                    <Link
                        :href="`/accounts/${account.id}`"
                        class="font-medium underline focus-visible:outline-2"
                        >{{ account.name }}</Link
                    >
                    <p class="mt-1 text-sm">
                        {{ account.type.replaceAll('_', ' ') }} ·
                        {{ account.currency }} ·
                        {{ account.archived ? 'Archived' : 'Active' }}
                    </p>
                    <p class="mt-2">
                        Posted balance:
                        <CurrencyDisplay
                            :amount="account.balance?.amount ?? null"
                            :currency="account.currency"
                        />
                    </p>
                    <div
                        v-if="account.type === 'investment'"
                        class="mt-4 space-y-2 text-sm"
                    >
                        <p>
                            Net-worth mode:
                            {{
                                account.valuation_mode === 'aggregate'
                                    ? 'Whole-account value'
                                    : account.valuation_mode === 'detailed'
                                      ? 'Detailed holdings'
                                      : account.valuation_mode === 'unconfirmed'
                                        ? 'Needs confirmation'
                                        : 'Ledger cash only'
                            }}
                        </p>
                        <p>
                            Selected contribution:
                            <CurrencyDisplay
                                :amount="
                                    account.net_worth_contribution?.amount ??
                                    null
                                "
                                :currency="account.currency"
                            />
                        </p>
                        <Link
                            :href="
                                account.portfolio_id
                                    ? '/portfolio/' + account.portfolio_id
                                    : '/portfolio'
                            "
                            class="inline-flex min-h-10 items-center underline"
                            >{{
                                account.portfolio_id
                                    ? 'Review portfolio evidence'
                                    : 'Set up portfolio mode'
                            }}</Link
                        >
                    </div>
                    <p
                        v-if="!account.included_in_net_worth"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        Internal account · excluded from net worth
                    </p>
                </li>
            </ul>
        </section>
        <form
            @submit.prevent="submit"
            class="space-y-5 rounded-lg border p-6"
            aria-labelledby="create-account-heading"
        >
            <h2 id="create-account-heading" class="text-lg font-medium">
                Create account
            </h2>
            <div class="grid gap-2">
                <Label for="account-name">Account name</Label>
                <Input
                    id="account-name"
                    v-model="form.name"
                    required
                    maxlength="120"
                    :aria-invalid="Boolean(form.errors.name)"
                    :aria-describedby="
                        form.errors.name ? 'account-name-error' : undefined
                    "
                />
                <InputError
                    id="account-name-error"
                    :message="form.errors.name"
                />
            </div>
            <div class="grid gap-2">
                <Label for="account-type">Account type</Label>
                <select
                    id="account-type"
                    v-model="form.type"
                    class="rounded border bg-background p-2"
                    :aria-invalid="Boolean(form.errors.type)"
                    :aria-describedby="
                        form.errors.type ? 'account-type-error' : undefined
                    "
                >
                    <option v-for="type in types" :key="type" :value="type">
                        {{ type.replaceAll('_', ' ') }}
                    </option>
                </select>
                <InputError
                    id="account-type-error"
                    :message="form.errors.type"
                />
            </div>
            <div class="grid gap-2">
                <Label for="account-currency">Currency</Label>
                <select
                    id="account-currency"
                    v-model="form.currency_code"
                    class="rounded border bg-background p-2"
                    :aria-invalid="Boolean(form.errors.currency_code)"
                    :aria-describedby="
                        form.errors.currency_code
                            ? 'account-currency-error'
                            : undefined
                    "
                >
                    <option
                        v-for="currency in currencies"
                        :key="currency.code"
                        :value="currency.code"
                    >
                        {{ currency.code }} ({{ currency.exponent }} decimal
                        places)
                    </option>
                </select>
                <InputError
                    id="account-currency-error"
                    :message="form.errors.currency_code"
                />
            </div>
            <p class="text-sm text-muted-foreground">
                Type and currency cannot change after creation. Debt is
                negative; an overpayment or credit is positive. Internal
                accounts do not contribute to net worth.
            </p>
            <div class="grid gap-2">
                <Label for="opening-amount">Opening amount (optional)</Label>
                <Input
                    id="opening-amount"
                    v-model="form.opening_balance"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    :aria-invalid="Boolean(form.errors.opening_balance)"
                    :aria-describedby="
                        form.errors.opening_balance
                            ? 'opening-help opening-error'
                            : 'opening-help'
                    "
                />
                <p id="opening-help" class="text-sm text-muted-foreground">
                    Saving this amount records an unposted request. It does not
                    create a transaction or change your balance. Use a minus
                    sign for debt.
                </p>
                <InputError
                    id="opening-error"
                    :message="form.errors.opening_balance"
                />
            </div>
            <Button type="submit" :disabled="form.processing"
                >Create account</Button
            >
        </form>
    </section>
</template>
