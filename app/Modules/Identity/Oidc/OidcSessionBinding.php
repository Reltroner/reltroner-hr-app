<?php

namespace App\Modules\Identity\Oidc;

use App\Modules\Identity\Models\ExternalIdentity;
use Illuminate\Http\Request;

class OidcSessionBinding
{
    /**
     * Session key storing the bound OIDC identity details.
     */
    public const SESSION_KEY = 'identity.oidc_binding';

    /**
     * Bind an approved ExternalIdentity to the active Laravel session.
     * Stored value contains strictly: external_identity_id, user_id, trust_key_fingerprint.
     */
    public static function create(Request $request, ExternalIdentity $identity): void
    {
        $fingerprint = hash('sha256', $identity->issuer."\0".$identity->subject);

        $request->session()->put(self::SESSION_KEY, [
            'external_identity_id' => (int) $identity->getKey(),
            'user_id' => (int) $identity->user_id,
            'trust_key_fingerprint' => $fingerprint,
        ]);
    }

    /**
     * Check if the current session contains an OIDC session binding.
     */
    public static function has(Request $request): bool
    {
        return $request->hasSession() && $request->session()->has(self::SESSION_KEY);
    }

    /**
     * Retrieve the OIDC session binding data from the active session.
     */
    public static function get(Request $request): mixed
    {
        return $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
    }

    /**
     * Remove the OIDC session binding from the active session.
     */
    public static function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }
}
