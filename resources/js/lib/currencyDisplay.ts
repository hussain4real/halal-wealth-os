// Presentation only: keep caller-supplied decimal text exact; never calculate,
// round, convert through Number, or silently assume a currency or missing zero.
export function currencyDisplay(
    amount: string | null,
    currency: string | null,
): string | null {
    if (
        amount === null ||
        currency === null ||
        !/^-?\d{1,64}(?:\.\d{1,18})?$/.test(amount) ||
        !/^[A-Z]{3}$/.test(currency)
    )
        return null;
    return `${amount} ${currency}`;
}
