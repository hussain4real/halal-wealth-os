<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onUnmounted, ref } from 'vue';
import CashAvailabilityPanel from '@/components/CashAvailabilityPanel.vue';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import DashboardDismiss from '@/components/DashboardDismiss.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import {
    portfolioRequestScopes,
    subscribePortfolioRequests,
} from '@/composables/portfolioRequestDraft';
import type { CashSnapshot } from '@/types/cash';
import type { DashboardView, MetricKey } from '@/types/dashboard';
const page = usePage();
const props = defineProps<{
    cash: CashSnapshot;
    dashboard: DashboardView;
    snapshot: { id: string; reference: string; hash: string } | null;
    snapshots: { id: string; reference: string; recorded_at: string }[];
    currencies: string[];
    current_settings_id: string | null;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }] },
});
const metrics = [
    {
        key: 'net_worth',
        label: 'Net worth',
        note: 'Selected assets and signed liabilities, counted once.',
    },
    {
        key: 'liabilities',
        label: 'Liability contribution',
        note: 'Already included in net worth. Never subtracted twice.',
    },
    {
        key: 'liquid_cash',
        label: 'Liquid cash',
        note: 'Posted bank and cash balances; protection is shown below.',
    },
    {
        key: 'investments',
        label: 'Investment value',
        note: 'Selected cash, holdings and IPO receivables, or whole-account value.',
    },
    {
        key: 'funded_goals',
        label: 'Funded goals',
        note: 'Actual backed cash, a subset of wealth. Nominal claims stay separate.',
    },
] satisfies { key: MetricKey; label: string; note: string }[];
const selected = ref<MetricKey | null>(null);
const count = ref(20);
const sourceHeading = ref<HTMLElement | null>(null);
const sourceRows = computed(() =>
    selected.value ? props.dashboard.sources[selected.value] : [],
);
async function inspect(key: MetricKey) {
    selected.value = key;
    count.value = 20;
    await nextTick();
    sourceHeading.value?.focus();
}
const visible = computed(() =>
    props.dashboard.actions.filter((a) => !a.dismissed),
);
const dismissed = computed(() =>
    props.dashboard.actions.filter((a) => a.dismissed),
);
const actionCount = ref(20);
const dismissalScope =
    String(usePage().props.auth.user.id) + ':dashboard-dismiss:';
const pendingScopes = ref(portfolioRequestScopes(dismissalScope));
onUnmounted(
    subscribePortfolioRequests(() => {
        pendingScopes.value = portfolioRequestScopes(dismissalScope);
    }),
);
const pendingDismissals = computed(() => {
    const activeKeys = new Set(
        visible.value.slice(0, actionCount.value).map((action) => action.key),
    );
    return pendingScopes.value
        .map((scope) => scope.slice(dismissalScope.length))
        .filter((key) => /^[0-9a-f]{64}$/.test(key) && !activeKeys.has(key));
});
const { form, locked, recovered, feedback, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) + ':dashboard-snapshot',
    () => ({
        base_currency: props.dashboard.base_currency,
        settings_id: props.current_settings_id ?? '',
    }),
);
</script>
<template>
    <Head title="Your financial overview" />
    <main
        class="mx-auto w-full max-w-6xl space-y-9 p-4 [overflow-wrap:anywhere] sm:p-8"
    >
        <header class="flex flex-wrap items-start justify-between gap-5">
            <div class="max-w-2xl">
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                    Your financial overview
                </h1>
                <p class="mt-3 text-muted-foreground">
                    See what you own, what is protected, and which records need
                    attention.
                </p>
                <p class="mt-3 text-xs text-muted-foreground">
                    {{ snapshot ? 'Saved snapshot' : 'Current view' }} ·
                    {{ dashboard.cutoff }} · Qatar planning date
                    {{ dashboard.planning_date }}
                </p>
            </div>
            <nav
                class="flex flex-wrap gap-4 text-sm"
                aria-label="Dashboard tools"
            >
                <Link
                    class="min-h-11 content-center underline"
                    href="/dashboard/rules"
                    >Action rules</Link
                ><Link
                    class="min-h-11 content-center underline"
                    :href="
                        '/dashboard?base_currency=' + dashboard.base_currency
                    "
                    >Refresh current view</Link
                >
            </nav>
        </header>
        <section
            v-if="snapshot"
            class="rounded-xl border bg-muted p-5"
            aria-label="Historical snapshot"
        >
            <h2 class="font-semibold">
                This view is retained, not recalculated.
            </h2>
            <p class="mt-2 text-sm">
                Later corrections and dismissals do not change these totals or
                their sources. Open the current view for today's position.
            </p>
            <a
                :href="'/dashboard/snapshots/' + snapshot.id + '/export'"
                class="mt-3 inline-block min-h-11 content-center text-sm underline"
                >Export complete snapshot evidence</a
            >
        </section>
        <form
            @submit.prevent="submit('/dashboard/snapshots')"
            class="flex flex-wrap items-end gap-4 border-b pb-5"
        >
            <PortfolioAttempt
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                :recovered="recovered"
                history="/dashboard"
            />
            <fieldset :disabled="locked || form.processing">
                <label class="grid gap-2 text-sm"
                    >Snapshot reporting currency<select
                        v-model="form.base_currency"
                        class="min-h-11 rounded-lg border bg-background px-3"
                    >
                        <option v-for="c in currencies" :key="c">
                            {{ c }}
                        </option>
                    </select></label
                >
            </fieldset>
            <button
                :disabled="form.processing"
                class="min-h-11 rounded-lg bg-teal-800 px-5 text-sm font-medium text-white dark:bg-teal-200 dark:text-teal-950"
            >
                {{
                    locked ? 'Retry exact snapshot' : 'Save a current snapshot'
                }}
            </button>
            <p class="max-w-lg text-xs text-muted-foreground">
                A new snapshot captures current records. It never posts money or
                an investment.
            </p>
        </form>
        <section
            aria-label="Financial position"
            class="grid gap-x-7 gap-y-5 sm:grid-cols-2 lg:grid-cols-3"
        >
            <article
                v-for="m in metrics"
                :key="m.key"
                class="min-w-0 border-b pb-5"
            >
                <h2 class="text-sm font-medium text-muted-foreground">
                    {{ m.label }} · {{ dashboard.base_currency }}
                </h2>
                <p class="mt-3 text-2xl font-semibold tabular-nums">
                    <CurrencyDisplay
                        v-if="dashboard.widgets[m.key].total"
                        :amount="dashboard.widgets[m.key].total?.amount ?? null"
                        :currency="dashboard.base_currency"
                    /><span v-else>Partial / unavailable</span>
                </p>
                <p
                    v-if="dashboard.widgets[m.key].state !== 'complete'"
                    class="mt-2 text-sm text-amber-900 dark:text-amber-200"
                >
                    {{ dashboard.widgets[m.key].missing }} component(s) missing.
                    Known subtotal:
                    <CurrencyDisplay
                        :amount="
                            dashboard.widgets[m.key].covered_subtotal?.amount ??
                            null
                        "
                        :currency="dashboard.base_currency"
                    />.
                </p>
                <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                    {{ m.note }}
                </p>
                <button
                    @click="inspect(m.key)"
                    class="mt-2 min-h-11 text-sm underline"
                    :aria-expanded="selected === m.key"
                >
                    View {{ m.label.toLowerCase() }} sources
                </button>
            </article>
        </section>
        <section
            v-if="selected"
            class="space-y-4 rounded-xl border p-5"
            aria-label="Captured source detail"
        >
            <div class="flex flex-wrap justify-between gap-3">
                <h2
                    ref="sourceHeading"
                    tabindex="-1"
                    class="text-lg font-semibold focus-visible:outline-2"
                >
                    {{ metrics.find((m) => m.key === selected)?.label }} ·
                    source detail
                </h2>
                <button
                    @click="selected = null"
                    class="min-h-11 text-sm underline"
                >
                    Close source detail
                </button>
            </div>
            <p class="text-sm text-muted-foreground">
                Same captured view · {{ dashboard.cutoff }}. Signed native and
                base amounts reconcile to the headline. Links open current
                records, which may have changed.
            </p>
            <ul class="space-y-2 text-sm">
                <li
                    v-for="g in dashboard.widgets[selected].native"
                    :key="g.currency"
                >
                    {{ g.currency }}:
                    <CurrencyDisplay
                        :amount="g.value?.amount ?? null"
                        :currency="g.currency"
                    /><span v-if="g.missing">
                        · {{ g.missing }} missing native values</span
                    >
                </li>
            </ul>
            <p v-if="!sourceRows.length" class="text-sm">
                No contributing records in this owned set. Its complete total is
                zero.
            </p>
            <ol class="divide-y">
                <li
                    v-for="(r, i) in sourceRows.slice(0, count)"
                    :key="i"
                    class="space-y-2 py-4 text-sm"
                >
                    <div class="flex flex-wrap justify-between gap-3">
                        <strong>{{ r.asset }} · {{ r.account }}</strong
                        ><span
                            ><CurrencyDisplay
                                :amount="r.native?.amount ?? null"
                                :currency="r.currency" />
                            →
                            <CurrencyDisplay
                                :amount="r.converted?.amount ?? null"
                                :currency="dashboard.base_currency"
                        /></span>
                    </div>
                    <p
                        v-if="r.reason"
                        class="text-amber-900 dark:text-amber-200"
                    >
                        Needs input: {{ r.reason.replaceAll('_', ' ') }}. No
                        complete total is inferred.
                    </p>
                    <p v-if="r.price" class="text-xs text-muted-foreground">
                        Price: {{ r.price.source }} · observed
                        {{ r.price.observed_at }} · effective
                        {{ r.price.effective_at }}
                    </p>
                    <p v-if="r.fx" class="text-xs text-muted-foreground">
                        FX: {{ r.fx.source }} · {{ r.fx.value }} · observed
                        {{ r.fx.observed_at }} · effective
                        {{ r.fx.effective_at }}
                    </p>
                    <nav
                        class="flex flex-wrap gap-4 underline"
                        aria-label="Current source records"
                    >
                        <Link :href="'/accounts/' + r.account_id"
                            >Current account</Link
                        ><Link v-if="r.asset_id" :href="'/assets/' + r.asset_id"
                            >Current asset</Link
                        ><Link v-if="r.goal_id" :href="'/goals/' + r.goal_id"
                            >Current goal</Link
                        ><Link
                            v-if="r.portfolio_account_id"
                            :href="
                                '/portfolio/' +
                                r.portfolio_account_id +
                                '/prices'
                            "
                            >Dated prices</Link
                        ><Link v-if="r.reason" href="/rates">Reporting FX</Link>
                    </nav>
                </li>
            </ol>
            <button
                v-if="count < sourceRows.length"
                @click="count += 20"
                class="min-h-11 rounded-lg border px-4 text-sm"
            >
                Show more sources ({{ count }} / {{ sourceRows.length }})
            </button>
        </section>
        <section class="space-y-5" aria-labelledby="next-actions">
            <p
                v-if="page.props.errors.action_key"
                role="alert"
                class="rounded-xl border border-destructive p-4 text-sm text-destructive"
            >
                {{ page.props.errors.action_key }}
            </p>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="next-actions" class="text-2xl font-semibold">
                    Next actions
                    <span class="text-base text-muted-foreground">{{
                        visible.length
                    }}</span>
                </h2>
                <Link
                    href="/dashboard/rules"
                    class="min-h-11 content-center text-sm underline"
                    >Configure rules</Link
                >
            </div>
            <p class="max-w-2xl text-sm text-muted-foreground">
                Review prompts from recorded facts and explicit thresholds.
                These do not move money, place trades or certify an investment.
            </p>
            <p
                v-if="!visible.length"
                class="rounded-xl border border-dashed p-6 text-sm"
            >
                No active action under these rules. This does not establish that
                every source is complete or an investment is suitable.
            </p>
            <ol class="divide-y">
                <li
                    v-for="a in visible.slice(0, actionCount)"
                    :key="a.key"
                    class="py-5"
                >
                    <h3 class="font-semibold">{{ a.title }}</h3>
                    <p
                        class="mt-2 max-w-3xl text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ a.explanation }}
                    </p>
                    <Link
                        :href="a.url"
                        class="mt-2 inline-block min-h-11 content-center text-sm underline"
                        >Review source records</Link
                    ><DashboardDismiss
                        :action-key="a.key"
                        :snapshot-id="snapshot?.id ?? ''"
                        :base="dashboard.base_currency"
                    />
                </li>
            </ol>
            <button
                v-if="actionCount < visible.length"
                @click="actionCount += 20"
                class="min-h-11 rounded-lg border px-4 text-sm"
            >
                Show more actions
            </button>

            <DashboardDismiss
                v-for="key in pendingDismissals"
                :key="key"
                :action-key="key"
                :snapshot-id="snapshot?.id ?? ''"
                :base="dashboard.base_currency"
                recovery-only
            />
            <details v-if="dismissed.length">
                <summary
                    class="min-h-11 cursor-pointer content-center text-sm underline"
                >
                    {{ dismissed.length }} dismissed review episode(s)
                </summary>
                <ul class="space-y-3 text-sm">
                    <li v-for="a in dismissed" :key="a.key">
                        <strong>{{ a.title }}</strong> ·
                        {{ a.dismissal?.reason }} ·
                        {{ a.dismissal?.recorded_at }}
                    </li>
                </ul>
            </details>
        </section>
        <section
            aria-label="Recorded currency allocation"
            class="border-y py-6"
        >
            <h2 class="text-lg font-semibold">Currency allocation</h2>
            <p class="mt-2 max-w-3xl text-xs text-muted-foreground">
                {{ dashboard.allocation.coverage }} coverage ·
                {{ dashboard.allocation.denominator }}. Partial or nonpositive
                denominators cannot support a complete comparison.
            </p>
            <ul class="mt-4 flex flex-wrap gap-5 text-sm">
                <li
                    v-for="g in dashboard.allocation.dimensions.currency"
                    :key="g.label"
                >
                    {{ g.label }} · {{ g.weight?.percent ?? 'Unavailable'
                    }}<span v-if="g.weight">%</span
                    ><span v-if="g.missing_components">
                        · {{ g.missing_components }} missing</span
                    >
                </li>
            </ul>
        </section>
        <CashAvailabilityPanel :cash="cash" />
        <details>
            <summary
                class="min-h-11 cursor-pointer content-center text-sm underline"
            >
                Statement reconciliation and calculation basis
            </summary>
            <p class="my-4 max-w-3xl text-sm text-muted-foreground">
                {{ dashboard.time_policy }}
            </p>
            <ul class="space-y-3 text-sm">
                <li v-for="r in dashboard.reconciliations" :key="r.account_id">
                    <strong>{{ r.account }}</strong> · {{ r.explanation }}
                    <span v-if="r.cutoff">Cutoff {{ r.cutoff }}.</span>
                </li>
            </ul>
        </details>
        <section v-if="snapshots.length" aria-label="Saved dashboard snapshots">
            <h2 class="text-lg font-semibold">Saved views</h2>
            <ul class="mt-3 divide-y">
                <li v-for="r in snapshots" :key="r.id">
                    <Link
                        :href="'/dashboard/snapshots/' + r.id"
                        class="block min-h-11 py-3 text-sm underline"
                        >{{ r.recorded_at }} · {{ r.reference }}</Link
                    >
                </li>
            </ul>
            <p class="mt-3 text-xs text-muted-foreground">
                Latest 20 saved views. Earlier view URLs remain available.
            </p>
        </section>
        <nav
            aria-label="Workspace shortcuts"
            class="flex flex-wrap gap-5 border-t pt-5 text-sm underline"
        >
            <Link class="min-h-11 content-center" href="/settings/profile"
                >Profile settings</Link
            ><Link class="min-h-11 content-center" href="/settings/security"
                >Security settings</Link
            >
        </nav>
    </main>
</template>
