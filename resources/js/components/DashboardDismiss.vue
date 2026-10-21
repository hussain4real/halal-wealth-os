<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import PortfolioAttempt from './PortfolioAttempt.vue';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
const props = defineProps<{
    actionKey: string;
    recoveryOnly?: boolean;
    snapshotId: string;
    base: string;
}>();
const { form, locked, recovered, feedback, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) +
        ':dashboard-dismiss:' +
        props.actionKey,
    {
        snapshot_id: props.snapshotId,
        base_currency: props.base,
        action_key: props.actionKey,
        reason: '',
    },
);
</script>
<template>
    <details :open="locked" v-if="!recoveryOnly || locked" class="mt-3">
        <summary
            class="min-h-11 cursor-pointer content-center text-sm underline"
        >
            {{
                recoveryOnly
                    ? 'Recover pending dismissal'
                    : 'Dismiss this review episode'
            }}
        </summary>
        <form
            @submit.prevent="submit('/dashboard/dismissals')"
            class="mt-3 max-w-xl space-y-3"
        >
            <PortfolioAttempt
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                :recovered="recovered"
                history="/dashboard"
            />
            <fieldset :disabled="locked || form.processing">
                <label :for="'reason-' + actionKey" class="text-sm font-medium"
                    >Why dismiss this action?</label
                ><input
                    :id="'reason-' + actionKey"
                    v-model="form.reason"
                    required
                    maxlength="180"
                    class="mt-2 min-h-11 w-full rounded-lg border bg-background px-3"
                />
            </fieldset>
            <p class="text-xs text-muted-foreground">
                Only this explanation is dismissed. Changed evidence can raise a
                new review. Saved snapshots keep their original history.
            </p>
            <button
                :disabled="form.processing"
                class="min-h-11 rounded-lg border px-4 text-sm font-medium"
            >
                {{ locked ? 'Retry exact dismissal' : 'Dismiss action' }}
            </button>
        </form>
    </details>
</template>
