<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard, login } from '@/routes';
</script>

<template>
    <Head title="Private access" />
    <main
        class="flex min-h-screen items-center justify-center bg-background p-6 text-foreground"
    >
        <section class="w-full max-w-md space-y-5 rounded-xl border p-8">
            <h1 class="text-2xl font-semibold">Halal Wealth OS</h1>
            <p class="text-muted-foreground">
                A private workspace. Public signup is closed.
            </p>
            <Link
                v-if="$page.props.auth.user"
                :href="dashboard()"
                class="inline-flex rounded border px-4 py-2 underline focus-visible:outline-2"
                >Open workspace</Link
            >
            <Link
                v-else
                :href="login()"
                class="inline-flex rounded border px-4 py-2 underline focus-visible:outline-2"
                >Log in</Link
            >
        </section>
    </main>
</template>
