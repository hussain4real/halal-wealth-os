import { nextTick, ref } from 'vue';

export function useLedgerFeedback(
    errors: () => object,
    clearErrors: () => void,
) {
    const uncertain = ref(false);
    const ambiguous = () => {
        uncertain.value = true;
        return false;
    };
    const options = (success: () => void) => ({
        onBefore: () => {
            clearErrors();
            uncertain.value = false;
        },
        onFinish: async () => {
            if (uncertain.value || Object.keys(errors()).length) {
                await nextTick();
                const target =
                    document.querySelector<HTMLElement>(
                        '[aria-invalid="true"]',
                    ) ??
                    document.querySelector<HTMLElement>('[data-ledger-error]');
                target?.focus();
            }
        },
        onNetworkError: ambiguous,
        onHttpException: ambiguous,
        onSuccess: success,
    });
    return { uncertain, options };
}

// Native datetime-local controls normalize exact minutes without a seconds part.
export function toLedgerUtc(value: string): string {
    return `${value.length === 16 ? `${value}:00` : value}Z`;
}
