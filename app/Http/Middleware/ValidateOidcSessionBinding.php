<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Models\ExternalIdentity;
use App\Modules\Identity\Oidc\OidcSessionBinding;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ValidateOidcSessionBinding
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has(OidcSessionBinding::SESSION_KEY)) {
            return $next($request);
        }

        $binding = $request->session()->get(OidcSessionBinding::SESSION_KEY);

        if (! is_array($binding)) {
            return $this->invalidateAndRedirect($request, 'malformed_binding');
        }

        $rawExternalId = $binding['external_identity_id'] ?? null;
        $rawUserId = $binding['user_id'] ?? null;
        $rawFingerprint = $binding['trust_key_fingerprint'] ?? null;

        $externalIdentityId = is_int($rawExternalId) && $rawExternalId > 0 ? $rawExternalId : null;
        $boundUserId = is_int($rawUserId) && $rawUserId > 0 ? $rawUserId : null;
        $fingerprint = is_string($rawFingerprint) && preg_match('/^[a-f0-9]{64}$/', $rawFingerprint) ? $rawFingerprint : null;

        if ($externalIdentityId === null || $boundUserId === null || $fingerprint === null) {
            return $this->invalidateAndRedirect(
                $request,
                'malformed_binding',
                $externalIdentityId,
                $boundUserId,
                $fingerprint
            );
        }

        $authUser = Auth::guard('web')->user();
        if ($authUser === null) {
            return $this->invalidateAndRedirect(
                $request,
                'missing_authenticated_user',
                $externalIdentityId,
                $boundUserId,
                $fingerprint
            );
        }

        $authUserId = (int) $authUser->getAuthIdentifier();
        if ($authUserId !== $boundUserId) {
            return $this->invalidateAndRedirect(
                $request,
                'user_mismatch',
                $externalIdentityId,
                $boundUserId,
                $fingerprint,
                $authUserId
            );
        }

        $identity = ExternalIdentity::find($externalIdentityId);
        if ($identity === null) {
            return $this->invalidateAndRedirect(
                $request,
                'missing_external_identity',
                $externalIdentityId,
                $boundUserId,
                $fingerprint,
                $authUserId
            );
        }

        if ($identity->provider !== 'keycloak') {
            return $this->invalidateAndRedirect(
                $request,
                'provider_mismatch',
                $externalIdentityId,
                $boundUserId,
                $fingerprint,
                $authUserId
            );
        }

        if ((int) $identity->user_id !== $boundUserId) {
            return $this->invalidateAndRedirect(
                $request,
                'user_mismatch',
                $externalIdentityId,
                $boundUserId,
                $fingerprint,
                $authUserId
            );
        }

        $expectedFingerprint = hash('sha256', $identity->issuer."\0".$identity->subject);
        if (! hash_equals($expectedFingerprint, $fingerprint)) {
            return $this->invalidateAndRedirect(
                $request,
                'fingerprint_mismatch',
                $externalIdentityId,
                $boundUserId,
                $fingerprint,
                $authUserId
            );
        }

        return $next($request);
    }

    /**
     * Invalidate session, logout, log safe warning, and redirect to login.
     */
    protected function invalidateAndRedirect(
        Request $request,
        string $reason,
        ?int $externalIdentityId = null,
        ?int $boundUserId = null,
        ?string $fingerprint = null,
        ?int $authUserId = null
    ): Response {
        if ($authUserId === null) {
            $user = Auth::guard('web')->user();
            if ($user !== null) {
                $authUserId = (int) $user->getAuthIdentifier();
            }
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Log::warning('oidc.session.binding_invalidated', [
            'reason' => $reason,
            'external_identity_id' => $externalIdentityId,
            'bound_user_id' => $boundUserId,
            'authenticated_user_id' => $authUserId,
            'trust_key_fingerprint' => $fingerprint,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->route('login');
    }
}
