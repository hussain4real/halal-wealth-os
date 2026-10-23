<script setup lang="ts">
import ObservationTimeEvidence from '@/components/ObservationTimeEvidence.vue';
import { Link } from '@inertiajs/vue3';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import { modeLabel, evidenceLabel } from '@/types/portfolio';
import type { FinancialEvidence } from '@/types/ledger';
defineProps<{ evidence: FinancialEvidence; showTotal?: boolean }>();
const reasons: Record<string, string> = {
    legacy_correction_timing:
        'A retained correction uses a different cancellation date. This historical balance is unavailable. Review the included journals and choose a cutoff on or after the cancellation date.',
    opening_pending: 'Opening request has not been posted.',
    opening_after_cutoff: 'The consumed opening falls after this cutoff.',
    out_of_range:
        'The complete native balance exceeds the supported cash range.',
    converted_out_of_range:
        'The converted result exceeds the supported cash range.',
};
</script>
<template>
    <section
        v-if="showTotal"
        class="rounded-2xl border bg-card p-5 shadow-sm sm:p-7"
        aria-label="Conversion completeness"
    >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p
                    class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                >
                    {{
                        evidence.state === 'complete'
                            ? 'Complete total'
                            : evidence.components.some((c) => c.portfolio)
                              ? 'Incomplete value'
                              : 'Incomplete conversion'
                    }}
                </p>
                <p
                    v-if="evidence.total"
                    class="mt-3 text-3xl font-semibold tracking-tight [overflow-wrap:anywhere]"
                >
                    <CurrencyDisplay
                        :amount="evidence.total.amount"
                        :currency="evidence.total.currency"
                    />
                </p>
                <h2 v-else class="mt-3 text-2xl font-semibold">
                    {{
                        evidence.state === 'partial'
                            ? 'Some balances are uncovered'
                            : evidence.state === 'stale'
                              ? 'Eligible rates are stale'
                              : evidence.state === 'missing'
                                ? 'Reporting rates are missing'
                                : 'A complete value is unavailable'
                    }}
                </h2>
            </div>
            <span
                class="rounded-full bg-muted px-3 py-1 text-xs font-medium capitalize"
                >{{ evidence.state }}</span
            >
        </div>
        <p
            v-if="!evidence.total && evidence.covered_subtotal"
            class="mt-4 text-sm"
        >
            Covered subtotal only:
            <strong class="[overflow-wrap:anywhere]"
                ><CurrencyDisplay
                    :amount="evidence.covered_subtotal.amount"
                    :currency="evidence.covered_subtotal.currency"
            /></strong>
            · {{ evidence.covered_accounts }} covered account(s)
        </p>
        <p class="mt-4 text-xs leading-relaxed text-muted-foreground">
            Cutoff {{ evidence.cutoff }} · Captured {{ evidence.captured_at }}.
            UTC business-date reporting includes visible entries effective
            on/before that date. Later backdated entries require a new report.
        </p>
    </section>
    <section class="overflow-hidden rounded-2xl border bg-card">
        <h2 class="border-b px-5 py-4 font-medium">
            Native values and evidence
        </h2>
        <div v-if="!evidence.components.length" class="space-y-3 p-6 text-sm">
            <p>No personal accounts are included yet.</p>
            <Link
                href="/accounts"
                class="inline-flex min-h-10 items-center underline"
                >Add an account</Link
            >
        </div>
        <article
            v-for="component in evidence.components"
            :key="component.account_id"
            class="min-w-0 border-b p-5 last:border-0 sm:p-6"
        >
            <div
                class="flex min-w-0 flex-wrap items-start justify-between gap-4"
            >
                <div class="min-w-0">
                    <Link
                        :href="`/accounts/${component.account_id}`"
                        class="inline-flex min-h-10 items-center font-medium [overflow-wrap:anywhere] underline"
                        >{{ component.name }}</Link
                    >
                    <p class="text-xs text-muted-foreground">
                        {{ component.currency }} · {{ component.type
                        }}{{ component.archived ? ' · archived' : '' }}
                    </p>
                </div>
                <span
                    class="rounded-full bg-muted px-3 py-1 text-xs capitalize"
                    >{{ component.state }}</span
                >
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">
                        {{
                            component.portfolio
                                ? 'Selected native contribution'
                                : 'Native balance'
                        }}
                    </p>
                    <p
                        v-if="component.native"
                        class="mt-1 font-medium [overflow-wrap:anywhere]"
                    >
                        <CurrencyDisplay
                            :amount="component.native.amount"
                            :currency="component.native.currency"
                        />
                    </p>
                    <p v-else class="mt-1 text-sm">Unavailable</p>
                </div>
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">
                        {{ evidence.base_currency }} reporting value
                    </p>
                    <p
                        v-if="component.converted"
                        class="mt-1 font-medium [overflow-wrap:anywhere]"
                    >
                        <CurrencyDisplay
                            :amount="component.converted.amount"
                            :currency="component.converted.currency"
                        />
                    </p>
                    <p v-else class="mt-1 text-sm">
                        {{
                            component.state === 'stale'
                                ? 'Stale rate excluded'
                                : component.state === 'missing'
                                  ? 'No eligible direct rate'
                                  : 'Unavailable'
                        }}
                    </p>
                </div>
            </div>
            <div
                v-if="component.portfolio"
                class="mt-4 space-y-2 rounded-xl border p-4 text-sm"
            >
                <Link
                    :href="'/portfolio/' + component.portfolio.id"
                    class="inline-flex min-h-10 items-center font-medium underline"
                    >{{ modeLabel(component.portfolio.mode) }}</Link
                >
                <p
                    class="text-xs [overflow-wrap:anywhere] text-muted-foreground"
                >
                    Label: {{ component.portfolio.platform_label }} · Source:
                    {{ component.portfolio.source }} · UTC
                    {{ component.portfolio.as_of }}
                </p>
                <p v-for="entry in component.portfolio.selected" :key="entry">
                    Included: {{ evidenceLabel(entry) }}
                </p>
                <p
                    v-for="entry in component.portfolio.excluded"
                    :key="entry"
                    class="text-muted-foreground"
                >
                    Excluded: {{ evidenceLabel(entry) }}
                </p>
                <p>
                    Retained ledger cash:
                    <CurrencyDisplay
                        v-if="component.ledger_cash"
                        :amount="component.ledger_cash.amount"
                        :currency="component.currency"
                    /><span v-else>Unavailable</span>
                </p>
                <p
                    v-for="entry in [
                        ...new Set(
                            component.portfolio.missing.map(evidenceLabel),
                        ),
                    ]"
                    :key="entry"
                >
                    {{ entry }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Manual reference values are usable only for the recorded UTC
                    date. Platform labels do not verify connection, ownership or
                    Shariah status.
                </p>
            </div>
            <p
                v-if="component.native_reason && !component.portfolio"
                class="mt-3 text-sm text-muted-foreground"
            >
                {{
                    reasons[component.native_reason] ?? component.native_reason
                }}
            </p>
            <p
                v-if="
                    component.conversion === 'same_currency' && component.native
                "
                class="mt-3 text-xs text-muted-foreground"
            >
                Already in the reporting currency. No FX observation is needed.
            </p>
            <div
                v-if="component.rate"
                class="mt-4 rounded-xl bg-muted/30 p-3 text-xs leading-relaxed [overflow-wrap:anywhere] text-muted-foreground"
            >
                <p>
                    {{ component.rate.value }} {{ component.rate.quote }}/{{
                        component.rate.base
                    }}
                    · version {{ component.rate.version }}
                </p>
                <p>{{ component.rate.source }}</p>
                <ObservationTimeEvidence :timing="component.rate" />
            </div>
            <details class="mt-4 text-sm">
                <summary class="min-h-10 cursor-pointer py-2">
                    Included ledger evidence ·
                    {{ component.journals.length }} movement(s)
                </summary>
                <p
                    v-if="!component.journals.length"
                    class="mt-2 text-xs text-muted-foreground"
                >
                    No eligible posted movements.
                </p>
                <ul v-else class="mt-2 space-y-2">
                    <li
                        v-for="movement in component.journals"
                        :key="movement.posting_id"
                        class="flex flex-wrap justify-between gap-2 text-xs"
                    >
                        <Link
                            :href="`/transactions/${movement.journal_id}`"
                            class="underline"
                            >Journal {{ movement.journal_id }} ·
                            {{ movement.effective_date }}</Link
                        ><span class="[overflow-wrap:anywhere]"
                            >{{ movement.minor }} minor
                            {{ component.currency }}</span
                        >
                    </li>
                </ul>
                <p
                    v-if="component.opening"
                    class="mt-3 text-xs text-muted-foreground"
                >
                    Opening state at cutoff:
                    {{ component.opening.state.replaceAll('_', ' ') }}. Its
                    consumed identity remains retained.
                </p>
            </details>
        </article>
    </section>
</template>
