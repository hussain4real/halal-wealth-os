<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import LedgerFeedback from '@/components/LedgerFeedback.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useGoalForm } from '@/composables/useGoalForm';
const props = defineProps<{
    scope: string;
    url: string;
    title: string;
    kind: 'reserve' | 'release' | 'link';
    options?: { id: string; label: string }[];
}>();
const defaults: Record<string, string> = { reason: '' };
if (props.kind === 'reserve') defaults.amount = '';
if (props.kind === 'link') defaults.posting_id = '';
const { form, feedback, submit, recovered } = useGoalForm(
    String(usePage().props.auth.user.id) + ':' + props.scope,
    defaults,
);
</script>
<template>
    <form class="space-y-4" @submit.prevent="submit(url)">
        <LedgerFeedback
            :errors="form.errors"
            :uncertain="feedback.uncertain.value"
            history="/goals"
        />
        <p v-if="recovered" class="rounded-lg bg-muted p-3 text-sm">
            Interrupted attempt restored. Retry uses the original details and
            request identity.
        </p>
        <div v-if="kind === 'reserve'">
            <Label :for="scope + '-amount'">Amount to reserve</Label
            ><Input
                :id="scope + '-amount'"
                v-model="form.amount"
                inputmode="decimal"
                required
                :aria-invalid="!!form.errors.amount"
            /><InputError :message="form.errors.amount" />
        </div>
        <div v-if="kind === 'link'">
            <Label :for="scope + '-posting'">Existing movement</Label
            ><select
                :id="scope + '-posting'"
                v-model="form.posting_id"
                class="mt-2 min-h-11 w-full min-w-0 rounded-lg border bg-background px-3"
                required
            >
                <option value="">Choose a movement from this page</option>
                <option
                    v-if="
                        form.posting_id &&
                        !options?.some((o) => o.id === form.posting_id)
                    "
                    :value="form.posting_id"
                >
                    Saved attempt · posting {{ form.posting_id }}
                </option>
                <option v-for="o in options" :key="o.id" :value="o.id">
                    {{ o.label }}
                </option>
            </select>
        </div>
        <div>
            <Label :for="scope + '-reason'">Reason / note</Label
            ><Input
                :id="scope + '-reason'"
                v-model="form.reason"
                required
                maxlength="180"
                :aria-invalid="!!form.errors.reason"
            /><InputError :message="form.errors.reason" />
        </div>
        <Button type="submit" :disabled="form.processing">{{
            form.processing ? 'Saving…' : title
        }}</Button>
    </form>
</template>
