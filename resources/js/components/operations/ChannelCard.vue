<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { synchronizeOperationPolicy } from '@/lib/operation-policy';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type ChannelView = {
    channel: 'copilot' | 'market' | 'email' | 'drive';
    label: string;
    unit: string;
    state: 'paused' | 'exhausted' | 'within_limits';
    policy: {
        paused: boolean;
        generation: string;
        requests_daily: number;
        units_daily: number;
        retention_days: number;
    };
    usage: {
        requests_today: number;
        units_today: number;
        retained: number;
        pending: number;
        uncertain: number;
    };
    maximum_requests: number;
    maximum_units: number;
    metrics: {
        attempts: number;
        failed: number;
        maximum_elapsed_ms: number | null;
    };
};

const props = defineProps<{
    item: ChannelView;
    driveAllowances: { action: string; calls: number }[];
}>();
const form = useForm({ ...props.item.policy });
const saved = ref(false);
let previousPolicy = { ...props.item.policy };
watch(
    () => props.item.policy.generation,
    () => {
        // Refresh authority after a stop while preserving independently unsaved limit edits.
        Object.assign(
            form,
            synchronizeOperationPolicy(form, previousPolicy, props.item.policy),
        );
        previousPolicy = { ...props.item.policy };
    },
);

function save(paused: boolean) {
    saved.value = false;
    form.transform((data) => ({ ...data, paused })).post(
        `/operations/${props.item.channel}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                saved.value = true;
            },
        },
    );
}
</script>

<template>
    <section
        :aria-labelledby="`channel-${item.channel}`"
        class="space-y-4 rounded-xl border bg-card p-5 text-card-foreground"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h2 :id="`channel-${item.channel}`" class="text-lg font-semibold">
                {{ item.label }}
            </h2>
            <span class="rounded border px-2 py-1 text-sm">{{
                item.state === 'within_limits'
                    ? 'Capacity available'
                    : item.state === 'paused'
                      ? 'Paused'
                      : 'Budget exhausted'
            }}</span>
        </div>
        <p class="text-sm text-muted-foreground">
            Today: {{ item.usage.requests_today }} requests;
            {{ item.usage.units_today }} {{ item.unit.toLowerCase() }} reserved.
            Daily windows use UTC.
        </p>
        <p class="text-sm">
            Saved limits: {{ item.policy.requests_daily }} requests and
            {{ item.policy.units_daily }} reserved units per day.
        </p>
        <p
            v-if="item.channel === 'drive'"
            class="text-sm text-muted-foreground"
        >
            Each action reserves its full call allowance:
            {{
                driveAllowances
                    .map((item) => `${item.action} ${item.calls}`)
                    .join(', ')
            }}. Unused allowance stays charged.
        </p>
        <p
            v-if="item.channel === 'market'"
            class="text-sm text-muted-foreground"
        >
            One shared request can serve several owners. Your participation
            reserves one credit; the provider request is made once.
        </p>
        <p
            v-if="item.policy.paused && item.policy.generation === '0'"
            class="text-sm text-muted-foreground"
        >
            Save initial stopped limits, then explicitly resume when ready.
        </p>
        <form class="space-y-4" @submit.prevent="save(item.policy.paused)">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label :for="`requests-${item.channel}`"
                        >Daily request limit</Label
                    >
                    <Input
                        :id="`requests-${item.channel}`"
                        v-model.number="form.requests_daily"
                        type="number"
                        min="0"
                        :max="item.maximum_requests"
                        step="1"
                        required
                    />
                    <InputError :message="form.errors.requests_daily" />
                </div>
                <div class="space-y-2">
                    <Label :for="`units-${item.channel}`"
                        >Daily reserved-unit budget</Label
                    >
                    <Input
                        :id="`units-${item.channel}`"
                        v-model.number="form.units_daily"
                        type="number"
                        min="0"
                        :max="item.maximum_units"
                        step="1"
                        required
                    />
                    <InputError :message="form.errors.units_daily" />
                </div>
                <div class="space-y-2 sm:col-span-2">
                    <Label :for="`retention-${item.channel}`"
                        >Coarse operational metric retention (days)</Label
                    >
                    <Input
                        :id="`retention-${item.channel}`"
                        v-model.number="form.retention_days"
                        type="number"
                        min="1"
                        max="30"
                        step="1"
                        required
                    />
                    <p class="text-sm text-muted-foreground">
                        Expiry removes only coarse metrics. Usage charges and
                        financial/recovery history remain retained.
                    </p>
                    <InputError :message="form.errors.retention_days" />
                </div>
            </div>
            <InputError :message="form.errors.generation" />
            <div class="flex flex-wrap gap-3">
                <Button type="submit" :disabled="form.processing"
                    >Save limits</Button
                >
                <Button
                    v-if="item.policy.paused && item.policy.generation !== '0'"
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="save(false)"
                    >Resume {{ item.label.toLowerCase() }}</Button
                >
                <Button
                    v-else-if="!item.policy.paused"
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="save(true)"
                    >Pause {{ item.label.toLowerCase() }}</Button
                >
            </div>
            <p v-if="saved" role="status" class="text-sm">
                Settings saved. Existing consent and provider access still
                apply.
            </p>
        </form>
        <dl class="grid grid-cols-2 gap-3 border-t pt-4 text-sm">
            <div>
                <dt class="text-muted-foreground">Measured outcomes</dt>
                <dd>{{ item.metrics.attempts }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Unsuccessful or uncertain</dt>
                <dd>{{ item.metrics.failed }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Longest processing time</dt>
                <dd>
                    {{
                        item.metrics.maximum_elapsed_ms === null
                            ? 'No completed measurement'
                            : `${item.metrics.maximum_elapsed_ms} ms`
                    }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Outcome still unknown</dt>
                <dd>{{ item.usage.pending + item.usage.uncertain }}</dd>
            </div>
        </dl>
    </section>
</template>
