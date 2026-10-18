<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import PortfolioShell from '@/components/PortfolioShell.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import type { PortfolioAccount } from '@/types/portfolio';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Portfolio', href: '/portfolio' },
            { title: 'Valuation mode', href: '#' },
        ],
    },
});
const props = defineProps<{ account: PortfolioAccount; today: string }>();
const { form, feedback, recovered, locked, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) +
        ':mode:' +
        props.account.portfolio_id,
    {
        mode: props.account.mode ?? 'detailed',
        platform_label: props.account.platform_label,
        source: props.account.source,
        aggregate_value: props.account.aggregate_value?.amount ?? '',
        aggregate_as_of: props.account.aggregate_as_of ?? props.today,
        reason: '',
    },
);
const choose = (mode: string) => {
    form.mode = mode;
    if (mode === 'detailed') {
        form.aggregate_value = '';
        form.aggregate_as_of = '';
    } else if (!form.aggregate_as_of) form.aggregate_as_of = props.today;
};
if (!locked.value && form.mode === 'detailed') choose('detailed');
</script>
<template>
    <PortfolioShell
        title="Choose what counts."
        :back="'/portfolio/' + account.portfolio_id"
        ><template #intro
            >{{ account.name }} · {{ account.currency }}. Every change needs a
            reconciliation preview. You will see the selected and excluded
            evidence before confirming.</template
        >
        <form
            class="max-w-3xl rounded-2xl border bg-card p-5 sm:p-7"
            @submit.prevent="
                submit('/portfolio/' + account.portfolio_id + '/previews')
            "
        >
            <PortfolioAttempt
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                :recovered="recovered"
                :history="'/portfolio/' + account.portfolio_id"
            />
            <fieldset
                :disabled="form.processing || locked || account.archived"
                class="space-y-6"
            >
                <legend class="mb-4 text-lg font-semibold">
                    Valuation method
                </legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label
                        v-for="mode in ['detailed', 'aggregate']"
                        :key="mode"
                        class="flex cursor-pointer gap-3 rounded-xl border p-5"
                        :class="
                            form.mode === mode
                                ? 'border-teal-700 bg-teal-50 dark:bg-teal-950/40'
                                : ''
                        "
                        ><input
                            type="radio"
                            name="mode"
                            :checked="form.mode === mode"
                            :value="mode"
                            class="mt-1 size-4 shrink-0"
                            @change="choose(mode)"
                        /><span
                            ><strong class="block">{{
                                mode === 'detailed'
                                    ? 'Detailed holdings'
                                    : 'Whole-account value'
                            }}</strong
                            ><span
                                class="mt-2 block text-sm leading-relaxed text-muted-foreground"
                                >{{
                                    mode === 'detailed'
                                        ? 'Posted cash + opening positions. The whole-account value is excluded.'
                                        : 'One statement value, including all cash. Ledger cash and opening positions are excluded.'
                                }}</span
                            ></span
                        ></label
                    >
                </div>
                <div
                    v-if="form.mode === 'aggregate'"
                    class="grid gap-5 sm:grid-cols-2"
                >
                    <div>
                        <Label for="aggregate"
                            >Whole-account value · {{ account.currency }}</Label
                        ><Input
                            id="aggregate"
                            v-model="form.aggregate_value"
                            inputmode="decimal"
                            required
                            :aria-invalid="!!form.errors.aggregate_value"
                        />
                        <p class="mt-2 text-xs text-muted-foreground">
                            Include cash already held at this platform. A signed
                            value or explicit zero is allowed.
                        </p>
                    </div>
                    <div>
                        <Label for="asof">Statement as-of · UTC</Label
                        ><Input
                            id="asof"
                            v-model="form.aggregate_as_of"
                            type="date"
                            required
                            :max="today"
                            :aria-invalid="!!form.errors.aggregate_as_of"
                        />
                        <p class="mt-2 text-xs text-muted-foreground">
                            Usable only for this recorded date. An older value
                            leaves today's total incomplete.
                        </p>
                    </div>
                </div>
                <div>
                    <Label for="platform">Platform reference label</Label
                    ><Input
                        id="platform"
                        v-model="form.platform_label"
                        required
                        maxlength="180"
                    />
                </div>
                <div>
                    <Label for="source">Valuation source</Label
                    ><Input
                        id="source"
                        v-model="form.source"
                        required
                        maxlength="180"
                        :aria-invalid="!!form.errors.source"
                    />
                </div>
                <div>
                    <Label for="reason">Reason for this decision</Label
                    ><Input
                        id="reason"
                        v-model="form.reason"
                        required
                        maxlength="180"
                        :aria-invalid="!!form.errors.reason"
                    />
                </div>
            </fieldset>
            <p
                class="mt-6 rounded-xl bg-muted/50 p-4 text-sm text-muted-foreground"
            >
                Previewing saves review evidence only. Your mode, positions and
                ledger stay unchanged until confirmation. A preview expires
                after ten minutes or when its source evidence or UTC date
                changes.
            </p>
            <div class="mt-6 flex flex-wrap gap-3">
                <Button
                    type="submit"
                    :disabled="form.processing || (account.archived && !locked)"
                    >{{
                        form.processing
                            ? 'Preparing…'
                            : locked
                              ? 'Retry same preview request'
                              : 'Review reconciliation preview'
                    }}</Button
                ><Button as-child variant="outline"
                    ><Link :href="'/portfolio/' + account.portfolio_id"
                        >Cancel</Link
                    ></Button
                >
            </div>
        </form>
    </PortfolioShell>
</template>
