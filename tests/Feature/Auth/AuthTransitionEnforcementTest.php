<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Identity\Models\ExternalIdentity;
use App\Modules\Identity\Oidc\OidcSessionBinding;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthTransitionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_ISSUER = 'https://auth.reltroner.com/realms/reltroner';

    private const FAKE_SUBJECT = 'enforcement-subject-101';

    protected function tearDown(): void
    {
        config(['app.env' => 'testing']);
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    private function setProductionMode(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);

        config(['app.env' => 'production']);
        $this->app['env'] = 'production';
    }

    /**
     * Create an approved user + ExternalIdentity pair.
     *
     * @return array{0: User, 1: ExternalIdentity}
     */
    private function createApprovedIdentity(
        string $issuer = self::FAKE_ISSUER,
        string $subject = self::FAKE_SUBJECT,
        string $email = 'enforcement-user@reltroner.com'
    ): array {
        $user = User::factory()->create([
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make('original-password'),
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
     * Helper to prepare session data for a valid bound session.
     *
     * @return array<string, mixed>
     */
    private function createBoundSessionData(User $user, ExternalIdentity $identity, string $csrfToken = 'test-token'): array
    {
        $sessionGuardName = 'login_web_'.sha1(SessionGuard::class);
        $fingerprint = hash('sha256', $identity->issuer."\0".$identity->subject);

        return [
            $sessionGuardName => $user->id,
            '_token' => $csrfToken,
            OidcSessionBinding::SESSION_KEY => [
                'external_identity_id' => (int) $identity->id,
                'user_id' => (int) $user->id,
                'trust_key_fingerprint' => $fingerprint,
            ],
        ];
    }

    /**
     * Production: POST /login returns 404 and leaves caller guest.
     */
    public function test_production_post_login_returns_404_and_leaves_caller_guest(): void
    {
        $this->setProductionMode();

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
     * Production: GET /register returns 404.
     */
    public function test_production_get_register_returns_404(): void
    {
        $this->setProductionMode();

        $response = $this->get('/register');

        $response->assertNotFound();
    }

    /**
     * Production: POST /register returns 404, creates no User, and leaves caller guest.
     */
    public function test_production_post_register_returns_404_creates_no_user_and_leaves_guest(): void
    {
        $this->setProductionMode();

        $response = $this->post('/register', [
            'name' => 'Prod User',
            'email' => 'prod@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
        $this->assertDatabaseMissing('users', [
            'email' => 'prod@example.com',
        ]);
    }

    /**
     * Production: forgot-password routes return 404.
     */
    public function test_production_forgot_password_routes_return_404(): void
    {
        $this->setProductionMode();

        $this->get('/forgot-password')->assertNotFound();

        $response = $this->post('/forgot-password', [
            'email' => 'nobody@example.com',
        ]);

        $response->assertNotFound();
    }

    /**
     * Production: reset-password routes return 404.
     */
    public function test_production_reset_password_routes_return_404(): void
    {
        $this->setProductionMode();

        $this->get('/reset-password/sample-token')->assertNotFound();

        $response = $this->post('/reset-password', [
            'token' => 'sample-token',
            'email' => 'nobody@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertNotFound();
    }

    /**
     * Production: confirm-password routes return 404 for valid OIDC-bound session.
     */
    public function test_production_confirm_password_routes_return_404(): void
    {
        $this->setProductionMode();
        [$user, $identity] = $this->createApprovedIdentity();
        $sessionData = $this->createBoundSessionData($user, $identity);

        $this->withSession($sessionData)->get('/confirm-password')->assertNotFound();

        $response = $this->withSession($sessionData)->post('/confirm-password', [
            'password' => 'original-password',
        ]);

        $response->assertNotFound();
    }

    /**
     * Production: password update (PUT /password) returns 404 for valid OIDC-bound session.
     */
    public function test_production_password_update_returns_404(): void
    {
        $this->setProductionMode();
        [$user, $identity] = $this->createApprovedIdentity();
        $sessionData = $this->createBoundSessionData($user, $identity);

        $response = $this->withSession($sessionData)->put('/password', [
            'current_password' => 'original-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertNotFound();
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    /**
     * Production: profile deletion (DELETE /profile) returns 404 for valid OIDC-bound session.
     */
    public function test_production_profile_deletion_returns_404(): void
    {
        $this->setProductionMode();
        [$user, $identity] = $this->createApprovedIdentity();
        $sessionData = $this->createBoundSessionData($user, $identity);

        $response = $this->withSession($sessionData)->delete('/profile', [
            'password' => 'original-password',
        ]);

        $response->assertNotFound();
        $this->assertNotNull(User::find($user->id));
    }

    /**
     * Production: GET /login remains 200 for SSO presentation.
     */
    public function test_production_get_login_remains_200(): void
    {
        $this->setProductionMode();

        $response = $this->get('/login');

        $response->assertOk();
    }

    /**
     * Non-production: POST /login succeeds and authenticates existing user.
     */
    public function test_non_production_post_login_succeeds_and_authenticates_user(): void
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
     * Non-production: GET and POST /register succeed.
     */
    public function test_non_production_registration_succeeds(): void
    {
        $this->get('/register')->assertOk();

        $response = $this->post('/register', [
            'name' => 'Registered User',
            'email' => 'newuser@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
        ]);
    }

    /**
     * Non-production: password reset routes are fully accessible.
     */
    public function test_non_production_password_reset_routes_are_accessible(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->get('/forgot-password')->assertOk();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->get('/reset-password/'.$notification->token)->assertOk();

            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

            $response->assertSessionHasNoErrors();
            $response->assertRedirect(route('login'));

            return true;
        });

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    /**
     * Non-production: password confirmation and update are accessible.
     */
    public function test_non_production_password_confirmation_and_update_accessible(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $this->actingAs($user)->get('/confirm-password')->assertOk();

        $confirmResponse = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'old-password',
        ]);
        $confirmResponse->assertRedirect();
        $confirmResponse->assertSessionHasNoErrors();

        $updateResponse = $this->actingAs($user)->put('/password', [
            'current_password' => 'old-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);
        $updateResponse->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }

    /**
     * Non-production: profile deletion is accessible.
     */
    public function test_non_production_profile_deletion_accessible(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $response = $this->actingAs($user)->delete('/profile', [
            'password' => 'password',
        ]);

        $response->assertRedirect('/');
        $this->assertNull(User::find($user->id));
    }

    /**
     * OIDC redirect route remains available in both environments.
     */
    public function test_oidc_redirect_route_remains_available_in_both_environments(): void
    {
        config([
            'oidc.issuer' => 'https://auth.reltroner-test.com/realms/reltroner',
            'oidc.client_id' => 'hrm-web',
            'oidc.client_secret' => 'super-secret-client-secret-value-xyz',
            'oidc.redirect_uri' => 'https://hrm.reltroner-test.com/auth/keycloak/callback',
            'oidc.scopes' => 'openid profile email',
            'oidc.transaction_ttl' => 300,
            'oidc.pkce_method' => 'S256',
        ]);

        // Non-production
        $responseNonProd = $this->get('/auth/keycloak/redirect');
        $responseNonProd->assertStatus(302);
        $this->assertStringContainsString('openid-connect/auth', (string) $responseNonProd->headers->get('Location'));

        // Production
        $this->setProductionMode();
        $responseProd = $this->get('/auth/keycloak/redirect');
        $responseProd->assertStatus(302);
        $this->assertStringContainsString('openid-connect/auth', (string) $responseProd->headers->get('Location'));
    }

    /**
     * OIDC callback registration remains unchanged.
     */
    public function test_oidc_callback_registration_remains_unchanged(): void
    {
        $route = Route::getRoutes()->getByName('oidc.callback');

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame('auth/keycloak/callback', $route->uri());
    }
}
