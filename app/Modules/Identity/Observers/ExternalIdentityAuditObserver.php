<?php

namespace App\Modules\Identity\Observers;

use App\Modules\Identity\Events\IdentityLinkAuditEvent;
use App\Modules\Identity\Models\ExternalIdentity;

/**
 * Lifecycle observer for ExternalIdentity link creation and explicit deletion.
 *
 * Excludes update/saving events to guarantee that ordinary operational mutations
 * (such as last_login_at timestamp updates) never emit link audit events.
 */
class ExternalIdentityAuditObserver
{
    /**
     * Handle the ExternalIdentity "created" event.
     */
    public function created(ExternalIdentity $identity): void
    {
        event(IdentityLinkAuditEvent::forIdentity(
            IdentityLinkAuditEvent::ACTION_LINKED,
            $identity
        ));
    }

    /**
     * Handle the ExternalIdentity "deleted" event.
     */
    public function deleted(ExternalIdentity $identity): void
    {
        event(IdentityLinkAuditEvent::forIdentity(
            IdentityLinkAuditEvent::ACTION_UNLINKED,
            $identity
        ));
    }
}
