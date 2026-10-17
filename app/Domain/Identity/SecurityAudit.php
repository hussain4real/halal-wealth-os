<?php

namespace App\Domain\Identity;

use Illuminate\Support\Facades\Log;

class SecurityAudit
{
    /**
     * Fail closed on context: never log request bodies, identities, credentials,
     * tokens, session IDs, recovery material or financial values. Only numeric
     * subject identity survives; other supplied context is discarded.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(SecurityEvent $event, ?int $actorId, array $context = []): void
    {
        Log::channel('security')->notice($event->value, [
            'actor_id' => $actorId,
            'subject_id' => is_int($context['subject_id'] ?? null) ? $context['subject_id'] : null,
        ]);
    }
}
