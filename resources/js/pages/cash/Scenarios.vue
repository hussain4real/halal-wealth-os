<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref } from 'vue';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import { cashDecimal, cashReason } from '@/lib/cashDisplay';
import type { CashForecast, CashSnapshot } from '@/types/cash';
const props = defineProps<{ cash: CashSnapshot }>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Cash clarity', href: '/dashboard' },
            { title: 'What-if forecast', href: '/cash/scenarios' },
        ],
    },
});
const currency = props.cash.actual.currency;
const fields = [
    { key: 'salary', label: 'Expected salary', source: 'salary' },
    {
        key: 'contribution',
        label: 'Additional goal contribution',
        source: 'contribution',
    },
    {
        key: 'investment_target',
        label: 'Total investment protection target',
        source: 'investment_target',
    },
    {
        key: 'commitments',
        label: 'Required bills for the month',
        source: 'monthly_commitments',
    },
] as const;
const input = reactive(
    Object.fromEntries(
        fields.map((f) => [
            f.key,
            cashDecimal(
                props.cash.actual.forecast_inputs[f.source],
                currency,
            ) ?? '',
        ]),
    ) as Record<string, string>,
);
const baselineInputs = { ...input };
const lastCalculated = ref<Record<string, string> | null>(null);
const result = ref<CashForecast | null>(null);
const pending = ref(false);
const error = ref('');
const errors = ref<Record<string, string>>({});
const stale = ref(false);
const dirty = computed(
    () =>
        lastCalculated.value !== null &&
        fields.some((f) => input[f.key] !== lastCalculated.value?.[f.key]),
);
const showing = computed(() => result.value ?? props.cash.forecast);
async function calculate() {
    if (pending.value) return;
    pending.value = true;
    error.value = '';
    errors.value = {};
    stale.value = false;
    try {
        const token = decodeURIComponent(
            document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '',
        );
        const response = await fetch('/cash/scenarios', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': token,
            },
            body: JSON.stringify({
                fingerprint: props.cash.fingerprint,
                overrides: { ...input },
            }),
        });
        if (response.status === 409) {
            stale.value = true;
            throw new Error(
                'Your source records changed. Refresh the baseline before calculating again.',
            );
        }
        const data = await response.json();
        if (!response.ok) {
            errors.value = Object.fromEntries(
                Object.entries(data.errors ?? {}).map(([k, value]) => [
                    k.replace('overrides.', ''),
                    Array.isArray(value) ? String(value[0]) : String(value),
                ]),
            );
            throw new Error(data.message ?? 'Check the entered amounts.');
        }
        result.value = data.forecast;
        lastCalculated.value = { ...input };
        await nextTick();
        document.querySelector<HTMLElement>('[data-scenario-result]')?.focus();
    } catch (failure) {
        error.value =
            failure instanceof Error
                ? failure.message
                : 'The calculation was interrupted. Retry when connected; no financial records were changed.';
    } finally {
        pending.value = false;
        if (error.value) {
            await nextTick();
            (
                document.querySelector<HTMLElement>('[aria-invalid="true"]') ??
                document.querySelector<HTMLElement>('[data-scenario-error]')
            )?.focus();
        }
    }
}
function reset() {
    lastCalculated.value = null;
    Object.assign(input, baselineInputs);
    result.value = null;
    error.value = '';
    errors.value = {};
    stale.value = false;
}
</script>

<template>
    <Head title="What-if cash forecast" />
    <section class="mx-auto w-full max-w-6xl space-y-7 p-4 sm:p-8">
        <header>
            <p
                class="text-xs font-semibold tracking-[0.18em] text-teal-700 uppercase dark:text-teal-300"
            >
                What if?
            </p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight">
                Explore next month
            </h1>
            <p
                class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground"
            >
                Forecast period {{ cash.forecast_period.start }} to
                {{ cash.forecast_period.end }} · Qatar calendar. Change
                assumptions without changing your ledger, plans or reservations.
            </p>
        </header>
        <aside
            class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border bg-muted/30 p-5"
        >
            <div>
                <p class="text-sm text-muted-foreground">
                    Actual available now stays unchanged
                </p>
                <p class="mt-1 text-xl font-semibold break-all">
                    <CurrencyDisplay
                        :amount="cash.actual.deployable?.amount ?? null"
                        :currency="currency"
                    />
                </p>
            </div>
            <Link
                href="/dashboard"
                class="inline-flex min-h-11 items-center text-sm underline"
                >Review actual breakdown</Link
            >
        </aside>
        <div class="grid items-start gap-6 lg:grid-cols-[0.9fr_1.1fr]">
            <form
                class="min-w-0 space-y-5 rounded-3xl border p-5 sm:p-7"
                @submit.prevent="calculate"
            >
                <h2 class="text-lg font-semibold">
                    Scenario assumptions · {{ currency }}
                </h2>
                <p class="text-xs leading-relaxed text-muted-foreground">
                    Every amount is explicit, including zero. Salary and bills
                    affect forecast household cash. Contributions and the
                    unbacked investment target only earmark free funds.
                </p>
                <div
                    v-if="error"
                    role="alert"
                    tabindex="-1"
                    data-scenario-error
                    class="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm leading-relaxed"
                >
                    {{ error }}
                    <ul
                        v-if="Object.keys(errors).length"
                        class="mt-2 list-inside list-disc"
                    >
                        <li v-for="(message, field) in errors" :key="field">
                            {{ message }}
                        </li>
                    </ul>
                    <button
                        v-if="stale"
                        type="button"
                        class="mt-3 min-h-11 font-semibold underline"
                        @click="
                            router.visit('/cash/scenarios', {
                                preserveState: false,
                            })
                        "
                    >
                        Refresh baseline
                    </button>
                </div>
                <label
                    v-for="field in fields"
                    :key="field.key"
                    class="block min-w-0 text-sm font-medium"
                    >{{ field.label
                    }}<input
                        v-model="input[field.key]"
                        :name="field.key"
                        inputmode="decimal"
                        required
                        :disabled="pending"
                        :aria-invalid="!!errors[field.key]"
                        autocomplete="off"
                        class="mt-2 min-h-11 w-full min-w-0 rounded-xl border bg-background px-3 py-2 focus-visible:outline-2"
                /></label>
                <div class="flex flex-wrap gap-3">
                    <button
                        type="submit"
                        :disabled="pending || stale"
                        class="min-h-11 rounded-full bg-teal-800 px-5 text-sm font-semibold text-white hover:bg-teal-900 focus-visible:outline-2 disabled:opacity-50"
                    >
                        {{
                            pending ? 'Calculating…' : 'Calculate what-if'
                        }}</button
                    ><button
                        type="button"
                        :disabled="pending"
                        class="min-h-11 rounded-full border px-5 text-sm font-medium hover:bg-muted"
                        @click="reset"
                    >
                        Reset scenario
                    </button>
                </div>
                <p class="text-xs leading-relaxed text-muted-foreground">
                    Reset discards only this scenario. Navigation or reload
                    restores the baseline. No financial operation is applied,
                    including on repeated requests.
                </p>
            </form>

            <section
                tabindex="-1"
                data-scenario-result
                aria-labelledby="forecast-heading"
                aria-live="polite"
                class="min-w-0 space-y-5 rounded-3xl border bg-teal-50/50 p-5 focus-visible:outline-2 sm:p-7 dark:bg-teal-950/20"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="forecast-heading" class="text-lg font-semibold">
                        {{ result ? 'What-if forecast' : 'Baseline forecast' }}
                    </h2>
                    <span class="rounded-full border px-3 py-1 text-xs">{{
                        showing.state === 'complete'
                            ? 'Assumption-based'
                            : 'Incomplete inputs'
                    }}</span>
                </div>
                <p
                    v-if="dirty && result"
                    class="text-xs leading-relaxed text-amber-800 dark:text-amber-200"
                >
                    Inputs may have changed since this result. Calculate again
                    to update the forecast.
                </p>
                <div>
                    <p class="text-sm text-muted-foreground">
                        Forecast free funds
                    </p>
                    <p
                        class="mt-2 text-3xl leading-tight font-semibold break-all"
                    >
                        <CurrencyDisplay
                            :amount="showing.free?.amount ?? null"
                            :currency="currency"
                        />
                    </p>
                    <p
                        class="mt-2 text-xs leading-relaxed text-muted-foreground"
                    >
                        Signed planning result after remaining protection,
                        additional earmarks and buffer. A negative result
                        remains visible; this is not money available today.
                    </p>
                </div>
                <dl class="space-y-4 border-t pt-5 text-sm">
                    <div class="flex flex-wrap justify-between gap-2">
                        <dt>Forecast household cash</dt>
                        <dd class="max-w-full font-medium break-all">
                            <CurrencyDisplay
                                :amount="showing.cash?.amount ?? null"
                                :currency="currency"
                            />
                        </dd>
                    </div>
                    <div
                        v-for="entry in [
                            {
                                label: 'Remaining protection after bills',
                                key: 'remaining_protection',
                            },
                            {
                                label: 'Additional investment earmark',
                                key: 'additional_investment',
                            },
                            {
                                label: 'Bill protection consumed once',
                                key: 'bill_overlap',
                            },
                            {
                                label: 'Rounding overlap held back',
                                key: 'rounding_overlap_not_applied',
                            },
                        ] as const"
                        :key="entry.key"
                        class="flex flex-wrap justify-between gap-2"
                    >
                        <dt class="text-muted-foreground">{{ entry.label }}</dt>
                        <dd class="max-w-full break-all">
                            <CurrencyDisplay
                                :amount="
                                    showing.details[entry.key]?.amount ?? null
                                "
                                :currency="currency"
                            />
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="
                        showing.details.rounding_overlap_not_applied?.minor !==
                            '0' && showing.details.rounding_overlap_not_applied
                    "
                    class="text-xs leading-relaxed text-muted-foreground"
                >
                    Currency rounding limits the bill protection released in
                    this forecast to protection currently recorded. The
                    held-back overlap keeps remaining protection nonnegative and
                    free funds conservative.
                </p>
                <section v-if="showing.missing.length">
                    <h3 class="text-sm font-semibold">Missing assumptions</h3>
                    <ul
                        class="mt-3 list-inside list-disc space-y-2 text-xs leading-relaxed"
                    >
                        <li v-for="reason in showing.missing" :key="reason">
                            {{ cashReason(reason) }}
                        </li>
                    </ul>
                </section>
                <p
                    class="border-t pt-4 text-xs leading-relaxed text-muted-foreground"
                >
                    Known reporting rates as of {{ cash.cutoff }} are
                    assumptions held for this forecast. No future rate, return,
                    salary receipt or bank execution is assumed to be actual
                    cash.
                </p>
                <Link
                    href="/cash/settings"
                    class="inline-flex min-h-11 items-center text-sm font-medium underline"
                    >Review buffer, required plans and target membership</Link
                >
            </section>
        </div>
    </section>
</template>
