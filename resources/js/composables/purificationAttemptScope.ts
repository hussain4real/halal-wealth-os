export function purificationAttemptScope(
    action:
        | 'obligation-create'
        | 'obligation-revise'
        | 'allocation'
        | 'release',
    identity: string,
): string {
    return 'purification:' + action + ':' + identity;
}
