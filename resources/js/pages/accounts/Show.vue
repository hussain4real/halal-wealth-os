<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
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
const page = usePage();
const opening = useForm(
    `opening:${page.props.auth.user.id}:${props.account.id}`,
    { idempotency_key: crypto.randomUUID() },
);
const archive = () => router.patch(`/accounts/${props.account.id}/archive`);
const openingUncertain = ref(false);
const openingUnknown = () => {
    openingUncertain.value = true;
    return false;
};
const postOpening = () => {
    openingUncertain.value = false;
    opening.post(`/accounts/${props.account.id}/opening`, {
        onNetworkError: openingUnknown,
        onHttpException: openingUnknown,
    });
};
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
                {{
                    account.balance_reason === 'out_of_range'
                        ? 'The exact derived total exceeds the supported display range. No amount has been truncated.'
                        : 'The opening request is still awaiting posting. Your complete balance is not yet available.'
                }}
            </p>
            <p class="mt-2 text-sm">
                Debt is negative; overpayment is positive. Balances cannot be
                edited.
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
                        :amount="account.net_worth_contribution?.amount ?? null"
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
                Opening request ·
                {{
                    account.opening_request.status === 'awaiting_posting'
                        ? 'Awaiting posting'
                        : account.opening_request.status === 'reversed'
                          ? 'Reversed'
                          : 'Posted'
                }}
            </h2>
            <p class="mt-2">
                <CurrencyDisplay
                    :amount="account.opening_request.amount.amount"
                    :currency="account.currency"
                />
            </p>
            <p class="mt-2 text-sm text-muted-foreground">
                {{
                    account.opening_request.status === 'awaiting_posting'
                        ? 'This saved request has not changed your balance or net worth. Post it explicitly when you are ready.'
                        : 'The immutable request is retained and linked to its journal history.'
                }}
            </p>
            <Link
                v-if="account.opening_request.journal_id"
                :href="`/transactions/${account.opening_request.journal_id}`"
                class="mt-4 inline-block underline"
                >View opening history</Link
            >
            <form
                v-else-if="!account.archived"
                class="mt-4 space-y-3"
                @submit.prevent="postOpening"
            >
                <p v-if="openingUncertain" role="alert" class="text-sm">
                    We could not confirm the result. Check history or retry this
                    same request safely; its identity is kept.
                    <Link href="/transactions" class="underline"
                        >Check history</Link
                    >
                </p>
                <InputError
                    v-for="(error, key) in opening.errors"
                    :key="key"
                    :message="error"
                /><Button
                    type="submit"
                    class="min-h-11"
                    :disabled="opening.processing"
                    >{{
                        opening.processing ? 'Posting…' : 'Post opening balance'
                    }}</Button
                >
            </form>
        </section>
        <p v-if="account.archived" role="status">
            This account is archived. Its details and opening request are
            retained.
        </p>
        <p v-else-if="account.system" class="text-sm text-muted-foreground">
            This internal account is maintained by the posting service and
            cannot be changed here.
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
