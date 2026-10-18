<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
defineProps<{ errors: object; uncertain: boolean; history: string }>();
</script>
<template>
    <div
        v-if="uncertain || Object.keys(errors).length"
        role="alert"
        tabindex="-1"
        data-ledger-error
        class="rounded-xl border border-destructive/35 bg-destructive/5 p-4 text-sm leading-relaxed focus-visible:outline-2"
    >
        <p v-if="uncertain">
            The response was interrupted. Your request may already be saved.
            Check history, or retry with these same details and request
            identity.
        </p>
        <ul v-else class="list-inside list-disc space-y-1">
            <li v-for="(error, key) in errors" :key="key">{{ error }}</li>
        </ul>
        <Link
            :href="history"
            class="mt-2 inline-flex min-h-10 items-center font-medium underline"
            >Check saved history</Link
        >
    </div>
</template>
