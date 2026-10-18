<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PortfolioShell from '@/components/PortfolioShell.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Asset, Page } from '@/types/portfolio';
import { kindLabel } from '@/types/portfolio';
defineOptions({
    layout: { breadcrumbs: [{ title: 'Asset catalog', href: '/assets' }] },
});
defineProps<{ choices: Page<Asset>; query: string }>();
</script>
<template>
    <PortfolioShell
        title="Your asset references."
        eyebrow="Investments · catalog"
        back="/portfolio"
        ><template #intro
            >Keep the instrument details behind your opening positions. Each
            correction preserves its earlier reference version.</template
        >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <form action="/assets" class="flex min-w-0 flex-1 gap-2">
                <Label for="q" class="sr-only"
                    >Search asset name or identifier</Label
                ><Input
                    id="q"
                    name="q"
                    :default-value="query"
                    placeholder="Search name or identifier"
                    class="max-w-md min-w-0"
                /><Button variant="outline" type="submit">Search</Button>
            </form>
            <Button as-child
                ><Link href="/assets/new">Add asset reference</Link></Button
            >
        </div>
        <section
            v-if="!choices.items.length"
            class="rounded-2xl border border-dashed p-8"
        >
            <h2 class="text-xl font-semibold">Start with the instrument.</h2>
            <p class="mt-3 max-w-xl text-muted-foreground">
                No matching references yet. Add an equity, unitized fund or
                manually valued investment. You will record holdings separately
                in a portfolio account.
            </p>
        </section>
        <div class="grid gap-4 md:grid-cols-2">
            <Link
                v-for="asset in choices.items"
                :key="asset.id"
                :href="'/assets/' + asset.id"
                class="min-w-0 rounded-2xl border bg-card p-6 transition hover:border-teal-600 focus-visible:outline-2"
                ><p class="text-xs text-muted-foreground">
                    {{ kindLabel(asset.kind) }} · {{ asset.currency }} · v{{
                        asset.version
                    }}
                </p>
                <h2 class="mt-2 text-lg font-semibold [overflow-wrap:anywhere]">
                    {{ asset.name }}
                </h2>
                <p class="mt-3 text-sm [overflow-wrap:anywhere]">
                    {{ asset.identifier || 'No identifier recorded' }} ·
                    {{ asset.market || 'Market unspecified' }}
                </p>
                <p
                    class="mt-2 text-xs [overflow-wrap:anywhere] text-muted-foreground"
                >
                    Source: {{ asset.source }}
                </p>
                <p
                    v-if="asset.unsupported_terms"
                    class="mt-3 text-sm text-amber-900 dark:text-amber-200"
                >
                    Unsupported terms: {{ asset.unsupported_terms }}
                </p></Link
            >
        </div>
        <Link
            v-if="choices.next"
            :href="
                '/assets?q=' +
                encodeURIComponent(query) +
                '&after=' +
                choices.next
            "
            class="inline-flex min-h-11 items-center underline"
            >More references →</Link
        >
    </PortfolioShell>
</template>
