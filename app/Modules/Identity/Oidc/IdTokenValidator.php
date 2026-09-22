<?php

namespace App\Modules\Identity\Oidc;

use App\Modules\Identity\Oidc\Exceptions\OidcCallbackException;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Log;
use Throwable;

class IdTokenValidator
{
    public function __construct(
        protected JwksProvider $jwksProvider
    ) {
    }

    /**
     * Cryptographically validate an ID token JWT and assert its claims.
     *
     * @param string $idToken
     * @param string $expectedNonce
     * @return ValidatedOidcIdentity
     * @throws OidcCallbackException
     */
    public function validate(string $idToken, string $expectedNonce): ValidatedOidcIdentity
    {
        $header = $this->parseUntrustedHeader($idToken);

        $kid = $header['kid'];
        $key = $this->jwksProvider->getKeyByKid($kid);

        $clockSkew = (int) config('oidc.clock_skew', 60);
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $clockSkew;

        try {
            $payload = JWT::decode($idToken, $key);
            $claims = json_decode(json_encode($payload), true);
            if (!is_array($claims)) {
                throw new OidcCallbackException(401, 'invalid_claims_payload', 'Invalid token payload.');
            }
        } catch (OidcCallbackException $e) {
            throw $e;
        } catch (\Firebase\JWT\ExpiredException $e) {
            Log::error('oidc.claims.expired');
            throw new OidcCallbackException(401, 'token_expired', 'Token has expired.', $e);
        } catch (\Firebase\JWT\BeforeValidException $e) {
            if (str_contains($e->getMessage(), 'iat')) {
                Log::error('oidc.claims.future_iat');
                throw new OidcCallbackException(401, 'future_iat', 'Token issued-at time is in the future.', $e);
            }
            Log::error('oidc.claims.future_nbf');
            throw new OidcCallbackException(401, 'future_nbf', 'Token is not yet valid.', $e);
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            Log::error('oidc.jwt.invalid_signature');
            throw new OidcCallbackException(401, 'invalid_signature', 'Invalid token signature.', $e);
        } catch (Throwable $e) {
            Log::error('oidc.jwt.invalid_token');
            throw new OidcCallbackException(401, 'invalid_token', 'Invalid token.', $e);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $this->validateClaims($claims, $expectedNonce, $clockSkew);
        $this->validateEnvironmentEligibility($claims);

        $email = isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : null;

        return new ValidatedOidcIdentity(
            issuer: (string) $claims['iss'],
            subject: (string) $claims['sub'],
            environmentIdentityClass: (string) config('oidc.expected_identity_class'),
            email: $email
        );
    }

    /**
     * Parse untrusted JWT header to extract and validate alg and kid.
     *
     * @param string $idToken
     * @return array{alg: string, kid: string}
     * @throws OidcCallbackException
     */
    protected function parseUntrustedHeader(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            Log::error('oidc.jwt.malformed_format');
            throw new OidcCallbackException(401, 'malformed_jwt', 'Invalid token format.');
        }

        $decodedHeader = $this->base64UrlDecode($parts[0]);
        $header = json_decode($decodedHeader, true);

        if (!is_array($header)) {
            Log::error('oidc.jwt.invalid_header');
            throw new OidcCallbackException(401, 'invalid_header', 'Invalid token header.');
        }

        if (!isset($header['alg']) || $header['alg'] !== 'RS256') {
            Log::error('oidc.jwt.unsupported_alg');
            throw new OidcCallbackException(401, 'unsupported_alg', 'Unsupported token signing algorithm.');
        }

        if (!isset($header['kid']) || !is_string($header['kid']) || trim($header['kid']) === '') {
            Log::error('oidc.jwt.missing_kid');
            throw new OidcCallbackException(401, 'missing_kid', 'Unable to verify token signature.');
        }

        return [
            'alg' => $header['alg'],
            'kid' => $header['kid'],
        ];
    }

    /**
     * Validate standard OIDC claims.
     *
     * @param array<string, mixed> $claims
     * @param string $expectedNonce
     * @param int $clockSkew
     * @throws OidcCallbackException
     */
    protected function validateClaims(array $claims, string $expectedNonce, int $clockSkew): void
    {
        // 1. Issuer
        $expectedIssuer = rtrim((string) config('oidc.issuer'), '/');
        if (!isset($claims['iss']) || $claims['iss'] !== $expectedIssuer) {
            Log::error('oidc.claims.invalid_issuer');
            throw new OidcCallbackException(401, 'invalid_issuer', 'Token issuer mismatch.');
        }

        // 2. Audience
        $clientId = (string) config('oidc.client_id');
        if (!isset($claims['aud'])) {
            Log::error('oidc.claims.missing_audience');
            throw new OidcCallbackException(401, 'missing_audience', 'Token audience mismatch.');
        }

        if (is_string($claims['aud'])) {
            if ($claims['aud'] !== $clientId) {
                Log::error('oidc.claims.invalid_audience');
                throw new OidcCallbackException(401, 'invalid_audience', 'Token audience mismatch.');
            }
        } elseif (is_array($claims['aud'])) {
            if (count($claims['aud']) !== 1 || $claims['aud'][0] !== $clientId) {
                Log::error('oidc.claims.invalid_audience');
                throw new OidcCallbackException(401, 'invalid_audience', 'Token audience mismatch.');
            }
        } else {
            Log::error('oidc.claims.invalid_audience');
            throw new OidcCallbackException(401, 'invalid_audience', 'Token audience mismatch.');
        }

        // 3. Authorized Party (azp)
        if (isset($claims['azp'])) {
            if (!is_string($claims['azp']) || $claims['azp'] !== $clientId) {
                Log::error('oidc.claims.invalid_azp');
                throw new OidcCallbackException(401, 'invalid_azp', 'Token authorized party mismatch.');
            }
        }

        // 4. Expiration (exp)
        if (!isset($claims['exp']) || (!is_int($claims['exp']) && !is_numeric($claims['exp']))) {
            Log::error('oidc.claims.missing_exp');
            throw new OidcCallbackException(401, 'missing_exp', 'Token has expired.');
        }

        $now = time();
        if ((int) $claims['exp'] < ($now - $clockSkew)) {
            Log::error('oidc.claims.expired');
            throw new OidcCallbackException(401, 'token_expired', 'Token has expired.');
        }

        // 5. Issued At (iat)
        if (!isset($claims['iat']) || (!is_int($claims['iat']) && !is_numeric($claims['iat']))) {
            Log::error('oidc.claims.missing_iat');
            throw new OidcCallbackException(401, 'missing_iat', 'Invalid token issued-at time.');
        }

        if ((int) $claims['iat'] > ($now + $clockSkew)) {
            Log::error('oidc.claims.future_iat');
            throw new OidcCallbackException(401, 'future_iat', 'Token issued-at time is in the future.');
        }

        // 6. Not Before (nbf)
        if (isset($claims['nbf']) && (is_int($claims['nbf']) || is_numeric($claims['nbf']))) {
            if ((int) $claims['nbf'] > ($now + $clockSkew)) {
                Log::error('oidc.claims.future_nbf');
                throw new OidcCallbackException(401, 'future_nbf', 'Token is not yet valid.');
            }
        }

        // 7. Nonce
        if (!isset($claims['nonce']) || !is_string($claims['nonce']) || $claims['nonce'] !== $expectedNonce) {
            Log::error('oidc.claims.invalid_nonce');
            throw new OidcCallbackException(401, 'invalid_nonce', 'Token nonce verification failed.');
        }

        // 8. Subject (sub)
        if (!isset($claims['sub']) || !is_string($claims['sub']) || trim($claims['sub']) === '') {
            Log::error('oidc.claims.missing_subject');
            throw new OidcCallbackException(401, 'missing_subject', 'Missing identity subject.');
        }
    }

    /**
     * Validate environment eligibility based on the reltroner_identity_class claim.
     *
     * @param array<string, mixed> $claims
     * @throws OidcCallbackException
     */
    protected function validateEnvironmentEligibility(array $claims): void
    {
        if (!isset($claims['reltroner_identity_class'])) {
            Log::error('oidc.identity.missing_class');
            throw new OidcCallbackException(403, 'missing_identity_class', 'Access denied for this environment.');
        }

        $idClass = $claims['reltroner_identity_class'];
        if (!is_array($idClass)) {
            Log::error('oidc.identity.scalar_rejected');
            throw new OidcCallbackException(403, 'scalar_identity_class', 'Access denied for this environment.');
        }

        $expectedClass = (string) config('oidc.expected_identity_class');

        if (count($idClass) !== 1 || $idClass[0] !== $expectedClass) {
            Log::error('oidc.identity.environment_forbidden');
            throw new OidcCallbackException(403, 'environment_forbidden', 'Access denied for this environment.');
        }
    }

    /**
     * Decode base64url-encoded string.
     */
    protected function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }

        return (string) base64_decode(strtr($input, '-_', '+/'));
    }
}
