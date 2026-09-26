<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\RequireLocalPasswordManagementEnabled;
use App\Http\Middleware\RequireProfileDeletionEnabled;
use App\Http\Middleware\ValidateOidcSessionBinding;
use App\Models\User;
use App\Modules\Identity\Auth\AuthTransitionPolicy;
use App\Modules\Identity\Models\ExternalIdentity;
use App\Modules\Identity\Oidc\OidcSessionBinding;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionCutoverTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_ISSUER = 'https://auth.reltroner.com/realms/reltroner';

    private const FAKE_SUBJECT = 'cutover-test-subject-101';

    private const FAKE_EMAIL = 'cutover-test@reltroner.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'oidc.issuer' => self::FAKE_ISSUER,
            'oidc.client_id' => 'hrm-web',
            'oidc.client_secret' => 'super-secret-client-secret-value-xyz',
            'oidc.redirect_uri' => 'https://hrm.reltroner.com/auth/keycloak/callback',
            'oidc.post_logout_redirect_uri' => 'https://hrm.reltroner.com/',
            'oidc.scopes' => 'openid profile email',
            'oidc.transaction_ttl' => 300,
            'oidc.pkce_method' => 'S256',
        ]);
    }

    protected function tearDown(): void
    {
        config(['app.env' => 'testing']);
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    private function setProductionMode(): void
    {
        config([
            'app.env' => 'production',
            'auth_transition.legacy_login_enabled' => true,
            'auth_transition.legacy_registration_enabled' => true,
        ]);
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
        string $email = self::FAKE_EMAIL
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
     * Create valid OidcSessionBinding array.
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
     * Helper to prepare session data for a valid bound session.
     *
     * @return array<string, mixed>
     */
    private function createBoundSessionData(User $user, ExternalIdentity $identity, string $csrfToken = 'test-token'): array
    {
        $sessionGuardName = 'login_web_'.sha1(SessionGuard::class);

        return [
            $sessionGuardName => $user->id,
            '_token' => $csrfToken,
            OidcSessionBinding::SESSION_KEY => $this->createBindingData($user, $identity),
        ];
    }

    /**
     * 1. Production with legacy-login flag true rejects valid local credentials with 404.
     */
    public function test_production_with_legacy_login_flag_true_rejects_valid_local_credentials(): void
    {
        $this->setProductionMode();

        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $csrfToken = 'csrf-token-abc';
        $response = $this->withSession(['_token' => $csrfToken])
            ->post('/login', [
                '_token' => $csrfToken,
                'email' => $user->email,
                'password' => 'password',
            ]);

        $response->assertNotFound();
        $this->assertGuest();
    }

    /**
     * 2. Production registration and all four forgot/reset routes remain denied (404).
     */
    public function test_production_registration_and_all_four_forgot_reset_routes_remain_denied(): void
    {
        $this->setProductionMode();
        $csrfToken = 'csrf-token-123';

        $this->get('/register')->assertNotFound();

        $this->withSession(['_token' => $csrfToken])
            ->post('/register', [
                '_token' => $csrfToken,
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])->assertNotFound();

        $this->get('/forgot-password')->assertNotFound();

        $this->withSession(['_token' => $csrfToken])
            ->post('/forgot-password', [
                '_token' => $csrfToken,
                'email' => 'test@example.com',
            ])->assertNotFound();

        $this->get('/reset-password/sample-token')->assertNotFound();

        $this->withSession(['_token' => $csrfToken])
            ->post('/reset-password', [
                '_token' => $csrfToken,
                'token' => 'sample-token',
                'email' => 'test@example.com',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])->assertNotFound();
    }

    /**
     * 3. A valid OIDC-bound production session cannot confirm password, update password, or delete profile.
     */
    public function test_valid_oidc_bound_production_session_cannot_confirm_update_password_or_delete_profile(): void
    {
        $this->setProductionMode();
        [$user, $identity] = $this->createApprovedIdentity();
        $csrfToken = 'valid-csrf-token';
        $sessionData = $this->createBoundSessionData($user, $identity, $csrfToken);

        // GET /confirm-password -> 404
        $this->withSession($sessionData)
            ->get('/confirm-password')
            ->assertNotFound();

        // POST /confirm-password -> 404
        $this->withSession($sessionData)
            ->post('/confirm-password', [
                '_token' => $csrfToken,
                'password' => 'original-password',
            ])->assertNotFound();

        // PUT /password -> 404
        $this->withSession($sessionData)
            ->put('/password', [
                '_token' => $csrfToken,
                'current_password' => 'original-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])->assertNotFound();

        // DELETE /profile -> 404
        $this->withSession($sessionData)
            ->delete('/profile', [
                '_token' => $csrfToken,
                'password' => 'original-password',
            ])->assertNotFound();
    }

    /**
     * 4. Password hash, User, and ExternalIdentity remain unchanged after denied operations.
     */
    public function test_password_hash_user_and_external_identity_remain_unchanged_after_denied_operations(): void
    {
        $this->setProductionMode();
        [$user, $identity] = $this->createApprovedIdentity();
        $originalHash = $user->password;
        $originalLinkedAt = $identity->linked_at;
        $csrfToken = 'valid-csrf-token';
        $sessionData = $this->createBoundSessionData($user, $identity, $csrfToken);

        $this->withSession($sessionData)->post('/confirm-password', [
            '_token' => $csrfToken,
            'password' => 'original-password',
        ])->assertNotFound();

        $this->withSession($sessionData)->put('/password', [
            '_token' => $csrfToken,
            'current_password' => 'original-password',
            'password' => 'new-password-attempt',
            'password_confirmation' => 'new-password-attempt',
        ])->assertNotFound();

        $this->withSession($sessionData)->delete('/profile', [
            '_token' => $csrfToken,
            'password' => 'original-password',
        ])->assertNotFound();

        $freshUser = User::find($user->id);
        $this->assertNotNull($freshUser, 'User must not be deleted.');
        $this->assertSame($originalHash, $freshUser->password, 'Password hash must remain unmodified.');

        $freshIdentity = ExternalIdentity::find($identity->id);
        $this->assertNotNull($freshIdentity, 'ExternalIdentity must not be deleted or unlinked.');
        $this->assertSame($user->id, $freshIdentity->user_id);
        $this->assertSame(self::FAKE_ISSUER, $freshIdentity->issuer);
        $this->assertSame(self::FAKE_SUBJECT, $freshIdentity->subject);
        $this->assertSame($originalLinkedAt->timestamp, $freshIdentity->linked_at->timestamp);
    }

    /**
     * 5. An authorized valid OIDC session can still view and edit profile information in production.
     */
    public function test_authorized_valid_oidc_session_can_still_view_and_edit_profile_information(): void
    {
        $this->setProductionMode();
        [$user, $identity] = $this->createApprovedIdentity();
        $csrfToken = 'valid-csrf-token';
        $sessionData = $this->createBoundSessionData($user, $identity, $csrfToken);

        // View profile
        $viewResponse = $this->withSession($sessionData)->get('/profile');
        $viewResponse->assertOk();
        $viewResponse->assertSee($user->email);

        // Edit profile
        $updateResponse = $this->withSession($sessionData)->patch('/profile', [
            '_token' => $csrfToken,
            'name' => 'Updated OIDC Name',
            'email' => $user->email,
        ]);
        $updateResponse->assertSessionHasNoErrors();
        $updateResponse->assertRedirect('/profile');

        $this->assertSame('Updated OIDC Name', $user->fresh()->name);
    }

    /**
     * 6. Production login and profile pages contain no corresponding local credential or account-deletion controls.
     */
    public function test_production_login_and_profile_pages_contain_no_local_credential_or_account_deletion_controls(): void
    {
        $this->setProductionMode();
        [$user, $identity] = $this->createApprovedIdentity();
        $sessionData = $this->createBoundSessionData($user, $identity);

        // GET /login page
        $loginResponse = $this->get('/login');
        $loginResponse->assertOk();
        $loginResponse->assertSee('Continue with Keycloak SSO');
        $loginResponse->assertDontSee('name="email"', false);
        $loginResponse->assertDontSee('name="password"', false);
        $loginResponse->assertDontSee('Forgot your password?');
        $loginResponse->assertDontSee('or continue with local account');
        $loginResponse->assertDontSee('Demo accounts:');
        $loginResponse->assertDontSee('admin@example.com');
        $loginResponse->assertDontSee('developer@example.com');
        $loginResponse->assertDontSee('(password: password)');

        // GET /profile page
        $profileResponse = $this->withSession($sessionData)->get('/profile');
        $profileResponse->assertOk();
        $profileResponse->assertSee('Profile Information');
        $profileResponse->assertDontSee('Update Password');
        $profileResponse->assertDontSee('Delete Account');
        $profileResponse->assertDontSee('name="current_password"', false);
        $profileResponse->assertDontSee('value="delete"', false);
    }

    /**
     * 7. A real HTTP-kernel request using a persisted unbound authenticated session is rejected in production.
     */
    public function test_real_http_kernel_unbound_authenticated_session_is_rejected_in_production(): void
    {
        $this->setProductionMode();
        $user = User::factory()->create();

        $sessionGuardName = 'login_web_'.sha1(SessionGuard::class);
        $sessionData = [
            $sessionGuardName => $user->id,
        ];

        Auth::forgetGuards();

        $response = $this->withSession($sessionData)->get('/profile');
        $response->assertRedirect(route('login'));

        $this->assertFalse(Auth::guard('web')->check());
        $this->assertNull(Auth::guard('web')->user());
        $this->assertFalse(session()->has($sessionGuardName));
    }

    /**
     * 8. A valid legacy recaller cookie without OIDC binding cannot preserve access in production.
     */
    public function test_valid_legacy_recaller_cookie_without_oidc_binding_cannot_preserve_access_in_production(): void
    {
        $this->setProductionMode();

        $rememberToken = Str::random(60);
        $user = User::factory()->create([
            'remember_token' => $rememberToken,
            'password' => Hash::make('password'),
        ]);

        $recallerName = Auth::guard('web')->getRecallerName();
        $recallerValue = $user->id.'|'.$rememberToken.'|'.$user->password;

        Auth::forgetGuards();

        // First request with recaller cookie in production: must be revoked and redirected to login
        $response = $this->withCookie($recallerName, $recallerValue)->get('/profile');
        $response->assertRedirect(route('login'));

        // Guard must be unauthenticated
        $this->assertFalse(Auth::guard('web')->check());

        // Normal Laravel logout cycled the remember_token
        $freshToken = $user->fresh()->remember_token;
        $this->assertNotSame($rememberToken, $freshToken);

        // Subsequent request using old cookie value fails to authenticate
        Auth::forgetGuards();
        $subsequentResponse = $this->withCookie($recallerName, $recallerValue)->get('/profile');
        $subsequentResponse->assertRedirect(route('login'));
        $this->assertFalse(Auth::guard('web')->check());
    }

    /**
     * 9. HTML invalidation redirects to login; JSON invalidation returns 401.
     */
    public function test_html_invalidation_redirects_to_login_and_json_invalidation_returns_401(): void
    {
        $this->setProductionMode();
        $user = User::factory()->create();

        $sessionGuardName = 'login_web_'.sha1(SessionGuard::class);
        $sessionData = [
            $sessionGuardName => $user->id,
        ];

        Auth::forgetGuards();
        $htmlResponse = $this->withSession($sessionData)->get('/profile');
        $htmlResponse->assertRedirect(route('login'));

        Auth::forgetGuards();
        $jsonResponse = $this->withSession($sessionData)->getJson('/profile');
        $jsonResponse->assertStatus(401);
        $jsonResponse->assertJson(['message' => 'Unauthenticated.']);
    }

    /**
     * 10. Invalidating a session does not create a login redirect loop.
     */
    public function test_invalidating_session_does_not_create_login_redirect_loop(): void
    {
        $this->setProductionMode();
        $user = User::factory()->create();

        $sessionGuardName = 'login_web_'.sha1(SessionGuard::class);
        $sessionData = [
            $sessionGuardName => $user->id,
        ];

        Auth::forgetGuards();

        // 1. Initial attempt redirects to login
        $firstResponse = $this->withSession($sessionData)->get('/profile');
        $firstResponse->assertRedirect(route('login'));

        // 2. Follow redirect to login as guest -> returns 200 OK without looping
        $loginResponse = $this->get('/login');
        $loginResponse->assertOk();
        $loginResponse->assertSee('Continue with Keycloak SSO');

        // 3. Fresh guest can initiate OIDC redirect
        $oidcResponse = $this->get('/auth/keycloak/redirect');
        $oidcResponse->assertStatus(302);
        $this->assertStringContainsString('openid-connect/auth', (string) $oidcResponse->headers->get('Location'));
    }

    /**
     * 11. Valid bound sessions continue to work; malformed and tampered bindings fail closed.
     */
    public function test_valid_bound_sessions_work_and_malformed_bindings_fail_closed(): void
    {
        $this->setProductionMode();
        [$user, $identity] = $this->createApprovedIdentity();
        $sessionData = $this->createBoundSessionData($user, $identity);

        // Valid binding works
        $this->withSession($sessionData)->get('/profile')->assertOk();

        // Tampered fingerprint fails closed
        $tamperedData = $sessionData;
        $tamperedData[OidcSessionBinding::SESSION_KEY]['trust_key_fingerprint'] = str_repeat('0', 64);
        Auth::forgetGuards();
        $this->withSession($tamperedData)->get('/profile')->assertRedirect(route('login'));
        $this->assertFalse(Auth::guard('web')->check());

        // Malformed binding fails closed
        $malformedData = $sessionData;
        $malformedData[OidcSessionBinding::SESSION_KEY] = 'not-an-array';
        Auth::forgetGuards();
        $this->withSession($malformedData)->get('/profile')->assertRedirect(route('login'));
        $this->assertFalse(Auth::guard('web')->check());
    }

    /**
     * 12. RP logout regressions still pass in production.
     */
    public function test_rp_logout_regressions_still_pass_in_production(): void
    {
        $this->setProductionMode();
        [$user, $identity] = $this->createApprovedIdentity();
        $csrfToken = 'logout-csrf-token';
        $sessionData = $this->createBoundSessionData($user, $identity, $csrfToken);

        $response = $this->withSession($sessionData)->post('/logout', [
            '_token' => $csrfToken,
        ]);

        $this->assertGuest();
        $response->assertStatus(302);

        $targetUrl = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://auth.reltroner.com/realms/reltroner/protocol/openid-connect/logout', $targetUrl);
        $this->assertStringContainsString('client_id=hrm-web', $targetUrl);
        $this->assertStringContainsString('post_logout_redirect_uri=https%3A%2F%2Fhrm.reltroner.com%2F', $targetUrl);
    }

    /**
     * 13. Nonproduction behavior remains fully compatible.
     */
    public function test_nonproduction_behavior_remains_compatible(): void
    {
        config([
            'app.env' => 'testing',
            'auth_transition.legacy_login_enabled' => true,
        ]);
        $this->app['env'] = 'testing';

        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        // POST /login works outside production
        $loginResponse = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);
        $this->assertAuthenticated();
        $loginResponse->assertRedirect(route('dashboard', absolute: false));

        // GET /confirm-password works
        $this->actingAs($user)->get('/confirm-password')->assertOk();

        // PUT /password works
        $this->actingAs($user)->put('/password', [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));

        // DELETE /profile works
        $this->actingAs($user)->delete('/profile', [
            'password' => 'new-password-123',
        ])->assertRedirect('/');
        $this->assertNull($user->fresh());
    }

    /**
     * 14. Production restrictions apply if either app()->environment('production') or config('app.env') === 'production'.
     */
    public function test_production_restrictions_apply_via_app_env_or_config_app_env(): void
    {
        $policy = app(AuthTransitionPolicy::class);

        // Case A: app['env'] = production, config('app.env') = local
        config([
            'app.env' => 'local',
            'auth_transition.legacy_login_enabled' => true,
            'auth_transition.legacy_registration_enabled' => true,
        ]);
        $this->app['env'] = 'production';

        $this->assertTrue($policy->isProduction());
        $this->assertFalse($policy->legacyLoginEnabled());
        $this->assertFalse($policy->legacyRegistrationEnabled());
        $this->assertFalse($policy->legacyPasswordResetEnabled());
        $this->assertFalse($policy->localPasswordManagementEnabled());
        $this->assertFalse($policy->profileDeletionEnabled());

        // Case B: app['env'] = testing, config('app.env') = production
        config([
            'app.env' => 'production',
            'auth_transition.legacy_login_enabled' => true,
            'auth_transition.legacy_registration_enabled' => true,
        ]);
        $this->app['env'] = 'testing';

        $this->assertTrue($policy->isProduction());
        $this->assertFalse($policy->legacyLoginEnabled());
        $this->assertFalse($policy->legacyRegistrationEnabled());
        $this->assertFalse($policy->legacyPasswordResetEnabled());
        $this->assertFalse($policy->localPasswordManagementEnabled());
        $this->assertFalse($policy->profileDeletionEnabled());

        // Case C: outside production (testing & testing)
        config([
            'app.env' => 'testing',
            'auth_transition.legacy_login_enabled' => true,
            'auth_transition.legacy_registration_enabled' => true,
        ]);
        $this->app['env'] = 'testing';

        $this->assertFalse($policy->isProduction());
        $this->assertTrue($policy->legacyLoginEnabled());
        $this->assertTrue($policy->legacyRegistrationEnabled());
        $this->assertTrue($policy->legacyPasswordResetEnabled());
        $this->assertTrue($policy->localPasswordManagementEnabled());
        $this->assertTrue($policy->profileDeletionEnabled());
    }

    /**
     * 15. Gathered middleware order remains correct across protected routes.
     */
    public function test_gathered_middleware_order_remains_correct_across_protected_routes(): void
    {
        $router = $this->app['router'];

        $routeExpectations = [
            'dashboard' => ['priority_after_start_session' => true],
            'profile.edit' => ['priority_after_start_session' => true],
            'password.confirm' => [
                'priority_after_start_session' => true,
                'contains_policy_middleware' => RequireLocalPasswordManagementEnabled::class,
            ],
            'password.update' => [
                'priority_after_start_session' => true,
                'contains_policy_middleware' => RequireLocalPasswordManagementEnabled::class,
            ],
            'profile.destroy' => [
                'priority_after_start_session' => true,
                'contains_policy_middleware' => RequireProfileDeletionEnabled::class,
            ],
        ];

        foreach ($routeExpectations as $routeName => $checks) {
            $route = $router->getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Route {$routeName} must exist.");

            $gathered = $router->gatherRouteMiddleware($route);
            $gatheredStartSession = array_search(StartSession::class, $gathered, true);
            $gatheredBinding = array_search(ValidateOidcSessionBinding::class, $gathered, true);
            $gatheredCsrf = array_search(PreventRequestForgery::class, $gathered, true);
            $gatheredSubstitute = array_search(SubstituteBindings::class, $gathered, true);

            $this->assertNotFalse($gatheredStartSession, "Gathered middleware for {$routeName} must contain StartSession.");
            $this->assertNotFalse($gatheredBinding, "Gathered middleware for {$routeName} must contain ValidateOidcSessionBinding.");
            $this->assertNotFalse($gatheredCsrf, "Gathered middleware for {$routeName} must contain PreventRequestForgery.");
            $this->assertNotFalse($gatheredSubstitute, "Gathered middleware for {$routeName} must contain SubstituteBindings.");

            $this->assertSame(
                $gatheredStartSession + 1,
                $gatheredBinding,
                "Gathered middleware for {$routeName} must place ValidateOidcSessionBinding immediately after StartSession."
            );
            $this->assertLessThan(
                $gatheredCsrf,
                $gatheredBinding,
                "Gathered middleware for {$routeName} must place ValidateOidcSessionBinding before PreventRequestForgery."
            );
            $this->assertLessThan(
                $gatheredSubstitute,
                $gatheredBinding,
                "Gathered middleware for {$routeName} must place ValidateOidcSessionBinding before SubstituteBindings."
            );

            if (isset($checks['contains_policy_middleware'])) {
                $expectedMiddleware = $checks['contains_policy_middleware'];
                $this->assertContains(
                    $expectedMiddleware,
                    $gathered,
                    "Gathered middleware for {$routeName} must contain {$expectedMiddleware}."
                );

                $policyIndex = array_search($expectedMiddleware, $gathered, true);
                $this->assertGreaterThan(
                    $gatheredBinding,
                    $policyIndex,
                    "Policy middleware for {$routeName} must run after ValidateOidcSessionBinding."
                );
            }
        }
    }
}
