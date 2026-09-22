<?php

namespace App\Modules\Identity\Oidc;

/**
 * Immutable value object holding only validated OIDC identity boundary fields.
 *
 * Excludes raw tokens, claims, secrets, and authorization parameters.
 */
readonly class ValidatedOidcIdentity
{
    public function __construct(
        public string $issuer,
        public string $subject,
        public string $environmentIdentityClass,
        public ?string $email = null
    ) {
    }
}
