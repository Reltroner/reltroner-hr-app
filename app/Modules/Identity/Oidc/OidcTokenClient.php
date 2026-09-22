<?php

namespace App\Modules\Identity\Oidc;

use App\Modules\Identity\Oidc\Exceptions\OidcCallbackException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OidcTokenClient
{
    /**
     * Exchange an authorization code for an ID token string using client_secret_basic.
     *
     * @param string $code
     * @param string $codeVerifier
     * @return string Raw ID token JWT string
     * @throws OidcCallbackException
     */
    public function exchangeCode(string $code, string $codeVerifier): string
    {
        $issuer = config('oidc.issuer');
        $clientId = config('oidc.client_id');
        $clientSecret = config('oidc.client_secret');
        $redirectUri = config('oidc.redirect_uri');
        $timeout = (int) config('oidc.http_timeout', 5);

        $endpoint = rtrim((string) $issuer, '/') . '/protocol/openid-connect/token';

        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ];

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->withBasicAuth((string) $clientId, (string) $clientSecret)
                ->timeout($timeout)
                ->post($endpoint, $body);
        } catch (Throwable $e) {
            Log::error('oidc.token.http_error', ['category' => 'connection_failed']);
            throw new OidcCallbackException(502, 'token_connection_failed', 'Identity provider communication failure.', $e);
        }

        if (!$response->successful()) {
            Log::error('oidc.token.http_error', ['status' => $response->status()]);
            throw new OidcCallbackException(502, 'token_http_error', 'Identity provider communication failure.');
        }

        $json = $response->json();
        if (!is_array($json) || !isset($json['id_token']) || !is_string($json['id_token']) || trim($json['id_token']) === '') {
            Log::error('oidc.token.malformed_response');
            throw new OidcCallbackException(502, 'malformed_token_response', 'Malformed identity token response.');
        }

        return $json['id_token'];
    }
}
