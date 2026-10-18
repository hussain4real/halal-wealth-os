<script setup lang="ts">
import { ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import PortfolioShell from '@/components/PortfolioShell.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import type { PortfolioAccount } from '@/types/portfolio';
import type { InvestmentEvent } from '@/types/investment';
import { eventLabels } from '@/types/investment';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Portfolio', href: '/portfolio' },
            { title: 'Retained receipt', href: '#' },
        ],
    },
});
const props = defineProps<{
    account: PortfolioAccount;
    event: InvestmentEvent;
}>();
const base = '/portfolio/' + props.account.portfolio_id + '/events';
const { form, feedback, locked, recovered, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) +
        ':investment-reverse:' +
        props.account.portfolio_id +
        ':' +
        props.event.id,
    { reason: '' },
);
const reviewing = ref(false);
</script>
<template>
    <PortfolioShell :title="eventLabels[event.kind] + ' receipt.'" :back="base"
        ><template #intro
            >{{ account.name }} · {{ event.currency }} · retained source version
            {{ event.asset_version }}. {{ event.reference }}</template
        >
        <section class="max-w-4xl rounded-2xl border bg-card p-5 sm:p-7">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold [overflow-wrap:anywhere]">
                        {{ event.asset_name }}
                    </h2>
                    <p class="mt-2 text-sm text-muted-foreground">
                        UTC {{ event.effective_date }} ·
                        {{ event.action_reference }}
                    </p>
                </div>
                <span class="rounded-full bg-muted px-3 py-1 text-xs">{{
                    event.reversed_by
                        ? 'Reversed · retained'
                        : event.kind === 'reversal'
                          ? 'Linked cancellation'
                          : 'Recorded evidence'
                }}</span>
            </div>
            <dl class="mt-7 grid gap-6 sm:grid-cols-2">
                <div
                    v-for="[label, value] in [
                        ['Recorded amount', event.amount],
                        ['Fees', event.fee],
                        ['Tax withheld', event.tax],
                        ['Posted cash effect', event.cash_effect],
                    ] as const"
                    :key="label"
                >
                    <dt class="text-sm text-muted-foreground">{{ label }}</dt>
                    <dd
                        class="mt-2 text-xl font-semibold [overflow-wrap:anywhere]"
                    >
                        <CurrencyDisplay
                            :amount="value.amount"
                            :currency="event.currency"
                        />
                    </dd>
                </div>
                <div v-if="event.quantity">
                    <dt class="text-sm text-muted-foreground">Exact units</dt>
                    <dd class="mt-2 font-mono break-all">
                        {{ event.quantity }}
                    </dd>
                </div>
                <div v-if="event.unit_price">
                    <dt class="text-sm text-muted-foreground">
                        Recorded execution price · not a quote
                    </dt>
                    <dd class="mt-2 font-mono break-all">
                        {{ event.unit_price }} {{ event.currency }}
                    </dd>
                </div>
                <div v-if="event.split_numerator">
                    <dt class="text-sm text-muted-foreground">
                        Split ratio · new : old
                    </dt>
                    <dd class="mt-2 font-mono break-all">
                        {{ event.split_numerator }} :
                        {{ event.split_denominator }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-muted-foreground">
                        Evidence and reason
                    </dt>
                    <dd class="mt-2 break-words">
                        {{ event.source }} · {{ event.reason }}
                    </dd>
                </div>
            </dl>
            <div class="mt-6 flex flex-wrap gap-4 text-sm">
                <Link
                    v-if="event.journal_id"
                    class="underline"
                    :href="'/transactions/' + event.journal_id"
                    >Linked cash journal</Link
                ><Link
                    v-if="event.subscription_id"
                    class="underline"
                    :href="base + '/' + event.subscription_id"
                    >Application receipt</Link
                ><Link
                    v-if="event.reverses_id"
                    class="underline"
                    :href="base + '/' + event.reverses_id"
                    >Cancelled original</Link
                ><Link
                    v-if="event.reversed_by"
                    class="underline"
                    :href="base + '/' + event.reversed_by"
                    >Cancellation receipt</Link
                ><Link
                    v-if="event.replacement_id"
                    class="underline"
                    :href="base + '/' + event.replacement_id"
                    >Replacement receipt</Link
                >
            </div>
        </section>
        <p class="max-w-4xl rounded-xl bg-muted/40 p-4 text-sm leading-relaxed">
            Holdings and realized book results rebuild from all applicable
            events and opening evidence. This receipt retains its original
            facts. Execution prices and book cost do not supply a current
            valuation. Current book results are on the activity page.
        </p>
        <form
            v-if="
                (!event.reversed_by &&
                    event.kind !== 'reversal' &&
                    !account.archived) ||
                locked
            "
            class="max-w-4xl space-y-5 rounded-2xl border p-5 sm:p-7"
            @submit.prevent="
                reviewing || locked
                    ? submit(base + '/' + event.id + '/reverse')
                    : (reviewing = true)
            "
        >
            <h2 class="text-lg font-semibold">Correct the retained history</h2>
            <PortfolioAttempt
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                :recovered="recovered"
                :history="base"
            />
            <p class="text-sm leading-relaxed text-muted-foreground">
                Linked cancellation reverses this fact and its cash at the
                original UTC business date. It cannot erase a paid receivable or
                leave a dependent sale unsupported. The entire replay must
                remain valid before anything saves.
            </p>
            <fieldset :disabled="locked || form.processing">
                <Label for="reason">Cancellation reason</Label
                ><Input
                    id="reason"
                    :aria-invalid="!!form.errors.reason"
                    v-model="form.reason"
                    required
                    maxlength="180"
                />
            </fieldset>
            <p
                v-if="reviewing || locked"
                role="status"
                class="rounded-xl bg-muted/40 p-4 text-sm"
            >
                Confirm cancellation of {{ eventLabels[event.kind] }} ·
                {{ event.asset_name }} · UTC {{ event.effective_date }}. Its
                complete original evidence remains.
            </p>
            <div class="flex flex-wrap gap-3">
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="form.processing"
                    >{{
                        form.processing
                            ? 'Saving…'
                            : locked
                              ? 'Retry exact cancellation'
                              : reviewing
                                ? 'Confirm linked cancellation'
                                : 'Review cancellation'
                    }}</Button
                ><Button
                    v-if="!locked && !reviewing && event.kind !== 'ipo_intent'"
                    as-child
                    variant="ghost"
                    ><Link :href="base + '/new?replace=' + event.id"
                        >Record atomic correction</Link
                    ></Button
                >
            </div>
        </form>
    </PortfolioShell>
</template>
