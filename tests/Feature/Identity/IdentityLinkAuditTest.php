<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Events\IdentityLinkAuditEvent;
use App\Modules\Identity\Models\ExternalIdentity;
use App\Modules\Identity\Oidc\OidcSessionManager;
use App\Modules\Identity\Oidc\ResolvedOidcIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class IdentityLinkAuditTest extends TestCase
{
    use DatabaseMigrations;

    private function createSessionRequest(): Request
    {
        $request = Request::create('/auth/keycloak/callback', 'GET');
        $session = $this->app['session']->driver();
        $session->start();
        $session->flush();
        $request->setLaravelSession($session);

        return $request;
    }

    /**
     * 1. Successful ExternalIdentity creation dispatches exactly one IdentityLinkAuditEvent.
     */
    public function test_successful_external_identity_creation_dispatches_exactly_one_linked_event(): void
    {
        $user = User::factory()->create();

        Event::fake([IdentityLinkAuditEvent::class]);

        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-create-test-01',
            'email_at_link' => 'user-create@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        $expectedFingerprint = hash('sha256', 'https://auth.reltroner.com/realms/reltroner' . "\0" . 'sub-create-test-01');

        Event::assertDispatchedTimes(IdentityLinkAuditEvent::class, 1);
        Event::assertDispatched(IdentityLinkAuditEvent::class, function (IdentityLinkAuditEvent $event) use ($identity, $user, $expectedFingerprint) {
            return $event->action === IdentityLinkAuditEvent::ACTION_LINKED
                && $event->externalIdentityId === $identity->id
                && $event->userId === $user->id
                && $event->trustKeyFingerprint === $expectedFingerprint;
        });
    }

    /**
     * 2. Successful explicit ExternalIdentity deletion dispatches exactly one unlinked event.
     */
    public function test_successful_explicit_deletion_dispatches_exactly_one_unlinked_event(): void
    {
        $user = User::factory()->create();
        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-delete-test-01',
            'email_at_link' => 'user-delete@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        Event::fake([IdentityLinkAuditEvent::class]);

        $identityId = $identity->id;
        $userId = $user->id;
        $expectedFingerprint = hash('sha256', 'https://auth.reltroner.com/realms/reltroner' . "\0" . 'sub-delete-test-01');

        $identity->delete();

        Event::assertDispatchedTimes(IdentityLinkAuditEvent::class, 1);
        Event::assertDispatched(IdentityLinkAuditEvent::class, function (IdentityLinkAuditEvent $event) use ($identityId, $userId, $expectedFingerprint) {
            return $event->action === IdentityLinkAuditEvent::ACTION_UNLINKED
                && $event->externalIdentityId === $identityId
                && $event->userId === $userId
                && $event->trustKeyFingerprint === $expectedFingerprint;
        });
    }

    /**
     * 3. User deletion through existing FK cascade dispatches exactly one unlink event per ExternalIdentity.
     */
    public function test_user_deletion_through_cascade_dispatches_unlink_event_per_external_identity(): void
    {
        $user = User::factory()->create();
        $identity1 = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-cascade-01',
            'email_at_link' => 'cascade1@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);
        $identity2 = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'issuer' => 'https://accounts.google.com',
            'subject' => 'sub-cascade-02',
            'email_at_link' => 'cascade2@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        $id1 = $identity1->id;
        $id2 = $identity2->id;

        Event::fake([IdentityLinkAuditEvent::class]);

        $user->delete();

        Event::assertDispatchedTimes(IdentityLinkAuditEvent::class, 2);

        Event::assertDispatched(IdentityLinkAuditEvent::class, function (IdentityLinkAuditEvent $event) use ($id1, $user) {
            return $event->action === IdentityLinkAuditEvent::ACTION_UNLINKED
                && $event->externalIdentityId === $id1
                && $event->userId === $user->id;
        });

        Event::assertDispatched(IdentityLinkAuditEvent::class, function (IdentityLinkAuditEvent $event) use ($id2, $user) {
            return $event->action === IdentityLinkAuditEvent::ACTION_UNLINKED
                && $event->externalIdentityId === $id2
                && $event->userId === $user->id;
        });

        $this->assertDatabaseMissing('external_identities', ['id' => $id1]);
        $this->assertDatabaseMissing('external_identities', ['id' => $id2]);
    }

    /**
     * 4. User deletion does NOT produce duplicate unlink events.
     */
    public function test_user_deletion_does_not_produce_duplicate_unlink_events(): void
    {
        $user = User::factory()->create();
        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-no-dup-01',
            'email_at_link' => 'nodup@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        Event::fake([IdentityLinkAuditEvent::class]);

        $user->delete();

        // Exactly one unlink event for the single child identity
        Event::assertDispatchedTimes(IdentityLinkAuditEvent::class, 1);
    }

    /**
     * 5. last_login_at update emits ZERO IdentityLinkAuditEvent.
     */
    public function test_last_login_at_update_emits_zero_identity_link_events(): void
    {
        $user = User::factory()->create();
        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-lastlogin-01',
            'email_at_link' => 'lastlogin@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        Event::fake([IdentityLinkAuditEvent::class]);

        $identity->last_login_at = now();
        $identity->save();

        Event::assertNotDispatched(IdentityLinkAuditEvent::class);
    }

    /**
     * 6. Real OidcSessionManager successful login emits ZERO identity-link audit events after fixtures exist.
     */
    public function test_oidc_session_manager_successful_login_emits_zero_identity_link_events(): void
    {
        $user = User::factory()->create([
            'email' => 'sso-session@reltroner.com',
            'email_verified_at' => now(),
        ]);
        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-sso-session-01',
            'email_at_link' => 'sso-session@reltroner.com',
            'linked_at' => now()->subDays(5),
            'last_login_at' => null,
        ]);

        Event::fake([IdentityLinkAuditEvent::class]);

        $sessionManager = new OidcSessionManager();
        $request = $this->createSessionRequest();
        $resolved = new ResolvedOidcIdentity($user, $identity);

        $sessionManager->establish($request, $resolved);

        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
        $this->assertNotNull($identity->fresh()->last_login_at);

        Event::assertNotDispatched(IdentityLinkAuditEvent::class);
    }

    /**
     * 7. Linked audit log contains only allowed fields.
     */
    public function test_linked_audit_log_contains_only_allowed_fields(): void
    {
        $user = User::factory()->create();

        Log::spy();

        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-log-linked-01',
            'email_at_link' => 'loglink@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        $expectedFingerprint = hash('sha256', 'https://auth.reltroner.com/realms/reltroner' . "\0" . 'sub-log-linked-01');

        Log::shouldHaveReceived('info')
            ->with(
                'identity.link.audit',
                \Mockery::on(function (array $context) use ($identity, $user, $expectedFingerprint) {
                    $allowedKeys = [
                        'action',
                        'external_identity_id',
                        'user_id',
                        'trust_key_fingerprint',
                        'actor_id',
                        'actor_type',
                    ];
                    $keys = array_keys($context);
                    sort($keys);
                    sort($allowedKeys);

                    return $keys === $allowedKeys
                        && $context['action'] === 'linked'
                        && $context['external_identity_id'] === $identity->id
                        && $context['user_id'] === $user->id
                        && $context['trust_key_fingerprint'] === $expectedFingerprint
                        && $context['actor_id'] === null
                        && $context['actor_type'] === 'system';
                })
            )
            ->once();
    }

    /**
     * 8. Unlinked audit log contains only allowed fields.
     */
    public function test_unlinked_audit_log_contains_only_allowed_fields(): void
    {
        $user = User::factory()->create();
        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-log-unlinked-01',
            'email_at_link' => 'logunlink@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        $identityId = $identity->id;
        $userId = $user->id;
        $expectedFingerprint = hash('sha256', 'https://auth.reltroner.com/realms/reltroner' . "\0" . 'sub-log-unlinked-01');

        Log::spy();

        $identity->delete();

        Log::shouldHaveReceived('info')
            ->with(
                'identity.link.audit',
                \Mockery::on(function (array $context) use ($identityId, $userId, $expectedFingerprint) {
                    $allowedKeys = [
                        'action',
                        'external_identity_id',
                        'user_id',
                        'trust_key_fingerprint',
                        'actor_id',
                        'actor_type',
                    ];
                    $keys = array_keys($context);
                    sort($keys);
                    sort($allowedKeys);

                    return $keys === $allowedKeys
                        && $context['action'] === 'unlinked'
                        && $context['external_identity_id'] === $identityId
                        && $context['user_id'] === $userId
                        && $context['trust_key_fingerprint'] === $expectedFingerprint
                        && $context['actor_id'] === null
                        && $context['actor_type'] === 'system';
                })
            )
            ->once();
    }

    /**
     * 9. Audit context does not contain sensitive identity claims or credentials.
     */
    public function test_audit_context_does_not_contain_sensitive_claims_or_credentials(): void
    {
        $conspicuousIssuer = 'https://conspicuous-idp.example.com/auth/realm';
        $conspicuousSubject = 'conspicuous-subject-uuid-7777';
        $conspicuousEmailAtLink = 'conspicuous-atlink@example.com';
        $conspicuousUserEmail = 'conspicuous-user@example.com';

        $user = User::factory()->create([
            'email' => $conspicuousUserEmail,
        ]);

        $capturedContexts = [];
        Log::listen(function ($message) use (&$capturedContexts) {
            if ($message->message === 'identity.link.audit') {
                $capturedContexts[] = $message->context;
            }
        });

        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => $conspicuousIssuer,
            'subject' => $conspicuousSubject,
            'email_at_link' => $conspicuousEmailAtLink,
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        $identity->delete();

        $this->assertCount(2, $capturedContexts);

        foreach ($capturedContexts as $context) {
            $serialized = json_encode($context);

            $this->assertStringNotContainsString($conspicuousIssuer, $serialized);
            $this->assertStringNotContainsString($conspicuousSubject, $serialized);
            $this->assertStringNotContainsString($conspicuousEmailAtLink, $serialized);
            $this->assertStringNotContainsString($conspicuousUserEmail, $serialized);

            $this->assertArrayNotHasKey('issuer', $context);
            $this->assertArrayNotHasKey('subject', $context);
            $this->assertArrayNotHasKey('email', $context);
            $this->assertArrayNotHasKey('email_at_link', $context);
            $this->assertArrayNotHasKey('token', $context);
            $this->assertArrayNotHasKey('code', $context);
            $this->assertArrayNotHasKey('pkce', $context);
            $this->assertArrayNotHasKey('secret', $context);
            $this->assertArrayNotHasKey('session_id', $context);
            $this->assertArrayNotHasKey('cookie', $context);
        }
    }

    /**
     * 10. Unauthenticated mutation records actor_id = null and actor_type = system.
     */
    public function test_unauthenticated_mutation_records_system_actor(): void
    {
        Auth::logout();
        $this->assertFalse(Auth::check());

        $user = User::factory()->create();

        Event::fake([IdentityLinkAuditEvent::class]);

        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-actor-system-01',
            'email_at_link' => 'system@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        Event::assertDispatched(IdentityLinkAuditEvent::class, function (IdentityLinkAuditEvent $event) {
            return $event->actorId === null
                && $event->actorType === 'system';
        });
    }

    /**
     * 11. Authenticated mutation records actor_id = authenticated User id and actor_type = user.
     */
    public function test_authenticated_mutation_records_user_actor(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);

        $targetUser = User::factory()->create();

        Event::fake([IdentityLinkAuditEvent::class]);

        $identity = ExternalIdentity::create([
            'user_id' => $targetUser->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-actor-user-01',
            'email_at_link' => 'target@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        Event::assertDispatched(IdentityLinkAuditEvent::class, function (IdentityLinkAuditEvent $event) use ($actor) {
            return $event->actorId === $actor->id
                && $event->actorType === 'user';
        });
    }

    /**
     * 12. Failed duplicate issuer+subject creation does NOT produce a linked event for the failed row.
     */
    public function test_failed_duplicate_issuer_subject_creation_does_not_dispatch_event_for_failed_row(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        ExternalIdentity::create([
            'user_id' => $user1->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'duplicate-sub-99',
            'email_at_link' => 'u1@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        Event::fake([IdentityLinkAuditEvent::class]);

        $duplicateFailed = false;

        try {
            ExternalIdentity::create([
                'user_id' => $user2->id,
                'provider' => 'keycloak',
                'issuer' => 'https://auth.reltroner.com/realms/reltroner',
                'subject' => 'duplicate-sub-99',
                'email_at_link' => 'u2@reltroner.com',
                'linked_at' => now(),
                'last_login_at' => null,
            ]);
        } catch (QueryException) {
            $duplicateFailed = true;
        }

        $this->assertTrue($duplicateFailed, 'Expected duplicate unique index insertion to fail.');
        Event::assertNotDispatched(IdentityLinkAuditEvent::class);
    }

    /**
     * 13. Ordinary ExternalIdentity model update unrelated to link lifecycle does NOT emit link event.
     */
    public function test_ordinary_external_identity_update_does_not_emit_link_event(): void
    {
        $user = User::factory()->create();
        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-ordinary-update-01',
            'email_at_link' => 'ord@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        Event::fake([IdentityLinkAuditEvent::class]);

        $identity->provider = 'updated-provider';
        $identity->email_at_link = 'changed@reltroner.com';
        $identity->last_login_at = now();
        $identity->save();

        Event::assertNotDispatched(IdentityLinkAuditEvent::class);
    }

    /**
     * 14. Existing User cascade deletion behavior remains intact.
     */
    public function test_existing_user_cascade_deletion_behavior_remains_intact(): void
    {
        $user = User::factory()->create();
        $identity1 = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-cascade-intact-01',
            'email_at_link' => 'c1@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);
        $identity2 = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'issuer' => 'https://accounts.google.com',
            'subject' => 'sub-cascade-intact-02',
            'email_at_link' => 'c2@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('external_identities', ['id' => $identity1->id]);
        $this->assertDatabaseHas('external_identities', ['id' => $identity2->id]);

        $user->delete();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('external_identities', ['id' => $identity1->id]);
        $this->assertDatabaseMissing('external_identities', ['id' => $identity2->id]);
    }

    /**
     * 15. Link audit event dispatches only after database transaction commit.
     */
    public function test_link_audit_event_dispatches_after_transaction_commit(): void
    {
        $count = 0;
        Event::listen(
            IdentityLinkAuditEvent::class,
            function (IdentityLinkAuditEvent $event) use (&$count) {
                $count++;
            }
        );

        $user = User::factory()->create();

        DB::beginTransaction();

        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-tx-commit-01',
            'email_at_link' => 'txcommit@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        $this->assertSame(0, $count, 'Event must not be dispatched before transaction commit.');

        DB::commit();

        $this->assertSame(1, $count, 'Event must be dispatched after transaction commit.');
        $this->assertDatabaseHas('external_identities', ['id' => $identity->id]);
    }

    /**
     * 16. Link audit event is not dispatched when creation transaction rolls back.
     */
    public function test_link_audit_event_is_not_dispatched_when_creation_transaction_rolls_back(): void
    {
        $count = 0;
        Event::listen(
            IdentityLinkAuditEvent::class,
            function (IdentityLinkAuditEvent $event) use (&$count) {
                $count++;
            }
        );

        $user = User::factory()->create();

        DB::beginTransaction();

        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-tx-rollback-01',
            'email_at_link' => 'txrollback@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        $this->assertSame(0, $count, 'Event must not be dispatched before rollback.');

        DB::rollBack();

        $this->assertSame(0, $count, 'Event must not be dispatched after transaction rollback.');
        $this->assertDatabaseMissing('external_identities', ['subject' => 'sub-tx-rollback-01']);
    }

    /**
     * 17. Unlink audit event is not dispatched when user deletion transaction rolls back.
     */
    public function test_unlink_audit_event_is_not_dispatched_when_user_delete_transaction_rolls_back(): void
    {
        $user = User::factory()->create();
        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-user-rollback-01',
            'email_at_link' => 'userrollback@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        $unlinkCount = 0;
        Event::listen(
            IdentityLinkAuditEvent::class,
            function (IdentityLinkAuditEvent $event) use (&$unlinkCount) {
                if ($event->action === IdentityLinkAuditEvent::ACTION_UNLINKED) {
                    $unlinkCount++;
                }
            }
        );

        DB::beginTransaction();

        $user->delete();

        $this->assertSame(0, $unlinkCount, 'Unlink event must not be dispatched while transaction is uncommitted.');

        DB::rollBack();

        $this->assertSame(0, $unlinkCount, 'Unlink event must not be dispatched after transaction rollback.');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('external_identities', ['id' => $identity->id]);
    }
}
