<?php

namespace Tests\Feature\Identity;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Identity\Models\ExternalIdentity;
use App\Modules\Identity\Oidc\Exceptions\OidcCallbackException;
use App\Modules\Identity\Oidc\OidcSessionManager;
use App\Modules\Identity\Oidc\ResolvedOidcIdentity;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class OidcSessionManagerTest extends TestCase
{
    use RefreshDatabase;

    private OidcSessionManager $sessionManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionManager = new OidcSessionManager();
    }

    private function createApprovedIdentity(
        string $issuer = 'https://auth.reltroner.com/realms/reltroner',
        string $subject = 'sub-user-12345',
        string $email = 'approved@reltroner.com'
    ): array {
        $user = User::factory()->create([
            'email' => $email,
            'email_verified_at' => now()->subDays(5),
            'remember_token' => null,
        ]);

        $externalIdentity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => $issuer,
            'subject' => $subject,
            'email_at_link' => $email,
            'linked_at' => now()->subDays(10),
            'last_login_at' => null,
        ]);

        return [$user, $externalIdentity];
    }

    private function createSessionRequest(array $sessionData = []): Request
    {
        $request = Request::create('/auth/keycloak/callback', 'GET');
        $session = $this->app['session']->driver();
        $session->start();
        $session->flush();

        foreach ($sessionData as $key => $value) {
            $session->put($key, $value);
        }

        $request->setLaravelSession($session);

        return $request;
    }

    public function test_guest_approved_identity_becomes_authenticated_as_exact_resolved_user(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $this->assertFalse(Auth::guard('web')->check());

        $this->sessionManager->establish($request, $resolved);

        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($user->id, Auth::guard('web')->id());
    }

    public function test_remember_auth_is_not_used(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $this->sessionManager->establish($request, $resolved);

        $this->assertFalse(Auth::guard('web')->viaRemember());
        $this->assertNull($user->refresh()->remember_token);
    }

    public function test_new_login_rotates_session_identifier(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest(['foo' => 'bar']);
        $initialId = $request->session()->getId();

        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);
        $this->sessionManager->establish($request, $resolved);

        $this->assertNotSame($initialId, $request->session()->getId());
    }

    public function test_new_login_regenerates_csrf_token(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $initialToken = $request->session()->token();

        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);
        $this->sessionManager->establish($request, $resolved);

        $this->assertNotSame($initialToken, $request->session()->token());
    }

    public function test_stale_session_role_is_removed_on_new_oidc_login(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest(['role' => 'StaleRole']);

        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);
        $this->sessionManager->establish($request, $resolved);

        $this->assertFalse($request->session()->has('role'));
        $this->assertNull(session('role'));
    }

    public function test_stale_session_employee_id_is_removed_on_new_oidc_login(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest(['employee_id' => 9999]);

        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);
        $this->sessionManager->establish($request, $resolved);

        $this->assertFalse($request->session()->has('employee_id'));
    }

    public function test_production_user_or_demo_user_is_never_written_to_session_role(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();

        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);
        $this->sessionManager->establish($request, $resolved);

        $this->assertNull(session('role'));
        $this->assertNotSame('production_user', session('role'));
        $this->assertNotSame('demo_user', session('role'));
    }

    public function test_last_login_at_updates_on_exact_external_identity_used(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $this->assertNull($externalIdentity->last_login_at);

        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $this->sessionManager->establish($request, $resolved);

        $externalIdentity->refresh();
        $this->assertNotNull($externalIdentity->last_login_at);
        $this->assertTrue(now()->diffInSeconds($externalIdentity->last_login_at) < 5);
    }

    public function test_another_external_identity_belonging_to_same_user_remains_untouched(): void
    {
        [$user, $identity1] = $this->createApprovedIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'sub-identity-1'
        );

        $identity2 = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak-secondary',
            'issuer' => 'https://auth.reltroner.com/realms/other',
            'subject' => 'sub-identity-2',
            'email_at_link' => $user->email,
            'linked_at' => now()->subDays(2),
            'last_login_at' => null,
        ]);

        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $identity1);

        $this->sessionManager->establish($request, $resolved);

        $identity1->refresh();
        $identity2->refresh();

        $this->assertNotNull($identity1->last_login_at);
        $this->assertNull($identity2->last_login_at);
    }

    public function test_linked_at_unchanged(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $originalLinkedAt = $externalIdentity->linked_at->toIso8601String();

        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $this->sessionManager->establish($request, $resolved);

        $externalIdentity->refresh();
        $this->assertSame($originalLinkedAt, $externalIdentity->linked_at->toIso8601String());
    }

    public function test_email_at_link_unchanged(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();

        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $this->sessionManager->establish($request, $resolved);

        $externalIdentity->refresh();
        $this->assertSame('approved@reltroner.com', $externalIdentity->email_at_link);
    }

    public function test_user_email_unchanged(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();

        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $this->sessionManager->establish($request, $resolved);

        $user->refresh();
        $this->assertSame('approved@reltroner.com', $user->email);
    }

    public function test_email_verified_at_unchanged(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $originalVerifiedAt = $user->email_verified_at->toIso8601String();

        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $this->sessionManager->establish($request, $resolved);

        $user->refresh();
        $this->assertSame($originalVerifiedAt, $user->email_verified_at->toIso8601String());
    }

    public function test_no_user_created(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $userCountBefore = User::count();

        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $this->sessionManager->establish($request, $resolved);

        $this->assertSame($userCountBefore, User::count());
    }

    public function test_no_external_identity_created(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $identityCountBefore = ExternalIdentity::count();

        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $this->sessionManager->establish($request, $resolved);

        $this->assertSame($identityCountBefore, ExternalIdentity::count());
    }

    public function test_no_employee_created(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $this->assertSame(0, Employee::count());

        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $this->sessionManager->establish($request, $resolved);

        $this->assertSame(0, Employee::count());
    }

    public function test_same_already_authenticated_user_is_accepted_idempotently(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        Auth::guard('web')->login($user);

        $request = $this->createSessionRequest(['role' => 'ExistingRole', 'employee_id' => 123]);
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $this->sessionManager->establish($request, $resolved);

        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($user->id, Auth::guard('web')->id());
    }

    public function test_same_user_callback_does_not_clear_role_or_employee_id(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        Auth::guard('web')->login($user);

        $request = $this->createSessionRequest(['role' => 'ExistingRole', 'employee_id' => 123]);
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $this->sessionManager->establish($request, $resolved);

        $this->assertSame('ExistingRole', $request->session()->get('role'));
        $this->assertSame(123, $request->session()->get('employee_id'));
    }

    public function test_same_user_callback_does_not_update_last_login_at(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        Auth::guard('web')->login($user);

        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $this->sessionManager->establish($request, $resolved);

        $externalIdentity->refresh();
        $this->assertNull($externalIdentity->last_login_at);
    }

    public function test_same_user_callback_does_not_rotate_session_or_csrf_token(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        Auth::guard('web')->login($user);

        $request = $this->createSessionRequest();
        $initialSessionId = $request->session()->getId();
        $initialToken = $request->session()->token();

        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);
        $this->sessionManager->establish($request, $resolved);

        $this->assertSame($initialSessionId, $request->session()->getId());
        $this->assertSame($initialToken, $request->session()->token());
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($user->id, Auth::guard('web')->id());
    }

    public function test_different_already_authenticated_user_throws_409_conflict(): void
    {
        [$userA, $identityA] = $this->createApprovedIdentity(subject: 'sub-user-a', email: 'usera@reltroner.com');
        [$userB, $identityB] = $this->createApprovedIdentity(subject: 'sub-user-b', email: 'userb@reltroner.com');

        Auth::guard('web')->login($userA);

        $request = $this->createSessionRequest();
        $resolvedB = new ResolvedOidcIdentity(user: $userB, externalIdentity: $identityB);

        try {
            $this->sessionManager->establish($request, $resolvedB);
            $this->fail('Expected 409 session_user_conflict was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('session_user_conflict', $e->getCategory());
            $this->assertSame('Authentication session conflict.', $e->getUserFacingMessage());
        }
    }

    public function test_different_user_conflict_leaves_original_user_authenticated(): void
    {
        [$userA, $identityA] = $this->createApprovedIdentity(subject: 'sub-user-a', email: 'usera@reltroner.com');
        [$userB, $identityB] = $this->createApprovedIdentity(subject: 'sub-user-b', email: 'userb@reltroner.com');

        Auth::guard('web')->login($userA);

        $request = $this->createSessionRequest();
        $resolvedB = new ResolvedOidcIdentity(user: $userB, externalIdentity: $identityB);

        try {
            $this->sessionManager->establish($request, $resolvedB);
        } catch (OidcCallbackException) {
        }

        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($userA->id, Auth::guard('web')->id());
    }

    public function test_different_user_conflict_preserves_original_role_and_employee_id(): void
    {
        [$userA, $identityA] = $this->createApprovedIdentity(subject: 'sub-user-a', email: 'usera@reltroner.com');
        [$userB, $identityB] = $this->createApprovedIdentity(subject: 'sub-user-b', email: 'userb@reltroner.com');

        Auth::guard('web')->login($userA);

        $request = $this->createSessionRequest(['role' => 'UserARole', 'employee_id' => 77]);
        $resolvedB = new ResolvedOidcIdentity(user: $userB, externalIdentity: $identityB);

        try {
            $this->sessionManager->establish($request, $resolvedB);
        } catch (OidcCallbackException) {
        }

        $this->assertSame('UserARole', $request->session()->get('role'));
        $this->assertSame(77, $request->session()->get('employee_id'));
    }

    public function test_deleted_link_before_establish_throws_403(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        // Link deleted before establish
        $externalIdentity->delete();

        $request = $this->createSessionRequest();

        try {
            $this->sessionManager->establish($request, $resolved);
            $this->fail('Expected 403 identity_link_changed was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('identity_link_changed', $e->getCategory());
        }

        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_changed_issuer_before_establish_throws_403(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $resolvedIdentity = clone $externalIdentity;
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $resolvedIdentity);

        // Issuer changed in database after resolution
        $externalIdentity->issuer = 'https://tampered-issuer.com';
        $externalIdentity->save();

        $request = $this->createSessionRequest();

        try {
            $this->sessionManager->establish($request, $resolved);
            $this->fail('Expected 403 identity_link_changed was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('identity_link_changed', $e->getCategory());
        }

        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_changed_subject_before_establish_throws_403(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $resolvedIdentity = clone $externalIdentity;
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $resolvedIdentity);

        // Subject changed in database after resolution
        $externalIdentity->subject = 'tampered-subject';
        $externalIdentity->save();

        $request = $this->createSessionRequest();

        try {
            $this->sessionManager->establish($request, $resolved);
            $this->fail('Expected 403 identity_link_changed was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('identity_link_changed', $e->getCategory());
        }

        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_link_reassigned_to_another_user_before_establish_throws_403(): void
    {
        [$userA, $externalIdentity] = $this->createApprovedIdentity();
        $userB = User::factory()->create();

        $resolvedIdentity = clone $externalIdentity;
        $resolved = new ResolvedOidcIdentity(user: $userA, externalIdentity: $resolvedIdentity);

        // Reassign link to userB in database after resolution
        $externalIdentity->user_id = $userB->id;
        $externalIdentity->save();

        $request = $this->createSessionRequest();

        try {
            $this->sessionManager->establish($request, $resolved);
            $this->fail('Expected 403 identity_link_changed was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('identity_link_changed', $e->getCategory());
        }

        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_missing_user_relation_throws_500_identity_integrity_error(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $fakeLink = clone $externalIdentity;
        $fakeLink->setRelation('user', null);

        $manager = new class($fakeLink) extends OidcSessionManager {
            public function __construct(private ExternalIdentity $fake)
            {
            }

            protected function findCurrentLink(mixed $id): ?ExternalIdentity
            {
                return $this->fake;
            }
        };

        $request = $this->createSessionRequest();

        try {
            $manager->establish($request, $resolved);
            $this->fail('Expected 500 identity_integrity_error was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertSame('identity_integrity_error', $e->getCategory());
        }
    }

    public function test_last_login_persistence_failure_rolls_back_to_guest_state(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $manager = new class extends OidcSessionManager {
            protected function persistLastLoginAt(ExternalIdentity $link): void
            {
                throw new Exception('Simulated database write failure');
            }
        };

        $request = $this->createSessionRequest();

        try {
            $manager->establish($request, $resolved);
            $this->fail('Expected 500 session_establishment_failed was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertSame('session_establishment_failed', $e->getCategory());
            $this->assertSame('Authentication could not be completed.', $e->getUserFacingMessage());
        }

        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_last_login_save_false_rolls_back_to_guest_state(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $intercept = true;
        ExternalIdentity::saving(
            function (ExternalIdentity $model) use ($externalIdentity, &$intercept) {
                if (
                    $intercept
                    && $model->getKey() === $externalIdentity->getKey()
                    && $model->isDirty('last_login_at')
                ) {
                    return false;
                }

                return true;
            }
        );

        $request = $this->createSessionRequest();

        try {
            $this->sessionManager->establish($request, $resolved);
            $this->fail('Expected 500 session_establishment_failed was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertSame('session_establishment_failed', $e->getCategory());
            $this->assertSame('Authentication could not be completed.', $e->getUserFacingMessage());
        } finally {
            $intercept = false;
            ExternalIdentity::flushEventListeners();
        }

        $this->assertFalse(Auth::guard('web')->check());
        $this->assertNull($externalIdentity->fresh()->last_login_at);
    }

    public function test_deleting_link_after_session_established_does_not_revoke_active_session(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $this->sessionManager->establish($request, $resolved);
        $this->assertTrue(Auth::guard('web')->check());

        // Documenting architecture contract: link deletion after session establishment
        // does not automatically revoke the active Laravel session.
        $externalIdentity->delete();

        $this->assertTrue(Auth::guard('web')->check());
    }

    public function test_unrelated_session_keys_are_preserved_on_login(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $request = $this->createSessionRequest([
            'url.intended' => '/profile',
            'custom_key' => 'custom_value',
        ]);
        $resolved = new ResolvedOidcIdentity(user: $user, externalIdentity: $externalIdentity);

        $this->sessionManager->establish($request, $resolved);

        $this->assertSame('/profile', $request->session()->get('url.intended'));
        $this->assertSame('custom_value', $request->session()->get('custom_key'));
    }
}
