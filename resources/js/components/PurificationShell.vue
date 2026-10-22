<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
defineProps<{ title: string }>();
</script>
<template>
    <Head :title="title" />
    <div
        class="mx-auto w-full max-w-6xl space-y-7 px-4 py-8 [overflow-wrap:anywhere] sm:px-8 sm:py-12"
    >
        <Link
            href="/purification"
            class="inline-flex min-h-11 items-center text-sm underline"
            >← Purification records</Link
        >
        <header class="max-w-3xl">
            <p
                class="text-xs font-semibold tracking-widest text-teal-800 uppercase dark:text-teal-200"
            >
                Sourced obligations · recorded payments
            </p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">
                {{ title }}
            </h1>
            <div class="mt-3 text-muted-foreground"><slot name="intro" /></div>
        </header>
        <slot />
        <footer class="border-t pt-5 text-xs text-muted-foreground">
            Records follow the specifically attributed source method. This
            workflow does not issue religious rulings, calculate zakat, transfer
            money or liquidate investments. No default rate is supplied.
        </footer>
    </div>
</template>
