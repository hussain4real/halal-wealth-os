import { router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { driveAttempt, isDriveReceipt } from './driveAttempt';
import { planningRequestIdentity } from './planningRequestIdentity';
import { portfolioRequestDraft } from './portfolioRequestDraft';
import { importErrors, importObject, importPost } from './useStatementRequest';

/** Only request identities and confirmation selectors persist; no OAuth/account/report material. */
export function useDriveRequest(
    owner: string,
    kind: 'prepare' | 'confirm',
    onOutcome: () => void = () => {},
) {
    const scope = 'drive:' + owner + ':' + kind;
    const identity = planningRequestIdentity(scope, crypto.randomUUID());
    const keys =
        kind === 'prepare'
            ? ['connection', 'generation', 'acknowledged']
            : ['review_token', 'acknowledged'];
    const draft = driveAttempt(identity, (key) =>
        portfolioRequestDraft(scope, key, keys),
    );
    const pending = ref(draft.load());
    const busy = ref(false);
    const feedback = ref('');
    const clear = () => {
        draft.complete();
        onOutcome();
        pending.value = null;
        feedback.value = '';
    };
    async function recover() {
        if (!pending.value || busy.value) return;
        busy.value = true;
        try {
            const response = await fetch(
                '/drive/requests/' + pending.value.idempotency_key,
                { headers: { Accept: 'application/json' } },
            );
            const body: unknown = await response.json();
            if (
                response.ok &&
                isDriveReceipt(body, pending.value.idempotency_key, kind)
            ) {
                clear();
                router.reload();
            } else
                feedback.value =
                    'No committed receipt was confirmed. Retry the unchanged request; its selection remains locked.';
        } catch {
            feedback.value =
                'Connection interrupted. Keep this request for exact retry.';
        } finally {
            busy.value = false;
        }
    }
    async function submit(fields?: Record<string, string>) {
        if (busy.value) return;
        const payload = pending.value ?? (fields ? draft.save(fields) : null);
        if (!payload) return;
        draft.save(payload);
        pending.value = payload;
        busy.value = true;
        try {
            const { response, body } = await importPost('/drive/' + kind, {
                ...payload,
                acknowledged: payload.acknowledged === '1',
            });
            if (
                response.ok &&
                isDriveReceipt(body, payload.idempotency_key, kind)
            ) {
                clear();
                router.reload();
            } else if (
                importObject(body) &&
                body.definitive_no_write === true
            ) {
                clear();
                feedback.value =
                    importErrors(body).join(' ') +
                    ' Nothing was queued by this request. Review again before a new request.';
            } else
                feedback.value =
                    'The outcome is uncertain. Check the receipt or retry this unchanged request.';
        } catch {
            feedback.value =
                'Connection interrupted. The request may have committed. Check its receipt or retry unchanged.';
        } finally {
            busy.value = false;
        }
    }
    onMounted(() => {
        if (pending.value) void recover();
    });
    return { pending, busy, feedback, submit, recover };
}
