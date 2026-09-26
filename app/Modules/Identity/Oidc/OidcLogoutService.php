<?php

namespace App\Modules\Identity\Oidc;

use Illuminate\Support\Facades\Log;

class OidcLogoutService
{
    /**
     * Build the RP-initiated Keycloak logout URL.
     *
     * Responsibilities:
     * - Read oidc.issuer, oidc.client_id, oidc.post_logout_redirect_uri
     * - Validate required configuration (fail closed on missing/invalid)
     * - Construct Keycloak end-session endpoint generically from issuer
     * - Encode query parameters using RFC 3986
     * - Include client_id and post_logout_redirect_uri
     * - Include id_token_hint only when a validated session-scoped hint is available
     * - Never include client_secret, access_token, refresh_token, or authorization code
     *
     * @return string The fully constructed Keycloak logout URL
     */
    public function buildLogoutUrl(#[\SensitiveParameter] ?string $idTokenHint = null): string
    {
        $this->validateConfiguration();

        /** @var string $issuer */
        $issuer = config('oidc.issuer');
        /** @var string $clientId */
        $clientId = config('oidc.client_id');
        /** @var string $postLogoutRedirectUri */
        $postLogoutRedirectUri = config('oidc.post_logout_redirect_uri');

        $endpoint = rtrim($issuer, '/').'/protocol/openid-connect/logout';

        $parameters = [
            'client_id' => $clientId,
        ];

        if (is_string($idTokenHint) && trim($idTokenHint) !== '') {
            $parameters['id_token_hint'] = $idTokenHint;
        }

        $parameters['post_logout_redirect_uri'] = $postLogoutRedirectUri;

        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        return $endpoint.'?'.$query;
    }

    /**
     * Validate all required Phase 10B logout configuration settings.
     * Fails closed with HTTP 500 without logging sensitive values.
     */
    protected function validateConfiguration(): void
    {
        $issuer = config('oidc.issuer');
        if (! $this->isValidAbsoluteUrl($issuer)) {
            Log::error('OIDC logout configuration invalid or missing: issuer');
            abort(500, 'OIDC configuration is not ready.');
        }

        $clientId = config('oidc.client_id');
        if (! is_string($clientId) || trim($clientId) === '') {
            Log::error('OIDC logout configuration invalid or missing: client_id');
            abort(500, 'OIDC configuration is not ready.');
        }

        $postLogoutRedirectUri = config('oidc.post_logout_redirect_uri');
        if (! $this->isValidAbsoluteUrl($postLogoutRedirectUri)) {
            Log::error('OIDC logout configuration invalid or missing: post_logout_redirect_uri');
            abort(500, 'OIDC configuration is not ready.');
        }
    }

    /**
     * Check if a given string is a valid absolute HTTP or HTTPS URL.
     */
    protected function isValidAbsoluteUrl(mixed $url): bool
    {
        if (! is_string($url) || trim($url) === '') {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) && is_string($host) && trim($host) !== '';
    }
}
