<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import type { DecisionReservation } from '@/types/decisions';
const props = defineProps<{
    reservation: DecisionReservation;
    history: string;
}>();
const { form, locked, recovered, feedback, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) +
        ':decision-release:' +
        props.reservation.id,
    { reason: '' },
);
</script>
<template>
    <form
        v-if="!reservation.released || locked"
        class="mt-4 space-y-3 border-t pt-4"
        @submit.prevent="
            submit('/opportunities/reservations/' + reservation.id + '/release')
        "
    >
        <PortfolioAttempt
            :errors="form.errors"
            :uncertain="feedback.uncertain.value"
            :recovered="recovered"
            :history="history"
        />
        <label class="block space-y-2"
            ><span class="text-sm"
                >Reason to release reservation {{ reservation.id }}</span
            ><input
                v-model="form.reason"
                required
                maxlength="180"
                :disabled="locked || form.processing"
                :aria-invalid="!!form.errors.reason"
                class="min-h-11 w-full rounded-lg border bg-background px-3" /></label
        ><button
            :disabled="form.processing"
            class="min-h-11 rounded-lg border px-4 text-sm underline"
        >
            {{
                locked
                    ? 'Retry exact release request'
                    : 'Release this cash reservation'
            }}
        </button>
        <p class="text-xs text-muted-foreground">
            Releases its protection claim. No cash movement or trade is
            recorded.
        </p>
    </form>
</template>
