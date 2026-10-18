<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import { cashDecimal, cashReason } from '@/lib/cashDisplay';
import type { CashSnapshot } from '@/types/cash';
const props = defineProps<{ cash: CashSnapshot }>();
const deficit = computed(
    () => props.cash.actual.raw?.minor.startsWith('-') ?? false,
);
const underfunded = computed(() =>
    props.cash.actual.components.filter(
        (c) => BigInt(c.native.reservation_shortfall ?? '0') > 0n,
    ),
);
</script>

<template>
    <section aria-labelledby="cash-heading" class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p
                    class="text-xs font-semibold tracking-[0.18em] text-teal-700 uppercase dark:text-teal-300"
                >
                    Cash clarity
                </p>
                <h1
                    id="cash-heading"
                    class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl"
                >
                    What is available now?
                </h1>
                <p
                    class="mt-3 max-w-xl text-sm leading-relaxed text-muted-foreground"
                >
                    Your current liquid cash, with protected money and required
                    bills kept aside. Future salary stays in your forecast.
                </p>
            </div>
            <Link
                href="/cash/settings"
                class="inline-flex min-h-11 items-center rounded-full border px-5 text-sm font-medium transition hover:bg-muted focus-visible:outline-2"
                >{{
                    cash.settings ? 'Review settings' : 'Set up cash plan'
                }}</Link
            >
        </div>

        <div class="grid gap-5 lg:grid-cols-[1.05fr_1fr]">
            <section
                class="min-w-0 rounded-3xl border border-teal-800/10 bg-teal-950 p-6 text-teal-50 shadow-sm sm:p-8"
                aria-labelledby="available-heading"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2
                        id="available-heading"
                        class="text-sm font-medium text-teal-100"
                    >
                        Available now · {{ cash.actual.currency }}
                    </h2>
                    <span
                        class="rounded-full border border-teal-100/25 px-3 py-1 text-xs"
                        >{{
                            cash.actual.state === 'complete'
                                ? 'Complete calculation'
                                : 'Needs inputs'
                        }}</span
                    >
                </div>
                <p
                    class="mt-7 text-4xl leading-tight font-semibold tracking-tight break-all sm:text-5xl"
                >
                    <CurrencyDisplay
                        v-if="cash.actual.deployable"
                        :amount="cash.actual.deployable.amount"
                        :currency="cash.actual.currency"
                    />
                    <span v-else class="text-3xl">Not yet available</span>
                </p>
                <p
                    v-if="deficit"
                    class="mt-5 rounded-2xl border border-amber-200/40 bg-amber-100/10 p-4 text-sm leading-relaxed text-amber-100"
                >
                    <strong>Cash shortfall:</strong>
                    <CurrencyDisplay
                        :amount="cash.actual.shortfall?.amount ?? null"
                        :currency="cash.actual.currency"
                    />. The signed result is negative. Available now is zero;
                    the deficit remains visible below.
                </p>
                <p
                    v-else
                    class="mt-5 max-w-md text-sm leading-relaxed text-teal-100/85"
                >
                    {{
                        cash.actual.state === 'complete'
                            ? 'After current protection, unfunded required bills and your minimum buffer.'
                            : 'Add the missing inputs below before relying on a consolidated amount.'
                    }}
                </p>
                <p
                    class="mt-7 border-t border-teal-100/20 pt-4 text-xs leading-relaxed text-teal-100/75"
                >
                    As of {{ cash.cutoff }} · Qatar planning date {{ cash.today
                    }}<br />Expected income:
                    {{ cash.settings?.next_income_date ?? 'Not confirmed' }} · A
                    planning assumption.
                </p>
            </section>

            <section
                aria-labelledby="breakdown-heading"
                class="min-w-0 rounded-3xl border bg-card p-6 sm:p-8"
            >
                <h2 id="breakdown-heading" class="text-lg font-semibold">
                    Every part, explained
                </h2>
                <p
                    v-if="cash.actual.state !== 'complete'"
                    class="mt-2 text-xs leading-relaxed text-muted-foreground"
                >
                    Known components below are informational. They are not a
                    complete availability result.
                </p>
                <dl class="mt-5 space-y-4 text-sm">
                    <div
                        v-for="item in [
                            {
                                label: 'Liquid cash',
                                value: cash.actual.breakdown.cash,
                            },
                            {
                                label: 'Less protected cash',
                                value: cash.actual.breakdown.protected,
                            },
                            {
                                label: 'Less unfunded required bills',
                                value: cash.actual.breakdown.unfunded,
                            },
                            {
                                label: 'Less minimum buffer',
                                value: cash.actual.buffer,
                            },
                        ]"
                        :key="item.label"
                        class="flex flex-wrap justify-between gap-x-5 gap-y-1"
                    >
                        <dt class="text-muted-foreground">{{ item.label }}</dt>
                        <dd class="max-w-full font-medium break-all">
                            <CurrencyDisplay
                                :amount="item.value?.amount ?? null"
                                :currency="cash.actual.currency"
                            />
                        </dd>
                    </div>
                    <div
                        class="flex flex-wrap justify-between gap-2 border-t pt-4 font-semibold"
                        :class="
                            deficit ? 'text-amber-800 dark:text-amber-200' : ''
                        "
                    >
                        <dt>Signed result</dt>
                        <dd class="max-w-full break-all">
                            <CurrencyDisplay
                                :amount="cash.actual.raw?.amount ?? null"
                                :currency="cash.actual.currency"
                            />
                        </dd>
                    </div>
                </dl>
                <p class="mt-5 text-xs leading-relaxed text-muted-foreground">
                    Required bills include overdue unpaid amounts through the
                    expected income date, including same-day bills. Explicitly
                    backed reservations are counted once.
                </p>
            </section>
        </div>

        <section
            v-if="cash.actual.missing.length"
            aria-labelledby="cash-missing-heading"
            class="rounded-2xl border border-amber-600/25 bg-amber-50/60 p-5 dark:bg-amber-950/20"
        >
            <h2 id="cash-missing-heading" class="font-semibold">
                Complete your cash picture
            </h2>
            <ul
                class="mt-3 list-inside list-disc space-y-2 text-sm leading-relaxed"
            >
                <li v-for="reason in cash.actual.missing" :key="reason">
                    {{ cashReason(reason) }}
                </li>
            </ul>
            <Link
                href="/cash/settings"
                class="mt-3 inline-flex min-h-11 items-center text-sm font-semibold underline"
                >Review inputs</Link
            >
        </section>

        <aside
            v-if="underfunded.length"
            class="rounded-2xl border p-5 text-sm leading-relaxed"
            aria-label="Reservation backing shortfall"
        >
            <p class="font-semibold">Some reservations are underfunded</p>
            <p
                v-for="component in underfunded"
                :key="component.currency"
                class="mt-2"
            >
                <CurrencyDisplay
                    :amount="
                        cashDecimal(
                            component.native.reservation_shortfall,
                            component.currency,
                        )
                    "
                    :currency="component.currency"
                />
                of nominal reservations currently lacks backing. Only actual
                backing is deducted above; this shortfall is disclosed
                separately.
            </p>
            <Link
                href="/goals"
                class="mt-2 inline-flex min-h-11 items-center font-medium underline"
                >Review goal protection</Link
            >
        </aside>

        <div class="grid gap-5 md:grid-cols-2">
            <details class="min-w-0 rounded-2xl border bg-card p-5">
                <summary class="min-h-11 cursor-pointer font-semibold">
                    Cash sources
                    <span class="font-normal text-muted-foreground"
                        >({{ cash.account_count }})</span
                    >
                </summary>
                <p
                    v-if="!cash.account_count"
                    class="mt-3 text-sm text-muted-foreground"
                >
                    No bank or cash accounts yet.
                    <Link href="/accounts" class="underline"
                        >Add an account</Link
                    >
                    and post any opening balance.
                </p>
                <ul class="mt-3 divide-y">
                    <li
                        v-for="account in cash.accounts"
                        :key="account.id"
                        class="space-y-2 py-4 text-sm"
                    >
                        <Link
                            :href="'/accounts/' + account.id"
                            class="block min-h-8 font-medium break-words underline"
                            >{{ account.name
                            }}<span
                                v-if="account.archived"
                                class="font-normal text-muted-foreground"
                            >
                                · archived, cash retained</span
                            ></Link
                        >
                        <p class="break-all">
                            <CurrencyDisplay
                                :amount="
                                    account.reason
                                        ? null
                                        : cashDecimal(
                                              account.minor,
                                              account.currency,
                                          )
                                "
                                :currency="account.currency"
                            />
                        </p>
                        <p
                            class="text-xs leading-relaxed text-muted-foreground"
                        >
                            {{
                                account.reason
                                    ? cashReason(account.reason)
                                    : 'Protected: '
                            }}<CurrencyDisplay
                                v-if="!account.reason"
                                :amount="
                                    cashDecimal(
                                        account.protected,
                                        account.currency,
                                    )
                                "
                                :currency="account.currency"
                            />
                        </p>
                    </li>
                </ul>
                <p
                    v-if="cash.account_count > cash.accounts.length"
                    class="mt-3 text-xs text-muted-foreground"
                >
                    Showing the first {{ cash.accounts.length }} sources. All
                    {{ cash.account_count }} are included in the calculation.
                    <Link href="/accounts" class="underline"
                        >Browse accounts</Link
                    >.
                </p>
            </details>
            <details class="min-w-0 rounded-2xl border bg-card p-5">
                <summary class="min-h-11 cursor-pointer font-semibold">
                    Required commitments
                    <span class="font-normal text-muted-foreground"
                        >({{ cash.occurrence_count ?? 'Unknown' }})</span
                    >
                </summary>
                <p
                    v-if="!cash.occurrence_count"
                    class="mt-3 text-sm text-muted-foreground"
                >
                    {{
                        cash.actual.state === 'complete'
                            ? 'No required bills are due in the confirmed horizon.'
                            : 'Confirm the required plans and income date to establish this horizon.'
                    }}
                </p>
                <ul class="mt-3 divide-y">
                    <li
                        v-for="bill in cash.occurrences"
                        :key="bill.schedule_id + ':' + bill.due"
                        class="space-y-2 py-4 text-sm"
                    >
                        <p class="font-medium break-words">
                            {{ bill.name }} · {{ bill.due }}
                        </p>
                        <p class="break-all">
                            Unpaid:
                            <CurrencyDisplay
                                :amount="
                                    cashDecimal(bill.remaining, bill.currency)
                                "
                                :currency="bill.currency"
                            />
                        </p>
                        <p
                            class="text-xs leading-relaxed text-muted-foreground"
                        >
                            Already protected:
                            <CurrencyDisplay
                                :amount="
                                    cashDecimal(bill.overlap, bill.currency)
                                "
                                :currency="bill.currency"
                            />
                            · Unfunded:
                            <CurrencyDisplay
                                :amount="
                                    cashDecimal(bill.unfunded, bill.currency)
                                "
                                :currency="bill.currency"
                            />
                        </p>
                    </li>
                </ul>
                <p
                    v-if="
                        cash.occurrence_count !== null &&
                        cash.occurrence_count > cash.occurrences.length
                    "
                    class="mt-3 text-xs text-muted-foreground"
                >
                    Showing {{ cash.occurrences.length }} of
                    {{ cash.occurrence_count ?? 'Unknown' }}; every required
                    occurrence is included.
                </p>
                <Link
                    href="/budgets"
                    class="mt-3 inline-flex min-h-11 items-center text-sm underline"
                    >Review budgets and paid matches</Link
                >
            </details>
        </div>

        <details class="rounded-2xl border p-5">
            <summary class="min-h-11 cursor-pointer text-sm font-semibold">
                Native amounts and conversion evidence
            </summary>
            <div
                v-for="component in cash.actual.components"
                :key="component.currency"
                class="mt-4 space-y-2 border-t pt-4 text-sm"
            >
                <h3 class="font-semibold">{{ component.currency }}</h3>
                <p class="break-all">
                    Cash:
                    <CurrencyDisplay
                        :amount="
                            component.reason
                                ? null
                                : cashDecimal(
                                      component.native.cash,
                                      component.currency,
                                  )
                        "
                        :currency="component.currency"
                    />
                    · Protected:
                    <CurrencyDisplay
                        :amount="
                            component.reason
                                ? null
                                : cashDecimal(
                                      component.native.protected,
                                      component.currency,
                                  )
                        "
                        :currency="component.currency"
                    />
                </p>
                <p class="text-xs text-muted-foreground">
                    Nominal reservations:
                    <CurrencyDisplay
                        :amount="
                            cashDecimal(
                                component.native.nominal_reserved,
                                component.currency,
                            )
                        "
                        :currency="component.currency"
                    />. Dedicated-account high-water and goal targets are not
                    additional deductions.
                </p>
                <p
                    v-if="component.rate"
                    class="text-xs leading-relaxed break-words text-muted-foreground"
                >
                    Direct reporting observation #{{ component.rate.id }} ·
                    {{ component.rate.source }} · {{ component.rate.value }}
                    {{ cash.actual.currency }} per {{ component.currency }} ·
                    Effective {{ component.rate.effective_at }} · Observed
                    {{ component.rate.observed_at }}
                </p>
                <p v-else class="text-xs text-muted-foreground">
                    {{
                        component.conversion === 'same_currency'
                            ? 'Same currency; no conversion.'
                            : 'No eligible direct reporting observation.'
                    }}
                </p>
            </div>
            <p class="mt-4 text-xs leading-relaxed text-muted-foreground">
                Native currency groups are converted together. Paired rounding
                keeps the displayed cash, protection and unfunded-bill
                arithmetic consistent. Current known rates are used for
                forecasts as assumptions.
            </p>
        </details>

        <section
            class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border bg-muted/30 p-5"
        >
            <div>
                <h2 class="font-semibold">Explore a different month</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Adjust a what-if forecast without changing your cash or
                    reservations.
                </p>
            </div>
            <Link
                href="/cash/scenarios"
                class="inline-flex min-h-11 items-center rounded-full bg-teal-800 px-5 text-sm font-medium text-white hover:bg-teal-900 focus-visible:outline-2 dark:bg-teal-700"
                >Open what-if forecast</Link
            >
        </section>
    </section>
</template>
