<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePortfolioForm } from '@/composables/usePortfolioForm';

type Item = {
    id: string;
    notification_id: string;
    channel: string;
    label: string;
    due_date: string | null;
    created_at: string;
    status: string;
    code: string;
    held_reason: string | null;
    read: boolean;
};
const props = defineProps<{
    defaults: Record<string, string>;
    rules: Record<string, string>;
    items: Item[];
    next: string | null;
    email_mode: string;
    capacity: { count: number; limit: number; full: boolean };
    schedules: { id: string; name: string; standing_order: boolean }[];
    query: string;
    schedule_next: string | null;
}>();
const { form, feedback, locked, recovered, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) + ':reminder-preferences',
    () => props.defaults,
    { schedules: 640 },
);
const query = ref(props.query);
const preview = ref<{ subject: string; body: string } | null>(null);
const previewError = ref('');
function pauseExternal(): void {
    router.post(
        '/notifications/pause',
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                if (locked.value) return;
                form.prior = props.defaults.prior;
                form.external_paused = props.defaults.external_paused;
                form.defaults('prior', props.defaults.prior);
                form.defaults(
                    'external_paused',
                    props.defaults.external_paused,
                );
            },
        },
    );
}
function selected(id: string): boolean {
    return form.schedules.split(',').includes(id);
}
function toggle(id: string): void {
    const ids = form.schedules === '' ? [] : form.schedules.split(',');
    form.schedules = (
        ids.includes(id) ? ids.filter((value) => value !== id) : [...ids, id]
    ).join(',');
}
function choices(after?: string): void {
    router.get(
        '/notifications',
        { q: query.value, ...(after ? { schedule_after: after } : {}) },
        { preserveState: true, preserveScroll: true },
    );
}
async function dryRun(): Promise<void> {
    previewError.value = '';
    try {
        const response = await fetch(
            '/notifications/preview?detail=' + encodeURIComponent(form.detail),
            { headers: { Accept: 'application/json' } },
        );
        const data: unknown = await response.json();
        if (
            !response.ok ||
            typeof data !== 'object' ||
            data === null ||
            !('subject' in data) ||
            !('body' in data) ||
            typeof data.subject !== 'string' ||
            typeof data.body !== 'string'
        )
            throw new Error('Preview unavailable');
        preview.value = { subject: data.subject, body: data.body };
    } catch {
        previewError.value =
            'Preview could not load. Your settings were not changed and nothing was sent.';
    }
}
const labels: Record<string, string> = {
    pending: 'Waiting',
    in_app: 'Published in app',
    mock_smtp: 'Accepted by synthetic SMTP',
    uncertain: 'Outcome unknown — inspect, do not resend',
    exhausted: 'Safe retry limit reached',
    deferred: 'Safe retry scheduled',
    retryable: 'Proved no handoff',
};
</script>
<template>
    <Head title="Reminders" />
    <div
        class="mx-auto max-w-5xl space-y-7 px-4 py-8 scheme-light sm:px-8 dark:scheme-dark"
    >
        <Link href="/dashboard" class="underline">← Dashboard</Link>
        <header>
            <p class="text-sm font-semibold text-teal-800 dark:text-teal-200">
                Your attention, on your terms
            </p>
            <h1 class="mt-2 text-3xl font-semibold">Reminders</h1>
            <p class="mt-3 max-w-3xl text-muted-foreground">
                Review prompts use your current records. They do not prove a
                bank deposit failed. Every rule and channel starts off,
                including schedules with a standing order.
            </p>
        </header>
        <section aria-labelledby="external-title" class="rounded-xl border p-5">
            <h2 id="external-title" class="text-lg font-semibold">
                External delivery is
                {{ defaults.external_paused === '1' ? 'paused' : 'configured' }}
            </h2>
            <p class="mt-2 text-sm">
                Live email is blocked. SMTP is approved for synthetic testing
                only; saving email preferences does not enable real sends.
            </p>
            <Button class="mt-4" variant="destructive" @click="pauseExternal"
                >Pause all external reminders</Button
            >
            <p class="mt-2 text-xs text-muted-foreground">
                A saved pause stops new handoffs and preserves waiting items. It
                cannot recall an email whose handoff already started. Pause
                stays available even when retained storage is full.
            </p>
        </section>
        <p
            v-if="capacity.full"
            role="alert"
            class="rounded-xl border border-amber-500 p-4"
        >
            Retained reminder capacity reached. New items or preference
            revisions may be blocked. Existing history and external pause remain
            available.
        </p>
        <PortfolioAttempt
            :errors="form.errors"
            :uncertain="feedback.uncertain.value"
            :recovered="recovered"
            history="/notifications"
        />
        <form
            class="space-y-6 rounded-xl border p-5"
            @submit.prevent="submit('/notifications/preferences')"
        >
            <h2 class="text-xl font-semibold">Preferences</h2>
            <fieldset :disabled="locked" class="space-y-5">
                <legend class="sr-only">Reminder preferences</legend>
                <div
                    v-for="(label, rule) in rules"
                    :key="rule"
                    class="flex flex-wrap items-center gap-4 border-b pb-4"
                >
                    <span class="min-w-52 flex-1 font-medium">{{ label }}</span
                    ><label class="flex min-h-10 items-center gap-2"
                        ><input
                            v-model="form[rule + '_in_app']"
                            type="checkbox"
                            true-value="1"
                            false-value="0"
                        />In app</label
                    ><label class="flex min-h-10 items-center gap-2"
                        ><input
                            v-model="form[rule + '_email']"
                            type="checkbox"
                            true-value="1"
                            false-value="0"
                        />Email (mock only)</label
                    >
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label for="timezone">Delivery timezone</Label
                        ><Input
                            id="timezone"
                            v-model="form.timezone"
                            autocomplete="off"
                            placeholder="Asia/Qatar"
                        />
                        <p class="mt-1 text-xs">
                            Use an IANA timezone, such as Asia/Qatar or
                            Europe/London.
                        </p>
                    </div>
                    <div>
                        <Label for="send-after">Send after local time</Label
                        ><Input
                            id="send-after"
                            v-model="form.send_after"
                            type="time"
                            required
                        />
                    </div>
                </div>
                <label class="flex min-h-10 items-center gap-2"
                    ><input
                        v-model="form.quiet_enabled"
                        type="checkbox"
                        true-value="1"
                        false-value="0"
                    />Use quiet hours</label
                >
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label for="quiet-start">Quiet hours start</Label
                        ><Input
                            id="quiet-start"
                            v-model="form.quiet_start"
                            type="time"
                            required
                        />
                    </div>
                    <div>
                        <Label for="quiet-end">Quiet hours end</Label
                        ><Input
                            id="quiet-end"
                            v-model="form.quiet_end"
                            type="time"
                            required
                        />
                    </div>
                </div>
                <div>
                    <Label for="grace">Contribution grace days</Label
                    ><Input
                        id="grace"
                        :model-value="form.grace_days"
                        @update:model-value="form.grace_days = String($event)"
                        type="number"
                        min="0"
                        max="30"
                        required
                    />
                    <p class="mt-1 text-sm text-muted-foreground">
                        The full due day and grace period use Asia/Qatar. We
                        check the current and two preceding calendar months.
                        Posted matches suppress a reminder; partial matches
                        remain reviewable. Revised schedules require a new
                        explicit selection.
                    </p>
                </div>
                <fieldset class="space-y-3 rounded-lg border p-4">
                    <legend class="px-2 font-medium">
                        Contribution schedules
                    </legend>
                    <p class="text-sm">
                        Select up to 32 incoming schedules. Standing orders stay
                        preferred; selecting a schedule only enables review
                        prompts for channels you explicitly choose above.
                    </p>
                    <div class="flex gap-2">
                        <Input
                            v-model="query"
                            aria-label="Search contribution schedules"
                        /><Button
                            type="button"
                            variant="outline"
                            @click="choices()"
                            >Search</Button
                        >
                    </div>
                    <label
                        v-for="schedule in schedules"
                        :key="schedule.id"
                        class="flex min-h-10 items-center gap-2"
                        ><input
                            type="checkbox"
                            :checked="selected(schedule.id)"
                            @change="toggle(schedule.id)"
                        />{{ schedule.name
                        }}<span
                            v-if="schedule.standing_order"
                            class="text-xs text-muted-foreground"
                            >Standing order</span
                        ></label
                    >
                    <p
                        v-if="schedules.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No matching incoming schedules. Add one in your monthly
                        plan first.
                    </p>
                    <Button
                        v-if="schedule_next"
                        type="button"
                        variant="outline"
                        @click="choices(schedule_next)"
                        >More schedules</Button
                    ><Link href="/budgets" class="block text-sm underline"
                        >Review monthly plan</Link
                    >
                </fieldset>
                <div>
                    <Label for="detail">External detail</Label
                    ><select
                        id="detail"
                        v-model="form.detail"
                        class="mt-2 block min-h-10 w-full rounded-md border bg-background px-3"
                    >
                        <option value="generic">
                            Generic private-page link only
                        </option>
                        <option value="review">Review type and due date</option>
                    </select>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Neither option includes balances, holdings, amounts,
                        account names or personal goal names.
                    </p>
                </div>
                <label class="flex min-h-10 items-center gap-2"
                    ><input
                        v-model="form.external_paused"
                        type="checkbox"
                        true-value="1"
                        false-value="0"
                    />Keep all external reminders paused</label
                >
            </fieldset>
            <Button :disabled="form.processing">{{
                locked ? 'Retry exact saved request' : 'Save preferences'
            }}</Button>
        </form>
        <section class="space-y-3 rounded-xl border p-5">
            <h2 class="text-lg font-semibold">Test channel preview</h2>
            <p class="text-sm">
                A synthetic example, using the selected detail level. No email,
                queue job or delivery record is created.
            </p>
            <Button variant="outline" @click="dryRun"
                >Preview synthetic email</Button
            >
            <p v-if="previewError" role="alert">{{ previewError }}</p>
            <div v-if="preview" role="status" class="rounded-lg bg-muted p-4">
                <strong>{{ preview.subject }}</strong>
                <p class="mt-2">{{ preview.body }}</p>
                <p class="mt-2 underline">Your authenticated reminders page</p>
            </div>
        </section>
        <section aria-labelledby="outbox-title" class="space-y-4">
            <h2 id="outbox-title" class="text-xl font-semibold">
                Inbox &amp; delivery history
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ capacity.count }} of {{ capacity.limit }} logical items
                retained. Waiting and paused items are kept for inspection.
                Unknown SMTP handoffs are never automatically resent; synthetic
                acceptance is not real mailbox delivery.
            </p>
            <p
                v-if="items.length === 0"
                class="rounded-xl border border-dashed p-6"
            >
                No reminder items yet. Save explicit preferences and the
                scheduled worker will check current records.
            </p>
            <article
                v-for="item in items"
                :key="item.id"
                class="space-y-2 rounded-xl border p-4"
            >
                <h3 class="font-semibold">{{ item.label }}</h3>
                <p v-if="item.due_date">Due {{ item.due_date }} · Asia/Qatar</p>
                <p>
                    {{ item.channel === 'in_app' ? 'In app' : 'Email' }} ·
                    {{ labels[item.status] ?? item.status }}
                </p>
                <p
                    v-if="item.held_reason"
                    class="text-sm text-muted-foreground"
                >
                    Current hold: {{ item.held_reason.replaceAll('_', ' ') }}
                </p>
                <Link
                    :href="
                        item.due_date
                            ? '/budgets?month=' + item.due_date.slice(0, 7)
                            : '/dashboard'
                    "
                    class="inline-block min-h-10 underline"
                    >Review current source</Link
                ><Button
                    v-if="!item.read"
                    variant="outline"
                    class="ml-3"
                    @click="
                        router.post(
                            '/notifications/' + item.notification_id + '/read',
                            {},
                            { preserveScroll: true },
                        )
                    "
                    >Mark reviewed</Button
                ><span v-else class="ml-3 text-sm">Reviewed</span>
            </article>
            <Link
                v-if="next"
                :href="'/notifications?before=' + next"
                class="underline"
                >Older delivery records</Link
            >
        </section>
    </div>
</template>
