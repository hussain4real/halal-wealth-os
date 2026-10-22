import { router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { planningRequestIdentity } from './planningRequestIdentity';
import { portfolioRequestDraft } from './portfolioRequestDraft';

export const importObject = (
    value: unknown,
): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);

export function importCsrf(): string {
    const cookie = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

export async function importPost(path: string, payload: FormData | object) {
    const response = await fetch(path, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': importCsrf(),
            ...(payload instanceof FormData
                ? {}
                : { 'Content-Type': 'application/json' }),
        },
        body: payload instanceof FormData ? payload : JSON.stringify(payload),
    });
    const body: unknown = await response.json();
    return { response, body };
}

export function importErrors(body: unknown): string[] {
    if (!importObject(body)) return ['The response could not be verified.'];
    if (importObject(body.errors)) {
        return Object.values(body.errors).flatMap((value) =>
            Array.isArray(value)
                ? value.filter(
                      (message): message is string =>
                          typeof message === 'string',
                  )
                : [],
        );
    }
    return [
        typeof body.message === 'string'
            ? body.message
            : 'The request could not be verified.',
    ];
}

export function importUrl(body: unknown): string | null {
    return importObject(body) &&
        typeof body.url === 'string' &&
        /^\/imports\/(?:receipts\/)?[1-9][0-9]*$/.test(body.url)
        ? body.url
        : null;
}

/** Reuses the existing owner-scoped exact-request store; raw source is never saved here. */
export function useStatementRequest(
    scope: string,
    path: string,
    defaults: Record<string, string>,
    transform: (fields: Record<string, string>) => object,
) {
    const identity = planningRequestIdentity(
        'statement:' + scope,
        crypto.randomUUID(),
    );
    const draft = () =>
        portfolioRequestDraft(
            'statement:' + scope,
            identity.current,
            Object.keys(defaults),
            { selection: 10000 },
        );
    const restored = draft().load();
    const fields = ref<Record<string, string>>({
        ...defaults,
        idempotency_key: identity.current,
        ...restored,
    });
    const locked = ref(restored !== null);
    const busy = ref(false);
    const errors = ref<string[]>([]);
    const notice = ref(
        restored
            ? 'Your interrupted request is restored. Retry sends the same selection and identity.'
            : '',
    );
    const finish = (url: string) => {
        draft().clear();
        identity.complete();
        locked.value = false;
        router.visit(url);
    };
    const recover = async () => {
        if (busy.value) return;
        busy.value = true;
        try {
            const response = await fetch(
                '/imports/requests/' + fields.value.idempotency_key,
                { headers: { Accept: 'application/json' } },
            );
            const body: unknown = await response.json();
            const url = importUrl(body);
            if (response.ok && url) finish(url);
            else
                notice.value =
                    'No committed receipt was confirmed. Retry the unchanged request; keep its identity until a definitive result.';
        } catch {
            notice.value =
                'Connection interrupted. The request remains locked for safe retry.';
        } finally {
            busy.value = false;
        }
    };
    const submit = async () => {
        if (busy.value) return;
        const payload = draft().load() ?? { ...fields.value };
        draft().save(payload);
        Object.assign(fields.value, payload);
        locked.value = true;
        busy.value = true;
        errors.value = [];
        try {
            const { response, body } = await importPost(
                path,
                transform(payload),
            );
            const url = importUrl(body);
            if (response.ok && url) finish(url);
            else {
                errors.value = importErrors(body);
                if (importObject(body) && body.definitive_no_write === true) {
                    draft().clear();
                    locked.value = false;
                    notice.value =
                        'Nothing from this request was committed. Review the reasons before submitting again.';
                } else
                    notice.value =
                        'The result is uncertain. Check the receipt or retry exactly this request.';
            }
        } catch {
            errors.value = [
                'Connection interrupted. The request may have committed. Check its receipt or retry unchanged.',
            ];
        } finally {
            busy.value = false;
            document
                .querySelector<HTMLElement>('[data-import-feedback]')
                ?.focus();
        }
    };
    onMounted(() => {
        if (locked.value) void recover();
    });
    return { fields, locked, busy, errors, notice, submit, recover };
}
