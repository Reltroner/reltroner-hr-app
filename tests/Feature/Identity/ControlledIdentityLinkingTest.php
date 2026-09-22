<?php

namespace Tests\Feature\Identity;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Identity\Events\IdentityLinkAuditEvent;
use App\Modules\Identity\Linking\IdentityLinkException;
use App\Modules\Identity\Linking\LinkExternalIdentity;
use App\Modules\Identity\Models\ExternalIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ControlledIdentityLinkingTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_ISSUER = 'https://fake-keycloak.test/realms/fake-realm';
    private const FAKE_SUBJECT = 'fake-sub-uuid-0001';

    protected function setUp(): void
    {
        parent::setUp();
        config(['oidc.issuer' => self::FAKE_ISSUER]);
    }

    /**
     * 1-7. Existing local User can be linked with exact attributes.
     */
    public function test_existing_local_user_can_be_linked_with_exact_attributes(): void
    {
        $user = User::factory()->create();

        $action = new LinkExternalIdentity();
        $link = $action->execute($user->id, self::FAKE_SUBJECT);

        $this->assertInstanceOf(ExternalIdentity::class, $link);
        $this->assertSame($user->id, (int) $link->user_id);
        $this->assertSame('keycloak', $link->provider);
        $this->assertSame(self::FAKE_ISSUER, $link->issuer);
        $this->assertSame(self::FAKE_SUBJECT, $link->subject);
        $this->assertNull($link->email_at_link);
        $this->assertNotNull($link->linked_at);
        $this->assertInstanceOf(Carbon::class, $link->linked_at);
        $this->assertNull($link->last_login_at);

        $this->assertDatabaseHas('external_identities', [
            'id' => $link->id,
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => self::FAKE_ISSUER,
            'subject' => self::FAKE_SUBJECT,
            'email_at_link' => null,
            'last_login_at' => null,
        ]);
    }

    /**
     * 2. Created link uses exact configured issuer.
     */
    public function test_created_link_uses_exact_configured_issuer(): void
    {
        $customIssuer = 'https://idp.example.org/realms/custom';
        config(['oidc.issuer' => $customIssuer]);

        $user = User::factory()->create();
        $action = new LinkExternalIdentity();
        $link = $action->execute($user->id, 'fake-sub-issuer-test');

        $this->assertSame($customIssuer, $link->issuer);
        $this->assertDatabaseHas('external_identities', [
            'id' => $link->id,
            'issuer' => $customIssuer,
        ]);
    }

    /**
     * 3. Created link preserves exact supplied subject without transformation.
     */
    public function test_created_link_preserves_exact_supplied_subject(): void
    {
        $opaqueSubject = 'Sub-With_Mixed-Case.123_VALUE';
        $user = User::factory()->create();

        $action = new LinkExternalIdentity();
        $link = $action->execute($user->id, $opaqueSubject);

        $this->assertSame($opaqueSubject, $link->subject);
        $this->assertDatabaseHas('external_identities', [
            'id' => $link->id,
            'subject' => $opaqueSubject,
        ]);
    }

    /**
     * 4. Provider metadata is exactly "keycloak".
     */
    public function test_provider_metadata_is_exactly_keycloak(): void
    {
        $user = User::factory()->create();
        $action = new LinkExternalIdentity();
        $link = $action->execute($user->id, self::FAKE_SUBJECT);

        $this->assertSame('keycloak', $link->provider);
    }

    /**
     * 5. email_at_link is null.
     */
    public function test_email_at_link_is_null_and_does_not_copy_user_email(): void
    {
        $user = User::factory()->create(['email' => 'local-user@example.test']);
        $action = new LinkExternalIdentity();
        $link = $action->execute($user->id, self::FAKE_SUBJECT);

        $this->assertNull($link->email_at_link);
        $this->assertDatabaseHas('external_identities', [
            'id' => $link->id,
            'email_at_link' => null,
        ]);
    }

    /**
     * 6. linked_at is populated.
     */
    public function test_linked_at_is_populated_on_creation(): void
    {
        $user = User::factory()->create();
        $before = now()->subSecond();

        $action = new LinkExternalIdentity();
        $link = $action->execute($user->id, self::FAKE_SUBJECT);

        $after = now()->addSecond();
        $this->assertNotNull($link->linked_at);
        $this->assertTrue($link->linked_at->between($before, $after));
    }

    /**
     * 7. last_login_at is null.
     */
    public function test_last_login_at_is_null_on_creation(): void
    {
        $user = User::factory()->create();
        $action = new LinkExternalIdentity();
        $link = $action->execute($user->id, self::FAKE_SUBJECT);

        $this->assertNull($link->last_login_at);
    }

    /**
     * 8-9. Linking creates no User and creates no Employee.
     */
    public function test_linking_creates_no_user_and_no_employee(): void
    {
        $user = User::factory()->create();

        $userCountBefore = User::count();
        $employeeCountBefore = Employee::count();

        $action = new LinkExternalIdentity();
        $action->execute($user->id, self::FAKE_SUBJECT);

        $this->assertSame($userCountBefore, User::count());
        $this->assertSame($employeeCountBefore, Employee::count());
    }

    /**
     * 10-13. Calling action twice with same User + same trust key is idempotent.
     */
    public function test_calling_action_twice_with_same_user_and_trust_key_is_idempotent(): void
    {
        $user = User::factory()->create();

        Event::fake([IdentityLinkAuditEvent::class]);

        $action = new LinkExternalIdentity();
        $firstLink = $action->execute($user->id, self::FAKE_SUBJECT);
        $originalLinkedAt = $firstLink->linked_at;
        $originalId = $firstLink->id;

        // Advance time to verify linked_at is untouched
        $this->travel(10)->minutes();

        $secondLink = $action->execute($user->id, self::FAKE_SUBJECT);

        // 11. Idempotent second call returns same ExternalIdentity id
        $this->assertSame($originalId, $secondLink->id);

        // 12. Idempotent second call leaves linked_at unchanged
        $this->assertEquals($originalLinkedAt->timestamp, $secondLink->linked_at->timestamp);
        $this->assertNull($secondLink->last_login_at);

        // Exactly one ExternalIdentity row exists
        $this->assertSame(1, ExternalIdentity::where('issuer', self::FAKE_ISSUER)->where('subject', self::FAKE_SUBJECT)->count());

        // 13. Idempotent second call emits no additional linked audit event
        Event::assertDispatchedTimes(IdentityLinkAuditEvent::class, 1);
    }

    /**
     * 14-17. Same trust key linked to another User fails closed.
     */
    public function test_same_trust_key_linked_to_another_user_fails_closed(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Event::fake([IdentityLinkAuditEvent::class]);

        $action = new LinkExternalIdentity();
        $originalLink = $action->execute($userA->id, self::FAKE_SUBJECT);

        Event::assertDispatchedTimes(IdentityLinkAuditEvent::class, 1);

        try {
            $action->execute($userB->id, self::FAKE_SUBJECT);
            $this->fail('Expected IdentityLinkException was not thrown on trust key conflict.');
        } catch (IdentityLinkException $e) {
            $this->assertSame('trust_key_conflict', $e->getReason());
        }

        // 15. Conflict preserves original user_id
        $persisted = ExternalIdentity::find($originalLink->id);
        $this->assertSame($userA->id, (int) $persisted->user_id);

        // 16. Conflict creates no new ExternalIdentity
        $this->assertSame(1, ExternalIdentity::where('issuer', self::FAKE_ISSUER)->where('subject', self::FAKE_SUBJECT)->count());

        // 17. Conflict emits no new linked audit event
        Event::assertDispatchedTimes(IdentityLinkAuditEvent::class, 1);
    }

    /**
     * 18. Missing User fails closed and creates no ExternalIdentity.
     */
    public function test_missing_user_fails_closed_and_creates_no_external_identity(): void
    {
        $nonExistentUserId = 999999;
        $this->assertDatabaseMissing('users', ['id' => $nonExistentUserId]);

        $action = new LinkExternalIdentity();

        try {
            $action->execute($nonExistentUserId, self::FAKE_SUBJECT);
            $this->fail('Expected IdentityLinkException was not thrown for non-existent user.');
        } catch (IdentityLinkException $e) {
            $this->assertSame('target_user_not_found', $e->getReason());
        }

        $this->assertSame(0, ExternalIdentity::count());
    }

    /**
     * 19. Missing issuer configuration fails closed.
     */
    public function test_missing_issuer_configuration_fails_closed(): void
    {
        config(['oidc.issuer' => null]);
        $user = User::factory()->create();

        $action = new LinkExternalIdentity();

        try {
            $action->execute($user->id, self::FAKE_SUBJECT);
            $this->fail('Expected IdentityLinkException was not thrown for null issuer.');
        } catch (IdentityLinkException $e) {
            $this->assertSame('invalid_issuer', $e->getReason());
        }

        $this->assertSame(0, ExternalIdentity::count());
    }

    /**
     * 20. Empty issuer fails closed.
     */
    public function test_empty_issuer_fails_closed(): void
    {
        $user = User::factory()->create();
        $action = new LinkExternalIdentity();

        foreach (['', '   ', "\t\n  "] as $invalidIssuer) {
            config(['oidc.issuer' => $invalidIssuer]);

            try {
                $action->execute($user->id, self::FAKE_SUBJECT);
                $this->fail('Expected IdentityLinkException was not thrown for empty issuer: ' . json_encode($invalidIssuer));
            } catch (IdentityLinkException $e) {
                $this->assertSame('invalid_issuer', $e->getReason());
            }
        }

        $this->assertSame(0, ExternalIdentity::count());
    }

    /**
     * 21. Invalid issuer URL fails closed.
     */
    public function test_invalid_issuer_url_fails_closed(): void
    {
        $user = User::factory()->create();
        $action = new LinkExternalIdentity();

        $invalidIssuers = [
            'not-a-url',
            'ftp://keycloak.test/realm',
            'file:///etc/passwd',
            'http://',
            'https://',
            'javascript:alert(1)',
            'https://' . str_repeat('a', 260) . '.test/realm', // exceeds 255 chars
        ];

        foreach ($invalidIssuers as $invalid) {
            config(['oidc.issuer' => $invalid]);

            try {
                $action->execute($user->id, self::FAKE_SUBJECT);
                $this->fail('Expected IdentityLinkException was not thrown for invalid issuer URL: ' . $invalid);
            } catch (IdentityLinkException $e) {
                $this->assertSame('invalid_issuer', $e->getReason());
            }
        }

        $this->assertSame(0, ExternalIdentity::count());
    }

    /**
     * 22. Empty subject fails closed.
     */
    public function test_empty_subject_fails_closed(): void
    {
        $user = User::factory()->create();
        $action = new LinkExternalIdentity();

        try {
            $action->execute($user->id, '');
            $this->fail('Expected IdentityLinkException was not thrown for empty subject.');
        } catch (IdentityLinkException $e) {
            $this->assertSame('invalid_subject', $e->getReason());
        }

        $this->assertSame(0, ExternalIdentity::count());
    }

    /**
     * 23. Whitespace-only subject fails closed.
     */
    public function test_whitespace_only_subject_fails_closed(): void
    {
        $user = User::factory()->create();
        $action = new LinkExternalIdentity();

        foreach ([' ', '   ', "\t", "\n", " \t \n "] as $whitespaceSubject) {
            try {
                $action->execute($user->id, $whitespaceSubject);
                $this->fail('Expected IdentityLinkException was not thrown for whitespace subject.');
            } catch (IdentityLinkException $e) {
                $this->assertSame('invalid_subject', $e->getReason());
            }
        }

        $this->assertSame(0, ExternalIdentity::count());
    }

    /**
     * 24. Oversized subject fails closed.
     */
    public function test_oversized_subject_fails_closed(): void
    {
        $user = User::factory()->create();
        $action = new LinkExternalIdentity();

        $oversized = str_repeat('a', 256);

        try {
            $action->execute($user->id, $oversized);
            $this->fail('Expected IdentityLinkException was not thrown for oversized subject.');
        } catch (IdentityLinkException $e) {
            $this->assertSame('invalid_subject', $e->getReason());
        }

        $this->assertSame(0, ExternalIdentity::count());
    }

    /**
     * Non-empty subject containing internal spaces is valid.
     */
    public function test_subject_with_internal_spaces_is_valid(): void
    {
        $user = User::factory()->create();
        $action = new LinkExternalIdentity();

        $subjectWithSpaces = 'fake subject with internal spaces';
        $link = $action->execute($user->id, $subjectWithSpaces);

        $this->assertSame($subjectWithSpaces, $link->subject);
        $this->assertDatabaseHas('external_identities', [
            'id' => $link->id,
            'subject' => $subjectWithSpaces,
        ]);
    }

    /**
     * 25. Same subject under a different configured issuer is a different trust key.
     */
    public function test_same_subject_under_different_configured_issuer_is_different_trust_key(): void
    {
        $issuerA = 'https://fake-idp-alpha.test/realms/alpha';
        $issuerB = 'https://fake-idp-beta.test/realms/beta';
        $sharedSubject = 'shared-subject-identity';

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $action = new LinkExternalIdentity();

        config(['oidc.issuer' => $issuerA]);
        $link1 = $action->execute($user1->id, $sharedSubject);

        config(['oidc.issuer' => $issuerB]);
        $link2 = $action->execute($user2->id, $sharedSubject);

        $this->assertNotSame($link1->id, $link2->id);
        $this->assertSame($issuerA, $link1->issuer);
        $this->assertSame($issuerB, $link2->issuer);
        $this->assertSame($sharedSubject, $link1->subject);
        $this->assertSame($sharedSubject, $link2->subject);

        $this->assertSame(2, ExternalIdentity::where('subject', $sharedSubject)->count());
    }

    /**
     * 26. Successful creation emits exactly one existing IdentityLinkAuditEvent ACTION_LINKED.
     */
    public function test_successful_creation_emits_exactly_one_existing_audit_event(): void
    {
        $user = User::factory()->create();

        Event::fake([IdentityLinkAuditEvent::class]);

        $action = new LinkExternalIdentity();
        $link = $action->execute($user->id, self::FAKE_SUBJECT);

        $expectedFingerprint = hash('sha256', self::FAKE_ISSUER . "\0" . self::FAKE_SUBJECT);

        Event::assertDispatchedTimes(IdentityLinkAuditEvent::class, 1);
        Event::assertDispatched(IdentityLinkAuditEvent::class, function (IdentityLinkAuditEvent $event) use ($link, $user, $expectedFingerprint) {
            return $event->action === IdentityLinkAuditEvent::ACTION_LINKED
                && $event->externalIdentityId === $link->id
                && $event->userId === $user->id
                && $event->trustKeyFingerprint === $expectedFingerprint
                && $event->actorType === 'system';
        });
    }

    /**
     * 27. Exception messages do not contain raw issuer or raw subject.
     */
    public function test_exception_messages_do_not_contain_raw_issuer_or_raw_subject(): void
    {
        $sensitiveIssuer = 'https://sensitive-secret-keycloak.internal.test/realms/secret-realm';
        $sensitiveSubject = 'sensitive-raw-subject-secret-token-xyz';

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $action = new LinkExternalIdentity();

        // 1) Target user not found
        try {
            config(['oidc.issuer' => $sensitiveIssuer]);
            $action->execute(888888, $sensitiveSubject);
            $this->fail('Expected exception');
        } catch (IdentityLinkException $e) {
            $this->assertStringNotContainsString($sensitiveIssuer, $e->getMessage());
            $this->assertStringNotContainsString($sensitiveSubject, $e->getMessage());
        }

        // 2) Invalid issuer
        try {
            config(['oidc.issuer' => 'https://invalid sensitive url ' . $sensitiveIssuer]);
            $action->execute($userA->id, $sensitiveSubject);
            $this->fail('Expected exception');
        } catch (IdentityLinkException $e) {
            $this->assertStringNotContainsString($sensitiveIssuer, $e->getMessage());
            $this->assertStringNotContainsString($sensitiveSubject, $e->getMessage());
        }

        // 3) Invalid subject
        try {
            config(['oidc.issuer' => $sensitiveIssuer]);
            $action->execute($userA->id, '   ');
            $this->fail('Expected exception');
        } catch (IdentityLinkException $e) {
            $this->assertStringNotContainsString($sensitiveIssuer, $e->getMessage());
            $this->assertStringNotContainsString($sensitiveSubject, $e->getMessage());
        }

        // 4) Trust key conflict
        config(['oidc.issuer' => $sensitiveIssuer]);
        $action->execute($userA->id, $sensitiveSubject);

        try {
            $action->execute($userB->id, $sensitiveSubject);
            $this->fail('Expected exception');
        } catch (IdentityLinkException $e) {
            $this->assertStringNotContainsString($sensitiveIssuer, $e->getMessage());
            $this->assertStringNotContainsString($sensitiveSubject, $e->getMessage());
        }
    }

    /**
     * 28. No local User fields, role, employee_id, or password are mutated.
     */
    public function test_no_local_user_fields_role_employee_id_or_password_are_mutated(): void
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'original@example.test',
            'password' => bcrypt('secret-password-123'),
            'employee_id' => '0',
        ]);

        $attributesBefore = $user->getAttributes();

        $action = new LinkExternalIdentity();
        $action->execute($user->id, self::FAKE_SUBJECT);

        $userAfter = $user->fresh();
        $attributesAfter = $userAfter->getAttributes();

        $this->assertSame($attributesBefore['name'], $attributesAfter['name']);
        $this->assertSame($attributesBefore['email'], $attributesAfter['email']);
        $this->assertSame($attributesBefore['password'], $attributesAfter['password']);
        $this->assertSame($attributesBefore['employee_id'], $attributesAfter['employee_id']);
        $this->assertSame($attributesBefore['updated_at'], $attributesAfter['updated_at']);
    }

    /**
     * Strict PHP equality defense against database collation case variants.
     */
    public function test_collation_defense_rejects_case_variant_candidate(): void
    {
        $user = User::factory()->create();

        // Create an existing candidate with different case
        $candidate = new ExternalIdentity([
            'id' => 999,
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => self::FAKE_ISSUER,
            'subject' => 'CASE-VARIANT-SUBJECT',
            'email_at_link' => null,
            'linked_at' => now(),
        ]);

        $action = new class($candidate) extends LinkExternalIdentity {
            public function __construct(private ExternalIdentity $candidate)
            {
            }

            protected function findExternalIdentity(string $issuer, string $subject): ?ExternalIdentity
            {
                return $this->candidate;
            }
        };

        try {
            $action->execute($user->id, 'case-variant-subject');
            $this->fail('Expected IdentityLinkException was not thrown for case-variant candidate.');
        } catch (IdentityLinkException $e) {
            $this->assertSame('trust_key_conflict', $e->getReason());
        }
    }
}
