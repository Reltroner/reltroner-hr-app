<?php

namespace App\Modules\Identity\Observers;

use App\Models\User;
use App\Modules\Identity\Events\IdentityLinkAuditEvent;

/**
 * Lifecycle observer capturing identity-link removals during User deletion.
 *
 * Prevents an audit blind spot caused by database foreign key ON DELETE CASCADE
 * (which operates at the SQL engine level and does not fire child Eloquent deleted hooks).
 * Snapshots child links during "deleting" and dispatches unlink events only after "deleted".
 */
class UserIdentityLinkAuditObserver
{
    /**
     * Snapshot associated ExternalIdentity rows before User is deleted.
     */
    public function deleting(User $user): void
    {
        $user->setRelation('auditExternalIdentitiesSnapshot', $user->externalIdentities()->get());
    }

    /**
     * Dispatch unlinked audit events for snapshotted child identities after User deletion succeeds.
     */
    public function deleted(User $user): void
    {
        if ($user->relationLoaded('auditExternalIdentitiesSnapshot')) {
            /** @var \Illuminate\Database\Eloquent\Collection<\App\Modules\Identity\Models\ExternalIdentity>|null $snapshot */
            $snapshot = $user->getRelation('auditExternalIdentitiesSnapshot');

            if ($snapshot !== null) {
                foreach ($snapshot as $identity) {
                    event(IdentityLinkAuditEvent::forIdentity(
                        IdentityLinkAuditEvent::ACTION_UNLINKED,
                        $identity
                    ));
                }
            }
        }
    }
}
