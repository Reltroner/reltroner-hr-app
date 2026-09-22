<?php

namespace App\Modules\Identity\Oidc;

use App\Models\User;
use App\Modules\Identity\Models\ExternalIdentity;

/**
 * Immutable value object holding the resolved approved local identity boundary.
 *
 * Strictly carries the resolved User model and the matching ExternalIdentity row.
 * Does NOT establish a Laravel session or grant business authorization.
 */
readonly class ResolvedOidcIdentity
{
    public function __construct(
        public User $user,
        public ExternalIdentity $externalIdentity
    ) {
    }
}
