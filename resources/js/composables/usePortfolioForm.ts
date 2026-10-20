import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { planningRequestIdentity } from './planningRequestIdentity';
import { portfolioRequestDraft } from './portfolioRequestDraft';
import { useLedgerFeedback } from './useLedgerFeedback';
export function usePortfolioForm(
    scope: string,
    defaults: Record<string, string>,
    fieldLimits: Record<string, number> = {},
) {
    const identity = planningRequestIdentity(
        'portfolio:' + scope,
        crypto.randomUUID(),
    );
    let draft = portfolioRequestDraft(
        scope,
        identity.current,
        Object.keys(defaults),
        fieldLimits,
    );
    const form = useForm<Record<string, string>>({
        ...defaults,
        idempotency_key: identity.current,
    });
    const restored = draft.load();
    const recovered = ref(restored !== null);
    const locked = ref(restored !== null);
    if (restored) Object.assign(form, restored);
    const feedback = useLedgerFeedback(
        () => form.errors,
        () => form.clearErrors(),
    );
    const submit = (url: string) => {
        if (form.processing) return;
        const payload = draft.load() ?? form.data();
        draft.save(payload);
        locked.value = true;
        Object.assign(form, payload);
        const options = feedback.options(() => {
            draft.clear();
            recovered.value = false;
            locked.value = false;
            const next = identity.complete();
            form.defaults({ ...defaults, idempotency_key: next });
            form.reset();
            draft = portfolioRequestDraft(
                scope,
                next,
                Object.keys(defaults),
                fieldLimits,
            );
        });
        form.post(url, {
            ...options,
            onError: (errors) => {
                if (!errors.idempotency_key) {
                    draft.clear();
                    locked.value = false;
                    recovered.value = false;
                }
            },
        });
    };
    return { form, feedback, submit, recovered, locked };
}
