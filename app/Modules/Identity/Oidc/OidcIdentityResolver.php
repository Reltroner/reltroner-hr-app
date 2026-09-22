<?php

namespace App\Modules\Identity\Oidc;

use App\Modules\Identity\Models\ExternalIdentity;
use App\Modules\Identity\Oidc\Exceptions\OidcCallbackException;
use Illuminate\Support\Facades\Log;

class OidcIdentityResolver
{
    /**
     * Resolve a validated external OIDC identity to an approved local User.
     *
     * Permanent trust key is strictly (issuer, subject).
     * Fails closed with HTTP 403 if no approved link exists.
     * Fails closed with HTTP 500 if database linkage is corrupted.
     *
     * @param ValidatedOidcIdentity $identity
     * @return ResolvedOidcIdentity
     * @throws OidcCallbackException
     */
    public function resolve(ValidatedOidcIdentity $identity): ResolvedOidcIdentity
    {
        $externalIdentity = $this->findExternalIdentity($identity);

        if ($externalIdentity === null) {
            Log::error('oidc.resolution.unlinked_identity');
            throw new OidcCallbackException(403, 'unlinked_identity', 'Access denied.');
        }

        // Collation defense: enforce strict binary/case-sensitive PHP equality
        if ($externalIdentity->issuer !== $identity->issuer || $externalIdentity->subject !== $identity->subject) {
            Log::error('oidc.resolution.unlinked_identity');
            throw new OidcCallbackException(403, 'unlinked_identity', 'Access denied.');
        }

        $user = $externalIdentity->user;
        if ($user === null) {
            Log::error('oidc.resolution.missing_user');
            throw new OidcCallbackException(500, 'identity_integrity_error', 'Identity resolution failed.');
        }

        return new ResolvedOidcIdentity(
            user: $user,
            externalIdentity: $externalIdentity
        );
    }

    /**
     * Query ExternalIdentity by exact issuer and subject with eager-loaded user relation.
     */
    protected function findExternalIdentity(ValidatedOidcIdentity $identity): ?ExternalIdentity
    {
        return ExternalIdentity::query()
            ->where('issuer', $identity->issuer)
            ->where('subject', $identity->subject)
            ->with('user')
            ->first();
    }
}
