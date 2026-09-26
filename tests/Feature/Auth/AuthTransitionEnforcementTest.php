<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthTransitionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Default fail closed: POST /login returns 404 and leaves caller guest.
     */
    public function test_post_login_returns_404_by_default(): void
    {
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
     * 2. Default fail closed: GET /register returns 404.
     */
    public function test_get_register_returns_404_by_default(): void
    {
        $response = $this->get('/register');

        $response->assertNotFound();
    }

    /**
     * 3. Default fail closed: POST /register returns 404, creates no User, leaves caller guest.
     */
    public function test_post_register_returns_404_and_creates_no_user_by_default(): void
    {
        $response = $this->post('/register', [
            'name' => 'Default Test User',
            'email' => 'default@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
        $this->assertDatabaseMissing('users', [
            'email' => 'default@example.com',
        ]);
    }

    /**
     * 4. Explicit login=true: POST /login succeeds and authenticates existing user.
     */
    public function test_post_login_succeeds_when_legacy_login_enabled(): void
    {
        config(['auth_transition.legacy_login_enabled' => true]);

        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    /**
     * 5. legacy login false: GET /login remains 200 with SSO presentation.
     */
    public function test_get_login_remains_200_when_legacy_login_disabled(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $response = $this->get('/login');

        $response->assertOk();
    }

    /**
     * 6. Production + registration=true: GET /register returns 404.
     */
    public function test_production_get_register_returns_404_even_if_flag_is_true(): void
    {
        config([
            'app.env' => 'production',
            'auth_transition.legacy_registration_enabled' => true,
        ]);
        $this->app['env'] = 'production';

        $response = $this->get('/register');

        $response->assertNotFound();
    }

    /**
     * 7. Production + registration=true: POST /register returns 404.
     */
    public function test_production_post_register_returns_404_even_if_flag_is_true(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        config([
            'app.env' => 'production',
            'auth_transition.legacy_registration_enabled' => true,
        ]);
        $this->app['env'] = 'production';

        $response = $this->post('/register', [
            'name' => 'Prod User',
            'email' => 'prod@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertNotFound();
    }

    /**
     * 8. Production + registration=true: POST /register creates no User.
     */
    public function test_production_post_register_creates_no_user(): void
    {
        config([
            'app.env' => 'production',
            'auth_transition.legacy_registration_enabled' => true,
        ]);
        $this->app['env'] = 'production';

        $this->post('/register', [
            'name' => 'Prod User',
            'email' => 'prod-nouser@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'prod-nouser@example.com',
        ]);
    }

    /**
     * 9. Production + registration=true: POST /register leaves caller guest.
     */
    public function test_production_post_register_leaves_caller_guest(): void
    {
        config([
            'app.env' => 'production',
            'auth_transition.legacy_registration_enabled' => true,
        ]);
        $this->app['env'] = 'production';

        $this->post('/register', [
            'name' => 'Prod User',
            'email' => 'prod-guest@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
    }

    /**
     * 10. Production + login=true: rejects credential login for an existing User with 404 (Phase 11 cutover).
     */
    public function test_production_with_legacy_login_flag_true_rejects_valid_local_credentials(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        config([
            'app.env' => 'production',
            'auth_transition.legacy_login_enabled' => true,
            'auth_transition.legacy_registration_enabled' => false,
        ]);
        $this->app['env'] = 'production';

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
     * 11. Outside production: login false + registration true allows registration.
     */
    public function test_outside_production_registration_remains_available_when_enabled(): void
    {
        config([
            'app.env' => 'testing',
            'auth_transition.legacy_login_enabled' => false,
            'auth_transition.legacy_registration_enabled' => true,
        ]);
        $this->app['env'] = 'testing';

        $getResponse = $this->get('/register');
        $getResponse->assertOk();

        $postResponse = $this->post('/register', [
            'name' => 'Registered User',
            'email' => 'newuser@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $postResponse->assertRedirect(route('dashboard', absolute: false));
        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
        ]);
    }

    /**
     * 12. legacy login false: GET /forgot-password returns 404.
     */
    public function test_get_forgot_password_returns_404_when_legacy_login_disabled(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $response = $this->get('/forgot-password');

        $response->assertNotFound();
    }

    /**
     * 13. legacy login false: POST /forgot-password returns 404.
     */
    public function test_post_forgot_password_returns_404_when_legacy_login_disabled(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $response = $this->post('/forgot-password', [
            'email' => 'nobody@example.com',
        ]);

        $response->assertNotFound();
    }

    /**
     * 14. legacy login false: GET /reset-password/{token} returns 404.
     */
    public function test_get_reset_password_with_token_returns_404_when_legacy_login_disabled(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $response = $this->get('/reset-password/sample-token');

        $response->assertNotFound();
    }

    /**
     * 15. legacy login false: POST /reset-password returns 404.
     */
    public function test_post_reset_password_returns_404_when_legacy_login_disabled(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $response = $this->post('/reset-password', [
            'token' => 'sample-token',
            'email' => 'nobody@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertNotFound();
    }

    /**
     * 16. legacy login true: existing password reset routes continue to be accessible.
     */
    public function test_password_reset_routes_accessible_when_legacy_login_enabled(): void
    {
        config(['auth_transition.legacy_login_enabled' => true]);

        $this->get('/forgot-password')->assertOk();
        $this->get('/reset-password/sample-token')->assertOk();
    }

    /**
     * 17. OIDC redirect route remains available regardless of legacy login flag.
     */
    public function test_oidc_redirect_route_remains_available_regardless_of_legacy_login_flag(): void
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

        config(['auth_transition.legacy_login_enabled' => false]);
        $responseWhenFalse = $this->get('/auth/keycloak/redirect');
        $responseWhenFalse->assertStatus(302);
        $this->assertStringContainsString('openid-connect/auth', $responseWhenFalse->headers->get('Location'));

        config(['auth_transition.legacy_login_enabled' => true]);
        $responseWhenTrue = $this->get('/auth/keycloak/redirect');
        $responseWhenTrue->assertStatus(302);
        $this->assertStringContainsString('openid-connect/auth', $responseWhenTrue->headers->get('Location'));
    }

    /**
     * 18. OIDC callback registration remains unchanged.
     */
    public function test_oidc_callback_registration_remains_unchanged(): void
    {
        $route = Route::getRoutes()->getByName('oidc.callback');

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame('auth/keycloak/callback', $route->uri());
    }

    /**
     * 19. Authenticated password confirmation/update routes remain unaffected by legacy login flag.
     */
    public function test_authenticated_password_confirmation_and_update_unaffected_by_legacy_login_flag(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        // GET confirm-password
        $this->actingAs($user)->get('/confirm-password')->assertOk();

        // POST confirm-password
        $confirmResponse = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'old-password',
        ]);
        $confirmResponse->assertRedirect();
        $confirmResponse->assertSessionHasNoErrors();

        // PUT password update
        $updateResponse = $this->actingAs($user)->put('/password', [
            'current_password' => 'old-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);
        $updateResponse->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }
}
