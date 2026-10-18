export function cashDecimal(
    minor: string | null | undefined,
    currency: string,
): string | null {
    if (minor == null || !/^-?\d+$/.test(minor)) return null;
    const scale = currency === 'JPY' ? 0 : currency === 'KWD' ? 3 : 2;
    const sign = minor.startsWith('-') ? '-' : '';
    const digits = minor.replace(/^-/, '').padStart(scale + 1, '0');
    return (
        sign +
        (scale ? digits.slice(0, -scale) + '.' + digits.slice(-scale) : digits)
    );
}
export function cashReason(reason: string): string {
    const labels: Record<string, string> = {
        settings_missing:
            'Confirm cash planning settings to calculate availability.',
        minimum_buffer_missing:
            'Choose an explicit minimum buffer, including zero.',
        next_income_date_needs_confirmation:
            'Confirm the next expected income date; missed dates are not advanced automatically.',
        unclassified_required_commitments:
            'Classify every expense plan as required or not required.',
        missing_fx: 'A required direct reporting rate is missing.',
        stale_fx: 'A reporting rate is older than seven days.',
        invalid_fx:
            'A reporting rate is outside the allowed direction or time.',
        opening_pending: 'An opening balance has not been posted.',
        opening_after_cutoff: 'An opening balance is dated after today.',
        legacy_correction_timing:
            'A historical correction needs timing review.',
        out_of_range: 'An exact amount exceeds the supported money range.',
        aggregate_out_of_range:
            'The consolidated calculation exceeds the supported money range.',
        projection_range_exceeded:
            'The recurrence calculation exceeds the supported 100,000-occurrence evaluation range. Confirm the income horizon or review long-running required plans; availability remains incomplete.',
        forecast_out_of_range:
            'The forecast exceeds the supported money range.',
        investment_target_missing:
            'Confirm the investment target, including zero.',
        no_expected_salary:
            'No expected salary is configured for this forecast month. Enter a scenario assumption, including zero.',
        variable:
            'A goal has variable contributions. Enter an explicit contribution assumption.',
    };
    return (
        labels[reason] ??
        reason
            .split(':')
            .map((part) => labels[part] ?? part.replaceAll('_', ' '))
            .join(' · ')
    );
}
