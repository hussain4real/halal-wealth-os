<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { useDriveRequest } from '@/composables/useDriveRequest';
import {
    importErrors,
    importObject,
    importPost,
} from '@/composables/useStatementRequest';

type Connection = {
    id: string;
    email: string;
    generation: string;
    status: string;
    folder_id: string | null;
    folder_name: string | null;
    revoke_state: string;
    restore_held: boolean;
};
type Operation = {
    id: string;
    request_key: string;
    kind: string;
    state: string;
    safe_code: string;
    attempts: number;
    content_hash: string;
    content_bytes: number;
    remote_id: string;
    target_id: string;
    restore_held: boolean;
};
type Review = {
    snapshot_id: string;
    connection_id: string;
    generation: string;
    review_token: string;
    email: string;
    folder_name: string;
    target_id: string;
    content_hash: string;
    content_bytes: number;
    expires_at: string;
    manifest: Record<string, unknown>;
};
const props = defineProps<{
    enabled: boolean;
    connection: Connection | null;
    operations: Operation[];
    snapshots: { id: string; reference: string; recorded_at: string }[];
    next: string | null;
}>();
const owner = String(usePage().props.auth.user.id);
const prepare = useDriveRequest(owner, 'prepare', () => {
    accountAcknowledged.value = false;
    feedback.value = '';
});
const confirm = useDriveRequest(owner, 'confirm', () => {
    review.value = null;
    acknowledged.value = false;
});
const review = ref<Review | null>(null);
const choice = ref('');
const acknowledged = ref(false);
const accountAcknowledged = ref(false);
const feedback = ref('');
const busy = ref(false);
function isReview(value: unknown): value is Review {
    return (
        importObject(value) &&
        typeof value.snapshot_id === 'string' &&
        typeof value.connection_id === 'string' &&
        typeof value.generation === 'string' &&
        typeof value.review_token === 'string' &&
        /^[a-f0-9]{64}$/.test(value.review_token) &&
        typeof value.email === 'string' &&
        typeof value.folder_name === 'string' &&
        typeof value.target_id === 'string' &&
        typeof value.content_hash === 'string' &&
        typeof value.content_bytes === 'number' &&
        typeof value.expires_at === 'string' &&
        importObject(value.manifest)
    );
}
watch(choice, () => {
    review.value = null;
    acknowledged.value = false;
    feedback.value = '';
});
watch(
    () =>
        [
            props.connection?.id,
            props.connection?.generation,
            props.connection?.email,
            props.connection?.status,
            props.connection?.folder_id,
        ].join(':'),
    () => {
        review.value = null;
        acknowledged.value = false;
        accountAcknowledged.value = false;
    },
);
function outcome(operation: Operation): string {
    if (operation.kind === 'folder' && operation.state === 'uploaded')
        return 'The private folder identity and ownership were verified in synthetic mocks. This operation uploaded no report.';
    if (operation.safe_code === 'stopped_remote_unconfirmed')
        return 'Local delivery is stopped. An earlier handoff may already have created this file; reconcile its retained identity before starting another export.';
    const messages: Record<string, string> = {
        queued: 'Waiting for a worker. This receipt does not yet prove delivery.',
        claimed:
            'A worker is checking this exact file identity. Refresh outcomes after it completes.',
        uploaded:
            'The exact remote identity, private destination and report bytes were verified in synthetic mocks.',
        unknown:
            'The remote result is unconfirmed. Keep this file identity for reconciliation; a new export could duplicate an earlier handoff.',
        reconnect:
            'Account access needs reconnection. Disconnect this identity and review a new connection; this operation will never move to another account.',
        cancelled:
            'Delivery stopped before an initial handoff. No new file was created by this operation.',
        expired:
            'Permission expired before an initial handoff. Review the report again before a new request.',
        conflict:
            'Remote identity, sharing or content did not match. Nothing was overwritten. Inspect the destination before starting another export.',
        failed: 'This report or destination could not be verified. Check the account and destination before a new review.',
    };
    return (
        messages[operation.state] ??
        'Refresh to inspect the current outcome before starting another export.'
    );
}
async function reviewSelected() {
    if (busy.value || prepare.pending.value || confirm.pending.value) return;
    const selected = choice.value;
    busy.value = true;
    feedback.value = '';
    review.value = null;
    acknowledged.value = false;
    try {
        const { response, body } = await importPost('/drive/review', {
            snapshot: selected,
        });
        if (
            response.ok &&
            isReview(body) &&
            choice.value === selected &&
            body.snapshot_id === selected
        ) {
            if (
                body.connection_id === props.connection?.id &&
                body.generation === props.connection?.generation &&
                body.target_id === props.connection?.folder_id
            )
                review.value = body;
            else {
                feedback.value =
                    'The connected account changed. Check the updated identity before reviewing again.';
                router.reload();
            }
        } else feedback.value = importErrors(body).join(' ');
    } catch {
        feedback.value =
            'Report review could not be loaded. No export was queued.';
    } finally {
        busy.value = false;
    }
}
async function disconnect() {
    if (!props.connection || busy.value) return;
    review.value = null;
    acknowledged.value = false;
    accountAcknowledged.value = false;
    feedback.value = '';
    busy.value = true;
    try {
        const { response, body } = await importPost(
            '/drive/connections/' + props.connection.id + '/disconnect',
            {},
        );
        if (
            response.ok &&
            importObject(body) &&
            body.status === 'locally_disconnected'
        ) {
            review.value = null;
            acknowledged.value = false;
            router.reload();
        } else feedback.value = importErrors(body).join(' ');
    } catch {
        feedback.value =
            'Stop could not be confirmed. Retry disconnect; it is safe to repeat.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="Google Drive export" />
    <div class="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-8">
        <header class="space-y-2">
            <h1 class="text-2xl font-semibold">Google Drive export</h1>
            <p>
                Choose a saved report, check the Google account and confirm its
                private destination. This is separate from the application
                database backup.
            </p>
            <p class="rounded-lg border border-amber-500 p-3" role="note">
                Live Google access is disabled. All connected-account and
                delivery results here are synthetic mock evidence. No real
                account is connected by this feature.
            </p>
            <Link href="/dashboard" class="underline">Back to dashboard</Link>
        </header>
        <div
            v-if="feedback || prepare.feedback.value || confirm.feedback.value"
            role="status"
            aria-live="polite"
            class="rounded-lg border p-4"
        >
            <p v-if="feedback">{{ feedback }}</p>
            <p v-if="prepare.feedback.value">{{ prepare.feedback.value }}</p>
            <p v-if="confirm.feedback.value">{{ confirm.feedback.value }}</p>
        </div>
        <section
            class="space-y-3 rounded-xl border p-4"
            aria-labelledby="account-heading"
        >
            <h2 id="account-heading" class="text-lg font-semibold">
                1. Check the account
            </h2>
            <p v-if="!connection">
                No Google account is connected. Live connection requires
                separate authorization and configuration.
            </p>
            <template v-else>
                <p class="break-all">
                    <strong>Connected Google identity:</strong>
                    {{ connection.email }}
                </p>
                <p>
                    Status: {{ connection.status }} · Remote revocation:
                    {{ connection.revoke_state }}
                </p>
                <p v-if="connection.restore_held" role="alert">
                    Restored execution is held. Workers cannot use this
                    connection in this runtime.
                </p>
                <p>
                    Reject a school or work account if it is not the account you
                    intend to use. The app does not infer account type from an
                    email domain.
                </p>
                <Button variant="outline" :disabled="busy" @click="disconnect"
                    >Reject account / disconnect</Button
                >
                <p v-if="connection.revoke_state === 'unconfirmed'">
                    Local export is stopped. Remote revocation was not
                    confirmed. Revoke the app in Google account security before
                    any future live reconnection.
                </p>
                <template
                    v-if="
                        enabled &&
                        connection.status === 'account_review' &&
                        !connection.restore_held
                    "
                >
                    <label class="flex gap-2"
                        ><input
                            v-model="accountAcknowledged"
                            type="checkbox"
                            :disabled="!!prepare.pending.value"
                        />
                        Use this displayed account to create an empty private
                        HalalOS reports folder.</label
                    >
                    <Button
                        :disabled="
                            !accountAcknowledged ||
                            busy ||
                            prepare.busy.value ||
                            !!prepare.pending.value
                        "
                        @click="
                            prepare.submit({
                                connection: connection.id,
                                generation: connection.generation,
                                acknowledged: '1',
                            })
                        "
                        >Queue private folder preparation</Button
                    >
                </template>
            </template>
            <template v-if="prepare.pending.value">
                <p>Folder request is locked until its receipt is confirmed.</p>
                <Button
                    variant="outline"
                    :disabled="prepare.busy.value"
                    @click="prepare.recover"
                    >Check folder receipt</Button
                >
                <Button
                    variant="outline"
                    :disabled="prepare.busy.value"
                    @click="prepare.submit()"
                    >Retry unchanged folder request</Button
                >
            </template>
        </section>
        <section
            class="space-y-3 rounded-xl border p-4"
            aria-labelledby="report-heading"
        >
            <h2 id="report-heading" class="text-lg font-semibold">
                2. Review one saved report
            </h2>
            <p>
                Exports include the complete selected snapshot and its retained
                financial source records, capture time and manifest. The
                Drive-only limit is 5,000,000 bytes; larger reports are rejected
                without truncation. Existing downloads remain available.
            </p>
            <p v-if="snapshots.length === 0">
                No saved reports yet. Save a dashboard snapshot first.
            </p>
            <label v-else class="block" for="drive-report"
                >Saved dashboard report</label
            >
            <select
                v-if="snapshots.length"
                id="drive-report"
                v-model="choice"
                class="w-full rounded-md border bg-background p-2"
                :disabled="
                    busy || !!confirm.pending.value || !!prepare.pending.value
                "
            >
                <option value="">Choose a report</option>
                <option
                    v-for="snapshot in snapshots"
                    :key="snapshot.id"
                    :value="snapshot.id"
                >
                    {{ snapshot.reference }} · {{ snapshot.recorded_at }}
                </option>
            </select>
            <Link v-if="next" :href="'/drive?before=' + next" class="underline"
                >Older reports</Link
            >
            <Button
                :disabled="
                    !enabled ||
                    connection?.status !== 'ready' ||
                    !!connection?.restore_held ||
                    !choice ||
                    busy ||
                    !!confirm.pending.value ||
                    !!prepare.pending.value
                "
                @click="reviewSelected"
                >Review account, destination and manifest</Button
            >
            <div v-if="review" class="space-y-3 border-t pt-4">
                <p class="break-all">
                    <strong>Upload to:</strong> {{ review.email }}
                </p>
                <p class="break-all">
                    <strong>Private folder:</strong>
                    {{ review.folder_name }} ({{ review.target_id }})
                </p>
                <p>
                    {{ review.content_bytes }} bytes · Review expires
                    {{ review.expires_at }}
                </p>
                <p class="break-all">SHA-256: {{ review.content_hash }}</p>
                <pre
                    class="max-h-80 overflow-auto rounded border p-3 text-xs"
                    >{{ JSON.stringify(review.manifest, null, 2) }}</pre>
                <label class="flex gap-2"
                    ><input
                        v-model="acknowledged"
                        type="checkbox"
                        :disabled="!!confirm.pending.value"
                    />
                    I checked this Google identity, private folder and complete
                    saved report. Queue only this export.</label
                >
                <Button
                    :disabled="
                        !acknowledged ||
                        busy ||
                        confirm.busy.value ||
                        !!confirm.pending.value
                    "
                    @click="
                        confirm.submit({
                            review_token: review.review_token,
                            acknowledged: '1',
                        })
                    "
                    >Queue this report</Button
                >
            </div>
            <template v-if="confirm.pending.value">
                <p>
                    The export request is locked for receipt recovery. No new
                    selection will replace it.
                </p>
                <Button
                    variant="outline"
                    :disabled="confirm.busy.value"
                    @click="confirm.recover"
                    >Check export receipt</Button
                >
                <Button
                    variant="outline"
                    :disabled="confirm.busy.value"
                    @click="confirm.submit()"
                    >Retry unchanged export request</Button
                >
            </template>
        </section>
        <section class="space-y-3" aria-labelledby="history-heading">
            <h2 id="history-heading" class="text-lg font-semibold">
                Queued work and outcomes
            </h2>
            <p>
                Queued means waiting for a worker. Uploaded requires verified
                remote file evidence. Unknown means reconcile the same file ID;
                a retry never chooses another account or file. Disconnect stops
                future handoffs but cannot recall a file already accepted by
                Google.
            </p>
            <Button variant="outline" @click="router.reload()"
                >Refresh outcomes</Button
            >
            <p v-if="operations.length === 0">No Drive operations recorded.</p>
            <article
                v-for="operation in operations"
                :key="operation.id"
                class="space-y-1 rounded-xl border p-4"
            >
                <h3 class="font-medium">
                    {{
                        operation.kind === 'folder'
                            ? 'Private folder'
                            : 'Saved report'
                    }}
                    · {{ operation.state }}
                </h3>
                <p>{{ outcome(operation) }}</p>
                <p>
                    Attempts
                    {{ operation.attempts }}/3
                </p>
                <p v-if="operation.restore_held" role="alert">
                    Held after restore; no handoff in this runtime.
                </p>
                <p class="break-all">
                    Remote identity: {{ operation.remote_id }}
                </p>
                <p v-if="operation.kind === 'report'" class="break-all">
                    {{ operation.content_bytes }} bytes ·
                    {{ operation.content_hash }}
                </p>
            </article>
        </section>
    </div>
</template>
