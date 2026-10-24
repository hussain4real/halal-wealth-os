# M6-P3 email channel selection

The human owner approved the existing Laravel SMTP adapter for disabled synthetic mock-only reminder testing on 24 October 2026. No live email, account, credentials or deployment is authorized. Reminder delivery requires both the testing environment and a synthetic mail fake; existing authentication email remains separate.

Generic external content contains a private authenticated-page link only. Explicit review detail adds only review type and due date, never money, holdings or personal account/goal names. A successful mock handoff is simulated acceptance, not mailbox delivery. SMTP has no exactly-once provider guarantee: uncertain handoffs are retained for inspection and never blindly retried. Only proved pre-handoff failures have finite scheduled retries.
