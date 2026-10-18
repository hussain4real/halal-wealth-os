<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
const page = usePage();
const links = [
    { href: '/fx', title: 'Exchanges' },
    { href: '/rates', title: 'Reporting rates' },
    { href: '/reports', title: 'Balance reports' },
    { href: '/reconciliations', title: 'Reconciliation' },
];
</script>
<template>
    <nav
        aria-label="Ledger tools"
        class="flex max-w-full gap-1 overflow-x-auto rounded-xl border bg-muted/30 p-1 text-sm"
    >
        <Link
            v-for="link in links"
            :key="link.href"
            :href="link.href"
            :aria-current="page.url.startsWith(link.href) ? 'page' : undefined"
            class="inline-flex min-h-10 shrink-0 items-center rounded-lg px-3 focus-visible:outline-2"
            :class="
                page.url.startsWith(link.href)
                    ? 'bg-background font-medium shadow-sm'
                    : 'text-muted-foreground hover:text-foreground'
            "
            >{{ link.title }}</Link
        >
    </nav>
</template>
