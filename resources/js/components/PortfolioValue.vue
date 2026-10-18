<script setup lang="ts">
import CurrencyDisplay from './CurrencyDisplay.vue';
import type { Contribution } from '@/types/portfolio';
import { modeLabel, evidenceLabel } from '@/types/portfolio';
defineProps<{ value: Contribution }>();
</script>
<template>
    <div class="min-w-0 rounded-2xl border bg-card p-5 sm:p-6">
        <p
            class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
        >
            {{ modeLabel(value.mode) }}
        </p>
        <p class="mt-3 text-2xl font-semibold [overflow-wrap:anywhere]">
            <CurrencyDisplay
                v-if="value.value"
                :amount="value.value.amount"
                :currency="value.value.currency"
            /><span v-else>Value unavailable</span>
        </p>
        <p class="mt-2 text-xs text-muted-foreground">
            Selected net-worth contribution · UTC {{ value.as_of }}
        </p>
        <div class="mt-4 border-t pt-3 text-sm">
            <p v-for="entry in value.selected" :key="entry">
                Included: {{ evidenceLabel(entry) }}
            </p>
            <p
                v-for="entry in value.excluded"
                :key="entry"
                class="mt-1 text-muted-foreground"
            >
                Excluded: {{ evidenceLabel(entry) }}
            </p>
        </div>
        <ul
            v-if="value.missing.length"
            class="mt-4 list-disc space-y-1 pl-5 text-sm text-amber-900 dark:text-amber-200"
        >
            <li
                v-for="entry in [...new Set(value.missing.map(evidenceLabel))]"
                :key="entry"
            >
                {{ entry }}
            </li>
        </ul>
    </div>
</template>
