<?php

namespace Tests\Feature\Identity;

use App\Http\Middleware\ValidateOidcSessionBinding;
use App\Models\User;
use App\Modules\Identity\Models\ExternalIdentity;
use App\Modules\Identity\Oidc\OidcSessionBinding;
use App\Modules\Identity\Oidc\OidcSessionManager;
use App\Modules\Identity\Oidc\ResolvedOidcIdentity;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OidcSessionBindingTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_ISSUER = 'https://auth.reltroner.com/realms/reltroner';

    private const FAKE_SUBJECT = 'test-binding-subject-99';

    private const FAKE_EMAIL = 'binding-test@reltroner.com';

    private OidcSessionManager $sessionManager;

    private ValidateOidcSessionBinding $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        config(['oidc.issuer' => self::FAKE_ISSUER]);
        $this->sessionManager = new OidcSessionManager;
        $this->middleware = app(ValidateOidcSessionBinding::class);
    }

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

    private function createSessionRequest(array $sessionData = []): Request
    {
        $request = Request::create('/dashboard', 'GET');
        $session = $this->app['session']->driver();
        $session->start();
        $session->flush();

        foreach ($sessionData as $key => $value) {
            $session->put($key, $value);
        }

        $request->setLaravelSession($session);

        return $request;
    }

    /**
     * 1. New guest OIDC authentication stores binding.
     */
    public function test_new_guest_oidc_authentication_stores_binding(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity($user, $identity);

        $this->sessionManager->establish($request, $resolved);

        $this->assertTrue(OidcSessionBinding::has($request));
        $this->assertNotNull(OidcSessionBinding::get($request));
    }

    /**
     * 2. Binding contains external_identity_id.
     */
    public function test_binding_contains_external_identity_id(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity($user, $identity);

        $this->sessionManager->establish($request, $resolved);
        $binding = OidcSessionBinding::get($request);

        $this->assertArrayHasKey('external_identity_id', $binding);
        $this->assertSame((int) $identity->id, $binding['external_identity_id']);
    }

    /**
     * 3. Binding contains user_id.
     */
    public function test_binding_contains_user_id(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity($user, $identity);

        $this->sessionManager->establish($request, $resolved);
        $binding = OidcSessionBinding::get($request);

        $this->assertArrayHasKey('user_id', $binding);
        $this->assertSame((int) $user->id, $binding['user_id']);
    }

    /**
     * 4. Binding contains fingerprint.
     */
    public function test_binding_contains_fingerprint(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity($user, $identity);

        $expectedFingerprint = hash('sha256', self::FAKE_ISSUER."\0".self::FAKE_SUBJECT);

        $this->sessionManager->establish($request, $resolved);
        $binding = OidcSessionBinding::get($request);

        $this->assertArrayHasKey('trust_key_fingerprint', $binding);
        $this->assertSame($expectedFingerprint, $binding['trust_key_fingerprint']);
    }

    /**
     * 5. Binding contains no issuer.
     */
    public function test_binding_contains_no_issuer(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity($user, $identity);

        $this->sessionManager->establish($request, $resolved);
        $binding = OidcSessionBinding::get($request);

        $this->assertArrayNotHasKey('issuer', $binding);
        $this->assertStringNotContainsString(self::FAKE_ISSUER, json_encode($binding));
    }

    /**
     * 6. Binding contains no subject.
     */
    public function test_binding_contains_no_subject(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity($user, $identity);

        $this->sessionManager->establish($request, $resolved);
        $binding = OidcSessionBinding::get($request);

        $this->assertArrayNotHasKey('subject', $binding);
        $this->assertStringNotContainsString(self::FAKE_SUBJECT, json_encode($binding));
    }

    /**
     * 7. Binding contains no email/token/session secret.
     */
    public function test_binding_contains_no_email_tokens_or_secrets(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity($user, $identity);

        $this->sessionManager->establish($request, $resolved);
        $binding = OidcSessionBinding::get($request);

        $forbiddenKeys = [
            'email', 'email_at_link', 'claims', 'tokens', 'token',
            'authorization_code', 'code', 'session_id', 'cookie', 'pkce_verifier', 'secret',
        ];

        foreach ($forbiddenKeys as $key) {
            $this->assertArrayNotHasKey($key, $binding);
        }

        $this->assertStringNotContainsString(self::FAKE_EMAIL, json_encode($binding));
    }

    /**
     * 8. Valid bound session remains authenticated.
     */
    public function test_valid_bound_session_remains_authenticated(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity($user, $identity);

        $this->sessionManager->establish($request, $resolved);

        $calledNext = false;
        $response = $this->middleware->handle($request, function ($req) use (&$calledNext) {
            $calledNext = true;

            return response('dashboard_content');
        });

        $this->assertTrue($calledNext);
        $this->assertSame('dashboard_content', $response->getContent());
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($user->id, Auth::guard('web')->id());
    }

    /**
     * 9. Deleting linked ExternalIdentity causes next request to revoke session.
     */
    public function test_deleting_linked_external_identity_causes_next_request_to_revoke_session(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity($user, $identity);

        $this->sessionManager->establish($request, $resolved);
        $this->assertTrue(Auth::guard('web')->check());

        // Committed unlink deletion
        $identity->delete();

        // Next request
        $response = $this->middleware->handle($request, function () {
            return response('unreachable');
        });

        $this->assertFalse(Auth::guard('web')->check());
        $this->assertTrue($response->isRedirect(route('login')));
    }

    /**
     * 10. Reassigning link user causes revocation.
     */
    public function test_reassigning_link_user_causes_revocation(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $otherUser = User::factory()->create();

        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity($user, $identity);

        $this->sessionManager->establish($request, $resolved);

        // Reassign link in DB to other user
        $identity->user_id = $otherUser->id;
        $identity->save();

        $response = $this->middleware->handle($request, function () {
            return response('unreachable');
        });

        $this->assertFalse(Auth::guard('web')->check());
        $this->assertTrue($response->isRedirect(route('login')));
    }

    /**
     * 11. Changing issuer causes fingerprint mismatch and revocation.
     */
    public function test_changing_issuer_causes_fingerprint_mismatch_and_revocation(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity($user, $identity);

        $this->sessionManager->establish($request, $resolved);

        // Tamper issuer in DB
        $identity->issuer = 'https://tampered-issuer.com';
        $identity->save();

        $response = $this->middleware->handle($request, function () {
            return response('unreachable');
        });

        $this->assertFalse(Auth::guard('web')->check());
        $this->assertTrue($response->isRedirect(route('login')));
    }

    /**
     * 12. Changing subject causes fingerprint mismatch and revocation.
     */
    public function test_changing_subject_causes_fingerprint_mismatch_and_revocation(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity($user, $identity);

        $this->sessionManager->establish($request, $resolved);

        // Tamper subject in DB
        $identity->subject = 'tampered-subject-id';
        $identity->save();

        $response = $this->middleware->handle($request, function () {
            return response('unreachable');
        });

        $this->assertFalse(Auth::guard('web')->check());
        $this->assertTrue($response->isRedirect(route('login')));
    }

    /**
     * 13. Malformed binding causes revocation.
     */
    public function test_malformed_binding_causes_revocation(): void
    {
        $malformedBindings = [
            'not-an-array',
            [],
            ['external_identity_id' => 'not-an-int', 'user_id' => 1, 'trust_key_fingerprint' => str_repeat('a', 64)],
            ['external_identity_id' => -1, 'user_id' => 1, 'trust_key_fingerprint' => str_repeat('a', 64)],
            ['external_identity_id' => 1, 'user_id' => -1, 'trust_key_fingerprint' => str_repeat('a', 64)],
            ['external_identity_id' => 1, 'user_id' => 1, 'trust_key_fingerprint' => 'invalid-fingerprint'],
            ['external_identity_id' => 1, 'user_id' => 1],
        ];

        foreach ($malformedBindings as $badBinding) {
            $user = User::factory()->create();
            Auth::guard('web')->login($user);

            $request = $this->createSessionRequest([
                OidcSessionBinding::SESSION_KEY => $badBinding,
            ]);

            $response = $this->middleware->handle($request, function () {
                return response('unreachable');
            });

            $this->assertFalse(Auth::guard('web')->check());
            $this->assertTrue($response->isRedirect(route('login')));
        }
    }

    /**
     * 14. Legacy credential session with no binding remains unaffected and executes no DB queries.
     */
    public function test_legacy_credential_session_with_no_binding_remains_unaffected(): void
    {
        $user = User::factory()->create();
        Auth::guard('web')->login($user);

        $request = $this->createSessionRequest();
        $this->assertFalse(OidcSessionBinding::has($request));

        DB::enableQueryLog();
        $queriesBefore = count(DB::getQueryLog());

        $calledNext = false;
        $response = $this->middleware->handle($request, function ($req) use (&$calledNext) {
            $calledNext = true;

            return response('legacy_ok');
        });

        $queriesAfter = count(DB::getQueryLog());

        $this->assertTrue($calledNext);
        $this->assertSame('legacy_ok', $response->getContent());
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($user->id, Auth::guard('web')->id());
        $this->assertSame($queriesBefore, $queriesAfter, 'Absence of binding must require zero DB queries.');
    }

    /**
     * 15. Guest with no binding remains unaffected.
     */
    public function test_guest_with_no_binding_remains_unaffected(): void
    {
        $request = $this->createSessionRequest();
        $this->assertFalse(OidcSessionBinding::has($request));
        $this->assertFalse(Auth::guard('web')->check());

        $calledNext = false;
        $response = $this->middleware->handle($request, function ($req) use (&$calledNext) {
            $calledNext = true;

            return response('guest_ok');
        });

        $this->assertTrue($calledNext);
        $this->assertSame('guest_ok', $response->getContent());
        $this->assertFalse(Auth::guard('web')->check());
    }

    /**
     * 16. Same-user callback on legacy session does not create binding.
     */
    public function test_same_user_callback_on_legacy_session_does_not_create_binding(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        Auth::guard('web')->login($user);

        $request = $this->createSessionRequest();
        $this->assertFalse(OidcSessionBinding::has($request));

        $resolved = new ResolvedOidcIdentity($user, $identity);
        $this->sessionManager->establish($request, $resolved);

        $this->assertFalse(OidcSessionBinding::has($request));
    }

    /**
     * 17. Same-user callback on existing OIDC session preserves valid binding.
     */
    public function test_same_user_callback_on_existing_oidc_session_preserves_valid_binding(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity($user, $identity);

        // Case A establishment
        $this->sessionManager->establish($request, $resolved);
        $originalBinding = OidcSessionBinding::get($request);
        $this->assertNotNull($originalBinding);

        // Case B callback by same authenticated user
        $this->sessionManager->establish($request, $resolved);

        $this->assertSame($originalBinding, OidcSessionBinding::get($request));
    }

    /**
     * 18. Logout / session invalidation naturally removes binding.
     */
    public function test_logout_and_session_invalidation_naturally_removes_binding(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity($user, $identity);

        $this->sessionManager->establish($request, $resolved);
        $this->assertTrue(OidcSessionBinding::has($request));

        Auth::guard('web')->logout();
        $request->session()->invalidate();

        $this->assertFalse(OidcSessionBinding::has($request));
    }

    /**
     * 19. Safe invalidation log contains no raw identity or secrets.
     */
    public function test_safe_invalidation_log_contains_no_raw_identity_or_secrets(): void
    {
        $conspicuousIssuer = 'https://conspicuous-revocation.example.com';
        $conspicuousSubject = 'conspicuous-revocation-uuid-999';
        $conspicuousEmail = 'revocation-user@example.com';

        [$user, $identity] = $this->createApprovedIdentity(
            issuer: $conspicuousIssuer,
            subject: $conspicuousSubject,
            email: $conspicuousEmail
        );

        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity($user, $identity);
        $this->sessionManager->establish($request, $resolved);

        // Delete identity to trigger invalidation
        $identity->delete();

        $capturedContexts = [];
        Log::listen(function ($message) use (&$capturedContexts) {
            if ($message->message === 'oidc.session.binding_invalidated') {
                $capturedContexts[] = $message->context;
            }
        });

        $this->middleware->handle($request, function () {
            return response('unreachable');
        });

        $this->assertCount(1, $capturedContexts);
        $context = $capturedContexts[0];

        $allowedKeys = [
            'reason',
            'external_identity_id',
            'bound_user_id',
            'authenticated_user_id',
            'trust_key_fingerprint',
        ];
        $this->assertEmpty(array_diff(array_keys($context), $allowedKeys));

        $serialized = json_encode($context);
        $this->assertStringNotContainsString($conspicuousIssuer, $serialized);
        $this->assertStringNotContainsString($conspicuousSubject, $serialized);
        $this->assertStringNotContainsString($conspicuousEmail, $serialized);

        $forbiddenKeys = ['issuer', 'subject', 'email', 'token', 'session_id', 'cookie', 'secret', 'claims'];
        foreach ($forbiddenKeys as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $context);
        }
    }

    /**
     * 20. Regression test: Middleware priority and gathered route middleware order (C1/C2).
     * Proves ValidateOidcSessionBinding is in web group, immediately AFTER StartSession,
     * and BEFORE PreventRequestForgery and SubstituteBindings.
     */
    public function test_middleware_order_enforces_priority_immediately_after_start_session(): void
    {
        $router = $this->app['router'];

        // 1. Verify ValidateOidcSessionBinding is in the 'web' middleware group
        $webGroup = $router->getMiddlewareGroups()['web'] ?? [];
        $this->assertContains(
            ValidateOidcSessionBinding::class,
            $webGroup,
            'ValidateOidcSessionBinding must be registered in the web middleware group.'
        );

        // 2. Verify effective gathered middleware on real routes
        $routeNames = ['dashboard', 'profile.edit'];
        foreach ($routeNames as $routeName) {
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
        }
    }

    /**
     * 21. Real HTTP-kernel next-request revocation test upon identity deletion (C2).
     * Dispatches real HTTP requests through the full kernel, resetting auth guards between requests.
     */
    public function test_real_http_kernel_next_request_revokes_session_upon_identity_deletion(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $fingerprint = hash('sha256', self::FAKE_ISSUER."\0".self::FAKE_SUBJECT);

        $sessionGuardName = 'login_web_'.sha1(SessionGuard::class);
        $sessionData = [
            $sessionGuardName => $user->id,
            OidcSessionBinding::SESSION_KEY => [
                'external_identity_id' => (int) $identity->id,
                'user_id' => (int) $user->id,
                'trust_key_fingerprint' => $fingerprint,
            ],
        ];

        // 1. Initial request with valid binding succeeds through the full HTTP kernel
        $initialResponse = $this->withSession($sessionData)->get('/profile');
        $initialResponse->assertOk();
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($user->id, Auth::guard('web')->id());

        // 2. Identity is deleted (e.g. unlinked or revoked upstream)
        $identity->delete();

        // 3. Forget/reset auth guard cache so test does not pass from in-memory SessionGuard
        Auth::forgetGuards();

        // 4. Next HTTP request through the full HTTP kernel must revoke session and redirect to login
        $nextResponse = $this->withSession($sessionData)->get('/profile');
        $nextResponse->assertRedirect(route('login'));

        // 5. User is unauthenticated and session binding is cleared
        $this->assertFalse(Auth::guard('web')->check());
        $this->assertNull(Auth::guard('web')->user());
        $this->assertFalse(session()->has(OidcSessionBinding::SESSION_KEY));
    }

    /**
     * 22. Real HTTP-kernel next-request revocation test upon fingerprint tampering (C2).
     */
    public function test_real_http_kernel_next_request_revokes_session_upon_fingerprint_tampering(): void
    {
        [$user, $identity] = $this->createApprovedIdentity();
        $fingerprint = hash('sha256', self::FAKE_ISSUER."\0".self::FAKE_SUBJECT);

        $sessionGuardName = 'login_web_'.sha1(SessionGuard::class);
        $sessionData = [
            $sessionGuardName => $user->id,
            OidcSessionBinding::SESSION_KEY => [
                'external_identity_id' => (int) $identity->id,
                'user_id' => (int) $user->id,
                'trust_key_fingerprint' => $fingerprint,
            ],
        ];

        // Verify valid first request
        $this->withSession($sessionData)->get('/profile')->assertOk();

        // Tamper the subject in DB, invalidating the trust-key fingerprint
        $identity->subject = 'tampered-sub-value';
        $identity->save();

        // Reset auth guards to prevent in-memory guard caching
        Auth::forgetGuards();

        // Next request through HTTP kernel
        $nextResponse = $this->withSession($sessionData)->get('/profile');
        $nextResponse->assertRedirect(route('login'));
        $this->assertFalse(Auth::guard('web')->check());
        $this->assertNull(Auth::guard('web')->user());
    }

    /**
     * 23. Real HTTP-kernel legacy session without binding proceeds through kernel unaffected (C2).
     */
    public function test_real_http_kernel_legacy_session_without_binding_proceeds_unaffected(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $sessionGuardName = 'login_web_'.sha1(SessionGuard::class);
        $sessionData = [
            $sessionGuardName => $user->id,
        ];

        Auth::forgetGuards();

        $response = $this->withSession($sessionData)->get('/profile');
        $response->assertOk();
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($user->id, Auth::guard('web')->id());
    }
}
