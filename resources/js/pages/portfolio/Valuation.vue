<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PortfolioShell from '@/components/PortfolioShell.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import ValuationSummary from '@/components/ValuationSummary.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import type { ValuationResult } from '@/types/valuation';
defineOptions({
    layout: { breadcrumbs: [{ title: 'Valuations', href: '/valuations' }] },
});
const props = defineProps<{
    input: {
        base_currency: string;
        cutoff: string;
        start: string;
        account_id: string;
    };
    result: ValuationResult;
    currencies: string[];
    accounts: { id: string; name: string; currency: string }[];
    next: string | null;
    runs_next: string | null;
    runs: { id: string; reference: string; created_at: string }[];
}>();
const queryErrors = computed(() => usePage().props.errors ?? {});
const { form, feedback, recovered, locked, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) + ':valuation',
    props.input,
);
const query = new URLSearchParams(props.input).toString();
</script>
<template>
    <PortfolioShell
        title="Value, with the evidence in view."
        eyebrow="Valuation · market movement & currency"
        back="/portfolio"
        ><template #intro
            >Choose a dated view of your recorded wealth. Prices and exchange
            rates come from retained manual observations. Nothing here places an
            order or predicts a return.</template
        >
        <form
            action="/valuations"
            class="grid gap-4 rounded-2xl border bg-card p-5 sm:grid-cols-2 sm:p-7"
        >
            <div
                v-if="Object.keys(queryErrors).length"
                role="alert"
                tabindex="-1"
                class="rounded-xl border p-4 text-sm sm:col-span-2"
            >
                <p class="font-medium">Review the valuation inputs.</p>
                <p
                    v-for="(message, field) in queryErrors"
                    :key="field"
                    class="mt-2"
                >
                    {{ field }}: {{ message }}
                </p>
            </div>
            <div>
                <Label for="scope">Account scope</Label
                ><select
                    id="scope"
                    name="account_id"
                    :value="input.account_id"
                    class="mt-2 min-h-11 w-full rounded-lg border bg-background px-3"
                >
                    <option value="">All owned financial accounts</option>
                    <option v-for="a in accounts" :key="a.id" :value="a.id">
                        {{ a.name }} · {{ a.currency }}
                    </option>
                </select>
            </div>
            <div>
                <Label for="base">Reporting currency</Label
                ><select
                    id="base"
                    name="base_currency"
                    :value="input.base_currency"
                    class="mt-2 min-h-11 w-full rounded-lg border bg-background px-3"
                >
                    <option v-for="c in currencies" :key="c">{{ c }}</option>
                </select>
            </div>
            <div>
                <Label for="cutoff">Valuation cutoff · UTC</Label
                ><Input
                    id="cutoff"
                    name="cutoff"
                    :default-value="input.cutoff"
                    required
                    placeholder="YYYY-MM-DDTHH:MM:SSZ"
                />
            </div>
            <div>
                <Label for="start">Compare from · UTC, optional</Label
                ><Input
                    id="start"
                    name="start"
                    :default-value="input.start"
                    placeholder="YYYY-MM-DDTHH:MM:SSZ"
                />
            </div>
            <p class="text-xs text-muted-foreground sm:col-span-2">
                Ledger facts use closing UTC business dates; manual prices and
                FX must also be effective and observed at or before the selected
                instant. Historical views reconstruct retained evidence, not
                what was known at a past commit time.
            </p>
            <Button type="submit" class="sm:justify-self-start"
                >Calculate dated view</Button
            >
        </form>
        <ValuationSummary :result="result" /><Link
            v-if="next"
            :href="'/valuations?' + query + '&after=' + next"
            class="inline-flex min-h-11 items-center underline"
            >More selected components →</Link
        >
        <section class="rounded-2xl border bg-card p-5 sm:p-7">
            <h2 class="text-xl font-semibold">Keep a reproducible record</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                A saved run retains its selected inputs and policy. New evidence
                creates a new calculation; earlier saved runs stay unchanged.
                Saving captures the latest retained evidence for these exact
                cutoffs and scope.
            </p>
            <form
                class="mt-5 space-y-4"
                @submit.prevent="submit('/valuations')"
            >
                <PortfolioAttempt
                    :errors="form.errors"
                    :uncertain="feedback.uncertain.value"
                    :recovered="recovered"
                    history="/valuations"
                />
                <p v-if="locked" class="text-sm break-words">
                    Retry scope: {{ form.account_id || 'all accounts' }} ·
                    {{ form.base_currency }} · {{ form.start || 'no period' }} →
                    {{ form.cutoff }}.
                </p>
                <div class="flex flex-wrap gap-3">
                    <Button type="submit" :disabled="form.processing">{{
                        form.processing
                            ? 'Saving…'
                            : locked
                              ? 'Retry same saved-run request'
                              : 'Save immutable run'
                    }}</Button
                    ><Button as-child variant="outline"
                        ><a :href="'/valuations/export?' + query"
                            >Export complete evidence</a
                        ></Button
                    >
                </div>
            </form>
        </section>
        <section v-if="runs.length" class="space-y-3">
            <h2 class="text-xl font-semibold">Recent saved runs</h2>
            <p class="text-sm text-muted-foreground">
                Up to twenty records per page. Each saved record has its own
                permanent authenticated link.
            </p>
            <Link
                v-for="r in runs"
                :key="r.id"
                :href="'/valuations/' + r.id"
                class="block rounded-xl border bg-card p-4 text-sm [overflow-wrap:anywhere] underline"
                >{{ r.reference }} · {{ r.created_at }}</Link
            >
        </section>
        <Link
            v-if="runs_next"
            :href="'/valuations?' + query + '&runs_after=' + runs_next"
            class="inline-flex min-h-11 items-center underline"
            >Older saved runs →</Link
        >
        <p class="text-xs break-all text-muted-foreground">
            Calculation policy: {{ result.policy }}
        </p></PortfolioShell
    >
</template>
