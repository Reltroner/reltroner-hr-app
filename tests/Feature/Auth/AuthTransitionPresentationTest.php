<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTransitionPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config(['app.env' => 'testing']);
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    private function setProductionMode(): void
    {
        config(['app.env' => 'production']);
        $this->app['env'] = 'production';
    }

    /**
     * Non-production: GET /login returns 200 with both SSO and local login form.
     */
    public function test_non_production_login_page_renders_sso_and_local_form(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Continue with Keycloak SSO');
        $response->assertSee(route('oidc.redirect'), escape: false);
        $response->assertSee('or continue with local account');
        $response->assertSee('name="email"', escape: false);
        $response->assertSee('name="password"', escape: false);
        $response->assertSee('action="'.route('login').'"', escape: false);
        $response->assertSee('Log in');
        $response->assertSee('Remember me');
        $response->assertSee('Forgot your password?');
        $response->assertSee(route('password.request'), escape: false);
        $response->assertSee('Demo accounts:');
    }

    /**
     * Non-production: SSO CTA appears before local credentials in display order.
     */
    public function test_non_production_sso_cta_appears_before_local_credentials(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSeeInOrder([
            'Continue with Keycloak SSO',
            'or continue with local account',
            'name="email"',
            'name="password"',
            'Log in',
        ], escape: false);
    }

    /**
     * Production: GET /login returns 200 with SSO CTA only.
     */
    public function test_production_login_page_renders_sso_cta_only(): void
    {
        $this->setProductionMode();

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Continue with Keycloak SSO');
        $response->assertSee(route('oidc.redirect'), escape: false);

        // Local form and controls must be strictly absent
        $response->assertDontSee('or continue with local account');
        $response->assertDontSee('name="email"', escape: false);
        $response->assertDontSee('name="password"', escape: false);
        $response->assertDontSee('action="'.route('login').'"', escape: false);
        $response->assertDontSee('Log in');
        $response->assertDontSee('name="remember"', escape: false);
        $response->assertDontSee('Forgot your password?');
        $response->assertDontSee(route('password.request'), escape: false);
        $response->assertDontSee('Demo accounts:');
        $response->assertDontSee('admin@example.com');
        $response->assertDontSee('developer@example.com');
    }

    /**
     * Production: POST /login remains rejected with 404 regardless of presentation.
     */
    public function test_production_post_login_remains_rejected_with_404(): void
    {
        $this->setProductionMode();

        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $csrfToken = 'csrf-test-token';
        $response = $this->withSession(['_token' => $csrfToken])->post('/login', [
            '_token' => $csrfToken,
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
    }

    /**
     * OIDC redirect route remains unchanged and available in both environments.
     */
    public function test_oidc_redirect_route_remains_unchanged_and_available(): void
    {
        config([
            'oidc.issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'oidc.client_id' => 'hrm-web',
            'oidc.client_secret' => 'super-secret-client-secret-value-xyz',
            'oidc.redirect_uri' => 'https://hrm.reltroner.com/auth/keycloak/callback',
            'oidc.scopes' => 'openid profile email',
            'oidc.transaction_ttl' => 300,
            'oidc.pkce_method' => 'S256',
        ]);

        // Non-production
        $response = $this->get('/auth/keycloak/redirect');
        $response->assertStatus(302);
        $this->assertStringContainsString('openid-connect/auth', (string) $response->headers->get('Location'));

        // Production
        $this->setProductionMode();
        $responseProd = $this->get('/auth/keycloak/redirect');
        $responseProd->assertStatus(302);
        $this->assertStringContainsString('openid-connect/auth', (string) $responseProd->headers->get('Location'));
    }
}
