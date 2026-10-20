<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PurificationShell from '@/components/PurificationShell.vue';
import type { Distribution } from '@/types/purification';
defineProps<{ distributions: Distribution[]; next: string | null }>();
</script>
<template>
    <PurificationShell title="Choose a recorded distribution."
        ><template #intro
            >The applicable base comes from the source instruction. Portfolio
            market value is never substituted for distribution income.</template
        >
        <p v-if="!distributions.length" class="rounded-xl border p-6">
            No current distributions on this page. Record an actual distribution
            from its portfolio activity before attaching an obligation.
        </p>
        <article
            v-for="d in distributions"
            :key="d.id"
            class="rounded-xl border p-5"
        >
            <p>
                {{ d.effective_date }} · Gross {{ d.currency }}
                {{ d.amount.amount }}
            </p>
            <p class="my-2 text-sm">{{ d.reference }}</p>
            <Link
                :href="'/purification/new?distribution=' + d.id"
                class="inline-flex min-h-11 items-center underline"
                >Attach sourced instruction</Link
            >
        </article>
        <Link
            v-if="next"
            :href="'/purification/new?after=' + next"
            class="inline-flex min-h-11 items-center underline"
            >Older recorded distributions</Link
        ><Link
            href="/portfolio"
            class="inline-flex min-h-11 items-center underline"
            >Open portfolio activity</Link
        ></PurificationShell
    >
</template>
