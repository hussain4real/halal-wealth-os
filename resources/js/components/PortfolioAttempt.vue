<script setup lang="ts">
import LedgerFeedback from './LedgerFeedback.vue';
defineProps<{
    errors: Record<string, string>;
    uncertain: boolean;
    recovered: boolean;
    history: string;
}>();
</script>
<template>
    <LedgerFeedback
        :errors="errors"
        :uncertain="uncertain"
        :history="history"
    />
    <p
        v-if="recovered"
        class="mb-5 rounded-xl bg-teal-50 p-4 text-sm text-teal-950 dark:bg-teal-950 dark:text-teal-100"
    >
        Your interrupted request is restored. Fields stay fixed so retry sends
        the exact same request. Check the saved history before starting another.
    </p>
</template>
