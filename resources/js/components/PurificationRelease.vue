<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import { purificationAttemptScope } from '@/composables/purificationAttemptScope';
const props = defineProps<{
    released: boolean;
    allocationId: string;
    obligationId: string;
}>();
const { form, feedback, locked, recovered, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) +
        ':' +
        purificationAttemptScope('release', props.allocationId),
    { reason: '' },
);
</script>
<template>
    <form
        v-if="!released || locked"
        class="mt-4 space-y-3 border-t pt-4"
        @submit.prevent="
            submit('/purification/allocations/' + allocationId + '/release')
        "
    >
        <PortfolioAttempt
            :errors="form.errors"
            :uncertain="feedback.uncertain.value"
            :recovered="recovered"
            :history="'/purification/obligations/' + obligationId"
        /><Label :for="'release-' + allocationId"
            >Reason to release this full allocation · no cash movement</Label
        ><Input
            :id="'release-' + allocationId"
            v-model="form.reason"
            required
            maxlength="180"
            :disabled="locked || form.processing"
        /><Button type="submit" variant="outline" :disabled="form.processing">{{
            locked
                ? 'Retry exact release'
                : 'Release full allocation · retain history'
        }}</Button>
    </form>
</template>
