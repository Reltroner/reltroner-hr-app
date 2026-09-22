<?php

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Events\IdentityLinkAuditEvent;
use Illuminate\Support\Facades\Log;

/**
 * Synchronous audit listener logging identity-link lifecycle events.
 *
 * Writes structured, safe context containing correlation identifiers
 * and excluding all credentials, raw provider claims, and tokens.
 */
class LogIdentityLinkAudit
{
    /**
     * Handle the identity link audit event.
     */
    public function handle(IdentityLinkAuditEvent $event): void
    {
        Log::info('identity.link.audit', [
            'action' => $event->action,
            'external_identity_id' => $event->externalIdentityId,
            'user_id' => $event->userId,
            'trust_key_fingerprint' => $event->trustKeyFingerprint,
            'actor_id' => $event->actorId,
            'actor_type' => $event->actorType,
        ]);
    }
}
