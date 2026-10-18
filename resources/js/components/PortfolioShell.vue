<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
defineProps<{ title: string; eyebrow?: string; back?: string }>();
</script>
<template>
    <Head :title="title" />
    <div
        class="mx-auto w-full max-w-6xl space-y-7 px-4 py-8 scheme-light sm:px-8 sm:py-12 dark:scheme-dark"
    >
        <Link
            :href="back ?? '/portfolio'"
            class="inline-flex min-h-10 items-center text-sm text-muted-foreground underline"
            >← {{ back === '/assets' ? 'Asset catalog' : 'Portfolio' }}</Link
        >
        <header class="max-w-3xl">
            <p
                class="text-xs font-semibold tracking-[0.18em] text-teal-800 uppercase dark:text-teal-200"
            >
                {{ eyebrow ?? 'Investments · manual evidence' }}
            </p>
            <h1
                class="mt-3 text-3xl font-semibold tracking-tight [overflow-wrap:anywhere] sm:text-4xl"
            >
                {{ title }}
            </h1>
            <div class="mt-3 leading-relaxed text-muted-foreground">
                <slot name="intro" />
            </div>
        </header>
        <slot />
        <footer
            class="border-t pt-5 text-xs leading-relaxed text-muted-foreground"
        >
            Platform names are reference labels only. No broker connection,
            verified ownership or Shariah status is implied. Values come from
            your recorded evidence; no live market prices are assumed.
        </footer>
    </div>
</template>
