<?php

namespace App\Modules\Identity\Oidc;

use App\Modules\Identity\Oidc\Exceptions\OidcCallbackException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OidcCallbackService
{
    public function __construct(
        protected OidcTransactionStore $transactionStore,
        protected OidcTokenClient $tokenClient,
        protected IdTokenValidator $idTokenValidator,
        protected OidcIdentityResolver $identityResolver
    ) {
    }

    /**
     * Process an incoming OIDC callback request and return resolved local identity.
     *
     * @param Request $request
     * @return ResolvedOidcIdentity
     * @throws OidcCallbackException
     */
    public function handleCallback(Request $request): ResolvedOidcIdentity
    {
        // 1. Require state query value to be a scalar non-empty string
        $state = $request->query('state');
        if (!is_string($state) || trim($state) === '') {
            Log::error('oidc.callback.missing_state');
            throw new OidcCallbackException(400, 'missing_state', 'Invalid authorization state.');
        }

        // 2. Consume state immediately using frozen OidcTransactionStore
        $transaction = $this->transactionStore->consume($state);

        // 3. If consume returns null: HTTP 400 fail closed
        if ($transaction === null) {
            Log::error('oidc.callback.invalid_state');
            throw new OidcCallbackException(400, 'invalid_state', 'Invalid or expired authorization state.');
        }

        // 4. If provider returned error: transaction is already consumed, fail closed
        if ($request->has('error')) {
            Log::error('oidc.callback.provider_error');
            throw new OidcCallbackException(400, 'provider_error', 'Authentication provider returned an error.');
        }

        // 5. Require code to be scalar non-empty string. If absent, HTTP 400 (transaction remains consumed)
        $code = $request->query('code');
        if (!is_string($code) || trim($code) === '') {
            Log::error('oidc.callback.missing_code');
            throw new OidcCallbackException(400, 'missing_code', 'Authorization code missing.');
        }

        // 6. Validate Phase 7E server configuration
        $this->validateServerConfiguration();

        // 7. Exchange code server-side & 8. Extract transient id_token
        $idToken = $this->tokenClient->exchangeCode($code, $transaction->codeVerifier);

        // 9-11. Validate header/JWKS/signature, claims, and environment eligibility
        $validatedIdentity = $this->idTokenValidator->validate($idToken, $transaction->nonce);

        // 12. Resolve approved local identity
        return $this->identityResolver->resolve($validatedIdentity);
    }

    /**
     * Validate Phase 7E server configuration readiness and environment coherence.
     *
     * @throws OidcCallbackException
     */
    protected function validateServerConfiguration(): void
    {
        $issuer = config('oidc.issuer');
        $clientId = config('oidc.client_id');
        $clientSecret = config('oidc.client_secret');
        $redirectUri = config('oidc.redirect_uri');
        $environment = config('oidc.environment');
        $expectedIdentityClass = config('oidc.expected_identity_class');

        if (
            !is_string($issuer) || trim($issuer) === ''
            || !is_string($clientId) || trim($clientId) === ''
            || !is_string($clientSecret) || trim($clientSecret) === ''
            || !is_string($redirectUri) || trim($redirectUri) === ''
            || !is_string($environment) || trim($environment) === ''
            || !is_string($expectedIdentityClass) || trim($expectedIdentityClass) === ''
        ) {
            Log::error('oidc.config.missing_required_settings');
            throw new OidcCallbackException(500, 'invalid_configuration', 'OIDC configuration is not ready.');
        }

        $isCoherent = ($environment === 'production' && $expectedIdentityClass === 'production_user')
            || ($environment === 'demo' && $expectedIdentityClass === 'demo_user');

        if (!$isCoherent) {
            Log::error('oidc.config.incoherent_environment_pair');
            throw new OidcCallbackException(500, 'incoherent_configuration', 'OIDC configuration is not ready.');
        }
    }
}
