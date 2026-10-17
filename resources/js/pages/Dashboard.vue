<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import { dashboard } from '@/routes';
import { edit as profile } from '@/routes/profile';
import { edit as security } from '@/routes/security';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] },
});
</script>

<template>
    <Head title="Private workspace" />
    <section class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-6">
        <header>
            <p class="text-sm text-muted-foreground">Halal Wealth OS</p>
            <h1 class="text-2xl font-semibold">Your private workspace</h1>
            <p class="mt-2 text-muted-foreground">
                Manage your profile and keep your account secure.
            </p>
        </header>
        <section
            aria-labelledby="empty-state-heading"
            class="rounded-xl border p-8"
        >
            <h2 id="empty-state-heading" class="text-lg font-medium">
                No financial records yet
            </h2>
            <p class="mt-2 text-muted-foreground">
                No amounts are available to display.
            </p>
            <p class="mt-4 text-sm">
                Amount: <CurrencyDisplay :amount="null" :currency="null" />
            </p>
        </section>
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
