import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { planningRequestIdentity } from './planningRequestIdentity';
import { goalRequestDraft } from './goalRequestDraft';
import { useLedgerFeedback } from './useLedgerFeedback';
export function useGoalForm(scope: string, defaults: Record<string, string>) {
    const identity = planningRequestIdentity(
        'goal:' + scope,
        crypto.randomUUID(),
    );
    let draft = goalRequestDraft(
        scope,
        identity.current,
        Object.keys(defaults),
    );
    const form = useForm<Record<string, string>>({
        ...defaults,
        idempotency_key: identity.current,
    });
    const restored = draft.load();
    const recovered = ref(restored !== null);
    if (restored) Object.assign(form, restored);
    const feedback = useLedgerFeedback(
        () => form.errors,
        () => form.clearErrors(),
    );
    const submit = (url: string) => {
        if (form.processing) return;
        const payload = draft.load() ?? form.data();
        draft.save(payload);
        Object.assign(form, payload);
        const options = feedback.options(() => {
            draft.clear();
            recovered.value = false;
            const next = identity.complete();
            form.defaults({ ...defaults, idempotency_key: next });
            form.reset();
            draft = goalRequestDraft(scope, next, Object.keys(defaults));
        });
        form.post(url, {
            ...options,
            onError: (errors) => {
                if (!errors.idempotency_key) draft.clear();
            },
        });
    };
    return { form, feedback, submit, recovered };
}
