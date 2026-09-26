<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Models\ExternalIdentity;
use App\Modules\Identity\Oidc\OidcLogoutContext;
use App\Modules\Identity\Oidc\OidcLogoutService;
use App\Modules\Identity\Oidc\OidcSessionBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class OidcLogoutTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_ISSUER = 'https://auth.reltroner.com/realms/reltroner';

    private const FAKE_SUBJECT = 'test-binding-subject-99';

    private const FAKE_EMAIL = 'binding-test@reltroner.com';

    private const FAKE_ID_TOKEN_HINT = 'header.payload.signature';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'oidc.issuer' => self::FAKE_ISSUER,
            'oidc.client_id' => 'hrm-web',
            'oidc.post_logout_redirect_uri' => 'https://hrm.reltroner.com/',
        ]);
    }

    /**
     * Create a user with an approved linked ExternalIdentity.
     *
     * @return array{0: User, 1: ExternalIdentity}
     */
    private function createApprovedIdentity(
        string $issuer = self::FAKE_ISSUER,
        string $subject = self::FAKE_SUBJECT,
        string $email = self::FAKE_EMAIL
    ): array {
        $user = User::factory()->create([
            'email' => $email,
            'email_verified_at' => now(),
        ]);

        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => $issuer,
            'subject' => $subject,
            'email_at_link' => $email,
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        return [$user, $identity];
    }

    /**
     * Create the frozen OidcSessionBinding data array.
     *
     * @return array<string, int|string>
     */
    private function createBindingData(User $user, ExternalIdentity $identity): array
    {
        $fingerprint = hash('sha256', $identity->issuer."\0".$identity->subject);

        return [
            'external_identity_id' => (int) $identity->id,
            'user_id' => (int) $user->id,
            'trust_key_fingerprint' => $fingerprint,
        ];
    }

    /**
     * Create encrypted logout context matching the production session shape.
     *
     * @return array<string, string>
     */
    private function createLogoutContextData(
        string $idTokenHint = self::FAKE_ID_TOKEN_HINT
    ): array {
        return [
            OidcLogoutContext::ENCRYPTED_ID_TOKEN_HINT_KEY
                => Crypt::encryptString($idTokenHint),
        ];
    }

    /**
     * Helper to parse query parameters from a redirect URL.
     *
     * @return array<string, string>
     */
    protected function parseRedirectQuery(string $url): array
    {
        $queryString = parse_url($url, PHP_URL_QUERY);
        if (empty($queryString)) {
            return [];
        }

        parse_str($queryString, $params);

        return $params;
    }

    /**
     * A. OIDC-bound POST logout redirects to Keycloak end-session endpoint with correct parameters.
     */
    public function test_oidc_bound_post_logout_redirects_to_keycloak_end_session_endpoint(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $bindingData = $this->createBindingData($user, $identity);

        $response = $this->actingAs($user)
            ->withSession([
                OidcSessionBinding::SESSION_KEY => $bindingData,
                OidcLogoutContext::SESSION_KEY => $this->createLogoutContextData(),
            ])
            ->post('/logout');

        $this->assertGuest();
        $response->assertStatus(302);

        $targetUrl = (string) $response->headers->get('Location');
        $this->assertNotEmpty($targetUrl);

        $parsed = parse_url($targetUrl);
        $this->assertSame('https', $parsed['scheme'] ?? null);
        $this->assertSame('auth.reltroner.com', $parsed['host'] ?? null);
        $this->assertSame('/realms/reltroner/protocol/openid-connect/logout', $parsed['path'] ?? null);

        $params = $this->parseRedirectQuery($targetUrl);
        $this->assertSame('hrm-web', $params['client_id'] ?? null);
        $this->assertSame(self::FAKE_ID_TOKEN_HINT, $params['id_token_hint'] ?? null);
        $this->assertSame('https://hrm.reltroner.com/', $params['post_logout_redirect_uri'] ?? null);
    }

    /**
     * B. Exact safe query contract: only client_id, id_token_hint, and
     * post_logout_redirect_uri are emitted for a fresh OIDC session.
     */
    public function test_logout_redirect_query_contains_only_required_hint_and_no_other_tokens_or_secrets(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $bindingData = $this->createBindingData($user, $identity);

        $response = $this->actingAs($user)
            ->withSession([
                OidcSessionBinding::SESSION_KEY => $bindingData,
                OidcLogoutContext::SESSION_KEY => $this->createLogoutContextData(),
            ])
            ->post('/logout');

        $targetUrl = (string) $response->headers->get('Location');
        $params = $this->parseRedirectQuery($targetUrl);

        $keys = array_keys($params);
        sort($keys);

        $this->assertSame([
            'client_id',
            'id_token_hint',
            'post_logout_redirect_uri',
        ], $keys);

        $this->assertSame(self::FAKE_ID_TOKEN_HINT, $params['id_token_hint']);

        $forbiddenKeys = [
            'client_secret',
            'id_token',
            'access_token',
            'refresh_token',
            'token',
            'code',
            'subject',
            'email',
        ];

        foreach ($forbiddenKeys as $forbiddenKey) {
            $this->assertArrayNotHasKey(
                $forbiddenKey,
                $params,
                "Query parameter '{$forbiddenKey}' must not be present in logout URL."
            );
        }
    }

    /**
     * C. Local session is invalidated and OIDC binding is removed upon logout.
     */
    public function test_local_session_is_invalidated_and_binding_removed_after_oidc_logout(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $bindingData = $this->createBindingData($user, $identity);

        $response = $this->actingAs($user)
            ->withSession([
                OidcSessionBinding::SESSION_KEY => $bindingData,
                OidcLogoutContext::SESSION_KEY => $this->createLogoutContextData(),
            ])
            ->post('/logout');

        $this->assertGuest();
        $response->assertSessionMissing(OidcSessionBinding::SESSION_KEY);
        $response->assertSessionMissing(OidcLogoutContext::SESSION_KEY);
    }

    /**
     * D. Legacy session with no OIDC binding remains local-only.
     */
    public function test_legacy_session_logout_remains_local_only(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');

        $targetUrl = (string) $response->headers->get('Location');
        $this->assertStringNotContainsString('protocol/openid-connect/logout', $targetUrl);
        $this->assertStringNotContainsString('auth.reltroner.com', $targetUrl);
    }

    /**
     * D (hardening). Legacy logout succeeds even if OIDC config is completely missing.
     */
    public function test_legacy_session_logout_works_when_oidc_config_is_completely_missing(): void
    {
        config([
            'oidc.issuer' => null,
            'oidc.client_id' => null,
            'oidc.post_logout_redirect_uri' => null,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    /**
     * E. Missing issuer fails closed with 500 AFTER local logout.
     */
    public function test_missing_issuer_fails_closed_after_local_logout(): void
    {
        config(['oidc.issuer' => null]);

        [$user, $identity] = $this->createApprovedIdentity();
        $bindingData = $this->createBindingData($user, $identity);

        $response = $this->actingAs($user)
            ->withSession([OidcSessionBinding::SESSION_KEY => $bindingData])
            ->post('/logout');

        $response->assertStatus(500);
        $this->assertGuest();
        $response->assertSessionMissing(OidcSessionBinding::SESSION_KEY);
    }

    /**
     * E. Missing client_id fails closed with 500 AFTER local logout.
     */
    public function test_missing_client_id_fails_closed_after_local_logout(): void
    {
        config(['oidc.client_id' => '']);

        [$user, $identity] = $this->createApprovedIdentity();
        $bindingData = $this->createBindingData($user, $identity);

        $response = $this->actingAs($user)
            ->withSession([OidcSessionBinding::SESSION_KEY => $bindingData])
            ->post('/logout');

        $response->assertStatus(500);
        $this->assertGuest();
        $response->assertSessionMissing(OidcSessionBinding::SESSION_KEY);
    }

    /**
     * E. Missing post_logout_redirect_uri fails closed with 500 AFTER local logout.
     */
    public function test_missing_post_logout_redirect_uri_fails_closed_after_local_logout(): void
    {
        config(['oidc.post_logout_redirect_uri' => null]);

        [$user, $identity] = $this->createApprovedIdentity();
        $bindingData = $this->createBindingData($user, $identity);

        $response = $this->actingAs($user)
            ->withSession([OidcSessionBinding::SESSION_KEY => $bindingData])
            ->post('/logout');

        $response->assertStatus(500);
        $this->assertGuest();
        $response->assertSessionMissing(OidcSessionBinding::SESSION_KEY);
    }

    /**
     * E. Invalid issuer URL fails closed with 500 AFTER local logout without exposing value.
     */
    public function test_invalid_issuer_url_fails_closed_after_local_logout(): void
    {
        $invalidUrl = 'not-a-valid-url-scheme';
        config(['oidc.issuer' => $invalidUrl]);

        [$user, $identity] = $this->createApprovedIdentity();
        $bindingData = $this->createBindingData($user, $identity);

        $response = $this->actingAs($user)
            ->withSession([OidcSessionBinding::SESSION_KEY => $bindingData])
            ->post('/logout');

        $response->assertStatus(500);
        $this->assertGuest();
        $response->assertSessionMissing(OidcSessionBinding::SESSION_KEY);
        $response->assertDontSee($invalidUrl);
    }

    /**
     * E. Invalid post_logout_redirect_uri fails closed with 500 AFTER local logout without exposing value.
     */
    public function test_invalid_post_logout_redirect_uri_fails_closed_after_local_logout(): void
    {
        $invalidUri = 'ftp://invalid-scheme-destination';
        config(['oidc.post_logout_redirect_uri' => $invalidUri]);

        [$user, $identity] = $this->createApprovedIdentity();
        $bindingData = $this->createBindingData($user, $identity);

        $response = $this->actingAs($user)
            ->withSession([OidcSessionBinding::SESSION_KEY => $bindingData])
            ->post('/logout');

        $response->assertStatus(500);
        $this->assertGuest();
        $response->assertSessionMissing(OidcSessionBinding::SESSION_KEY);
        $response->assertDontSee($invalidUri);
    }

    /**
     * F. Logout does not create, delete, or alter User or ExternalIdentity.
     */
    public function test_oidc_logout_does_not_mutate_business_or_identity_persistence(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $bindingData = $this->createBindingData($user, $identity);

        $userCountBefore = User::count();
        $identityCountBefore = ExternalIdentity::count();
        $linkedAtBefore = $identity->linked_at;
        $lastLoginAtBefore = $identity->last_login_at;

        $response = $this->actingAs($user)
            ->withSession([OidcSessionBinding::SESSION_KEY => $bindingData])
            ->post('/logout');

        $response->assertStatus(302);
        $this->assertSame($userCountBefore, User::count());
        $this->assertSame($identityCountBefore, ExternalIdentity::count());

        $freshIdentity = $identity->fresh();
        $this->assertNotNull($freshIdentity);
        $this->assertSame($user->id, $freshIdentity->user_id);
        $this->assertSame(self::FAKE_ISSUER, $freshIdentity->issuer);
        $this->assertSame(self::FAKE_SUBJECT, $freshIdentity->subject);
        $this->assertSame($linkedAtBefore->timestamp, $freshIdentity->linked_at->timestamp);
        $this->assertSame($lastLoginAtBefore, $freshIdentity->last_login_at);
    }

    /**
     * G. OIDC-bound session calling legacy GET /logout route also redirects to Keycloak.
     */
    public function test_oidc_bound_get_logout_route_also_redirects_to_keycloak(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $bindingData = $this->createBindingData($user, $identity);

        $response = $this->actingAs($user)
            ->withSession([
                OidcSessionBinding::SESSION_KEY => $bindingData,
                OidcLogoutContext::SESSION_KEY => $this->createLogoutContextData(),
            ])
            ->get('/logout');

        $this->assertGuest();
        $response->assertStatus(302);

        $targetUrl = (string) $response->headers->get('Location');
        $parsed = parse_url($targetUrl);
        $this->assertSame('/realms/reltroner/protocol/openid-connect/logout', $parsed['path'] ?? null);

        $params = $this->parseRedirectQuery($targetUrl);
        $this->assertSame('hrm-web', $params['client_id'] ?? null);
        $this->assertSame(self::FAKE_ID_TOKEN_HINT, $params['id_token_hint'] ?? null);
        $this->assertSame('https://hrm.reltroner.com/', $params['post_logout_redirect_uri'] ?? null);
    }

    /**
     * Issuer with trailing slash is normalized correctly without double slash.
     */
    public function test_issuer_with_trailing_slash_is_normalized_correctly(): void
    {
        config(['oidc.issuer' => self::FAKE_ISSUER.'/']);

        [$user, $identity] = $this->createApprovedIdentity();
        $bindingData = $this->createBindingData($user, $identity);

        $response = $this->actingAs($user)
            ->withSession([OidcSessionBinding::SESSION_KEY => $bindingData])
            ->post('/logout');

        $targetUrl = (string) $response->headers->get('Location');
        $parsed = parse_url($targetUrl);
        $this->assertSame('/realms/reltroner/protocol/openid-connect/logout', $parsed['path'] ?? null);
        $this->assertStringNotContainsString('//protocol', $targetUrl);
    }

    /**
     * Direct unit invocation of OidcLogoutService builds valid RFC 3986 URL.
     */
    public function test_direct_service_build_logout_url_constructs_valid_rfc3986_url(): void
    {
        $service = app(OidcLogoutService::class);
        $url = $service->buildLogoutUrl(self::FAKE_ID_TOKEN_HINT);

        $this->assertStringStartsWith('https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/logout?', $url);
        $this->assertStringContainsString('client_id=hrm-web', $url);
        $this->assertStringContainsString('id_token_hint=header.payload.signature', $url);
        $this->assertStringContainsString('post_logout_redirect_uri=https%3A%2F%2Fhrm.reltroner.com%2F', $url);
    }

    /**
     * Historical OIDC sessions created before this remediation have no logout
     * context and retain the confirmation-capable fallback.
     */
    public function test_oidc_bound_historical_session_without_logout_context_uses_safe_fallback(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $bindingData = $this->createBindingData($user, $identity);

        $response = $this->actingAs($user)
            ->withSession([OidcSessionBinding::SESSION_KEY => $bindingData])
            ->post('/logout');

        $this->assertGuest();

        $params = $this->parseRedirectQuery(
            (string) $response->headers->get('Location')
        );

        $keys = array_keys($params);
        sort($keys);

        $this->assertSame([
            'client_id',
            'post_logout_redirect_uri',
        ], $keys);
        $this->assertArrayNotHasKey('id_token_hint', $params);
    }

    public function test_corrupt_logout_context_falls_back_after_local_logout(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $bindingData = $this->createBindingData($user, $identity);
        $corruptCiphertext = 'not-a-valid-encrypted-payload';

        $response = $this->actingAs($user)
            ->withSession([
                OidcSessionBinding::SESSION_KEY => $bindingData,
                OidcLogoutContext::SESSION_KEY => [
                    OidcLogoutContext::ENCRYPTED_ID_TOKEN_HINT_KEY
                        => $corruptCiphertext,
                ],
            ])
            ->post('/logout');

        $this->assertGuest();
        $response->assertSessionMissing(OidcLogoutContext::SESSION_KEY);

        $targetUrl = (string) $response->headers->get('Location');
        $params = $this->parseRedirectQuery($targetUrl);

        $this->assertArrayNotHasKey('id_token_hint', $params);
        $this->assertStringNotContainsString($corruptCiphertext, $targetUrl);
    }

    /**
     * Security invariant: OidcSessionBinding structure remains frozen with no token material.
     */
    public function test_oidc_session_binding_frozen_structure_contains_no_token_material(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $bindingData = $this->createBindingData($user, $identity);

        $this->assertSame([
            'external_identity_id',
            'user_id',
            'trust_key_fingerprint',
        ], array_keys($bindingData));

        $this->assertArrayNotHasKey('id_token', $bindingData);
        $this->assertArrayNotHasKey('id_token_hint', $bindingData);
        $this->assertArrayNotHasKey('access_token', $bindingData);
        $this->assertArrayNotHasKey('refresh_token', $bindingData);
    }
}
