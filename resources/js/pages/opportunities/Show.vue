<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import DecisionShell from '@/components/DecisionShell.vue';
import DecisionRelease from '@/components/DecisionRelease.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import type {
    Decision,
    DecisionAssessment,
    DecisionReservation,
    DecisionLink,
} from '@/types/decisions';
import { decisionLabels } from '@/types/decisions';
const props = defineProps<{
    opportunity: Decision;
    current_id: string;
    is_current: boolean;
    assessment: DecisionAssessment;
    history: {
        id: string;
        version: string;
        name: string;
        reason: string;
        recorded_at: string;
    }[];
    history_next: string | null;
    reservations: DecisionReservation[];
    reservations_next: string | null;
    event_links: DecisionLink[];
    links_next: string | null;
    goals: { id: string; name: string; account_id: string }[];
}>();
const ownerScope =
    String(usePage().props.auth.user.id) + ':decision:' + props.opportunity.id;
const reserve = usePortfolioForm(ownerScope + ':reserve', {
    opportunity_id: props.opportunity.id,
    tranche_key: props.opportunity.tranches[0]?.key ?? '',
    goal_id: '',
    amount: '',
    reason: '',
});
const actual = usePortfolioForm(ownerScope + ':actual', {
    opportunity_id: props.opportunity.id,
    tranche_key: props.opportunity.tranches[0]?.key ?? '',
    event_id: '',
    reason: '',
});
const inactive = computed(
    () => !props.is_current || props.opportunity.status === 'closed',
);
const url = '/opportunities/' + props.opportunity.id;
const evidenceLabels = {
    status: 'Offer status',
    price: 'Offer price per unit',
    eligibility: 'Eligibility',
};
const thesisLabels = {
    rationale: 'Rationale',
    risks: 'Risks',
    horizon: 'Horizon',
    review_triggers: 'Review triggers',
};
</script>
<template>
    <DecisionShell :title="opportunity.name"
        ><template #intro
            ><span class="font-medium"
                >{{ decisionLabels[opportunity.status] }} · version
                {{ opportunity.version }}</span
            >
            · {{ opportunity.reference }}<br />Retained
            {{ opportunity.recorded_at }}. {{ opportunity.reason }}</template
        >
        <p
            v-if="!is_current"
            role="status"
            class="rounded-xl border border-amber-500 p-5"
        >
            You are viewing a retained thesis version. Reservations and actual
            links below reflect the complete decision series today.
            <Link :href="'/opportunities/' + current_id" class="underline"
                >Open current version →</Link
            >
        </p>
        <nav class="flex flex-wrap gap-4 text-sm underline">
            <Link
                v-if="is_current && opportunity.status !== 'closed'"
                :href="url + '/revise'"
                class="min-h-11 content-center"
                >Revise thesis</Link
            ><Link
                v-if="opportunity.asset_id"
                :href="'/assets/' + opportunity.asset_id + '/assessments'"
                class="min-h-11 content-center"
                >Exact asset Shariah evidence</Link
            ><a href="/opportunities/export" class="min-h-11 content-center"
                >Export complete history</a
            >
        </nav>
        <section
            class="grid gap-4 sm:grid-cols-3"
            aria-label="Allocation summary"
        >
            <div class="rounded-2xl border bg-card p-5">
                <p class="text-sm text-muted-foreground">Proposed target</p>
                <p class="mt-2 text-2xl font-semibold">
                    {{
                        opportunity.target
                            ? opportunity.currency +
                              ' ' +
                              opportunity.target.amount
                            : 'Unknown'
                    }}
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-5">
                <p class="text-sm text-muted-foreground">Maximum allocation</p>
                <p class="mt-2 text-2xl font-semibold">
                    {{
                        opportunity.maximum
                            ? opportunity.currency +
                              ' ' +
                              opportunity.maximum.amount
                            : 'Not set'
                    }}
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-5">
                <p class="text-sm text-muted-foreground">Thesis review</p>
                <p
                    class="mt-2 text-xl font-semibold"
                    :class="
                        opportunity.review_overdue
                            ? 'text-amber-800 dark:text-amber-200'
                            : ''
                    "
                >
                    {{ opportunity.attributes.review_on ?? 'Not scheduled' }}
                </p>
                <p v-if="opportunity.review_overdue" class="mt-1 text-sm">
                    Overdue · review the rationale
                </p>
            </div>
        </section>
        <section class="grid gap-4 md:grid-cols-2" aria-label="Retained thesis">
            <article
                v-for="(label, key) in thesisLabels"
                :key="key"
                class="rounded-2xl border p-5"
            >
                <h2 class="font-semibold">{{ label }}</h2>
                <p class="mt-3 text-sm leading-relaxed whitespace-pre-wrap">
                    {{ opportunity.attributes[key] ?? 'Not recorded' }}
                </p>
            </article>
        </section>
        <section class="space-y-4">
            <h2 class="text-xl font-semibold">Conditional tranches</h2>
            <p class="text-sm text-muted-foreground">
                Reservations are nominal protection claims. Current cash backing
                appears in reservation history. Gross attributed spend includes
                fees and tax. Actual events can exceed intent; a negative
                remainder needs review.
            </p>
            <p v-if="!opportunity.tranches.length">
                No tranches while the target is unknown.
            </p>
            <article
                v-for="t in opportunity.tranches"
                :key="t.key"
                class="rounded-2xl border bg-card p-5"
            >
                <h3 class="font-semibold">
                    {{ t.label }} · {{ opportunity.currency }}
                    {{ t.amount?.amount }}
                </h3>
                <p class="mt-2 text-sm">{{ t.condition }}</p>
                <dl class="mt-5 grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Reserved claim
                        </dt>
                        <dd class="mt-1 font-medium">
                            {{ opportunity.currency }} {{ t.reserved_amount }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Attributed gross spend
                        </dt>
                        <dd class="mt-1 font-medium">
                            {{ opportunity.currency }} {{ t.spent_amount }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Uncommitted proposal
                        </dt>
                        <dd class="mt-1 font-medium">
                            {{ opportunity.currency }} {{ t.remaining_amount }}
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="t.remaining_minor?.startsWith('-')"
                    class="mt-4 font-medium text-amber-800 dark:text-amber-200"
                >
                    Reserved claims and actual spend exceed this proposal.
                    Review the limit and release any protection no longer
                    needed.
                </p>
                <p class="mt-3 text-xs text-muted-foreground">
                    Stable tranche reference: {{ t.key }}
                </p>
            </article>
        </section>
        <section class="grid gap-4 lg:grid-cols-2">
            <article class="rounded-2xl border p-5">
                <h2 class="text-xl font-semibold">Currency concentration</h2>
                <p class="mt-3 font-medium">
                    {{ opportunity.concentration.state.replaceAll('_', ' ') }}
                </p>
                <p
                    v-if="opportunity.concentration.percent !== null"
                    class="mt-2"
                >
                    Projected {{ opportunity.concentration.percent }}% ·
                    threshold {{ opportunity.concentration.threshold }}%
                </p>
                <p class="mt-3 text-sm text-muted-foreground">
                    {{ opportunity.concentration.explanation }}
                </p>
                <p class="mt-3 text-xs text-muted-foreground">
                    Reference:
                    {{
                        opportunity.attributes.concentration.source ?? 'unknown'
                    }}
                    · observed
                    {{
                        opportunity.attributes.concentration.on ??
                        'not recorded'
                    }}
                    · review by
                    {{
                        opportunity.attributes.concentration.review_on ??
                        'not set'
                    }}
                </p>
            </article>
            <article class="rounded-2xl border p-5">
                <h2 class="text-xl font-semibold">Shariah review</h2>
                <p class="mt-3 font-medium">
                    {{
                        assessment?.summary.guidance_state.replaceAll(
                            '_',
                            ' ',
                        ) ?? 'Pending review · exact asset unidentified'
                    }}
                </p>
                <p class="mt-3 text-sm text-muted-foreground">
                    {{
                        assessment?.summary.guidance ??
                        'No compliance classification is inferred from a watchlist entry or offer source.'
                    }}
                </p>
            </article>
        </section>
        <section class="space-y-4">
            <h2 class="text-xl font-semibold">Offer evidence</h2>
            <div class="grid gap-4 md:grid-cols-3">
                <article
                    v-for="(label, key) in evidenceLabels"
                    :key="key"
                    class="rounded-2xl border p-5"
                >
                    <h3 class="font-semibold">{{ label }}</h3>
                    <p class="mt-3">
                        {{
                            opportunity.attributes.evidence[key].value ??
                            'Unknown'
                        }}
                    </p>
                    <p class="mt-2 text-sm font-medium">
                        {{
                            opportunity.attributes.evidence[key].verified
                                ? 'Owner checked official source · in review window'
                                : 'Unverified · evidence missing or due for review'
                        }}
                    </p>
                    <p class="mt-3 text-xs text-muted-foreground">
                        Checked
                        {{
                            opportunity.attributes.evidence[key].verified_on ??
                            'not recorded'
                        }}
                        · review by
                        {{
                            opportunity.attributes.evidence[key].review_on ??
                            'not set'
                        }}
                    </p>
                    <a
                        v-if="opportunity.attributes.evidence[key].url"
                        :href="opportunity.attributes.evidence[key].url ?? ''"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-3 inline-flex min-h-11 items-center text-sm underline"
                        >Open retained source ↗</a
                    >
                </article>
            </div>
        </section>
        <section class="grid gap-6 lg:grid-cols-2">
            <form
                class="space-y-4 rounded-2xl border p-5"
                @submit.prevent="reserve.submit('/opportunities/reservations')"
            >
                <h2 class="text-xl font-semibold">Reserve existing cash</h2>
                <p class="text-sm text-muted-foreground">
                    Choose an open reserved goal in {{ opportunity.currency }}.
                    Account-wide protection, including other goals, is checked
                    before saving. Dedicated emergency cash is excluded.
                </p>
                <PortfolioAttempt
                    :errors="reserve.form.errors"
                    :uncertain="reserve.feedback.uncertain.value"
                    :recovered="reserve.recovered.value"
                    :history="url"
                />
                <fieldset
                    :disabled="
                        inactive ||
                        reserve.locked.value ||
                        reserve.form.processing
                    "
                    class="space-y-4"
                >
                    <label class="block space-y-2"
                        ><span>Reservation tranche</span
                        ><select
                            v-model="reserve.form.tranche_key"
                            required
                            class="decision-input"
                        >
                            <option
                                v-for="t in opportunity.tranches"
                                :key="t.key"
                                :value="t.key"
                            >
                                {{ t.label }}
                            </option>
                        </select></label
                    ><label class="block space-y-2"
                        ><span>Existing reserved goal ID</span
                        ><input
                            v-model="reserve.form.goal_id"
                            list="decision-goals"
                            required
                            inputmode="numeric"
                            class="decision-input"
                        /><small class="block text-muted-foreground"
                            >Latest 100 open goals suggested; enter an older
                            owned ID if needed.</small
                        ></label
                    ><datalist id="decision-goals">
                        <option v-for="g in goals" :key="g.id" :value="g.id">
                            {{ g.name }} · account {{ g.account_id }}
                        </option></datalist
                    ><label class="block space-y-2"
                        ><span
                            >Reservation amount ·
                            {{ opportunity.currency }}</span
                        ><input
                            v-model="reserve.form.amount"
                            required
                            inputmode="decimal"
                            class="decision-input" /></label
                    ><label class="block space-y-2"
                        ><span>Reason for reservation</span
                        ><input
                            v-model="reserve.form.reason"
                            required
                            maxlength="180"
                            class="decision-input"
                    /></label>
                </fieldset>
                <button
                    :disabled="
                        reserve.form.processing ||
                        (inactive && !reserve.locked.value) ||
                        (!opportunity.tranches.length && !reserve.locked.value)
                    "
                    class="decision-button"
                >
                    {{
                        reserve.locked.value
                            ? 'Retry exact reservation'
                            : 'Reserve cash for this tranche'
                    }}</button
                ><Link
                    href="/goals"
                    class="inline-flex min-h-11 items-center text-sm underline"
                    >Review or create a reserved goal →</Link
                >
            </form>
            <form
                class="space-y-4 rounded-2xl border p-5"
                @submit.prevent="actual.submit('/opportunities/event-links')"
            >
                <h2 class="text-xl font-semibold">
                    Link an actual investment event
                </h2>
                <p class="text-sm text-muted-foreground">
                    Attribute a purchase or paid subscription already recorded
                    for this exact asset reference. This adds a decision link
                    only. Release any obsolete cash reservation separately.
                </p>
                <PortfolioAttempt
                    :errors="actual.form.errors"
                    :uncertain="actual.feedback.uncertain.value"
                    :recovered="actual.recovered.value"
                    :history="url"
                />
                <fieldset
                    :disabled="
                        inactive ||
                        actual.locked.value ||
                        actual.form.processing ||
                        !opportunity.asset_id
                    "
                    class="space-y-4"
                >
                    <label class="block space-y-2"
                        ><span>Actual event tranche</span
                        ><select
                            v-model="actual.form.tranche_key"
                            required
                            class="decision-input"
                        >
                            <option
                                v-for="t in opportunity.tranches"
                                :key="t.key"
                                :value="t.key"
                            >
                                {{ t.label }}
                            </option>
                        </select></label
                    ><label class="block space-y-2"
                        ><span>Existing investment event ID</span
                        ><input
                            v-model="actual.form.event_id"
                            required
                            inputmode="numeric"
                            class="decision-input" /></label
                    ><label class="block space-y-2"
                        ><span>Reason for attribution</span
                        ><input
                            v-model="actual.form.reason"
                            required
                            maxlength="180"
                            class="decision-input"
                    /></label>
                </fieldset>
                <p v-if="!opportunity.asset_id" class="text-sm">
                    Identify the exact asset in a thesis revision before linking
                    an actual event.
                </p>
                <button
                    :disabled="
                        actual.form.processing ||
                        ((inactive ||
                            !opportunity.asset_id ||
                            !opportunity.tranches.length) &&
                            !actual.locked.value)
                    "
                    class="decision-button"
                >
                    {{
                        actual.locked.value
                            ? 'Retry exact event link'
                            : 'Link existing actual event'
                    }}</button
                ><Link
                    href="/portfolio"
                    class="inline-flex min-h-11 items-center text-sm underline"
                    >Find recorded investment events →</Link
                >
            </form>
        </section>
        <section class="space-y-4">
            <h2 class="text-xl font-semibold">Reservation history</h2>
            <p v-if="!reservations.length">
                No cash reserved through this decision.
            </p>
            <article
                v-for="r in reservations"
                :key="r.id"
                class="rounded-2xl border p-5"
            >
                <p class="font-semibold">
                    {{ r.amount.currency }} {{ r.amount.amount }} ·
                    {{ r.released ? 'Released' : 'Active nominal claim' }}
                </p>
                <p class="mt-2 text-sm">
                    Tranche {{ r.tranche_key }} ·
                    <Link
                        :href="'/opportunities/' + r.opportunity_id"
                        class="underline"
                        >original thesis</Link
                    >
                    ·
                    <Link :href="'/goals/' + r.goal_id" class="underline"
                        >goal {{ r.goal_id }}</Link
                    >
                    · cash account {{ r.account_id }}
                </p>
                <p class="mt-2 text-sm">
                    Current cash backing:
                    {{
                        r.backing.amount === null
                            ? 'unknown'
                            : r.amount.currency + ' ' + r.backing.amount
                    }}
                    · shortfall {{ r.backing.shortfall ?? 'unknown' }}
                </p>
                <p class="mt-2 text-sm">
                    {{ r.reason
                    }}<span v-if="r.release_reason">
                        · Released: {{ r.release_reason }}</span
                    >
                </p>
                <DecisionRelease :reservation="r" :history="url" />
            </article>
            <Link
                v-if="reservations_next"
                :href="url + '?reservations_before=' + reservations_next"
                class="inline-flex min-h-11 items-center underline"
                >Older reservations</Link
            >
        </section>
        <section class="space-y-4">
            <h2 class="text-xl font-semibold">Actual event attribution</h2>
            <p v-if="!event_links.length">
                No actual investment event attributed. Planning creates no
                shares.
            </p>
            <article
                v-for="e in event_links"
                :key="e.id"
                class="rounded-2xl border p-5"
            >
                <p class="font-semibold">
                    {{ opportunity.currency }} {{ e.amount }} · {{ e.kind }}
                </p>
                <p
                    v-if="e.source_changed"
                    class="mt-2 text-amber-800 dark:text-amber-200"
                >
                    This source event was reversed or replaced. Retained
                    attribution now contributes zero current spend. Link its
                    replacement explicitly after review.
                </p>
                <p class="mt-2 text-sm">
                    {{ e.reason }} · tranche {{ e.tranche_key }}
                </p>
                <div class="mt-3 flex flex-wrap gap-4 text-sm underline">
                    <Link
                        :href="
                            '/portfolio/' +
                            e.portfolio_account_id +
                            '/events/' +
                            e.event_id
                        "
                        class="min-h-11 content-center"
                        >Actual event {{ e.event_reference }}</Link
                    ><Link
                        :href="'/opportunities/' + e.opportunity_id"
                        class="min-h-11 content-center"
                        >Original decision thesis</Link
                    >
                </div>
            </article>
            <Link
                v-if="links_next"
                :href="url + '?links_before=' + links_next"
                class="inline-flex min-h-11 items-center underline"
                >Older actual links</Link
            >
        </section>
        <section class="space-y-3">
            <h2 class="text-xl font-semibold">Thesis versions</h2>
            <article
                v-for="h in history"
                :key="h.id"
                class="rounded-xl border p-4"
            >
                <Link
                    :href="'/opportunities/' + h.id"
                    class="inline-flex min-h-11 items-center font-medium underline"
                    >Version {{ h.version }} · {{ h.name }}</Link
                >
                <p class="text-sm">{{ h.reason }} · {{ h.recorded_at }}</p>
            </article>
            <Link
                v-if="history_next"
                :href="url + '?history_before=' + history_next"
                class="inline-flex min-h-11 items-center underline"
                >Older thesis versions</Link
            >
        </section>
    </DecisionShell>
</template>
<style scoped>
@reference '../../../css/app.css';
.decision-input {
    @apply block min-h-11 w-full rounded-lg border bg-background px-3 py-2 text-base disabled:opacity-60;
}
.decision-button {
    @apply min-h-11 rounded-lg bg-teal-800 px-5 py-3 text-sm font-medium text-white disabled:opacity-50 dark:bg-teal-200 dark:text-teal-950;
}
</style>
