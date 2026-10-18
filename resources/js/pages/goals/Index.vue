<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowUpRight, ShieldCheck, Plus, Target } from '@lucide/vue';
import BudgetAmount from '@/components/BudgetAmount.vue';
import { Button } from '@/components/ui/button';
import type { Goal } from '@/types/goal';
defineOptions({
    layout: { breadcrumbs: [{ title: 'Goals & protection', href: '/goals' }] },
});
defineProps<{ goals: Goal[]; month: string; next: string | null }>();
</script>
<template>
    <Head title="Goals & protection" />
    <div class="mx-auto w-full max-w-6xl space-y-8 px-4 py-8 sm:px-8 sm:py-12">
        <header class="flex flex-wrap items-end justify-between gap-5">
            <div class="max-w-2xl">
                <p
                    class="text-xs font-semibold tracking-[0.18em] text-teal-800 uppercase dark:text-teal-200"
                >
                    Make room for what matters
                </p>
                <h1
                    class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl"
                >
                    Goals & protection.
                </h1>
                <p class="mt-4 leading-relaxed text-muted-foreground">
                    Give existing cash a purpose. See what is backed today, what
                    is reserved, and what is still part of your plan.
                </p>
            </div>
            <Button as-child
                ><Link href="/goals/new"
                    ><Plus class="size-4" />Create a goal</Link
                ></Button
            >
        </header>
        <section
            class="grid gap-5 rounded-2xl border border-teal-200 bg-teal-50 p-6 sm:grid-cols-[auto_1fr] dark:border-teal-900 dark:bg-teal-950/40"
        >
            <ShieldCheck class="size-8 text-teal-800 dark:text-teal-200" />
            <div>
                <h2 class="font-semibold">
                    One source of cash. One clear purpose.
                </h2>
                <p
                    class="mt-2 max-w-3xl text-sm leading-relaxed text-muted-foreground"
                >
                    Dedicated accounts belong exclusively to one open goal.
                    Reservations share only the cash still available in an
                    account. Expected deposits never increase your funded
                    progress.
                </p>
            </div>
        </section>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">Your goals</h2>
            <form class="flex items-center gap-2" action="/goals">
                <label for="month" class="text-sm text-muted-foreground"
                    >Coverage month</label
                ><input
                    id="month"
                    name="month"
                    type="month"
                    :value="month"
                    class="min-h-10 rounded-lg border bg-background px-3"
                /><Button variant="outline" type="submit">View</Button>
            </form>
        </div>
        <section
            v-if="!goals.length"
            class="rounded-2xl border border-dashed p-10 text-center"
        >
            <Target class="mx-auto size-10 text-teal-700 dark:text-teal-300" />
            <h2 class="mt-5 text-xl font-semibold">Start with a purpose.</h2>
            <p
                class="mx-auto mt-3 max-w-md leading-relaxed text-muted-foreground"
            >
                Create an emergency reserve or another savings goal, then choose
                the real cash that backs it.
            </p>
            <Button as-child class="mt-6"
                ><Link href="/goals/new">Create your first goal</Link></Button
            >
        </section>
        <section v-else class="grid gap-5 lg:grid-cols-2" aria-label="Goals">
            <article
                v-for="g in goals"
                :key="g.id"
                class="flex min-w-0 flex-col rounded-2xl border bg-card p-6 shadow-sm"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p
                            class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                        >
                            {{
                                g.closed
                                    ? 'Closed'
                                    : g.mode === 'dedicated'
                                      ? 'Dedicated account'
                                      : 'Cash reservations'
                            }}
                        </p>
                        <h3 class="mt-2 text-xl font-semibold break-words">
                            {{ g.name }}
                        </h3>
                        <p
                            class="mt-1 text-sm break-words text-muted-foreground"
                        >
                            {{ g.account_name }}
                        </p>
                    </div>
                    <ShieldCheck
                        class="size-5 shrink-0 text-teal-700 dark:text-teal-300"
                    />
                </div>
                <div class="mt-7">
                    <p class="text-sm text-muted-foreground">Actually backed</p>
                    <p class="mt-1 text-3xl font-semibold tracking-tight">
                        <BudgetAmount v-if="g.backed" :value="g.backed" /><span
                            v-else
                            >Unavailable</span
                        >
                    </p>
                    <p class="mt-2 text-sm text-muted-foreground">
                        of <BudgetAmount :value="g.target" /> target
                    </p>
                </div>
                <p
                    v-if="g.shortfall && g.shortfall.minor !== '0'"
                    class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950/50 dark:text-amber-100"
                >
                    Backing shortfall: <BudgetAmount :value="g.shortfall" />. A
                    ledger withdrawal reduced the cash behind this goal.
                </p>
                <div class="mt-6 grid grid-cols-2 gap-4 border-t pt-5 text-sm">
                    <div>
                        <p class="text-muted-foreground">Further deposits</p>
                        <p class="mt-1 font-medium">
                            {{
                                g.projection.deposits ??
                                (g.projection.status === 'unreachable'
                                    ? 'Unreachable'
                                    : 'Not projected')
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Essential coverage</p>
                        <p class="mt-1 font-medium">
                            {{
                                g.projection.coverage_months === null
                                    ? 'Unavailable'
                                    : g.projection.coverage_months + ' months'
                            }}
                        </p>
                    </div>
                </div>
                <Link
                    :href="'/goals/' + g.id + '?month=' + month"
                    class="mt-6 inline-flex min-h-11 items-center justify-between gap-3 rounded-lg bg-muted/60 px-4 text-sm font-medium hover:bg-muted"
                    >View goal & backing<ArrowUpRight class="size-4"
                /></Link>
            </article>
        </section>
        <Link
            v-if="next"
            :href="'/goals?month=' + month + '&before=' + next"
            class="inline-flex min-h-11 items-center underline"
            >Older goals →</Link
        >
        <p class="text-xs leading-relaxed text-muted-foreground">
            Coverage uses your selected month’s native-currency essential plan.
            It does not change your chosen target or verify money with a bank.
        </p>
    </div>
</template>
