<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import PortfolioShell from '@/components/PortfolioShell.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import PortfolioValue from '@/components/PortfolioValue.vue';
import { Button } from '@/components/ui/button';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import type { Contribution, PortfolioAccount } from '@/types/portfolio';
import type { ExactMoney } from '@/types/accounts';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Portfolio', href: '/portfolio' },
            { title: 'Reconciliation preview', href: '#' },
        ],
    },
});
const props = defineProps<{
    account: PortfolioAccount;
    preview: {
        id: string;
        reference: string;
        expires_at: string;
        business_date: string;
        stale: boolean;
        confirmed_id: string | null;
        payload: { platform_label: string; source: string; reason: string };
        evidence: {
            before: Contribution;
            after: Contribution;
            difference_minor: string | null;
            ledger_cash: ExactMoney | null;
            position_count: number;
        };
    };
}>();
const { form, feedback, recovered, locked, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) +
        ':confirm:' +
        props.account.portfolio_id +
        ':' +
        props.preview.id,
    { preview_id: props.preview.id, acknowledge: '0' },
);
</script>
<template>
    <PortfolioShell
        title="Review what will count."
        :back="'/portfolio/' + account.portfolio_id"
        ><template #intro
            >{{ account.name }} · preview for UTC {{ preview.business_date }}.
            Confirming records one valuation decision; it never posts cash or
            edits opening positions.</template
        >
        <div class="grid gap-5 md:grid-cols-2">
            <section class="space-y-3">
                <h2 class="text-sm font-medium">Before confirmation</h2>
                <PortfolioValue :value="preview.evidence.before" />
            </section>
            <section class="space-y-3">
                <h2 class="text-sm font-medium">After confirmation</h2>
                <PortfolioValue :value="preview.evidence.after" />
            </section>
        </div>
        <section class="rounded-2xl border bg-card p-5 sm:p-7">
            <h2 class="text-lg font-semibold">Reconciliation evidence</h2>
            <dl class="mt-5 grid gap-5 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Difference in native minor units
                    </dt>
                    <dd class="mt-1 font-mono [overflow-wrap:anywhere]">
                        {{
                            preview.evidence.difference_minor ??
                            'Unknown · an incomplete value cannot be subtracted'
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Retained ledger cash
                    </dt>
                    <dd class="mt-1">
                        <CurrencyDisplay
                            v-if="preview.evidence.ledger_cash"
                            :amount="preview.evidence.ledger_cash.amount"
                            :currency="account.currency"
                        /><span v-else>Unavailable</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Retained positions
                    </dt>
                    <dd class="mt-1">{{ preview.evidence.position_count }}</dd>
                </div>
            </dl>
            <p class="mt-5 text-sm [overflow-wrap:anywhere]">
                Label: {{ preview.payload.platform_label }} · Source:
                {{ preview.payload.source }}
            </p>
            <p class="mt-2 text-sm [overflow-wrap:anywhere]">
                Reason: {{ preview.payload.reason }}
            </p>
            <p class="mt-3 text-xs text-muted-foreground">
                Expires {{ preview.expires_at }}. Source changes, archiving or a
                UTC date change require a new preview.
            </p>
        </section>
        <form
            class="max-w-3xl rounded-2xl border bg-card p-5 sm:p-7"
            @submit.prevent="
                submit('/portfolio/' + account.portfolio_id + '/confirm')
            "
        >
            <PortfolioAttempt
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                :recovered="recovered"
                :history="'/portfolio/' + account.portfolio_id"
            />
            <div
                v-if="preview.confirmed_id && !locked"
                role="status"
                class="mb-5 rounded-xl bg-teal-50 p-4 text-sm text-teal-950 dark:bg-teal-950 dark:text-teal-100"
            >
                This preview is already confirmed.
                <Link
                    :href="'/portfolio/' + account.portfolio_id"
                    class="underline"
                    >View the saved valuation decision</Link
                >.
            </div>
            <div
                v-else-if="preview.stale && !locked"
                role="status"
                class="mb-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100"
            >
                This preview has expired or its source changed.
                <Link
                    :href="'/portfolio/' + account.portfolio_id + '/mode'"
                    class="underline"
                    >Prepare a fresh preview</Link
                >
                before confirming.
            </div>
            <label
                class="flex min-h-11 items-start gap-3 text-sm leading-relaxed"
                ><input
                    v-model="form.acknowledge"
                    type="checkbox"
                    true-value="1"
                    false-value="0"
                    class="mt-1 size-4 shrink-0"
                    :disabled="
                        form.processing ||
                        locked ||
                        preview.stale ||
                        !!preview.confirmed_id
                    "
                />I reviewed the included and excluded evidence. I understand
                that only the selected method contributes to net worth, and
                incomplete values remain unavailable.</label
            >
            <div class="mt-6 flex flex-wrap gap-3">
                <Button
                    type="submit"
                    :disabled="
                        form.processing ||
                        (!locked &&
                            (form.acknowledge !== '1' ||
                                preview.stale ||
                                !!preview.confirmed_id))
                    "
                    >{{
                        form.processing
                            ? 'Confirming…'
                            : locked
                              ? 'Retry same confirmation'
                              : 'Confirm valuation decision'
                    }}</Button
                ><Button as-child variant="outline"
                    ><Link :href="'/portfolio/' + account.portfolio_id">{{
                        preview.confirmed_id
                            ? 'Back to account'
                            : 'Cancel without changing mode'
                    }}</Link></Button
                >
            </div>
        </form>
    </PortfolioShell>
</template>
