<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
defineProps<{ title: string }>();
</script>
<template>
    <Head :title="title" />
    <div
        class="mx-auto w-full max-w-6xl space-y-7 px-4 py-8 [overflow-wrap:anywhere] sm:px-8 sm:py-12"
    >
        <nav class="flex flex-wrap gap-5 text-sm underline">
            <Link class="min-h-11 content-center" href="/opportunities"
                >← Opportunities</Link
            ><Link class="min-h-11 content-center" href="/dashboard"
                >Dashboard</Link
            >
        </nav>
        <header class="max-w-3xl">
            <p
                class="text-xs font-semibold tracking-widest text-teal-800 uppercase dark:text-teal-200"
            >
                Decisions &amp; watchlist
            </p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">
                {{ title }}
            </h1>
            <div class="mt-3 text-muted-foreground"><slot name="intro" /></div>
        </header>
        <slot />
        <footer class="border-t pt-5 text-xs text-muted-foreground">
            A proposal records intent. Reservations protect existing cash;
            actual trades are recorded separately. Source claims require review.
            No offer access, current price, religious ruling or recommendation
            is inferred.
        </footer>
    </div>
</template>
