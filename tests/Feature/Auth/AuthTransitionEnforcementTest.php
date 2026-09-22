<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthTransitionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. defaults true: POST /login continues normal credential behavior.
     */
    public function test_post_login_succeeds_by_default(): void
    {
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
     * 2. legacy login false: POST /login returns 404 and guest remains guest.
     */
    public function test_post_login_returns_404_and_keeps_guest_when_legacy_login_disabled(): void
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
     * 3. legacy login false: GET /login remains 200.
     */
    public function test_get_login_remains_200_when_legacy_login_disabled(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $response = $this->get('/login');

        $response->assertOk();
    }

    /**
     * 4. legacy registration false: GET /register returns 404.
     */
    public function test_get_register_returns_404_when_legacy_registration_disabled(): void
    {
        config(['auth_transition.legacy_registration_enabled' => false]);

        $response = $this->get('/register');

        $response->assertNotFound();
    }

    /**
     * 5. legacy registration false: POST /register returns 404 and no User is created.
     */
    public function test_post_register_returns_404_and_creates_no_user_when_legacy_registration_disabled(): void
    {
        config(['auth_transition.legacy_registration_enabled' => false]);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
        $this->assertDatabaseMissing('users', [
            'email' => 'test@example.com',
        ]);
    }

    /**
     * 6. registration false + login true: POST /login remains available.
     */
    public function test_post_login_remains_available_when_registration_disabled_and_login_enabled(): void
    {
        config([
            'auth_transition.legacy_registration_enabled' => false,
            'auth_transition.legacy_login_enabled' => true,
        ]);

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
     * 7. login false + registration true: registration remains available.
     */
    public function test_registration_remains_available_when_login_disabled_and_registration_enabled(): void
    {
        config([
            'auth_transition.legacy_login_enabled' => false,
            'auth_transition.legacy_registration_enabled' => true,
        ]);

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
     * 8. legacy login false: GET /forgot-password returns 404.
     */
    public function test_get_forgot_password_returns_404_when_legacy_login_disabled(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $response = $this->get('/forgot-password');

        $response->assertNotFound();
    }

    /**
     * 9. legacy login false: POST /forgot-password returns 404.
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
     * 10. legacy login false: GET /reset-password/{token} returns 404.
     */
    public function test_get_reset_password_with_token_returns_404_when_legacy_login_disabled(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $response = $this->get('/reset-password/sample-token');

        $response->assertNotFound();
    }

    /**
     * 11. legacy login false: POST /reset-password returns 404.
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
     * 12. legacy login true: existing password reset routes continue to be accessible.
     */
    public function test_password_reset_routes_accessible_when_legacy_login_enabled(): void
    {
        config(['auth_transition.legacy_login_enabled' => true]);

        $this->get('/forgot-password')->assertOk();
        $this->get('/reset-password/sample-token')->assertOk();
    }

    /**
     * 13. OIDC redirect route remains available regardless of legacy login flag.
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
     * 14. OIDC callback registration remains unchanged.
     */
    public function test_oidc_callback_registration_remains_unchanged(): void
    {
        $route = Route::getRoutes()->getByName('oidc.callback');

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame('auth/keycloak/callback', $route->uri());
    }

    /**
     * 15. Authenticated password confirmation/update routes remain unaffected by legacy login flag.
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
