<?php

namespace App\Modules\Identity\Oidc;

use App\Modules\Identity\Models\ExternalIdentity;
use App\Modules\Identity\Oidc\Exceptions\OidcCallbackException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class OidcSessionManager
{
    /**
     * Establish a Laravel web authentication session for a resolved OIDC identity.
     *
     * @throws OidcCallbackException
     */
    public function establish(
        Request $request,
        ResolvedOidcIdentity $resolved,
        #[\SensitiveParameter] ?string $idTokenHint = null
    ): void
    {
        $currentUser = Auth::guard('web')->user();

        // Case C: Conflict if request is already authenticated as a different user
        if ($currentUser !== null && $currentUser->getAuthIdentifier() !== $resolved->user->getAuthIdentifier()) {
            Log::error('oidc.session.user_conflict');
            throw new OidcCallbackException(
                409,
                'session_user_conflict',
                'Authentication session conflict.'
            );
        }

        // Final approved-link revalidation
        $currentLink = $this->findCurrentLink($resolved->externalIdentity->getKey());

        if ($currentLink === null) {
            Log::error('oidc.session.link_changed');
            throw new OidcCallbackException(403, 'identity_link_changed', 'Access denied.');
        }

        if ($currentLink->issuer !== $resolved->externalIdentity->issuer ||
            $currentLink->subject !== $resolved->externalIdentity->subject) {
            Log::error('oidc.session.link_changed');
            throw new OidcCallbackException(403, 'identity_link_changed', 'Access denied.');
        }

        if ($currentLink->user === null) {
            Log::error('oidc.session.missing_user');
            throw new OidcCallbackException(500, 'identity_integrity_error', 'Identity resolution failed.');
        }

        if ($currentLink->user->isNot($resolved->user)) {
            Log::error('oidc.session.link_changed');
            throw new OidcCallbackException(403, 'identity_link_changed', 'Access denied.');
        }

        // Case B: Same-user idempotent completion (no re-login, no session reset, no last_login_at update)
        if ($currentUser !== null && $currentUser->getAuthIdentifier() === $resolved->user->getAuthIdentifier()) {
            return;
        }

        // Case A: New guest -> OIDC login path
        try {
            // 1. Clear stale authorization compatibility session keys
            $request->session()->forget([
                'role',
                'employee_id',
            ]);

            // 2. Authenticate explicitly with remember = false
            Auth::guard('web')->login($resolved->user, false);

            // 3. Regenerate session ID and CSRF token
            $request->session()->regenerate();

            // 4. Create OIDC session binding
            OidcSessionBinding::create($request, $currentLink);

            // 5. Replace stale logout context. Production callback flows provide
            // the already-validated ID token; callers without one retain fallback.
            OidcLogoutContext::forget($request);

            if ($idTokenHint !== null) {
                OidcLogoutContext::store($request, $idTokenHint);
            }

            // 6. Persist last_login_at on the revalidated exact ExternalIdentity row
            $this->persistLastLoginAt($currentLink);
        } catch (Throwable $e) {
            // Best-effort rollback of newly established session
            try {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            } catch (Throwable) {
                // Ignore secondary rollback exceptions
            }

            Log::error('oidc.session.establishment_failed');

            throw new OidcCallbackException(
                500,
                'session_establishment_failed',
                'Authentication could not be completed.'
            );
        }
    }

    /**
     * Re-fetch the exact ExternalIdentity link row with eager-loaded user relation.
     */
    protected function findCurrentLink(mixed $id): ?ExternalIdentity
    {
        return ExternalIdentity::query()
            ->with('user')
            ->find($id);
    }

    /**
     * Persist last_login_at timestamp on the exact approved ExternalIdentity link.
     */
    protected function persistLastLoginAt(ExternalIdentity $link): void
    {
        $link->last_login_at = now();

        if ($link->save() === false) {
            throw new \RuntimeException(
                'OIDC login telemetry persistence failed.'
            );
        }
    }
}
