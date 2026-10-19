<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import PortfolioShell from '@/components/PortfolioShell.vue';
import ValuationSummary from '@/components/ValuationSummary.vue';
import type { ValuationResult } from '@/types/valuation';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Valuations', href: '/valuations' },
            { title: 'Saved run', href: '#' },
        ],
    },
});
const props = defineProps<{
    run: { id: string; reference: string; hash: string; created_at: string };
    evidence: ValuationResult;
}>();
const page = ref(0);
const count = props.evidence.ending.components?.length ?? 0;
const shown = computed(() => ({
    ...props.evidence,
    ending: {
        ...props.evidence.ending,
        components: props.evidence.ending.components?.slice(
            page.value * 20,
            page.value * 20 + 20,
        ),
    },
}));
</script>
<template>
    <PortfolioShell
        title="A retained view, exactly as saved."
        eyebrow="Immutable valuation run"
        back="/valuations"
        ><template #intro
            >This record keeps its original price and FX inputs. Later
            corrections cannot refresh this saved evidence in place.</template
        >
        <div
            class="rounded-2xl border bg-card p-5 text-sm [overflow-wrap:anywhere]"
        >
            <p>{{ run.reference }} · saved {{ run.created_at }}</p>
            <p class="mt-2">
                Scope:
                {{
                    evidence.scope_account_id
                        ? 'account ' + evidence.scope_account_id
                        : 'all owned financial accounts'
                }}
                · cutoff {{ evidence.cutoff }}
            </p>
            <a
                :href="'/valuations/' + run.id + '/export'"
                class="mt-3 inline-flex min-h-11 items-center underline"
                >Export full original evidence →</a
            >
        </div>
        <ValuationSummary :result="shown" />
        <nav
            v-if="count > 20"
            aria-label="Saved valuation components"
            class="flex flex-wrap gap-4"
        >
            <button
                :disabled="page === 0"
                class="min-h-11 rounded-lg border px-4 disabled:opacity-50"
                @click="page--"
            >
                Previous components</button
            ><span class="self-center text-sm"
                >Page {{ page + 1 }} · {{ count }} components</span
            ><button
                :disabled="(page + 1) * 20 >= count"
                class="min-h-11 rounded-lg border px-4 disabled:opacity-50"
                @click="page++"
            >
                Next components
            </button>
        </nav>
        <details class="rounded-xl border p-4 text-sm">
            <summary class="min-h-11 cursor-pointer py-3">
                Record identity and calculation policy
            </summary>
            <p class="mt-2 break-all">SHA-256 {{ run.hash }}</p>
            <p class="mt-2 break-all">{{ evidence.policy }}</p>
        </details>
        <Link
            href="/valuations"
            class="inline-flex min-h-11 items-center underline"
            >Calculate a separate refreshed view →</Link
        ></PortfolioShell
    >
</template>
