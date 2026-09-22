<?php

namespace App\Modules\Identity\Oidc;

use Illuminate\Support\Facades\Log;

class OidcAuthorizationService
{
    public function __construct(
        protected OidcTransactionStore $store
    ) {
    }

    /**
     * Build the OIDC authorization redirect URL and persist the transaction server-side.
     *
     * Order of operations:
     * 1. validate configuration (fail closed if missing or invalid)
     * 2. generate cryptographic primitives
     * 3. create OidcTransaction DTO
     * 4. construct complete RFC 3986 authorization URL
     * 5. store transaction in session
     * 6. return authorization URL string
     *
     * @return string The fully constructed authorization URL
     */
    public function buildAuthorizationUrl(): string
    {
        $this->validateConfiguration();

        /** @var string $issuer */
        $issuer = config('oidc.issuer');
        /** @var string $clientId */
        $clientId = config('oidc.client_id');
        /** @var string $redirectUri */
        $redirectUri = config('oidc.redirect_uri');
        /** @var string $scopes */
        $scopes = config('oidc.scopes');

        $state = OidcPrimitives::generateState();
        $nonce = OidcPrimitives::generateNonce();
        $codeVerifier = OidcPrimitives::generateCodeVerifier();
        $codeChallenge = OidcPrimitives::codeChallengeS256($codeVerifier);

        $transaction = new OidcTransaction(
            state: $state,
            nonce: $nonce,
            codeVerifier: $codeVerifier,
            codeChallenge: $codeChallenge,
            createdAt: time()
        );

        $endpoint = rtrim($issuer, '/') . '/protocol/openid-connect/auth';

        $parameters = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scopes,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $authorizationUrl = $endpoint . '?' . $query;

        $this->store->store($transaction);

        return $authorizationUrl;
    }

    /**
     * Validate all required Phase 7D configuration settings.
     * Fails closed with HTTP 500 without logging sensitive values.
     */
    protected function validateConfiguration(): void
    {
        $issuer = config('oidc.issuer');
        if (!$this->isValidAbsoluteUrl($issuer)) {
            Log::error('OIDC configuration invalid or missing: issuer');
            abort(500, 'OIDC configuration is not ready.');
        }

        $clientId = config('oidc.client_id');
        if (!is_string($clientId) || trim($clientId) === '') {
            Log::error('OIDC configuration invalid or missing: client_id');
            abort(500, 'OIDC configuration is not ready.');
        }

        $redirectUri = config('oidc.redirect_uri');
        if (!$this->isValidAbsoluteUrl($redirectUri)) {
            Log::error('OIDC configuration invalid or missing: redirect_uri');
            abort(500, 'OIDC configuration is not ready.');
        }

        $scopes = config('oidc.scopes');
        if (!$this->isValidScopes($scopes)) {
            Log::error('OIDC configuration invalid or missing: scopes');
            abort(500, 'OIDC configuration is not ready.');
        }

        $pkceMethod = config('oidc.pkce_method');
        if ($pkceMethod !== 'S256') {
            Log::error('OIDC configuration invalid or missing: pkce_method');
            abort(500, 'OIDC configuration is not ready.');
        }
    }

    /**
     * Check if a given string is a valid absolute HTTP or HTTPS URL.
     */
    protected function isValidAbsoluteUrl(mixed $url): bool
    {
        if (!is_string($url) || trim($url) === '') {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) && is_string($host) && trim($host) !== '';
    }

    /**
     * Check if scopes are non-empty whitespace-separated string containing exact 'openid' token.
     */
    protected function isValidScopes(mixed $scopes): bool
    {
        if (!is_string($scopes) || trim($scopes) === '') {
            return false;
        }

        $tokens = preg_split('/\s+/', trim($scopes));

        return is_array($tokens) && in_array('openid', $tokens, true);
    }
}
