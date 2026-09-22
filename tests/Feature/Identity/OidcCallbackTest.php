<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Oidc\OidcTransaction;
use App\Modules\Identity\Oidc\OidcTransactionStore;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OidcCallbackTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey;
    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'oidc.issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'oidc.client_id' => 'hrm-web',
            'oidc.client_secret' => 'super-secret-client-secret-value-xyz',
            'oidc.redirect_uri' => 'https://hrm.reltroner.com/auth/keycloak/callback',
            'oidc.scopes' => 'openid profile email',
            'oidc.environment' => 'production',
            'oidc.expected_identity_class' => 'production_user',
            'oidc.transaction_ttl' => 300,
            'oidc.clock_skew' => 60,
            'oidc.jwks_cache_ttl' => 3600,
            'oidc.http_timeout' => 5,
        ]);

        Cache::flush();

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
    }

    private function createStoredTransaction(string $state = 'valid-state', string $nonce = 'valid-nonce', ?int $createdAt = null): OidcTransaction
    {
        return new OidcTransaction(
            state: $state,
            nonce: $nonce,
            codeVerifier: 'code-verifier-1234567890123456789012345678901234567890123',
            codeChallenge: 'code-challenge-s256-test',
            createdAt: $createdAt ?? time(),
        );
    }

    private function buildIdToken(array $overrides = []): string
    {
        $now = time();
        $claims = array_merge([
            'iss' => 'https://auth.reltroner.com/realms/reltroner',
            'aud' => 'hrm-web',
            'azp' => 'hrm-web',
            'sub' => 'sub-user-999',
            'nonce' => 'valid-nonce',
            'exp' => $now + 300,
            'iat' => $now,
            'email' => 'user@reltroner.com',
            'reltroner_identity_class' => ['production_user'],
        ], $overrides);

        return JWT::encode($claims, $this->privateKey, 'RS256', 'test-kid-1');
    }

    public function test_missing_state_returns_400(): void
    {
        $response = $this->get('/auth/keycloak/callback');
        $response->assertStatus(400);
    }

    public function test_empty_state_returns_400(): void
    {
        $response = $this->get('/auth/keycloak/callback?state=');
        $response->assertStatus(400);
    }

    public function test_unknown_state_returns_400(): void
    {
        $response = $this->get('/auth/keycloak/callback?state=unknown-state-xyz');
        $response->assertStatus(400);
    }

    public function test_expired_state_returns_400_and_is_pruned(): void
    {
        $tx = $this->createStoredTransaction('expired-state', 'nonce-1', time() - 400);

        $response = $this->withSession([
            OidcTransactionStore::SESSION_KEY => [
                'expired-state' => $tx->toArray(),
            ],
        ])->get('/auth/keycloak/callback?state=expired-state');

        $response->assertStatus(400);
        $stored = session(OidcTransactionStore::SESSION_KEY, []);
        $this->assertArrayNotHasKey('expired-state', $stored);
    }

    public function test_replayed_state_fails_on_second_attempt(): void
    {
        $tx = $this->createStoredTransaction('replay-state', 'valid-nonce');
        $idToken = $this->buildIdToken(['nonce' => 'valid-nonce']);

        Http::fake([
            'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/certs' => Http::response(['keys' => [$this->jwk]], 200),
            'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/token' => Http::response(['id_token' => $idToken], 200),
        ]);

        $firstResponse = $this->withSession([
            OidcTransactionStore::SESSION_KEY => [
                'replay-state' => $tx->toArray(),
            ],
        ])->get('/auth/keycloak/callback?state=replay-state&code=test-code');

        $firstResponse->assertStatus(503);

        // Replay attempt with same state
        $secondResponse = $this->get('/auth/keycloak/callback?state=replay-state&code=test-code');
        $secondResponse->assertStatus(400);
    }

    public function test_provider_error_consumes_valid_state_and_returns_400(): void
    {
        $tx = $this->createStoredTransaction('error-state');

        $response = $this->withSession([
            OidcTransactionStore::SESSION_KEY => [
                'error-state' => $tx->toArray(),
            ],
        ])->get('/auth/keycloak/callback?state=error-state&error=access_denied&error_description=Sensitive+internal+details');

        $response->assertStatus(400);
        $this->assertStringNotContainsString('Sensitive internal details', $response->getContent());
        $this->assertStringNotContainsString('access_denied', $response->getContent());

        // Prove state was consumed
        $stored = session(OidcTransactionStore::SESSION_KEY, []);
        $this->assertArrayNotHasKey('error-state', $stored);

        // Second attempt fails
        $retryResponse = $this->get('/auth/keycloak/callback?state=error-state&code=some-code');
        $retryResponse->assertStatus(400);
    }

    public function test_missing_code_consumes_valid_state_and_returns_400(): void
    {
        $tx = $this->createStoredTransaction('no-code-state');

        $response = $this->withSession([
            OidcTransactionStore::SESSION_KEY => [
                'no-code-state' => $tx->toArray(),
            ],
        ])->get('/auth/keycloak/callback?state=no-code-state');

        $response->assertStatus(400);

        // Prove state was consumed
        $stored = session(OidcTransactionStore::SESSION_KEY, []);
        $this->assertArrayNotHasKey('no-code-state', $stored);
    }

    public function test_token_failure_leaves_state_consumed(): void
    {
        $tx = $this->createStoredTransaction('token-fail-state');

        Http::fake([
            'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/token' => Http::response(['error' => 'server_error'], 500),
        ]);

        $response = $this->withSession([
            OidcTransactionStore::SESSION_KEY => [
                'token-fail-state' => $tx->toArray(),
            ],
        ])->get('/auth/keycloak/callback?state=token-fail-state&code=test-code');

        $response->assertStatus(502);

        // Prove state remains consumed
        $stored = session(OidcTransactionStore::SESSION_KEY, []);
        $this->assertArrayNotHasKey('token-fail-state', $stored);
    }

    public function test_successful_crypto_flow_still_assert_guest(): void
    {
        $tx = $this->createStoredTransaction('success-state', 'valid-nonce');
        $idToken = $this->buildIdToken(['nonce' => 'valid-nonce']);

        Http::fake([
            'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/certs' => Http::response(['keys' => [$this->jwk]], 200),
            'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/token' => Http::response(['id_token' => $idToken], 200),
        ]);

        $response = $this->withSession([
            OidcTransactionStore::SESSION_KEY => [
                'success-state' => $tx->toArray(),
            ],
        ])->get('/auth/keycloak/callback?state=success-state&code=test-code');

        $this->assertGuest();
        $response->assertStatus(503);
    }

    public function test_successful_crypto_flow_returns_503(): void
    {
        $tx = $this->createStoredTransaction('sso-state', 'valid-nonce');
        $idToken = $this->buildIdToken(['nonce' => 'valid-nonce']);

        Http::fake([
            'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/certs' => Http::response(['keys' => [$this->jwk]], 200),
            'https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/token' => Http::response(['id_token' => $idToken], 200),
        ]);

        $response = $this->withSession([
            OidcTransactionStore::SESSION_KEY => [
                'sso-state' => $tx->toArray(),
            ],
        ])->get('/auth/keycloak/callback?state=sso-state&code=test-code');

        $response->assertStatus(503);
        $response->assertSee('SSO login is not yet available.');
    }

    public function test_authenticated_existing_session_receives_callback_without_guest_middleware_bypass(): void
    {
        $user = User::factory()->create();

        // If guest middleware were on this route, actingAs would cause a 302 redirect.
        // Instead, the callback route executes normally and returns 400 for missing state.
        $response = $this->actingAs($user)->get('/auth/keycloak/callback');

        $response->assertStatus(400);
        $this->assertNotEquals(302, $response->getStatusCode());
    }

    public function test_callback_route_has_lock_seconds_30(): void
    {
        $route = Route::getRoutes()->getByName('oidc.callback');
        $this->assertNotNull($route);
        $this->assertSame(30, $route->locksFor());
    }

    public function test_callback_route_has_wait_seconds_10(): void
    {
        $route = Route::getRoutes()->getByName('oidc.callback');
        $this->assertNotNull($route);
        $this->assertSame(10, $route->waitsFor());
    }

    public function test_redirect_route_still_exists(): void
    {
        $this->assertTrue(Route::has('oidc.redirect'));

        $response = $this->get(route('oidc.redirect'));
        $response->assertStatus(302);
    }

    public function test_legacy_login_route_still_works(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
    }
}
