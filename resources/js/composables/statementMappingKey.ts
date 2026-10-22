/** Match PHP trim() used for CSV cells; Unicode label characters remain significant. */
export function statementMappingKey(value: string): string {
    const padding = ' \t\n\r\0\v';
    let first = 0;
    let last = value.length;
    while (first < last && padding.includes(value.charAt(first))) first++;
    while (last > first && padding.includes(value.charAt(last - 1))) last--;
    return value.slice(first, last);
}
