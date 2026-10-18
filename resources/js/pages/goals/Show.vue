<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ShieldCheck, ArrowUpRight, History } from '@lucide/vue';
import BudgetAmount from '@/components/BudgetAmount.vue';
import GoalAction from '@/components/GoalAction.vue';
import { Button } from '@/components/ui/button';
import type { Goal, GoalMovement, GoalReservation } from '@/types/goal';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Goals & protection', href: '/goals' },
            { title: 'Goal', href: '#' },
        ],
    },
});
defineProps<{
    goal: Goal;
    month: string;
    movements: GoalMovement[];
    movement_next: string | null;
    reservations: GoalReservation[];
    reservation_next: string | null;
    version_next: string | null;
    versions: {
        id: string;
        version: string;
        reason: string | null;
        closed: boolean;
        created_at: string;
    }[];
}>();
const dateReasons: Record<string, string> = {
    first_deposit_missing:
        'Choose a next / first deposit date to see a completion date.',
    missed_plan_needs_update:
        'A planned date has passed. Update the next deposit date; no missed deposits are assumed paid.',
    date_out_of_range: 'The projected date exceeds the supported calendar.',
    unreachable: 'A positive gap with zero fixed contributions is unreachable.',
    variable: 'Variable deposits have no fixed completion date.',
    reached: 'Your target is already reached.',
    backing_unavailable:
        'Backing is unavailable, so progress cannot be projected.',
};
</script>
<template>
    <Head :title="goal.name" />
    <div
        class="mx-auto w-full max-w-6xl space-y-7 px-4 py-8 scheme-light sm:px-8 sm:py-12 dark:scheme-dark"
    >
        <Link
            href="/goals"
            class="inline-flex min-h-10 items-center text-sm text-muted-foreground underline"
            >← All goals</Link
        >
        <header class="flex flex-wrap items-end justify-between gap-5">
            <div class="min-w-0">
                <p
                    class="text-xs font-semibold tracking-[0.18em] text-teal-800 uppercase dark:text-teal-200"
                >
                    {{ goal.closed ? 'Closed goal' : 'Your protection plan' }}
                </p>
                <h1
                    class="mt-3 text-3xl font-semibold tracking-tight break-words sm:text-4xl"
                >
                    {{ goal.name }}
                </h1>
                <Link
                    :href="'/accounts/' + goal.account_id"
                    class="mt-3 inline-flex min-h-10 items-center gap-2 text-sm text-muted-foreground underline"
                    >{{ goal.account_name
                    }}<ArrowUpRight class="size-4 shrink-0"
                /></Link>
                <p class="text-sm text-muted-foreground">
                    {{
                        goal.mode === 'dedicated'
                            ? 'Exclusive dedicated account'
                            : 'Explicit cash reservations'
                    }}
                    · {{ goal.currency }}
                    {{ goal.account_archived ? '· Account archived' : '' }}
                </p>
            </div>
            <Button v-if="!goal.closed" as-child variant="outline"
                ><Link :href="'/goals/' + goal.id + '/revise'"
                    >Revise plan</Link
                ></Button
            >
        </header>
        <p v-if="goal.closed" class="rounded-xl border bg-muted/60 p-5 text-sm">
            This goal is closed and claims no current cash. Its opening
            snapshot, versions, reservations and journal annotations remain
            historical evidence.
        </p>
        <section
            class="grid gap-6 rounded-2xl border border-teal-200 bg-teal-50 p-6 sm:p-8 lg:grid-cols-[1.3fr_1fr] dark:border-teal-900 dark:bg-teal-950/40"
        >
            <div class="min-w-0">
                <div class="flex items-center gap-3">
                    <ShieldCheck
                        class="size-6 text-teal-800 dark:text-teal-200"
                    />
                    <h2 class="text-sm font-medium">Actually backed today</h2>
                </div>
                <p
                    class="mt-4 text-4xl font-semibold tracking-tight sm:text-5xl"
                >
                    <BudgetAmount
                        v-if="goal.backed"
                        :value="goal.backed"
                    /><span v-else>Unavailable</span>
                </p>
                <p class="mt-3 text-sm text-muted-foreground">
                    {{ goal.projection.percent ?? 'Unknown'
                    }}{{
                        goal.projection.percent !== null
                            ? '% of your target'
                            : ''
                    }}
                </p>
                <p
                    v-if="goal.backing_reason === 'negative_cash'"
                    class="mt-3 text-sm"
                >
                    This account has a negative balance. No positive cash is
                    backed.
                </p>
            </div>
            <div
                class="grid min-w-0 gap-5 border-t border-teal-200 pt-5 sm:grid-cols-2 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-6 dark:border-teal-900"
            >
                <div>
                    <p class="text-sm text-muted-foreground">
                        Your explicit target
                    </p>
                    <p class="mt-2 text-xl font-semibold">
                        <BudgetAmount :value="goal.target" />
                    </p>
                </div>
                <div>
                    <p class="text-sm text-muted-foreground">Still to fund</p>
                    <p class="mt-2 text-xl font-semibold">
                        <BudgetAmount
                            v-if="goal.projection.remaining"
                            :value="goal.projection.remaining"
                        /><span v-else>Unavailable</span>
                    </p>
                </div>
                <div>
                    <p class="text-sm text-muted-foreground">
                        {{
                            goal.mode === 'reserved'
                                ? 'Nominal reservations'
                                : 'Historical backing high-water'
                        }}
                    </p>
                    <p class="mt-2 font-medium">
                        <BudgetAmount :value="goal.nominal" />
                    </p>
                </div>
                <div>
                    <p class="text-sm text-muted-foreground">
                        Original opening snapshot
                    </p>
                    <p class="mt-2 font-medium">
                        <BudgetAmount :value="goal.opening" />
                    </p>
                </div>
            </div>
        </section>
        <section
            v-if="goal.shortfall && goal.shortfall.minor !== '0'"
            role="status"
            class="rounded-xl border border-amber-300 bg-amber-50 p-5 text-amber-950 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-100"
        >
            <h2 class="font-semibold">Part of this goal is underfunded.</h2>
            <p class="mt-2 text-sm leading-relaxed">
                Backing shortfall: <BudgetAmount :value="goal.shortfall" />.
                Later ledger withdrawals reduced available cash.
                {{
                    goal.mode === 'reserved'
                        ? 'Nominal reservations keep their history; current backing is allocated in oldest-reservation order across all goals sharing this account.'
                        : 'This dedicated account backs only this goal. Current backing follows its ledger balance; the recorded high-water is historical evidence, including retained corrections and cancellations.'
                }}
            </p>
        </section>
        <div class="grid gap-5 lg:grid-cols-2">
            <section class="min-w-0 rounded-2xl border bg-card p-6">
                <h2 class="text-lg font-semibold">The contribution plan</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Plans do not post deposits or increase cash.
                </p>
                <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm text-muted-foreground">
                            {{
                                goal.policy === 'fixed_monthly'
                                    ? 'Planned each month'
                                    : 'Variable contribution reference'
                            }}
                        </dt>
                        <dd class="mt-1 text-xl font-semibold">
                            <BudgetAmount :value="goal.contribution" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-muted-foreground">
                            Further deposits
                        </dt>
                        <dd class="mt-1 text-xl font-semibold">
                            {{
                                goal.projection.deposits ??
                                (goal.projection.status === 'unreachable'
                                    ? 'Unreachable'
                                    : 'Not projected')
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-muted-foreground">
                            Next / first planned date
                        </dt>
                        <dd class="mt-1 font-medium">
                            {{ goal.first_deposit ?? 'Not configured' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-muted-foreground">
                            Projected completion
                        </dt>
                        <dd class="mt-1 font-medium">
                            {{
                                goal.projection.completion_date ?? 'Unavailable'
                            }}
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="goal.projection.date_reason"
                    class="mt-5 rounded-lg bg-muted/60 p-4 text-sm leading-relaxed"
                >
                    {{
                        dateReasons[goal.projection.date_reason] ??
                        goal.projection.date_reason
                    }}
                </p>
                <p v-if="goal.target_date" class="mt-4 text-sm">
                    Target date: {{ goal.target_date }} ·
                    {{
                        goal.projection.target_date_status.replaceAll('_', ' ')
                    }}
                </p>
                <p class="mt-5 text-xs text-muted-foreground">
                    {{
                        goal.standing_order
                            ? 'Existing standing order is informational. '
                            : ''
                    }}Reminders off. No bank instructions or notifications.
                </p>
            </section>
            <section class="min-w-0 rounded-2xl border bg-card p-6">
                <h2 class="text-lg font-semibold">
                    Essential-expense coverage
                </h2>
                <p class="mt-4 text-3xl font-semibold">
                    {{
                        goal.projection.coverage_months === null
                            ? 'Unavailable'
                            : goal.projection.coverage_months + ' months'
                    }}
                </p>
                <p
                    v-if="goal.essential"
                    class="mt-4 text-sm text-muted-foreground"
                >
                    {{ month }} monthly essential run-rate:
                    <BudgetAmount :value="goal.essential" />.
                </p>
                <p v-else class="mt-4 text-sm text-muted-foreground">
                    No available essential run-rate in {{ goal.currency }} for
                    {{ month }}. Set emergency-essential schedules in your
                    monthly plan.
                </p>
                <p class="mt-4 text-sm leading-relaxed text-muted-foreground">
                    Coverage compares backed cash with the month-end essential
                    plan in this currency. A missing or zero denominator is
                    unavailable. Your explicit target stays separate.
                </p>
                <Link
                    :href="'/budgets?month=' + month"
                    class="mt-4 inline-flex min-h-10 items-center underline"
                    >Review essential plan →</Link
                >
                <form
                    :action="'/goals/' + goal.id"
                    class="mt-4 flex flex-wrap items-end gap-2"
                >
                    <div>
                        <label for="coverage-month" class="text-sm"
                            >Coverage month</label
                        ><input
                            id="coverage-month"
                            name="month"
                            type="month"
                            :value="month"
                            class="mt-2 block min-h-11 rounded-lg border bg-background px-3"
                        />
                    </div>
                    <Button variant="outline" type="submit">Update</Button>
                </form>
            </section>
        </div>
        <section
            v-if="goal.mode === 'reserved' && !goal.closed"
            class="rounded-2xl border bg-card p-6"
        >
            <h2 class="text-lg font-semibold">Reserve more existing cash</h2>
            <p class="mt-2 mb-5 text-sm leading-relaxed text-muted-foreground">
                This earmarks cash without transferring it. All open goals share
                this account’s capacity. A later withdrawal can leave
                reservations underfunded.
            </p>
            <GoalAction
                :key="'reserve-' + goal.id"
                :scope="'reserve:' + goal.root_id"
                :url="'/goals/' + goal.id + '/reservations'"
                title="Reserve cash"
                kind="reserve"
            />
        </section>
        <section class="rounded-2xl border bg-card p-5 sm:p-6">
            <h2 class="text-lg font-semibold">Reservation history</h2>
            <p
                v-if="!reservations.length"
                class="mt-4 text-sm text-muted-foreground"
            >
                {{
                    goal.mode === 'dedicated'
                        ? 'Dedicated backing follows the account balance; no separate reservation is added.'
                        : 'No reservations yet.'
                }}
            </p>
            <article
                v-for="r in reservations"
                :key="r.id"
                class="mt-5 min-w-0 border-t pt-5"
            >
                <div class="flex flex-col gap-2 sm:flex-row sm:justify-between">
                    <div class="min-w-0">
                        <p class="font-semibold">
                            <BudgetAmount :value="r.amount" /> ·
                            {{ r.released ? 'Released' : 'Reserved' }}
                        </p>
                        <p
                            class="mt-1 text-sm break-words text-muted-foreground"
                        >
                            {{ r.reason }}
                        </p>
                        <p v-if="r.release_reason" class="mt-1 text-sm">
                            Release: {{ r.release_reason }}
                        </p>
                        <p
                            class="mt-1 font-mono text-xs break-all text-muted-foreground"
                        >
                            {{ r.reference }}
                        </p>
                    </div>
                </div>
                <details v-if="!r.released && !goal.closed" class="mt-3">
                    <summary
                        class="inline-flex min-h-11 cursor-pointer items-center text-sm underline"
                    >
                        Release this reservation
                    </summary>
                    <div class="mt-3 max-w-xl rounded-xl bg-muted/40 p-4">
                        <GoalAction
                            :scope="'release:' + r.id"
                            :url="'/goals/reservations/' + r.id + '/release'"
                            title="Confirm release"
                            kind="release"
                        />
                    </div>
                </details>
            </article>
            <Link
                v-if="reservation_next"
                :href="
                    '/goals/' +
                    goal.id +
                    '?month=' +
                    month +
                    '&reservation_before=' +
                    reservation_next
                "
                class="mt-5 inline-flex min-h-11 items-center underline"
                >Older reservations →</Link
            >
        </section>
        <section class="rounded-2xl border bg-card p-5 sm:p-6">
            <div class="flex items-center gap-2">
                <History class="size-5" />
                <h2 class="text-lg font-semibold">Real account movements</h2>
            </div>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Existing posted cash activity, including transfers, withdrawals
                and reversals. Linking adds a note for this goal and never posts
                or increases a reservation. Backing always comes from the ledger
                balance.
            </p>
            <p
                v-if="!movements.length"
                class="mt-5 text-sm text-muted-foreground"
            >
                No posted movements on this page.
            </p>
            <article
                v-for="m in movements"
                :key="m.posting_id"
                class="mt-5 min-w-0 border-t pt-5"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:justify-between">
                    <div class="min-w-0">
                        <Link
                            :href="'/transactions/' + m.journal_id"
                            class="font-medium break-words underline"
                            >{{ m.description }}</Link
                        >
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ m.date }} UTC · {{ m.kind }} · {{ m.status }}
                        </p>
                        <p
                            v-if="m.reverses_journal_id"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            Reverses journal {{ m.reverses_journal_id }}
                        </p>
                        <p
                            class="mt-1 font-mono text-xs break-all text-muted-foreground"
                        >
                            {{ m.reference }}
                        </p>
                        <p
                            v-if="m.linked"
                            class="mt-2 text-sm break-words text-teal-800 dark:text-teal-200"
                        >
                            Linked · {{ m.link_reason }}
                        </p>
                    </div>
                    <p class="shrink-0 font-semibold">
                        <BudgetAmount :value="m.amount" />
                    </p>
                </div>
            </article>
            <Link
                v-if="movement_next"
                :href="
                    '/goals/' +
                    goal.id +
                    '?month=' +
                    month +
                    '&before=' +
                    movement_next
                "
                class="mt-5 inline-flex min-h-11 items-center underline"
                >Older movements →</Link
            >
            <details v-if="!goal.closed" class="mt-6 border-t pt-5">
                <summary
                    class="inline-flex min-h-11 cursor-pointer items-center font-medium underline"
                >
                    Link an existing contribution or withdrawal
                </summary>
                <div class="mt-4 max-w-xl rounded-xl bg-muted/40 p-4">
                    <GoalAction
                        :key="'link-' + goal.id"
                        :scope="'link:' + goal.root_id"
                        :url="'/goals/' + goal.id + '/links'"
                        title="Link movement"
                        kind="link"
                        :options="
                            movements.map((m) => ({
                                id: m.posting_id,
                                label:
                                    m.date +
                                    ' · ' +
                                    m.description +
                                    (m.linked ? ' · linked' : ''),
                            }))
                        "
                    />
                </div>
            </details>
        </section>
        <details class="rounded-2xl border bg-card p-6">
            <summary class="cursor-pointer font-medium">
                Plan version history
            </summary>
            <p class="mt-3 text-xs text-muted-foreground">
                Versions are paged in groups of 20. All original versions remain
                retained.
            </p>
            <ol class="mt-4 space-y-3">
                <li v-for="v in versions" :key="v.id" class="text-sm">
                    Version {{ v.version }} ·
                    {{ v.closed ? 'closed' : (v.reason ?? 'Initial plan')
                    }}<span class="mt-1 block text-xs text-muted-foreground">{{
                        v.created_at
                    }}</span>
                </li>
            </ol>
            <Link
                v-if="version_next"
                :href="
                    '/goals/' +
                    goal.id +
                    '?month=' +
                    month +
                    '&version_before=' +
                    version_next
                "
                class="mt-4 inline-flex min-h-11 items-center underline"
                >Older plan versions →</Link
            >
        </details>
    </div>
</template>
