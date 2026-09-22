<?php

namespace App\Modules\Identity\Oidc;

class OidcPrimitives
{
    /**
     * Encode binary or string data to unpadded Base64URL (RFC 7636 / RFC 4648 §5).
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Generate a cryptographically secure, high-entropy random state (43 characters).
     */
    public static function generateState(): string
    {
        return self::base64UrlEncode(random_bytes(32));
    }

    /**
     * Generate a cryptographically secure, high-entropy random nonce (43 characters).
     */
    public static function generateNonce(): string
    {
        return self::base64UrlEncode(random_bytes(32));
    }

    /**
     * Generate a cryptographically secure PKCE code_verifier (43 characters, RFC 7636 §4.1).
     */
    public static function generateCodeVerifier(): string
    {
        return self::base64UrlEncode(random_bytes(32));
    }

    /**
     * Compute the PKCE S256 code_challenge from a code_verifier (RFC 7636 §4.2).
     *
     * code_challenge = BASE64URL(SHA256(code_verifier))
     */
    public static function codeChallengeS256(string $verifier): string
    {
        return self::base64UrlEncode(hash('sha256', $verifier, true));
    }
}
