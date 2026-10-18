<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Account } from '@/types/accounts';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Accounts', href: '/accounts' }] },
});
const props = defineProps<{ account: Account }>();
const form = useForm({ name: props.account.name });
const archive = () => router.patch(`/accounts/${props.account.id}/archive`);
</script>

<template>
    <Head :title="account.name" />
    <section class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-6">
        <Link href="/accounts" class="underline focus-visible:outline-2"
            >All accounts</Link
        >
        <header>
            <h1 class="text-2xl font-semibold">{{ account.name }}</h1>
            <p class="mt-2">
                {{ account.type.replaceAll('_', ' ') }} ·
                {{ account.currency }} ·
                {{ account.archived ? 'Archived' : 'Active' }}
            </p>
        </header>
        <section
            aria-labelledby="balance-heading"
            class="rounded-lg border p-6"
        >
            <h2 id="balance-heading" class="text-lg font-medium">
                Posted balance
            </h2>
            <p class="mt-2 text-xl">
                <CurrencyDisplay
                    :amount="account.balance?.amount ?? null"
                    :currency="account.currency"
                />
            </p>
            <p
                v-if="account.balance_status === 'unavailable'"
                class="mt-2 text-muted-foreground"
            >
                No posted balance is available. An opening request is not a
                posted transaction.
            </p>
            <p class="mt-2 text-sm">
                Debt is negative; overpayment is positive. Balances cannot be
                edited.
            </p>
            <p v-if="!account.included_in_net_worth" class="mt-2 text-sm">
                Internal account · excluded from net worth
            </p>
        </section>
        <section
            v-if="account.opening_request"
            aria-labelledby="opening-heading"
            class="rounded-lg border p-6"
        >
            <h2 id="opening-heading" class="text-lg font-medium">
                Opening request · Awaiting posting
            </h2>
            <p class="mt-2">
                <CurrencyDisplay
                    :amount="account.opening_request.amount.amount"
                    :currency="account.currency"
                />
            </p>
            <p class="mt-2 text-sm text-muted-foreground">
                This saved request has not changed your balance or net worth.
            </p>
        </section>
        <p v-if="account.archived" role="status">
            This account is archived. Its details and opening request are
            retained.
        </p>
        <template v-else>
            <form
                @submit.prevent="form.patch(`/accounts/${account.id}`)"
                class="space-y-3"
            >
                <Label for="rename-account">Account name</Label>
                <Input
                    id="rename-account"
                    v-model="form.name"
                    required
                    maxlength="120"
                    :aria-invalid="Boolean(form.errors.name)"
                    :aria-describedby="
                        form.errors.name ? 'rename-error' : undefined
                    "
                />
                <InputError id="rename-error" :message="form.errors.name" />
                <Button type="submit" :disabled="form.processing"
                    >Save name</Button
                >
            </form>
            <div>
                <p class="mb-3 text-sm text-muted-foreground">
                    Archiving retains this account and its history. It prevents
                    further changes.
                </p>
                <Button type="button" variant="secondary" @click="archive"
                    >Archive account</Button
                >
            </div>
        </template>
    </section>
</template>
