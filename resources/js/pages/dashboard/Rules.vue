<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import { dashboardTargets } from '@/lib/dashboardTargets';
import type { CurrencyTarget, DashboardSettings } from '@/types/dashboard';
const props = defineProps<{
    settings: DashboardSettings;
    rules: Record<string, string>;
    currencies: string[];
}>();
const { form, locked, recovered, feedback, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) + ':dashboard-rules',
    () => ({
        settings_id: props.settings.id ?? '',
        grace_days: props.settings.data.grace_days,
        targets: JSON.stringify(props.settings.data.targets),
        reason: '',
        ...Object.fromEntries(
            Object.keys(props.rules).map((key) => [
                key,
                props.settings.data.enabled.includes(key) ? '1' : '0',
            ]),
        ),
    }),
    { targets: 4000 },
);
const parsed = computed(() => dashboardTargets(form.targets));
const rows = computed(() => (parsed.value.ok ? parsed.value.rows : []));
function update(index: number, key: keyof CurrencyTarget, event: Event) {
    if (
        !(event.target instanceof HTMLInputElement) &&
        !(event.target instanceof HTMLSelectElement)
    )
        return;
    const value = event.target.value;
    form.targets = JSON.stringify(
        rows.value.map((r, i) => (i === index ? { ...r, [key]: value } : r)),
    );
}
function add() {
    const currency = props.currencies.find(
        (c) => !rows.value.some((r) => r.currency === c),
    );
    if (currency)
        form.targets = JSON.stringify([
            ...rows.value,
            { currency, target: '0', tolerance: '0' },
        ]);
}
function remove(index: number) {
    form.targets = JSON.stringify(rows.value.filter((_, i) => i !== index));
}
</script>
<template>
    <Head title="Dashboard action rules" />
    <div
        class="mx-auto max-w-4xl space-y-7 p-4 [overflow-wrap:anywhere] sm:p-8"
    >
        <Link
            href="/dashboard"
            class="inline-block min-h-11 content-center text-sm underline"
            >← Financial overview</Link
        >
        <header>
            <h1 class="text-3xl font-semibold">
                Choose what needs your attention.
            </h1>
            <p class="mt-3 max-w-2xl text-muted-foreground">
                Rules explain recorded facts. They never make a financial
                decision, configure a payment or certify an investment.
            </p>
        </header>
        <form @submit.prevent="submit('/dashboard/rules')" class="space-y-6">
            <PortfolioAttempt
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                :recovered="recovered"
                history="/dashboard/rules"
            />
            <fieldset :disabled="locked || form.processing" class="space-y-6">
                <legend class="mb-4 font-semibold">Enabled review rules</legend>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label
                        v-for="(label, code) in rules"
                        :key="code"
                        class="flex min-h-11 items-center gap-3"
                        ><input
                            v-model="form[code]"
                            type="checkbox"
                            true-value="1"
                            false-value="0"
                            class="size-4"
                        />{{ label }}</label
                    >
                </div>
                <label class="grid max-w-sm gap-2 text-sm"
                    >Funding and thesis review grace (days)<input
                        v-model="form.grace_days"
                        inputmode="numeric"
                        required
                        maxlength="3"
                        class="min-h-11 rounded-lg border bg-background px-3"
                    /><span class="text-xs text-muted-foreground"
                        >An explicit operational delay of 0–365 days. Source
                        evidence validity stays independent.</span
                    ></label
                >
                <section
                    class="space-y-4 border-y py-5"
                    aria-labelledby="targets"
                >
                    <h2 id="targets" class="text-lg font-semibold">
                        Your currency allocation targets
                    </h2>
                    <p class="max-w-2xl text-sm text-muted-foreground">
                        Optional. Targets total exactly 100%. Give each held
                        currency an explicit target and tolerance in percentage
                        points. No target or reassuring comparison is inferred
                        from a partial valuation.
                    </p>
                    <p
                        v-if="!parsed.ok"
                        class="text-sm text-red-700 dark:text-red-200"
                    >
                        {{ parsed.message }}
                    </p>
                    <div
                        v-for="(r, i) in rows"
                        :key="i"
                        class="flex flex-wrap items-end gap-3"
                    >
                        <label class="grid gap-2 text-sm"
                            >Currency<select
                                :value="r.currency"
                                @change="update(i, 'currency', $event)"
                                class="min-h-11 rounded-lg border bg-background px-3"
                            >
                                <option v-for="c in currencies" :key="c">
                                    {{ c }}
                                </option>
                            </select></label
                        ><label class="grid gap-2 text-sm"
                            >Target %<input
                                :value="r.target"
                                @input="update(i, 'target', $event)"
                                inputmode="decimal"
                                required
                                maxlength="30"
                                class="min-h-11 w-32 rounded-lg border bg-background px-3" /></label
                        ><label class="grid gap-2 text-sm"
                            >Tolerance (points)<input
                                :value="r.tolerance"
                                @input="update(i, 'tolerance', $event)"
                                inputmode="decimal"
                                required
                                maxlength="30"
                                class="min-h-11 w-32 rounded-lg border bg-background px-3" /></label
                        ><button
                            type="button"
                            @click="remove(i)"
                            class="min-h-11 px-3 text-sm underline"
                        >
                            Remove {{ r.currency }}
                        </button>
                    </div>
                    <button
                        type="button"
                        @click="add"
                        :disabled="rows.length >= currencies.length"
                        class="min-h-11 rounded-lg border px-4 text-sm"
                    >
                        Add a currency target
                    </button>
                </section>
                <label class="grid gap-2 text-sm"
                    >Reason for this rule version<input
                        v-model="form.reason"
                        required
                        maxlength="180"
                        class="min-h-11 rounded-lg border bg-background px-3"
                /></label>
            </fieldset>
            <button
                :disabled="form.processing"
                class="min-h-11 rounded-lg bg-teal-800 px-5 font-medium text-white dark:bg-teal-200 dark:text-teal-950"
            >
                {{ locked ? 'Retry exact settings' : 'Save rule settings' }}
            </button>
        </form>
        <p class="text-xs text-muted-foreground">
            Configured standing orders do not generate setup reminders. A past
            plan date does not establish that a transfer failed. Dismissals
            apply only to the specific evidence episode; saved snapshots retain
            their original facts and rules.
        </p>
    </div>
</template>
