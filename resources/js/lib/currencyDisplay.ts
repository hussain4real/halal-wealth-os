// Presentation only: keep caller-supplied decimal text exact; never calculate,
// round, convert through Number, or silently assume a currency or missing zero.
export function currencyDisplay(
    amount: string | null,
    currency: string | null,
    groupThousands = false,
): string | null {
    if (
        amount === null ||
        currency === null ||
        !/^-?\d{1,64}(?:\.\d{1,18})?$/.test(amount) ||
        !/^[A-Z]{3}$/.test(currency)
    )
        return null;
    if (groupThousands) {
        const [integer, fraction] = amount.split('.');
        const grouped = integer.replace(/\B(?=(\d{3})+$)/g, ',');
        amount = fraction === undefined ? grouped : grouped + '.' + fraction;
    }
    return `${amount} ${currency}`;
}
