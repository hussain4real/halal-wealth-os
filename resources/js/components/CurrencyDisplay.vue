<script setup lang="ts">
import { computed } from 'vue';
import { currencyDisplay } from '@/lib/currencyDisplay';

const props = defineProps<{ amount: string | null; currency: string | null }>();
const display = computed(() => currencyDisplay(props.amount, props.currency));
</script>

<template>
    <span class="tabular-nums" :aria-label="display ?? 'Amount unavailable'">{{
        display ?? 'Not available'
    }}</span>
</template>
