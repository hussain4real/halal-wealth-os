<script setup lang="ts">
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import type { BudgetMoney } from '@/types/budget';
defineProps<{ value: BudgetMoney }>();
</script>
<template>
    <span class="inline-block min-w-0 [overflow-wrap:anywhere]">
        <CurrencyDisplay :amount="value.amount" :currency="value.currency" />
        <span
            v-if="!value.available"
            class="mt-1 block text-xs font-normal text-muted-foreground"
        >
            {{
                value.reason === 'legacy_correction_timing'
                    ? 'Retained correction crosses ledger months; period comparison unavailable.'
                    : 'Outside the supported amount range.'
            }}
        </span>
    </span>
</template>
