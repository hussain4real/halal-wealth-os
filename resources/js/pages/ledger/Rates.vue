<script setup lang="ts">
import ObservationTimeEvidence from '@/components/ObservationTimeEvidence.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import LedgerFeedback from '@/components/LedgerFeedback.vue';
import LedgerNav from '@/components/LedgerNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    toLedgerUtc,
    useLedgerFeedback,
} from '@/composables/useLedgerFeedback';
import type { RateObservation } from '@/types/ledger';
defineOptions({
    layout: { breadcrumbs: [{ title: 'Reporting rates', href: '/rates' }] },
});
const props = defineProps<{
    observations: RateObservation[];
    prior: RateObservation | null;
    currencies: string[];
    now_utc: string;
}>();
const page = usePage();
const form = useForm(
    `rate:${page.props.auth.user.id}:${props.prior?.id ?? 'new'}`,
    {
        idempotency_key: crypto.randomUUID(),
        base_currency: props.prior?.base_currency ?? 'NGN',
        quote_currency: props.prior?.quote_currency ?? 'QAR',
        value: props.prior?.value ?? '',
        source: props.prior?.source ?? '',
        observed_at: (props.prior?.observed_at ?? props.now_utc).slice(0, -1),
        effective_at: (props.prior?.effective_at ?? props.now_utc).slice(0, -1),
        supersedes_id: props.prior?.id ?? '',
        correction_reason: '',
    },
);
const feedback = useLedgerFeedback(
    () => form.errors,
    () => form.clearErrors(),
);
const submit = () =>
    form
        .transform((data) => ({
            ...data,
            observed_at: toLedgerUtc(data.observed_at),
            effective_at: toLedgerUtc(data.effective_at),
        }))
        .post(
            '/rates',
            feedback.options(() => {
                form.value = '';
                form.source = '';
                form.supersedes_id = '';
                form.correction_reason = '';
                form.idempotency_key = crypto.randomUUID();
            }),
        );
const selectClass =
    'mt-2 min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm focus-visible:outline-2';
</script>
<template>
    <Head title="Reporting rates" />
    <div class="mx-auto w-full max-w-5xl space-y-7 px-4 py-8 sm:px-8 sm:py-12">
        <LedgerNav />
        <header class="max-w-2xl">
            <p
                class="text-xs font-medium tracking-[0.18em] text-muted-foreground uppercase"
            >
                RATE, SOURCE, POINT IN TIME
            </p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight">
                Reporting rates you can trace.
            </h1>
            <p class="mt-3 leading-relaxed text-muted-foreground">
                Add a manual rate for balance reports. Your actual exchange
                receipts stay unchanged. Every revision preserves the earlier
                version.
            </p>
        </header>
        <div class="grid gap-7 lg:grid-cols-[1fr_1fr]">
            <form
                class="min-w-0 rounded-2xl border bg-card p-5 shadow-sm sm:p-7"
                @submit.prevent="submit"
            >
                <h2 class="text-lg font-medium">
                    {{
                        prior
                            ? `Revise version ${prior.version}`
                            : 'Add a reporting observation'
                    }}
                </h2>
                <p v-if="prior" class="mt-2 text-sm text-muted-foreground">
                    A new version will link back to the original. Referenced
                    reports retain their existing evidence.
                </p>
                <fieldset :disabled="form.processing" class="mt-6 space-y-5">
                    <legend class="sr-only">Reporting-rate evidence</legend>
                    <LedgerFeedback
                        :errors="form.errors"
                        :uncertain="feedback.uncertain.value"
                        history="/rates"
                    />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label for="rate-base">Base currency</Label
                            ><select
                                id="rate-base"
                                v-model="form.base_currency"
                                :class="selectClass"
                                :disabled="!!prior"
                            >
                                <option
                                    v-for="currency in currencies"
                                    :key="currency"
                                >
                                    {{ currency }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <Label for="rate-quote">Quote currency</Label
                            ><select
                                id="rate-quote"
                                v-model="form.quote_currency"
                                :class="selectClass"
                                :disabled="!!prior"
                                :aria-invalid="!!form.errors.quote_currency"
                                aria-describedby="rate-pair-error"
                            >
                                <option
                                    v-for="currency in currencies.filter(
                                        (c) => c !== form.base_currency,
                                    )"
                                    :key="currency"
                                >
                                    {{ currency }}
                                </option></select
                            ><InputError
                                id="rate-pair-error"
                                :message="form.errors.quote_currency"
                            />
                        </div>
                    </div>
                    <div>
                        <Label for="rate-value"
                            >{{ form.quote_currency }} per 1
                            {{ form.base_currency }}</Label
                        ><Input
                            id="rate-value"
                            v-model="form.value"
                            required
                            inputmode="decimal"
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.value"
                            aria-describedby="rate-value-help rate-value-error"
                        />
                        <p
                            id="rate-value-help"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Positive exact rate, at most 12 decimal places. No
                            reciprocal or missing-rate fallback is inferred.
                        </p>
                        <InputError
                            id="rate-value-error"
                            :message="form.errors.value"
                        />
                    </div>
                    <div>
                        <Label for="rate-source">Source or reference</Label
                        ><Input
                            id="rate-source"
                            v-model="form.source"
                            required
                            maxlength="180"
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.source"
                            aria-describedby="rate-source-error"
                        /><InputError
                            id="rate-source-error"
                            :message="form.errors.source"
                        />
                    </div>
                    <div>
                        <Label for="rate-observed">Observed at · UTC</Label
                        ><Input
                            id="rate-observed"
                            v-model="form.observed_at"
                            type="datetime-local"
                            step="1"
                            :max="now_utc.slice(0, -1)"
                            required
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.observed_at"
                            aria-describedby="rate-observed-error"
                        /><InputError
                            id="rate-observed-error"
                            :message="form.errors.observed_at"
                        />
                    </div>
                    <div>
                        <Label for="rate-effective">Effective at · UTC</Label
                        ><Input
                            id="rate-effective"
                            v-model="form.effective_at"
                            type="datetime-local"
                            step="1"
                            :max="now_utc.slice(0, -1)"
                            required
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.effective_at"
                            aria-describedby="rate-effective-error"
                        /><InputError
                            id="rate-effective-error"
                            :message="form.errors.effective_at"
                        />
                    </div>
                    <div v-if="prior">
                        <Label for="rate-reason">Reason for revision</Label
                        ><Input
                            id="rate-reason"
                            v-model="form.correction_reason"
                            required
                            maxlength="180"
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.correction_reason"
                            aria-describedby="rate-reason-error"
                        /><InputError
                            id="rate-reason-error"
                            :message="form.errors.correction_reason"
                        />
                    </div>
                </fieldset>
                <p class="sr-only" role="status">
                    {{ form.processing ? 'Saving reporting observation' : '' }}
                </p>
                <Button
                    type="submit"
                    :disabled="form.processing"
                    :aria-busy="form.processing"
                    class="mt-6 min-h-11 w-full"
                    ><span>{{
                        form.processing
                            ? 'Saving…'
                            : prior
                              ? 'Save new version'
                              : 'Save reporting observation'
                    }}</span></Button
                ><Link
                    v-if="prior"
                    href="/rates"
                    class="mt-3 inline-flex min-h-10 items-center text-sm underline"
                    >Cancel revision</Link
                >
            </form>
            <section class="min-w-0 rounded-2xl border p-5 sm:p-7">
                <h2 class="font-medium">
                    Retained observations
                    <span class="text-xs font-normal text-muted-foreground"
                        >Latest 30</span
                    >
                </h2>
                <p
                    v-if="!observations.length"
                    class="mt-5 text-sm leading-relaxed text-muted-foreground"
                >
                    No reporting rates yet. Native balances stay visible;
                    unsupported conversions remain explicitly missing.
                </p>
                <ol v-else class="mt-4 divide-y">
                    <li
                        v-for="rate in observations"
                        :key="rate.id"
                        class="min-w-0 py-4"
                    >
                        <p class="font-medium [overflow-wrap:anywhere]">
                            {{ rate.value }} {{ rate.quote_currency }}/{{
                                rate.base_currency
                            }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Version {{ rate.version }}
                        </p>
                        <ObservationTimeEvidence :timing="rate" />
                        <p class="mt-2 text-sm [overflow-wrap:anywhere]">
                            {{ rate.source }}
                        </p>
                        <p
                            v-if="rate.correction_reason"
                            class="mt-2 text-xs [overflow-wrap:anywhere] text-muted-foreground"
                        >
                            Revision: {{ rate.correction_reason }}
                        </p>
                        <Link
                            :href="`/rates?supersedes=${rate.id}`"
                            class="mt-2 inline-flex min-h-10 items-center text-sm underline"
                            >Revise with a new version</Link
                        >
                    </li>
                </ol>
                <p
                    class="mt-5 border-t pt-4 text-xs leading-relaxed text-muted-foreground"
                >
                    Reports use direct observations effective and observed
                    at/before their cutoff. More than seven days old is stale
                    and excluded from complete totals.
                </p>
            </section>
        </div>
    </div>
</template>
