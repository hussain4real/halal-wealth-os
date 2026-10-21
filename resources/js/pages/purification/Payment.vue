<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PurificationShell from '@/components/PurificationShell.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
const props = defineProps<{
    accounts: { id: string; name: string; currency: string }[];
    today: string;
}>();
const { form, feedback, locked, recovered, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) + ':purification-payment',
    {
        mode: 'link',
        payment_reference: '',
        journal_id: '',
        account_id: '',
        amount: '',
        effective_date: '',
        description: '',
        evidence: '',
        reason: '',
    },
);
const reviewing = ref(false);
const fixed = computed(
    () => locked.value || form.processing || reviewing.value,
);
watch(
    () => form.mode,
    (v) => {
        if (!locked.value) {
            if (v === 'link') {
                form.account_id = '';
                form.amount = '';
                form.effective_date = '';
                form.description = '';
            } else {
                form.journal_id = '';
                form.effective_date = props.today;
            }
        }
    },
);
watch(
    () => form.hasErrors,
    (v) => {
        if (v) reviewing.value = false;
    },
);
function proceed() {
    if (locked.value || reviewing.value) submit('/purification/payments');
    else reviewing.value = true;
}
const sourceFields = [
    { key: 'payment_reference', label: 'Unique payment / receipt reference' },
    { key: 'evidence', label: 'Retained charity payment evidence' },
    { key: 'reason', label: 'Reason for recording this charity attribution' },
];
</script>
<template>
    <PurificationShell title="Record a charity payment already made."
        ><template #intro
            >Link an existing expense without posting it again, or record a
            manual expense for a payment you already made. This application
            never sends a transfer. Allocations are recorded separately against
            the saved payment.</template
        ><a
            href="/transactions"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-flex min-h-11 items-center underline"
            >Check existing transactions in a new tab before recording</a
        >
        <form class="max-w-3xl space-y-6" @submit.prevent="proceed">
            <PortfolioAttempt
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                :recovered="recovered"
                history="/purification"
            />
            <fieldset
                :disabled="fixed"
                class="space-y-5 rounded-xl border bg-card p-5"
            >
                <div>
                    <Label for="mode"
                        >How is this actual payment recorded?</Label
                    ><select
                        id="mode"
                        v-model="form.mode"
                        class="mt-2 min-h-11 w-full rounded-md border bg-background px-3"
                    >
                        <option value="link">
                            Link an expense journal already recorded · no new
                            debit
                        </option>
                        <option value="record">
                            Record a payment already made · posts one manual
                            expense
                        </option>
                    </select>
                </div>
                <div v-if="form.mode === 'link'">
                    <Label for="journal_id"
                        >Existing personal expense journal ID</Label
                    ><Input
                        id="journal_id"
                        v-model="form.journal_id"
                        required
                        inputmode="numeric"
                        maxlength="19"
                        class="mt-2"
                    />
                    <p class="mt-2 text-sm text-muted-foreground">
                        Use the journal ID in its transaction URL. Transfers,
                        reversals, FX members and investment fees cannot be
                        attributed here.
                    </p>
                </div>
                <template v-else
                    ><div>
                        <Label for="account_id">Account actually debited</Label
                        ><select
                            id="account_id"
                            v-model="form.account_id"
                            required
                            class="mt-2 min-h-11 w-full rounded-md border bg-background px-3"
                        >
                            <option value="">Choose the actual account</option>
                            <option
                                v-for="a in accounts"
                                :key="a.id"
                                :value="a.id"
                            >
                                {{ a.name }} · {{ a.currency }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <Label for="amount"
                            >Actual amount · account's native currency</Label
                        ><Input
                            id="amount"
                            v-model="form.amount"
                            required
                            inputmode="decimal"
                            maxlength="32"
                            class="mt-2"
                        />
                    </div>
                    <div>
                        <Label for="effective_date"
                            >Actual payment date · UTC</Label
                        ><Input
                            id="effective_date"
                            v-model="form.effective_date"
                            required
                            type="date"
                            :max="today"
                            class="mt-2"
                        />
                    </div>
                    <div>
                        <Label for="description">Expense description</Label
                        ><Input
                            id="description"
                            v-model="form.description"
                            required
                            maxlength="180"
                            class="mt-2"
                        /></div
                ></template>
                <div v-for="f in sourceFields" :key="f.key">
                    <Label :for="f.key">{{ f.label }}</Label
                    ><Input
                        :id="f.key"
                        v-model="form[f.key]"
                        required
                        maxlength="180"
                        class="mt-2"
                    />
                </div>
            </fieldset>
            <section
                v-if="reviewing"
                class="space-y-3 rounded-xl border border-teal-700 p-5"
                aria-live="polite"
            >
                <h2 class="text-lg font-semibold">
                    Review the actual payment record
                </h2>
                <p>{{ form.payment_reference }} · {{ form.evidence }}</p>
                <p v-if="form.mode === 'link'">
                    Existing journal {{ form.journal_id }} is attributed as
                    charity. Its cash amount remains unchanged; no new debit
                    occurs.
                </p>
                <p v-else>
                    This records one actual expense of {{ form.amount }} in
                    account {{ form.account_id }} on {{ form.effective_date }}.
                    Confirm it is not already in your transactions. No transfer
                    will be sent.
                </p>
            </section>
            <div class="flex flex-wrap gap-3">
                <Button type="submit" :disabled="form.processing">{{
                    locked
                        ? 'Retry exact saved attempt'
                        : reviewing
                          ? form.mode === 'link'
                              ? 'Confirm link · no new debit'
                              : 'Confirm actual expense record'
                          : 'Review payment record'
                }}</Button
                ><Button
                    v-if="reviewing && !locked"
                    type="button"
                    variant="outline"
                    @click="reviewing = false"
                    >Edit details</Button
                >
            </div>
        </form></PurificationShell
    >
</template>
