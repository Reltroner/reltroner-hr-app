<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTransitionPresentationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. GET /login returns 200 with default configuration.
     */
    public function test_get_login_returns_200_by_default(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
    }

    /**
     * 2. Default login page contains the SSO CTA.
     */
    public function test_default_login_page_contains_sso_cta(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Continue with Keycloak SSO');
    }

    /**
     * 3. SSO CTA points to route('oidc.redirect').
     */
    public function test_sso_cta_points_to_oidc_redirect_route(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee(route('oidc.redirect'), escape: false);
    }

    /**
     * 4. SSO CTA appears when legacy login is true.
     */
    public function test_sso_cta_appears_when_legacy_login_is_true(): void
    {
        config(['auth_transition.legacy_login_enabled' => true]);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Continue with Keycloak SSO');
        $response->assertSee(route('oidc.redirect'), escape: false);
    }

    /**
     * 5. SSO CTA appears when legacy login is false.
     */
    public function test_sso_cta_appears_when_legacy_login_is_false(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Continue with Keycloak SSO');
        $response->assertSee(route('oidc.redirect'), escape: false);
    }

    /**
     * 6. When legacy login is true: email input is rendered.
     */
    public function test_email_input_is_rendered_when_legacy_login_is_true(): void
    {
        config(['auth_transition.legacy_login_enabled' => true]);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('name="email"', escape: false);
    }

    /**
     * 7. When legacy login is true: password input is rendered.
     */
    public function test_password_input_is_rendered_when_legacy_login_is_true(): void
    {
        config(['auth_transition.legacy_login_enabled' => true]);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('name="password"', escape: false);
    }

    /**
     * 8. When legacy login is true: POST login form is rendered.
     */
    public function test_post_login_form_is_rendered_when_legacy_login_is_true(): void
    {
        config(['auth_transition.legacy_login_enabled' => true]);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('action="' . route('login') . '"', escape: false);
        $response->assertSee('Log in');
        $response->assertSee('Remember me');
    }

    /**
     * 9. When legacy login is true: forgot-password link is rendered.
     */
    public function test_forgot_password_link_is_rendered_when_legacy_login_is_true(): void
    {
        config(['auth_transition.legacy_login_enabled' => true]);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee(route('password.request'), escape: false);
        $response->assertSee('Forgot your password?');
    }

    /**
     * 10. When legacy login is false: email input is absent.
     */
    public function test_email_input_is_absent_when_legacy_login_is_false(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertDontSee('name="email"', escape: false);
    }

    /**
     * 11. When legacy login is false: password input is absent.
     */
    public function test_password_input_is_absent_when_legacy_login_is_false(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertDontSee('name="password"', escape: false);
    }

    /**
     * 12. When legacy login is false: POST login form is absent.
     */
    public function test_post_login_form_is_absent_when_legacy_login_is_false(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertDontSee('action="' . route('login') . '"', escape: false);
        $response->assertDontSee('name="remember"', escape: false);
        $response->assertDontSee('Log in');
    }

    /**
     * 13. When legacy login is false: forgot-password link is absent.
     */
    public function test_forgot_password_link_is_absent_when_legacy_login_is_false(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertDontSee(route('password.request'), escape: false);
        $response->assertDontSee('Forgot your password?');
    }

    /**
     * 14. GET /login remains 200 when legacy login is false.
     */
    public function test_get_login_remains_200_when_legacy_login_is_false(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    /**
     * 15. Changing presentation does not alter POST /login enforcement: legacy login false still returns 404.
     */
    public function test_presentation_change_does_not_alter_post_login_enforcement(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
    }

    /**
     * 16. OIDC redirect route remains unchanged/available.
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

        $response = $this->get('/auth/keycloak/redirect');
        $response->assertStatus(302);
        $this->assertStringContainsString('openid-connect/auth', $response->headers->get('Location'));
    }

    /**
     * 17. SSO presentation occurs before local credential presentation when legacy login is enabled.
     */
    public function test_sso_cta_appears_before_local_credentials_when_legacy_login_is_enabled(): void
    {
        config(['auth_transition.legacy_login_enabled' => true]);

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
}
