<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import CashAvailabilityPanel from '@/components/CashAvailabilityPanel.vue';
import type { CashSnapshot } from '@/types/cash';
import { dashboard } from '@/routes';
import { edit as profile } from '@/routes/profile';
import { edit as security } from '@/routes/security';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] },
});
defineProps<{ cash: CashSnapshot }>();
</script>

<template>
    <Head title="Cash clarity" />
    <section
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 p-4 sm:p-8"
    >
        <CashAvailabilityPanel :cash="cash" />
        <nav aria-label="Workspace shortcuts" class="flex flex-wrap gap-4">
            <Link
                :href="profile()"
                class="rounded border px-4 py-2 underline focus-visible:outline-2"
                >Profile settings</Link
            >
            <Link
                :href="security()"
                class="rounded border px-4 py-2 underline focus-visible:outline-2"
                >Security settings</Link
            >
        </nav>
    </section>
</template>
