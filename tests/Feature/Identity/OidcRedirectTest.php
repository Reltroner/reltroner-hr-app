<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Oidc\OidcTransactionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OidcRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'oidc.issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'oidc.client_id' => 'hrm-web',
            'oidc.client_secret' => 'super-secret-client-secret-value-xyz',
            'oidc.redirect_uri' => 'https://hrm.reltroner.com/auth/keycloak/callback',
            'oidc.scopes' => 'openid profile email',
            'oidc.transaction_ttl' => 300,
            'oidc.pkce_method' => 'S256',
        ]);
    }

    /**
     * Helper to parse query parameters from redirect target URL.
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
     * 1. Prove guest GET /auth/keycloak/redirect returns external HTTP 302.
     */
    public function test_guest_redirect_returns_external_302(): void
    {
        $response = $this->get('/auth/keycloak/redirect');

        $response->assertStatus(302);
        $targetUrl = $response->headers->get('Location');
        $this->assertNotNull($targetUrl);
        $this->assertStringStartsWith('https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/auth', $targetUrl);
    }

    /**
     * 2. Prove redirect host/path equals {issuer}/protocol/openid-connect/auth.
     */
    public function test_redirect_host_and_path_matches_keycloak_endpoint(): void
    {
        $response = $this->get('/auth/keycloak/redirect');

        $targetUrl = (string) $response->headers->get('Location');
        $parsedUrl = parse_url($targetUrl);

        $this->assertSame('https', $parsedUrl['scheme'] ?? null);
        $this->assertSame('auth.reltroner.com', $parsedUrl['host'] ?? null);
        $this->assertSame('/realms/reltroner/protocol/openid-connect/auth', $parsedUrl['path'] ?? null);
    }

    /**
     * 3. Prove query contains exactly correct parameters.
     */
    public function test_query_contains_all_exact_required_parameters(): void
    {
        $response = $this->get('/auth/keycloak/redirect');

        $targetUrl = (string) $response->headers->get('Location');
        $params = $this->parseRedirectQuery($targetUrl);

        $this->assertSame('code', $params['response_type'] ?? null);
        $this->assertSame('hrm-web', $params['client_id'] ?? null);
        $this->assertSame('https://hrm.reltroner.com/auth/keycloak/callback', $params['redirect_uri'] ?? null);
        $this->assertSame('openid profile email', $params['scope'] ?? null);
        $this->assertSame('S256', $params['code_challenge_method'] ?? null);

        $this->assertNotEmpty($params['state'] ?? null);
        $this->assertNotEmpty($params['nonce'] ?? null);
        $this->assertNotEmpty($params['code_challenge'] ?? null);
    }

    /**
     * 4. Prove state in redirect maps to a stored transaction.
     */
    public function test_state_in_redirect_maps_to_stored_transaction(): void
    {
        $response = $this->get('/auth/keycloak/redirect');

        $targetUrl = (string) $response->headers->get('Location');
        $params = $this->parseRedirectQuery($targetUrl);
        $state = $params['state'];

        /** @var OidcTransactionStore $store */
        $store = app(OidcTransactionStore::class);
        $transaction = $store->find($state);

        $this->assertNotNull($transaction);
        $this->assertSame($state, $transaction->state);
    }

    /**
     * 5. Prove stored transaction nonce equals redirect nonce.
     */
    public function test_stored_transaction_nonce_equals_redirect_nonce(): void
    {
        $response = $this->get('/auth/keycloak/redirect');

        $targetUrl = (string) $response->headers->get('Location');
        $params = $this->parseRedirectQuery($targetUrl);

        /** @var OidcTransactionStore $store */
        $store = app(OidcTransactionStore::class);
        $transaction = $store->find($params['state']);

        $this->assertNotNull($transaction);
        $this->assertSame($params['nonce'], $transaction->nonce);
    }

    /**
     * 6. Prove stored transaction codeChallenge equals redirect code_challenge.
     */
    public function test_stored_transaction_code_challenge_equals_redirect_code_challenge(): void
    {
        $response = $this->get('/auth/keycloak/redirect');

        $targetUrl = (string) $response->headers->get('Location');
        $params = $this->parseRedirectQuery($targetUrl);

        /** @var OidcTransactionStore $store */
        $store = app(OidcTransactionStore::class);
        $transaction = $store->find($params['state']);

        $this->assertNotNull($transaction);
        $this->assertSame($params['code_challenge'], $transaction->codeChallenge);
    }

    /**
     * 7. Prove codeVerifier exists server-side.
     */
    public function test_code_verifier_exists_server_side(): void
    {
        $response = $this->get('/auth/keycloak/redirect');

        $targetUrl = (string) $response->headers->get('Location');
        $params = $this->parseRedirectQuery($targetUrl);

        /** @var OidcTransactionStore $store */
        $store = app(OidcTransactionStore::class);
        $transaction = $store->find($params['state']);

        $this->assertNotNull($transaction);
        $this->assertNotEmpty($transaction->codeVerifier);
        $this->assertSame(43, strlen($transaction->codeVerifier));
    }

    /**
     * 8. Prove code_verifier parameter is absent from redirect query.
     */
    public function test_code_verifier_parameter_is_absent_from_redirect_query(): void
    {
        $response = $this->get('/auth/keycloak/redirect');

        $targetUrl = (string) $response->headers->get('Location');
        $params = $this->parseRedirectQuery($targetUrl);

        $this->assertArrayNotHasKey('code_verifier', $params);
        $this->assertStringNotContainsString('code_verifier', $targetUrl);
    }

    /**
     * 9. Prove configured client_secret value is absent from redirect query and complete URL.
     */
    public function test_configured_client_secret_is_absent_from_redirect_url(): void
    {
        $secret = 'super-secret-client-secret-value-xyz';
        $response = $this->get('/auth/keycloak/redirect');

        $targetUrl = (string) $response->headers->get('Location');
        $params = $this->parseRedirectQuery($targetUrl);

        $this->assertArrayNotHasKey('client_secret', $params);
        $this->assertStringNotContainsString($secret, $targetUrl);
    }

    /**
     * 10. Prove two redirect requests in the same session generate distinct states.
     */
    public function test_two_redirect_requests_generate_distinct_states(): void
    {
        $responseA = $this->get('/auth/keycloak/redirect');
        $paramsA = $this->parseRedirectQuery((string) $responseA->headers->get('Location'));

        $responseB = $this->get('/auth/keycloak/redirect');
        $paramsB = $this->parseRedirectQuery((string) $responseB->headers->get('Location'));

        $this->assertNotSame($paramsA['state'], $paramsB['state']);
        $this->assertNotSame($paramsA['nonce'], $paramsB['nonce']);
    }

    /**
     * 11. Prove both transactions coexist after the second redirect.
     */
    public function test_both_transactions_coexist_after_second_redirect(): void
    {
        $responseA = $this->get('/auth/keycloak/redirect');
        $paramsA = $this->parseRedirectQuery((string) $responseA->headers->get('Location'));

        $responseB = $this->get('/auth/keycloak/redirect');
        $paramsB = $this->parseRedirectQuery((string) $responseB->headers->get('Location'));

        /** @var OidcTransactionStore $store */
        $store = app(OidcTransactionStore::class);

        $txA = $store->find($paramsA['state']);
        $txB = $store->find($paramsB['state']);

        $this->assertNotNull($txA);
        $this->assertNotNull($txB);
        $this->assertSame($paramsA['state'], $txA->state);
        $this->assertSame($paramsB['state'], $txB->state);
    }

    /**
     * 12. Prove missing issuer fails closed without storing transaction.
     */
    public function test_missing_issuer_fails_closed_and_creates_no_transaction(): void
    {
        config(['oidc.issuer' => null]);

        $response = $this->get('/auth/keycloak/redirect');

        $response->assertStatus(500);
        $this->assertEmpty(session()->get(OidcTransactionStore::SESSION_KEY, []));
    }

    /**
     * 13. Prove missing client_id fails closed without storing transaction.
     */
    public function test_missing_client_id_fails_closed_and_creates_no_transaction(): void
    {
        config(['oidc.client_id' => '']);

        $response = $this->get('/auth/keycloak/redirect');

        $response->assertStatus(500);
        $this->assertEmpty(session()->get(OidcTransactionStore::SESSION_KEY, []));
    }

    /**
     * 14. Prove missing redirect_uri fails closed without storing transaction.
     */
    public function test_missing_redirect_uri_fails_closed_and_creates_no_transaction(): void
    {
        config(['oidc.redirect_uri' => null]);

        $response = $this->get('/auth/keycloak/redirect');

        $response->assertStatus(500);
        $this->assertEmpty(session()->get(OidcTransactionStore::SESSION_KEY, []));
    }

    /**
     * 15. Prove empty scopes fail closed without storing transaction.
     */
    public function test_empty_scopes_fail_closed_and_creates_no_transaction(): void
    {
        config(['oidc.scopes' => '   ']);

        $response = $this->get('/auth/keycloak/redirect');

        $response->assertStatus(500);
        $this->assertEmpty(session()->get(OidcTransactionStore::SESSION_KEY, []));
    }

    /**
     * 16. Prove scopes without exact "openid" token fail closed without storing transaction.
     */
    public function test_scopes_without_openid_token_fail_closed(): void
    {
        config(['oidc.scopes' => 'profile email notopenid']);

        $response = $this->get('/auth/keycloak/redirect');

        $response->assertStatus(500);
        $this->assertEmpty(session()->get(OidcTransactionStore::SESSION_KEY, []));
    }

    /**
     * 17. Prove pkce method other than S256 fails closed without storing transaction.
     */
    public function test_pkce_method_other_than_s256_fails_closed(): void
    {
        config(['oidc.pkce_method' => 'plain']);

        $response = $this->get('/auth/keycloak/redirect');

        $response->assertStatus(500);
        $this->assertEmpty(session()->get(OidcTransactionStore::SESSION_KEY, []));
    }

    /**
     * 18. Prove invalid issuer URL fails closed without storing transaction.
     */
    public function test_invalid_issuer_url_fails_closed(): void
    {
        config(['oidc.issuer' => 'not-a-valid-url']);

        $response = $this->get('/auth/keycloak/redirect');

        $response->assertStatus(500);
        $this->assertEmpty(session()->get(OidcTransactionStore::SESSION_KEY, []));
    }

    /**
     * 19. Prove invalid redirect URI fails closed without storing transaction.
     */
    public function test_invalid_redirect_uri_fails_closed(): void
    {
        config(['oidc.redirect_uri' => 'ftp://invalid-scheme-destination']);

        $response = $this->get('/auth/keycloak/redirect');

        $response->assertStatus(500);
        $this->assertEmpty(session()->get(OidcTransactionStore::SESSION_KEY, []));
    }

    /**
     * 20. Prove legacy GET /login remains available.
     */
    public function test_legacy_get_login_remains_available(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    /**
     * 21. Prove route name oidc.redirect exists.
     */
    public function test_route_name_oidc_redirect_exists(): void
    {
        $this->assertTrue(Route::has('oidc.redirect'));
        $this->assertSame(url('/auth/keycloak/redirect'), route('oidc.redirect'));
    }

    public function test_oidc_callback_registration_does_not_replace_redirect_route(): void
    {
        $this->assertTrue(Route::has('oidc.redirect'));
        $this->assertTrue(Route::has('oidc.callback'));

        $this->assertNotSame(
            route('oidc.redirect'),
            route('oidc.callback')
        );
    }

    /**
     * 23. Prove authenticated Laravel user cannot start guest OIDC redirect flow
     *     and is handled by existing guest middleware behavior.
     */
    public function test_authenticated_user_cannot_start_guest_oidc_redirect(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/auth/keycloak/redirect');

        // Handled by guest middleware (RedirectIfAuthenticated) - redirects away from guest route
        $response->assertStatus(302);
        $targetUrl = (string) $response->headers->get('Location');

        // Must NOT redirect to Keycloak external authorization endpoint
        $this->assertStringNotContainsString('auth.reltroner.com', $targetUrl);
        $this->assertStringNotContainsString('/protocol/openid-connect/auth', $targetUrl);

        // No OIDC transaction must be created in session
        $this->assertEmpty(session()->get(OidcTransactionStore::SESSION_KEY, []));
    }
}
