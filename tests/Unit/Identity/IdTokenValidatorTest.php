<?php

namespace Tests\Unit\Identity;

use App\Modules\Identity\Oidc\Exceptions\OidcCallbackException;
use App\Modules\Identity\Oidc\IdTokenValidator;
use App\Modules\Identity\Oidc\JwksProvider;
use App\Modules\Identity\Oidc\ValidatedOidcIdentity;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use ReflectionClass;
use Tests\TestCase;

class IdTokenValidatorTest extends TestCase
{
    private string $privateKey;
    private array $jwk;
    private JwksProvider $jwksProvider;
    private IdTokenValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'oidc.issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'oidc.client_id' => 'hrm-web',
            'oidc.environment' => 'production',
            'oidc.expected_identity_class' => 'production_user',
            'oidc.clock_skew' => 60,
        ]);

        $this->privateKey = "-----BEGIN PRIVATE KEY-----\n"
            . "MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDB1c3MmVzyyYfp\n"
            . "2HlmYXFJ7jOBx2aMJ4KvTME6vYZ1i57CEQ1hhyW2uSgGk2tcwlrpBH6FUyN0rQXU\n"
            . "9OZXV2MU7h7mfYqGimOb8voaf3anxIP2j3r+SXYcka8t4FKkc6MkQFKT/HL49fwr\n"
            . "+kBnPj/B4Jmuf50+A9lPSjGqDoxXWIn4GHKvkKgTnUqMltRxVRw0BqZfvTFbZHv6\n"
            . "ktSdEjdhsT4cg9QRlJ4voRbJe1gw9kgI39TTfQgStoJP6bMKNHtGvmUXUZWHJ8om\n"
            . "VymwjhnzWewlFPPIZl7TfZOj7Bi9zEcSD7T+p7voYLoYW3cSdKPbMMB4239aH36b\n"
            . "I+uv0QA7AgMBAAECggEAGg1rDkNUr1sv9dm/I2gYanfmG1zaJx9OXNJjrEn57wWX\n"
            . "jnztP/0CsCb9vriEtyB2SJhuiuvsOYvh20gZR4b6zb7dj1wzSLcEAVtsizAzmgP7\n"
            . "OqH5RYFJKzjXg0KByRGzzTUKBFLrfxPM03pcuqOuvRe7gC0tzL6GsDYIK9OtwDVt\n"
            . "n23xmu4G8USFGHfJm8mTiVH2P/PDyKFQEJlq8rCpkzvrI3Rpby96pOV86R9QW/Jc\n"
            . "NVKW8oAtjV4j5zcwUAnLYMgHdG/3GYMidD2FHnn1t3rkaPtWWBwQNJ32sqiDG+km\n"
            . "XMjYOefCQVkmvmhS41Gv1ocpk50mbSRACCjMoPYx8QKBgQD0OPvgyMR1VzmrMqwm\n"
            . "JGHwq6ImNMXFTVY6PjM5BzY6+QsTwgQOTZgsSbswjurH0yki/l/XZe001M75/VNN\n"
            . "Vcnq8A9Ed3gO9HxF2WWjUczjhv31hqulHHZOre//68IG80hgMXjiw73Cq1RyQJdo\n"
            . "+bQOqX4C+PSwHei3YdDpQ7TrKwKBgQDLLsTi50JBUR6LSKCy/fdcN06sJHS786KA\n"
            . "KlVNuRDUO4AJ6JX60SO6cUeSEvl+74fTSC1bQTVSa2HV6PhwoDMv4HlEYyAPYwsx\n"
            . "ULU1hHYjPll/B2THUCGE1ChbRpwW+yT1zY++fTxcsDoJkvFtl9AVT7hYqHd7r4QB\n"
            . "9ml3dx13MQKBgA9ublPku60iZs1vdTsvv1SCs8swOHLgERu7BGeNEhsl01JbRwBU\n"
            . "XNInkoFd9m3L5OSGKC4nDZbx/2YCYLoZOpnyszRDTD29qwCK3QY1y/lwdSmHad8T\n"
            . "7lHIYcrM7cScqK0TUy0Y6yuawco6VJbYeE0Y3pJ3gpaCPUshDh8/HPZjAoGAFf/i\n"
            . "YY8YpWnbHMmoXLkS53E1m333BcLDfY0X32qCX/hxTKFaW+X5MF7DmRVk3lGhK0dN\n"
            . "YewVke7+kOLAw7EU2cI8XyM8fW4D8DsE496LzBUcK5zpVItglblDBV8H15Up01OG\n"
            . "lOGKf561KgQ3D964MRaIp1DWXxYJ/QxpLv4+uoECgYAENDd0J2GWqIY0CK2bGlmx\n"
            . "T8SMGP8RyvmEXtquRPvRVPniUOGh7NWaib6xbe2P6OGpnRWaYcf4HWEKhHQM6qXj\n"
            . "JjokZ37AyVxafVL8uGQ8o7zjPrQRAXNqHOiEMdFlvZvITuNaMFRR4/SfGpJrcW92\n"
            . "vKSSlgYEMvceSx6uFTWNbQ==\n"
            . "-----END PRIVATE KEY-----";

        $this->jwk = [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => 'test-kid-1',
            'n' => 'wdXNzJlc8smH6dh5ZmFxSe4zgcdmjCeCr0zBOr2GdYuewhENYYcltrkoBpNrXMJa6QR-hVMjdK0F1PTmV1djFO4e5n2Khopjm_L6Gn92p8SD9o96_kl2HJGvLeBSpHOjJEBSk_xy-PX8K_pAZz4_weCZrn-dPgPZT0oxqg6MV1iJ-Bhyr5CoE51KjJbUcVUcNAamX70xW2R7-pLUnRI3YbE-HIPUEZSeL6EWyXtYMPZICN_U030IEraCT-mzCjR7Rr5lF1GVhyfKJlcpsI4Z81nsJRTzyGZe032To-wYvcxHEg-0_qe76GC6GFt3EnSj2zDAeNt_Wh9-myPrr9EAOw',
            'e' => 'AQAB',
        ];

        $this->jwksProvider = $this->createMock(JwksProvider::class);
        $this->jwksProvider->method('getKeyByKid')->willReturnCallback(function (string $kid) {
            if ($kid === 'test-kid-1') {
                return JWK::parseKey($this->jwk, 'RS256');
            }
            throw new OidcCallbackException(401, 'unknown_kid', 'Unable to verify token signature.');
        });

        $this->validator = new IdTokenValidator($this->jwksProvider);
    }

    private function validClaims(array $overrides = []): array
    {
        $now = time();
        return array_merge([
            'iss' => 'https://auth.reltroner.com/realms/reltroner',
            'aud' => 'hrm-web',
            'azp' => 'hrm-web',
            'sub' => 'sub-12345',
            'nonce' => 'nonce-abcde',
            'exp' => $now + 300,
            'iat' => $now,
            'email' => 'user@example.com',
            'reltroner_identity_class' => ['production_user'],
        ], $overrides);
    }

    private function encodeToken(array $claims, string $kid = 'test-kid-1'): string
    {
        return JWT::encode($claims, $this->privateKey, 'RS256', $kid);
    }

    public function test_valid_rs256_token_succeeds(): void
    {
        $token = $this->encodeToken($this->validClaims());
        $identity = $this->validator->validate($token, 'nonce-abcde');

        $this->assertInstanceOf(ValidatedOidcIdentity::class, $identity);
        $this->assertSame('https://auth.reltroner.com/realms/reltroner', $identity->issuer);
        $this->assertSame('sub-12345', $identity->subject);
        $this->assertSame('production_user', $identity->environmentIdentityClass);
        $this->assertSame('user@example.com', $identity->email);
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $token = $this->encodeToken($this->validClaims());
        $parts = explode('.', $token);
        // Tamper with signature
        $parts[2] = rtrim(strtr(base64_encode('tampered-signature-bytes'), '+/', '-_'), '=');
        $tamperedToken = implode('.', $parts);

        $this->expectException(OidcCallbackException::class);
        $this->expectExceptionCode(401);

        $this->validator->validate($tamperedToken, 'nonce-abcde');
    }

    public function test_alg_none_is_rejected(): void
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'kid' => 'test-kid-1'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode($this->validClaims())), '+/', '-_'), '=');
        $token = "{$header}.{$payload}.";

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('unsupported_alg', $e->getCategory());
        }
    }

    public function test_hs256_is_rejected(): void
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'kid' => 'test-kid-1'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode($this->validClaims())), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", 'secret', true)), '+/', '-_'), '=');
        $token = "{$header}.{$payload}.{$sig}";

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('unsupported_alg', $e->getCategory());
        }
    }

    public function test_ps256_is_rejected(): void
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'PS256', 'kid' => 'test-kid-1'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode($this->validClaims())), '+/', '-_'), '=');
        $token = "{$header}.{$payload}.fakesig";

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('unsupported_alg', $e->getCategory());
        }
    }

    public function test_es256_is_rejected(): void
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'ES256', 'kid' => 'test-kid-1'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode($this->validClaims())), '+/', '-_'), '=');
        $token = "{$header}.{$payload}.fakesig";

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('unsupported_alg', $e->getCategory());
        }
    }

    public function test_missing_kid_is_rejected(): void
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode($this->validClaims())), '+/', '-_'), '=');
        $token = "{$header}.{$payload}.fakesig";

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('missing_kid', $e->getCategory());
        }
    }

    public function test_unknown_kid_is_rejected(): void
    {
        $token = $this->encodeToken($this->validClaims(), 'unknown-kid');

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('unknown_kid', $e->getCategory());
        }
    }

    public function test_correct_issuer_is_accepted(): void
    {
        $token = $this->encodeToken($this->validClaims(['iss' => 'https://auth.reltroner.com/realms/reltroner']));
        $identity = $this->validator->validate($token, 'nonce-abcde');
        $this->assertSame('https://auth.reltroner.com/realms/reltroner', $identity->issuer);
    }

    public function test_wrong_issuer_is_rejected(): void
    {
        $token = $this->encodeToken($this->validClaims(['iss' => 'https://evil.example.com/realms/reltroner']));

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('invalid_issuer', $e->getCategory());
        }
    }

    public function test_aud_string_exact_is_accepted(): void
    {
        $token = $this->encodeToken($this->validClaims(['aud' => 'hrm-web']));
        $identity = $this->validator->validate($token, 'nonce-abcde');
        $this->assertInstanceOf(ValidatedOidcIdentity::class, $identity);
    }

    public function test_aud_array_with_client_id_is_accepted(): void
    {
        $token = $this->encodeToken($this->validClaims(['aud' => ['hrm-web']]));
        $identity = $this->validator->validate($token, 'nonce-abcde');
        $this->assertInstanceOf(ValidatedOidcIdentity::class, $identity);
    }

    public function test_aud_extra_audience_is_denied(): void
    {
        $token = $this->encodeToken($this->validClaims(['aud' => ['hrm-web', 'account']]));

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('invalid_audience', $e->getCategory());
        }
    }

    public function test_wrong_aud_is_denied(): void
    {
        $token = $this->encodeToken($this->validClaims(['aud' => 'other-client']));

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('invalid_audience', $e->getCategory());
        }
    }

    public function test_azp_mismatch_is_denied(): void
    {
        $token = $this->encodeToken($this->validClaims(['azp' => 'other-client']));

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('invalid_azp', $e->getCategory());
        }
    }

    public function test_missing_exp_is_denied(): void
    {
        $claims = $this->validClaims();
        unset($claims['exp']);
        $token = $this->encodeToken($claims);

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('missing_exp', $e->getCategory());
        }
    }

    public function test_expired_exp_is_denied(): void
    {
        $claims = $this->validClaims(['exp' => time() - 3600]);
        $token = $this->encodeToken($claims);

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function test_missing_iat_is_denied(): void
    {
        $claims = $this->validClaims();
        unset($claims['iat']);
        $token = $this->encodeToken($claims);

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('missing_iat', $e->getCategory());
        }
    }

    public function test_future_iat_is_denied(): void
    {
        $claims = $this->validClaims(['iat' => time() + 3600]);
        $token = $this->encodeToken($claims);

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('future_iat', $e->getCategory());
        }
    }

    public function test_future_nbf_is_denied(): void
    {
        $claims = $this->validClaims(['nbf' => time() + 3600]);
        $token = $this->encodeToken($claims);

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function test_nonce_correct_is_accepted(): void
    {
        $token = $this->encodeToken($this->validClaims(['nonce' => 'correct-nonce']));
        $identity = $this->validator->validate($token, 'correct-nonce');
        $this->assertInstanceOf(ValidatedOidcIdentity::class, $identity);
    }

    public function test_nonce_wrong_is_denied(): void
    {
        $token = $this->encodeToken($this->validClaims(['nonce' => 'wrong-nonce']));

        try {
            $this->validator->validate($token, 'expected-nonce');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('invalid_nonce', $e->getCategory());
        }
    }

    public function test_nonce_missing_is_denied(): void
    {
        $claims = $this->validClaims();
        unset($claims['nonce']);
        $token = $this->encodeToken($claims);

        try {
            $this->validator->validate($token, 'expected-nonce');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('invalid_nonce', $e->getCategory());
        }
    }

    public function test_sub_valid_is_accepted(): void
    {
        $token = $this->encodeToken($this->validClaims(['sub' => 'valid-sub-id']));
        $identity = $this->validator->validate($token, 'nonce-abcde');
        $this->assertSame('valid-sub-id', $identity->subject);
    }

    public function test_sub_missing_or_empty_is_denied(): void
    {
        $claims = $this->validClaims(['sub' => '']);
        $token = $this->encodeToken($claims);

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('missing_subject', $e->getCategory());
        }
    }

    public function test_production_user_accepted_in_production(): void
    {
        config(['oidc.expected_identity_class' => 'production_user']);
        $token = $this->encodeToken($this->validClaims(['reltroner_identity_class' => ['production_user']]));
        $identity = $this->validator->validate($token, 'nonce-abcde');
        $this->assertSame('production_user', $identity->environmentIdentityClass);
    }

    public function test_scalar_production_user_is_denied(): void
    {
        $token = $this->encodeToken($this->validClaims(['reltroner_identity_class' => 'production_user']));

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('scalar_identity_class', $e->getCategory());
        }
    }

    public function test_missing_identity_class_is_denied(): void
    {
        $claims = $this->validClaims();
        unset($claims['reltroner_identity_class']);
        $token = $this->encodeToken($claims);

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('missing_identity_class', $e->getCategory());
        }
    }

    public function test_demo_user_denied_in_production(): void
    {
        config(['oidc.expected_identity_class' => 'production_user']);
        $token = $this->encodeToken($this->validClaims(['reltroner_identity_class' => ['demo_user']]));

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('environment_forbidden', $e->getCategory());
        }
    }

    public function test_dual_classification_denied(): void
    {
        config(['oidc.expected_identity_class' => 'production_user']);
        $token = $this->encodeToken($this->validClaims(['reltroner_identity_class' => ['production_user', 'demo_user']]));

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('environment_forbidden', $e->getCategory());
        }
    }

    public function test_service_account_denied(): void
    {
        config(['oidc.expected_identity_class' => 'production_user']);
        $token = $this->encodeToken($this->validClaims(['reltroner_identity_class' => ['service_account']]));

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('environment_forbidden', $e->getCategory());
        }
    }

    public function test_production_user_with_other_denied(): void
    {
        config(['oidc.expected_identity_class' => 'production_user']);
        $token = $this->encodeToken($this->validClaims(['reltroner_identity_class' => ['production_user', 'other']]));

        try {
            $this->validator->validate($token, 'nonce-abcde');
            $this->fail('Expected OidcCallbackException was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('environment_forbidden', $e->getCategory());
        }
    }

    public function test_validated_oidc_identity_carries_only_authorized_fields(): void
    {
        $token = $this->encodeToken($this->validClaims([
            'preferred_username' => 'alice',
            'name' => 'Alice Smith',
            'custom_attr' => 'secret_value',
        ]));

        $identity = $this->validator->validate($token, 'nonce-abcde');

        $ref = new ReflectionClass($identity);
        $propertyNames = array_map(fn($p) => $p->getName(), $ref->getProperties());

        $this->assertEqualsCanonicalizing(
            ['issuer', 'subject', 'environmentIdentityClass', 'email'],
            $propertyNames
        );
        $this->assertFalse(property_exists($identity, 'rawClaims'));
        $this->assertFalse(property_exists($identity, 'token'));
        $this->assertFalse(property_exists($identity, 'preferred_username'));
    }
}
