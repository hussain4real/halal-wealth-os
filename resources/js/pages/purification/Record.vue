<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import PurificationShell from '@/components/PurificationShell.vue';
import PurificationRelease from '@/components/PurificationRelease.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import { purificationAttemptScope } from '@/composables/purificationAttemptScope';
import type {
    Obligation,
    Payment,
    Allocation,
    Distribution,
} from '@/types/purification';
import { statusLabels } from '@/types/purification';
const props = defineProps<{
    obligation: Obligation;
    requested_id: string;
    distribution: Distribution;
    history: { items: Obligation[]; next: string | null };
    allocations: Allocation[];
    payments: Payment[];
}>();
const { form, feedback, locked, recovered, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) +
        ':' +
        purificationAttemptScope('allocation', props.obligation.series_id),
    {
        obligation_id: props.obligation.id,
        payment_id: '',
        amount: '',
        reason: '',
    },
);
const reviewing = ref(false);
const fixed = computed(
    () => locked.value || form.processing || reviewing.value,
);
const allocationBlocked = computed(
    () =>
        !locked.value &&
        (!props.obligation.due ||
            props.obligation.source_changed ||
            !props.obligation.outstanding ||
            props.obligation.outstanding.minor === '0'),
);
watch(
    () => form.hasErrors,
    (v) => {
        if (v) reviewing.value = false;
    },
);
watch(
    () => form.wasSuccessful,
    (successful) => {
        if (successful) reviewing.value = false;
    },
);
function proceed() {
    if (form.processing || allocationBlocked.value) return;
    if (locked.value || reviewing.value) submit('/purification/allocations');
    else reviewing.value = true;
}
</script>
<template>
    <PurificationShell
        :title="
            obligation.method_reference +
            ' · ' +
            statusLabels[obligation.status]
        "
        ><template #intro
            >Current instruction v{{ obligation.version }} ·
            {{ obligation.method_version ?? 'method version missing' }}. Every
            correction and linked payment remains in the audit
            history.</template
        >
        <p v-if="requested_id !== obligation.id" class="rounded-xl border p-4">
            You opened an earlier version. Current amounts below use v{{
                obligation.version
            }}; the original version remains in history.
        </p>
        <p
            v-if="obligation.source_changed"
            role="alert"
            class="rounded-xl border border-amber-500 p-5"
        >
            The source distribution was reversed or replaced. These retained
            amounts require review. Revise against current distribution evidence
            before a new allocation.
        </p>
        <section class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border bg-card p-5">
                <p class="text-sm text-muted-foreground">Sourced obligation</p>
                <p class="mt-2 text-2xl font-semibold">
                    {{
                        obligation.due
                            ? obligation.currency + ' ' + obligation.due.amount
                            : 'Pending'
                    }}
                </p>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <p class="text-sm text-muted-foreground">
                    Active linked payments
                </p>
                <p class="mt-2 text-2xl font-semibold">
                    {{ obligation.currency }} {{ obligation.paid.amount }}
                </p>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <p class="text-sm text-muted-foreground">Outstanding</p>
                <p class="mt-2 text-2xl font-semibold">
                    {{
                        obligation.outstanding
                            ? obligation.currency +
                              ' ' +
                              obligation.outstanding.amount
                            : 'Unknown'
                    }}
                </p>
            </div>
        </section>
        <p v-if="obligation.pending_explanation" role="status">
            {{ obligation.pending_explanation }}
        </p>
        <p
            v-if="obligation.excess.minor !== '0'"
            class="rounded-xl border border-amber-500 p-5"
        >
            Excess linked {{ obligation.currency }}
            {{ obligation.excess.amount }} after correction. No refund or
            reallocation is assumed. Payment capacity remains used until an
            explicit allocation release.
        </p>
        <section class="space-y-3 rounded-xl border bg-card p-5">
            <h2 class="text-xl font-semibold">
                Retained calculation and evidence
            </h2>
            <p>
                Base: {{ obligation.base_kind }} ·
                {{
                    obligation.base
                        ? obligation.currency + ' ' + obligation.base.amount
                        : 'missing'
                }}
                · {{ obligation.base_explanation }}
            </p>
            <p>
                Instruction: {{ obligation.calculation }} ·
                {{
                    obligation.rate_percent !== null
                        ? obligation.rate_percent + '%'
                        : obligation.fixed
                          ? obligation.currency + ' ' + obligation.fixed.amount
                          : 'value missing'
                }}
            </p>
            <p class="whitespace-pre-wrap">
                {{ obligation.source_instruction ?? 'Instruction missing' }}
            </p>
            <p>
                Recorded {{ obligation.recorded_at }} · Actor
                {{ obligation.actor_id }} · {{ obligation.reason }}
            </p>
            <p v-if="obligation.assessment">
                Source assessment: {{ obligation.assessment.provider }} · v{{
                    obligation.assessment.version
                }}
                · {{ obligation.assessment.classification }} · review
                {{ obligation.assessment.review_due_on ?? 'date missing'
                }}<span v-if="obligation.assessment.overdue"> · overdue</span
                ><span v-if="obligation.assessment_superseded">
                    · a newer assessment exists; this instruction retains its
                    selected version</span
                >
            </p>
            <nav class="flex flex-wrap gap-4 text-sm underline">
                <a
                    :href="
                        '/portfolio/' +
                        distribution.portfolio_account_id +
                        '/events/' +
                        distribution.id
                    "
                    target="_blank"
                    rel="noopener noreferrer"
                    class="min-h-11 content-center"
                    >Read exact distribution in a new tab</a
                ><a
                    v-if="obligation.assessment"
                    :href="
                        '/assets/' +
                        distribution.asset_id +
                        '/assessments/' +
                        obligation.assessment.id
                    "
                    target="_blank"
                    rel="noopener noreferrer"
                    class="min-h-11 content-center"
                    >Read retained source version in a new tab</a
                ><Link
                    :href="
                        '/purification/obligations/' + obligation.id + '/revise'
                    "
                    class="min-h-11 content-center"
                    >Revise sourced instruction</Link
                >
            </nav>
        </section>
        <section class="space-y-4 rounded-xl border p-5">
            <h2 class="text-xl font-semibold">Allocate an actual payment</h2>
            <p class="text-sm text-muted-foreground">
                Allocation moves no cash. Use a same-currency charity payment
                already recorded. Both actual payment capacity and known
                outstanding are checked against complete history.
            </p>
            <a
                href="/purification/payments/new"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex min-h-11 items-center underline"
                >Record or link a payment already made in a new tab</a
            >
            <p v-if="obligation.outstanding?.minor === '0'" role="status">
                No amount is outstanding. New allocations are unavailable.
                <span v-if="locked">
                    Retry the exact saved allocation below to recover its
                    receipt.
                </span>
            </p>
            <form class="max-w-2xl space-y-4" @submit.prevent="proceed">
                <PortfolioAttempt
                    :errors="form.errors"
                    :uncertain="feedback.uncertain.value"
                    :recovered="recovered"
                    :history="'/purification/obligations/' + obligation.id"
                />
                <fieldset
                    :disabled="fixed || allocationBlocked"
                    class="space-y-4"
                >
                    <div>
                        <Label for="payment_id">Recorded charity payment</Label
                        ><select
                            id="payment_id"
                            v-model="form.payment_id"
                            class="mt-2 min-h-11 w-full rounded-md border bg-background px-3"
                        >
                            <option value="">Choose a recorded payment</option>
                            <option
                                v-for="p in payments"
                                :key="p.id"
                                :value="p.id"
                                :disabled="
                                    p.journal_status !== 'posted' ||
                                    p.available.minor === '0'
                                "
                            >
                                {{ p.payment_reference }} · {{ p.currency }}
                                {{ p.available.amount }} available ·
                                {{ p.journal_status }}
                            </option>
                        </select>
                        <div class="mt-4">
                            <Label for="payment-id-direct"
                                >Payment ID · including older saved
                                records</Label
                            ><Input
                                id="payment-id-direct"
                                v-model="form.payment_id"
                                required
                                inputmode="numeric"
                                maxlength="19"
                                class="mt-2"
                            />
                            <p class="mt-2 text-sm text-muted-foreground">
                                Find the ID on the saved payment receipt or
                                complete audit export. The server verifies
                                ownership, native currency and unused actual
                                amount.
                            </p>
                        </div>
                        <p class="mt-2 text-sm text-muted-foreground">
                            Latest 100 same-currency payment records. Reload
                            this page after recording a new payment; retained
                            exact attempts remain recoverable.
                        </p>
                    </div>
                    <div>
                        <Label for="allocation-amount"
                            >Allocation · {{ obligation.currency }}</Label
                        ><Input
                            id="allocation-amount"
                            v-model="form.amount"
                            required
                            maxlength="32"
                            inputmode="decimal"
                            class="mt-2"
                        />
                    </div>
                    <div>
                        <Label for="allocation-reason">Allocation reason</Label
                        ><Input
                            id="allocation-reason"
                            v-model="form.reason"
                            required
                            maxlength="180"
                            class="mt-2"
                        />
                    </div>
                </fieldset>
                <p
                    v-if="reviewing"
                    class="rounded-xl border border-teal-700 p-4"
                    aria-live="polite"
                >
                    Link {{ obligation.currency }} {{ form.amount }} from
                    payment {{ form.payment_id }} to obligation v{{
                        obligation.version
                    }}. No additional cash debit will occur.
                </p>
                <div class="flex flex-wrap gap-3">
                    <Button
                        type="submit"
                        :disabled="form.processing || allocationBlocked"
                        >{{
                            locked
                                ? 'Retry exact saved allocation'
                                : reviewing
                                  ? 'Confirm allocation · no cash movement'
                                  : 'Review allocation'
                        }}</Button
                    ><Button
                        v-if="reviewing && !locked"
                        type="button"
                        variant="outline"
                        @click="reviewing = false"
                        >Edit details</Button
                    >
                </div>
            </form>
        </section>
        <section class="space-y-4">
            <h2 class="text-xl font-semibold">Payment allocation history</h2>
            <p v-if="!allocations.length">No allocations recorded.</p>
            <article
                v-for="a in allocations"
                :key="a.id"
                class="rounded-xl border bg-card p-5"
            >
                <p>
                    {{ a.amount.currency }} {{ a.amount.amount }} → instruction
                    v{{ a.obligation_version }} ·
                    {{
                        a.active
                            ? 'active'
                            : a.release_id
                              ? 'released'
                              : 'payment journal reversed'
                    }}
                </p>
                <p class="mt-2 text-sm">
                    {{ a.recorded_at }} · Actor {{ a.actor_id }} ·
                    {{ a.reason }}
                </p>
                <Link
                    :href="'/purification/payments/' + a.payment_id"
                    class="inline-flex min-h-11 items-center underline"
                    >Open actual payment {{ a.payment_id }}</Link
                ><PurificationRelease
                    :released="!!a.release_id"
                    :allocation-id="a.id"
                    :obligation-id="obligation.id"
                />
            </article>
        </section>
        <section class="space-y-4">
            <h2 class="text-xl font-semibold">Instruction version history</h2>
            <article
                v-for="v in history.items"
                :key="v.id"
                class="rounded-xl border p-5"
            >
                <p class="font-semibold">
                    v{{ v.version }} ·
                    {{ v.method_version ?? 'version missing' }} ·
                    {{ v.due ? v.currency + ' ' + v.due.amount : 'pending' }}
                </p>
                <p>
                    Base {{ v.base_kind }} {{ v.base?.amount ?? 'missing' }} ·
                    {{ v.calculation }}
                    {{ v.rate_percent ?? v.fixed?.amount ?? 'missing' }}
                </p>
                <p class="mt-2 whitespace-pre-wrap">
                    {{ v.source_instruction }}
                </p>
                <p class="mt-2 text-sm">
                    {{ v.recorded_at }} · Actor {{ v.actor_id }} ·
                    {{ v.reason }} · Distribution {{ v.distribution_id }} ·
                    Assessment {{ v.assessment_id ?? 'missing' }}
                </p>
            </article>
            <Link
                v-if="history.next"
                :href="
                    '/purification/obligations/' +
                    obligation.id +
                    '?after=' +
                    history.next
                "
                class="inline-flex min-h-11 items-center underline"
                >Older instruction versions</Link
            >
        </section></PurificationShell
    >
</template>
