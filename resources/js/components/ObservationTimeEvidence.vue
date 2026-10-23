<script setup lang="ts">
import type {
    ObservationTiming,
    OpeningTiming,
} from '@/types/observation-time';
defineProps<{ timing: ObservationTiming | OpeningTiming }>();
</script>
<template>
    <span v-if="timing.provider_time" class="block">
        <span v-if="timing.provider_time.precision === 'date'" class="block">
            Source date {{ timing.provider_time.source_date }} ·
            {{ timing.provider_time.source_timezone }}
            <span class="block"
                >Daily date only; no publication time was supplied.</span
            >
        </span>
        <span v-else class="block"
            >Source observed {{ timing.observed_at }} · effective
            {{ timing.effective_at }}</span
        >
        <span class="block"
            >Received {{ timing.provider_time.received_at }}</span
        >
        <span v-if="timing.provider_time.precision === 'date'" class="block"
            >Eligible after {{ timing.provider_time.eligible_after }} under the
            completed-day policy.</span
        >
    </span>
    <span v-else-if="'effective_date' in timing" class="block"
        >Opening statement date {{ timing.effective_date }}</span
    >
    <span v-else class="block"
        >Effective {{ timing.effective_at }} · observed
        {{ timing.observed_at }}</span
    >
</template>
