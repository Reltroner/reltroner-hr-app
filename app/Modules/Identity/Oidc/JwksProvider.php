<?php

namespace App\Modules\Identity\Oidc;

use App\Modules\Identity\Oidc\Exceptions\OidcCallbackException;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class JwksProvider
{
    /**
     * Resolve a Firebase\JWT\Key object by kid from cached or fresh JWKS.
     *
     * @param string $kid
     * @return Key
     * @throws OidcCallbackException
     */
    public function getKeyByKid(string $kid): Key
    {
        $issuer = config('oidc.issuer');
        $cacheKey = 'oidc:jwks:' . hash('sha256', (string) $issuer);
        $ttl = (int) config('oidc.jwks_cache_ttl', 3600);

        $cachedJwks = Cache::get($cacheKey);

        if (is_array($cachedJwks) && isset($cachedJwks['keys']) && is_array($cachedJwks['keys'])) {
            $cachedJwkData = $this->findJwkInKeys($cachedJwks['keys'], $kid);
            if ($cachedJwkData !== null) {
                try {
                    return $this->buildKeyFromJwk($cachedJwkData);
                } catch (Throwable) {
                    // Cached matching JWK is malformed/invalid/unparsable:
                    // Fall through to perform exactly one fresh JWKS fetch.
                }
            }
        }

        // Fetch fresh JWKS if cache missing, malformed, kid absent, or cached key invalid
        $freshJwks = $this->fetchFreshJwks();
        Cache::put($cacheKey, $freshJwks, $ttl);

        $freshJwkData = $this->findJwkInKeys($freshJwks['keys'], $kid);
        if ($freshJwkData === null) {
            Log::error('oidc.jwt.unknown_kid');
            throw new OidcCallbackException(401, 'unknown_kid', 'Unable to verify token signature.');
        }

        return $this->buildKeyFromJwk($freshJwkData);
    }

    /**
     * Find a JWK matching the given kid in an array of JWKs.
     *
     * @param array<int, mixed> $keys
     * @param string $kid
     * @return array<string, mixed>|null
     */
    protected function findJwkInKeys(array $keys, string $kid): ?array
    {
        foreach ($keys as $key) {
            if (is_array($key) && isset($key['kid']) && $key['kid'] === $kid) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Fetch fresh JWKS from Keycloak certs endpoint.
     *
     * @return array{keys: array<int, mixed>}
     * @throws OidcCallbackException
     */
    protected function fetchFreshJwks(): array
    {
        $issuer = config('oidc.issuer');
        $timeout = (int) config('oidc.http_timeout', 5);
        $endpoint = rtrim((string) $issuer, '/') . '/protocol/openid-connect/certs';

        try {
            $response = Http::acceptJson()
                ->timeout($timeout)
                ->get($endpoint);
        } catch (Throwable $e) {
            Log::error('oidc.jwks.network_error');
            throw new OidcCallbackException(502, 'jwks_network_error', 'Identity provider communication failure.', $e);
        }

        if (!$response->successful()) {
            Log::error('oidc.jwks.http_error', ['status' => $response->status()]);
            throw new OidcCallbackException(502, 'jwks_http_error', 'Identity provider communication failure.');
        }

        $json = $response->json();
        if (!is_array($json) || !isset($json['keys']) || !is_array($json['keys']) || empty($json['keys'])) {
            Log::error('oidc.jwks.malformed_response');
            throw new OidcCallbackException(502, 'jwks_malformed', 'Identity provider communication failure.');
        }

        return $json;
    }

    /**
     * Validate JWK attributes and parse into a Firebase\JWT\Key object.
     *
     * @param array<string, mixed> $jwk
     * @return Key
     * @throws OidcCallbackException
     */
    protected function buildKeyFromJwk(array $jwk): Key
    {
        if (!isset($jwk['kty']) || $jwk['kty'] !== 'RSA') {
            Log::error('oidc.jwt.invalid_kty');
            throw new OidcCallbackException(401, 'invalid_kty', 'Unable to verify token signature.');
        }

        if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
            Log::error('oidc.jwt.invalid_key_use');
            throw new OidcCallbackException(401, 'invalid_key_use', 'Unable to verify token signature.');
        }

        if (isset($jwk['alg']) && $jwk['alg'] !== 'RS256') {
            Log::error('oidc.jwt.invalid_key_alg');
            throw new OidcCallbackException(401, 'invalid_key_alg', 'Unable to verify token signature.');
        }

        try {
            $key = JWK::parseKey($jwk, 'RS256');
            if ($key === null) {
                throw new OidcCallbackException(401, 'key_parse_failed', 'Unable to verify token signature.');
            }
            return $key;
        } catch (OidcCallbackException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('oidc.jwt.key_parse_error');
            throw new OidcCallbackException(401, 'key_parse_error', 'Unable to verify token signature.', $e);
        }
    }
}
