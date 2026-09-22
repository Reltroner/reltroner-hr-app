<?php

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\ExternalIdentity;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Auth;

/**
 * Immutable domain audit event representing identity-link creation or removal.
 *
 * Excludes all sensitive and credential data; identifies trust key solely
 * via sha256(issuer . "\0" . subject) correlation fingerprint.
 */
readonly class IdentityLinkAuditEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const ACTION_LINKED = 'linked';
    public const ACTION_UNLINKED = 'unlinked';

    public function __construct(
        public string $action,
        public int $externalIdentityId,
        public int $userId,
        public string $trustKeyFingerprint,
        public ?int $actorId = null,
        public string $actorType = 'system'
    ) {
    }

    /**
     * Factory helper to create an audit event from an ExternalIdentity model instance.
     */
    public static function forIdentity(string $action, ExternalIdentity $identity): self
    {
        $actorId = Auth::id() !== null ? (int) Auth::id() : null;
        $actorType = $actorId !== null ? 'user' : 'system';
        $fingerprint = hash('sha256', $identity->issuer . "\0" . $identity->subject);

        return new self(
            action: $action,
            externalIdentityId: (int) $identity->getKey(),
            userId: (int) $identity->user_id,
            trustKeyFingerprint: $fingerprint,
            actorId: $actorId,
            actorType: $actorType
        );
    }
}
