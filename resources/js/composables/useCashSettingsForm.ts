import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { cashSettingsDraft } from './cashSettingsDraft';
import { planningRequestIdentity } from './planningRequestIdentity';
import { useLedgerFeedback } from './useLedgerFeedback';
export function useCashSettingsForm(
    owner: string,
    defaults: Record<string, string>,
) {
    const scope = 'owner:' + owner;
    const identity = planningRequestIdentity(
        'cash-settings:' + scope,
        crypto.randomUUID(),
    );
    let draft = cashSettingsDraft(
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
    form.transform((data) => ({
        ...data,
        requirements: (
            JSON.parse(data.requirements) as { required: string }[]
        ).filter((r) => r.required === '0' || r.required === '1'),
        overlaps: JSON.parse(data.overlaps),
        investment_goals: JSON.parse(data.investment_goals),
    }));
    const submit = () => {
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
            draft = cashSettingsDraft(scope, next, Object.keys(defaults));
        });
        form.post('/cash/settings', {
            ...options,
            onError: (errors) => {
                if (!errors.idempotency_key) {
                    draft.clear();
                    recovered.value = false;
                }
            },
        });
    };
    return { form, feedback, recovered, submit };
}
