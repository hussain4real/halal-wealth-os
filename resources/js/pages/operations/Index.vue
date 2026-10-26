<script setup lang="ts">
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import ChannelCard, {
    type ChannelView,
} from '@/components/operations/ChannelCard.vue';

defineProps<{
    operations: {
        global_paused: boolean;
        channels: ChannelView[];
        retained_limit: number;
        drive_allowances: { action: string; calls: number }[];
        health: Record<string, number>;
        access: Record<string, string>;
    };
}>();
const healthGroups = [
    {
        title: 'Market observations',
        rows: [
            ['market_monitored_mappings', 'Mappings checked'],
            ['market_stale_observations', 'Stale observations'],
            ['market_missing_observations', 'Missing observations'],
            ['market_unadmitted_samples', 'New samples not yet admitted'],
        ],
    },
    {
        title: 'Import review',
        rows: [
            ['import_previews_sampled', 'Recent previews checked'],
            ['import_previews_unavailable', 'Previews needing inspection'],
            ['import_rows_ready', 'Rows ready for review'],
            ['import_rows_retained', 'Rows already retained'],
            ['import_rows_invalid', 'Invalid rows'],
            ['import_rows_ambiguous', 'Ambiguous matches'],
            ['import_rows_identity_conflict', 'Identity conflicts'],
        ],
    },
    {
        title: 'Email delivery',
        rows: [
            ['email_unclaimed', 'Waiting for first attempt'],
            ['email_uncertain', 'Unconfirmed outcomes — inspect before acting'],
            ['email_retryable', 'Proved safe to retry within native limits'],
            ['email_exhausted', 'Retry limit reached'],
        ],
    },
    {
        title: 'Other recovery work',
        rows: [
            [
                'notification_claims_without_result',
                'Reminder work without a confirmed result',
            ],
            ['drive_unknown', 'Unconfirmed Drive operations'],
            ['drive_reconnect', 'Drive connections needing reconnect'],
        ],
    },
] as const;
const retainedTotals = [
    ['market_retained_mappings', 'Market mapping history'],
    ['market_retained_admissions', 'Retained market admissions'],
    ['imports_snapshots', 'Retained import previews'],
    ['imports_receipts', 'Retained import receipts'],
    ['notification_intents', 'Retained reminder intents'],
] as const;
const pause = useForm({});
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Optional feature controls', href: '/operations' },
        ],
    },
});
</script>

<template>
    <Head title="Optional feature controls" />
    <div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
        <header class="space-y-4">
            <h1 class="text-2xl font-semibold">Optional feature controls</h1>
            <p class="max-w-3xl text-muted-foreground">
                Pause optional work, set hard usage limits and check safe
                operational summaries. Manual financial pages remain available.
                Calls already sent can finish after a pause; unknown outcomes
                keep their original recovery rules.
            </p>
            <form
                @submit.prevent="
                    pause.post('/operations/pause', { preserveScroll: true })
                "
            >
                <Button
                    type="submit"
                    variant="destructive"
                    class="bg-red-700 text-white hover:bg-red-800 dark:bg-red-700 dark:hover:bg-red-800"
                    :disabled="pause.processing"
                    >Pause all optional work</Button
                >
            </form>
            <Button
                type="button"
                variant="outline"
                @click="router.reload({ only: ['operations'] })"
                >Refresh saved settings and health</Button
            >
            <p
                v-if="operations.global_paused"
                role="status"
                class="rounded border p-3"
            >
                The operator stop is active. Owner settings cannot lift it.
            </p>
            <p class="rounded border bg-muted p-3 text-sm">
                Market, email and Drive remain disabled for live use and support
                synthetic testing only. Copilot needs separate enabled
                transport, current consent and explicit disclosure. Resuming a
                channel grants no provider access.
            </p>
        </header>
        <div class="grid items-start gap-5 lg:grid-cols-2">
            <ChannelCard
                v-for="item in operations.channels"
                :key="item.channel"
                :item="item"
                :drive-allowances="operations.drive_allowances"
            />
        </div>
        <section
            aria-labelledby="operational-health"
            class="space-y-4 rounded-xl border p-5"
        >
            <h2 id="operational-health" class="text-lg font-semibold">
                Owned operational summary
            </h2>
            <p class="text-sm text-muted-foreground">
                Counts exclude prompts, document contents, financial figures,
                destinations and provider error text. Processing times include
                orchestration. Pending or uncertain work needs inspection before
                another action. Import row counts cover at most the latest three
                retained previews; market counts cover up to four bounded pages.
            </p>
            <p
                v-if="operations.health.market_summary_truncated"
                role="status"
                class="text-sm"
            >
                The market summary is partial. Inspect the market observations
                page for the remaining mappings.
            </p>
            <div class="grid gap-5 sm:grid-cols-2">
                <section
                    v-for="group in healthGroups"
                    :key="group.title"
                    class="space-y-3"
                >
                    <h3 class="font-medium">{{ group.title }}</h3>
                    <dl class="space-y-2">
                        <div
                            v-for="row in group.rows"
                            :key="row[0]"
                            class="flex items-start justify-between gap-3 text-sm"
                        >
                            <dt class="text-muted-foreground">{{ row[1] }}</dt>
                            <dd class="font-medium">
                                {{ operations.health[row[0]] }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>
            <details class="text-sm">
                <summary class="cursor-pointer">
                    Retained evidence totals
                </summary>
                <dl class="mt-3 space-y-2">
                    <div
                        v-for="row in retainedTotals"
                        :key="row[0]"
                        class="flex justify-between gap-3"
                    >
                        <dt>{{ row[1] }}</dt>
                        <dd>{{ operations.health[row[0]] }}</dd>
                    </div>
                </dl>
            </details>
            <p class="text-sm text-muted-foreground">
                Up to {{ operations.retained_limit }} immutable usage
                reservations are retained per owner. Failed or uncertain work
                stays charged. Metric expiry and lower limits cannot reset usage
                or delete financial history.
            </p>
            <nav
                aria-label="Inspect retained feature evidence"
                class="flex flex-wrap gap-4 text-sm underline"
            >
                <Link href="/market-data">Market observations</Link
                ><Link href="/imports">Import reconciliation</Link
                ><Link href="/notifications">Reminder delivery</Link
                ><Link href="/drive">Drive recovery</Link
                ><Link href="/copilot/chat">Copilot</Link>
            </nav>
        </section>
    </div>
</template>
