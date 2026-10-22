<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import FinancialEvidenceView from '@/components/FinancialEvidence.vue';
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
import type { FinancialEvidence } from '@/types/ledger';
defineOptions({
    layout: { breadcrumbs: [{ title: 'Balance reports', href: '/reports' }] },
});
const props = defineProps<{
    evidence: FinancialEvidence;
    saved: { id: string; reference: string; recorded_at: string } | null;
    reports: {
        id: string;
        reference: string;
        cutoff: string;
        base_currency: string;
    }[];
    currencies: string[];
}>();
const page = usePage();
const form = useForm(
    `report:${page.props.auth.user.id}:${props.saved?.id ?? props.evidence.cutoff}`,
    {
        idempotency_key: crypto.randomUUID(),
        base_currency: props.evidence.base_currency,
        cutoff: props.evidence.cutoff.slice(0, -1),
    },
);
const feedback = useLedgerFeedback(
    () => form.errors,
    () => form.clearErrors(),
);
const preview = () =>
    form
        .transform((data) => ({
            base_currency: data.base_currency,
            cutoff: toLedgerUtc(data.cutoff),
        }))
        .get('/reports', {
            ...feedback.options(() => {}),
            preserveState: true,
            preserveScroll: true,
        });
const save = () =>
    form
        .transform((data) => ({
            ...data,
            cutoff: `${props.evidence.cutoff}`,
            base_currency: props.evidence.base_currency,
        }))
        .post(
            '/reports',
            feedback.options(() => {
                form.idempotency_key = crypto.randomUUID();
            }),
        );
</script>
<template>
    <Head title="Balance reports" />
    <div class="mx-auto w-full max-w-5xl space-y-7 px-4 py-8 sm:px-8 sm:py-12">
        <LedgerNav />
        <header class="max-w-2xl">
            <p
                class="text-xs font-medium tracking-[0.18em] text-muted-foreground uppercase"
            >
                NATIVE FIRST, EVIDENCE RETAINED
            </p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight">
                A balance you can explain.
            </h1>
            <p class="mt-3 leading-relaxed text-muted-foreground">
                See each native balance beside its reporting value. Missing or
                stale rates stay visible, with a covered subtotal when a
                complete total is unavailable.
            </p>
        </header>
        <form
            class="rounded-2xl border bg-card p-5 sm:p-7"
            @submit.prevent="preview"
        >
            <h2 class="font-medium">Choose a new preview</h2>
            <div class="mt-5 grid gap-5 sm:grid-cols-[1fr_2fr_auto]">
                <div>
                    <Label for="report-base">Reporting currency</Label
                    ><select
                        id="report-base"
                        v-model="form.base_currency"
                        class="mt-2 min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm"
                    >
                        <option v-for="currency in currencies" :key="currency">
                            {{ currency }}
                        </option>
                    </select>
                </div>
                <div>
                    <Label for="report-cutoff">Cutoff · UTC</Label
                    ><Input
                        id="report-cutoff"
                        v-model="form.cutoff"
                        type="datetime-local"
                        step="1"
                        required
                        class="mt-2 min-h-11"
                        :aria-invalid="!!form.errors.cutoff"
                        aria-describedby="report-cutoff-error"
                    /><InputError
                        id="report-cutoff-error"
                        :message="form.errors.cutoff"
                    />
                </div>
                <Button type="submit" class="min-h-11 self-end"
                    >Preview balances</Button
                >
            </div>
            <p class="mt-4 text-xs text-muted-foreground">
                Preview your selection before saving. Saving captures current
                visible entries for the displayed cutoff.
            </p>
        </form>
        <div v-if="saved" class="rounded-xl border bg-muted/30 p-5">
            <p class="font-medium">Saved report</p>
            <p class="mt-1 text-sm [overflow-wrap:anywhere]">
                {{ saved.reference }}
            </p>
            <p class="mt-2 text-xs text-muted-foreground">
                Recorded {{ saved.recorded_at }}. This evidence is retained
                unchanged when quotes or ledger entries change.
            </p>
            <Link
                href="/reports"
                class="mt-3 inline-flex min-h-10 items-center text-sm underline"
                >Start a current preview</Link
            >
        </div>
        <FinancialEvidenceView :evidence="evidence" show-total />
        <form
            v-if="!saved"
            class="rounded-xl border p-5"
            @submit.prevent="save"
        >
            <LedgerFeedback
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                history="/reports"
            />
            <p class="text-sm text-muted-foreground">
                Save an immutable capture for {{ evidence.base_currency }} at
                {{ evidence.cutoff }}. A fresh snapshot is taken when you save.
            </p>
            <p class="sr-only" role="status">
                {{ form.processing ? 'Saving balance report' : '' }}
            </p>
            <Button
                type="submit"
                :disabled="form.processing"
                :aria-busy="form.processing"
                class="mt-4 min-h-11"
                >{{
                    form.processing ? 'Saving…' : 'Save displayed cutoff'
                }}</Button
            >
        </form>
        <section class="rounded-2xl border p-5 sm:p-7">
            <h2 class="font-medium">
                Saved reports
                <span class="text-xs font-normal text-muted-foreground"
                    >Latest 20</span
                >
            </h2>
            <p
                v-if="!reports.length"
                class="mt-4 text-sm text-muted-foreground"
            >
                No saved reports yet. Preview your balances above, then retain a
                capture.
            </p>
            <ul v-else class="mt-4 divide-y">
                <li v-for="report in reports" :key="report.id" class="py-3">
                    <Link
                        :href="`/reports/${report.id}`"
                        class="inline-flex min-h-10 items-center text-sm [overflow-wrap:anywhere] underline"
                        >{{ report.base_currency }} · {{ report.cutoff }}</Link
                    >
                    <p
                        class="text-xs [overflow-wrap:anywhere] text-muted-foreground"
                    >
                        {{ report.reference }}
                    </p>
                </li>
            </ul>
        </section>
    </div>
</template>
