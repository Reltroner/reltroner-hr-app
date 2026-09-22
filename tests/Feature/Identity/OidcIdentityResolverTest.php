<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Models\ExternalIdentity;
use App\Modules\Identity\Oidc\Exceptions\OidcCallbackException;
use App\Modules\Identity\Oidc\OidcIdentityResolver;
use App\Modules\Identity\Oidc\ResolvedOidcIdentity;
use App\Modules\Identity\Oidc\ValidatedOidcIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OidcIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    private OidcIdentityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new OidcIdentityResolver();
    }

    private function createApprovedIdentity(
        string $issuer = 'https://auth.reltroner.com/realms/reltroner',
        string $subject = 'sub-user-12345',
        string $email = 'approved@reltroner.com',
        string $provider = 'keycloak'
    ): array {
        $user = User::factory()->create(['email' => $email]);
        $externalIdentity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'issuer' => $issuer,
            'subject' => $subject,
            'email_at_link' => $email,
            'linked_at' => now()->subDay(),
            'last_login_at' => null,
        ]);

        return [$user, $externalIdentity];
    }

    public function test_exact_issuer_and_subject_resolves_expected_user(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'sub-user-12345',
            environmentIdentityClass: 'production_user',
            email: 'approved@reltroner.com'
        );

        $resolved = $this->resolver->resolve($validated);

        $this->assertInstanceOf(ResolvedOidcIdentity::class, $resolved);
        $this->assertSame($user->id, $resolved->user->id);
        $this->assertSame($user->email, $resolved->user->email);
    }

    public function test_returned_resolved_oidc_identity_contains_exact_external_identity_row(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'sub-user-12345',
            environmentIdentityClass: 'production_user'
        );

        $resolved = $this->resolver->resolve($validated);

        $this->assertSame($externalIdentity->id, $resolved->externalIdentity->id);
        $this->assertSame('sub-user-12345', $resolved->externalIdentity->subject);
        $this->assertSame('https://auth.reltroner.com/realms/reltroner', $resolved->externalIdentity->issuer);
    }

    public function test_missing_link_throws_403_unlinked_identity(): void
    {
        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'non-existent-sub',
            environmentIdentityClass: 'production_user'
        );

        try {
            $this->resolver->resolve($validated);
            $this->fail('Expected OidcCallbackException 403 was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('unlinked_identity', $e->getCategory());
            $this->assertSame('Access denied.', $e->getUserFacingMessage());
        }
    }

    public function test_existing_user_with_matching_email_but_no_link_throws_403(): void
    {
        User::factory()->create(['email' => 'unlinked-match@reltroner.com']);

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'unlinked-subject-999',
            environmentIdentityClass: 'production_user',
            email: 'unlinked-match@reltroner.com'
        );

        try {
            $this->resolver->resolve($validated);
            $this->fail('Expected OidcCallbackException 403 was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('unlinked_identity', $e->getCategory());
        }

        $this->assertSame(0, ExternalIdentity::where('subject', 'unlinked-subject-999')->count());
    }

    public function test_changed_token_email_still_resolves_same_approved_user(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity(
            email: 'original@reltroner.com'
        );

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'sub-user-12345',
            environmentIdentityClass: 'production_user',
            email: 'completely-different-email@external.com'
        );

        $resolved = $this->resolver->resolve($validated);

        $this->assertSame($user->id, $resolved->user->id);
    }

    public function test_changed_token_email_does_not_update_user_email(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity(
            email: 'original@reltroner.com'
        );

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'sub-user-12345',
            environmentIdentityClass: 'production_user',
            email: 'new-email@external.com'
        );

        $this->resolver->resolve($validated);

        $user->refresh();
        $this->assertSame('original@reltroner.com', $user->email);
    }

    public function test_changed_token_email_does_not_update_email_at_link(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity(
            email: 'provenance@reltroner.com'
        );

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'sub-user-12345',
            environmentIdentityClass: 'production_user',
            email: 'spoofed@other.com'
        );

        $this->resolver->resolve($validated);

        $externalIdentity->refresh();
        $this->assertSame('provenance@reltroner.com', $externalIdentity->email_at_link);
    }

    public function test_same_subject_under_different_issuer_does_not_resolve(): void
    {
        $this->createApprovedIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'shared-sub-id'
        );

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://other-issuer.com/realms/reltroner',
            subject: 'shared-sub-id',
            environmentIdentityClass: 'production_user'
        );

        try {
            $this->resolver->resolve($validated);
            $this->fail('Expected OidcCallbackException 403 was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('unlinked_identity', $e->getCategory());
        }
    }

    public function test_wrong_subject_under_same_issuer_does_not_resolve(): void
    {
        $this->createApprovedIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'correct-sub-id'
        );

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'wrong-sub-id',
            environmentIdentityClass: 'production_user'
        );

        try {
            $this->resolver->resolve($validated);
            $this->fail('Expected OidcCallbackException 403 was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('unlinked_identity', $e->getCategory());
        }
    }

    public function test_case_variant_subject_does_not_resolve(): void
    {
        $this->createApprovedIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'Subject-ABC'
        );

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'subject-abc',
            environmentIdentityClass: 'production_user'
        );

        try {
            $this->resolver->resolve($validated);
            $this->fail('Expected OidcCallbackException 403 was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('unlinked_identity', $e->getCategory());
        }
    }

    public function test_strict_php_equality_rejects_case_variant_subject_candidate(): void
    {
        $user = User::factory()->create();
        $candidate = new ExternalIdentity([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'Subject-ABC',
            'email_at_link' => $user->email,
            'linked_at' => now(),
        ]);
        $candidate->setRelation('user', $user);

        $resolver = new class($candidate) extends OidcIdentityResolver {
            public function __construct(private ExternalIdentity $candidate)
            {
            }

            protected function findExternalIdentity(ValidatedOidcIdentity $identity): ?ExternalIdentity
            {
                return $this->candidate;
            }
        };

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'subject-abc',
            environmentIdentityClass: 'production_user'
        );

        try {
            $resolver->resolve($validated);
            $this->fail('Expected OidcCallbackException 403 was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('unlinked_identity', $e->getCategory());
            $this->assertSame('Access denied.', $e->getUserFacingMessage());
        }
    }

    public function test_case_variant_issuer_does_not_resolve(): void
    {
        $this->createApprovedIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'exact-sub-123'
        );

        $validated = new ValidatedOidcIdentity(
            issuer: 'HTTPS://AUTH.RELTRONER.COM/REALMS/RELTRONER',
            subject: 'exact-sub-123',
            environmentIdentityClass: 'production_user'
        );

        try {
            $this->resolver->resolve($validated);
            $this->fail('Expected OidcCallbackException 403 was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('unlinked_identity', $e->getCategory());
        }
    }

    public function test_strict_php_equality_rejects_case_variant_issuer_candidate(): void
    {
        $user = User::factory()->create();
        $candidate = new ExternalIdentity([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://AUTH.reltroner.com/realms/reltroner',
            'subject' => 'exact-sub-123',
            'email_at_link' => $user->email,
            'linked_at' => now(),
        ]);
        $candidate->setRelation('user', $user);

        $resolver = new class($candidate) extends OidcIdentityResolver {
            public function __construct(private ExternalIdentity $candidate)
            {
            }

            protected function findExternalIdentity(ValidatedOidcIdentity $identity): ?ExternalIdentity
            {
                return $this->candidate;
            }
        };

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'exact-sub-123',
            environmentIdentityClass: 'production_user'
        );

        try {
            $resolver->resolve($validated);
            $this->fail('Expected OidcCallbackException 403 was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('unlinked_identity', $e->getCategory());
            $this->assertSame('Access denied.', $e->getUserFacingMessage());
        }
    }

    public function test_provider_metadata_mismatch_does_not_prevent_resolution(): void
    {
        // Provider is 'custom_idp_name' in DB, but issuer and subject match exactly
        [$user, $externalIdentity] = $this->createApprovedIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'sub-user-provider-test',
            provider: 'alternate-provider-label'
        );

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'sub-user-provider-test',
            environmentIdentityClass: 'production_user'
        );

        $resolved = $this->resolver->resolve($validated);

        $this->assertSame($user->id, $resolved->user->id);
        $this->assertSame('alternate-provider-label', $resolved->externalIdentity->provider);
    }

    public function test_successful_resolution_does_not_update_last_login_at(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $this->assertNull($externalIdentity->last_login_at);

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'sub-user-12345',
            environmentIdentityClass: 'production_user'
        );

        $this->resolver->resolve($validated);

        $externalIdentity->refresh();
        $this->assertNull($externalIdentity->last_login_at);
    }

    public function test_successful_resolution_does_not_update_linked_at(): void
    {
        [$user, $externalIdentity] = $this->createApprovedIdentity();
        $originalLinkedAt = $externalIdentity->linked_at->toIso8601String();

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'sub-user-12345',
            environmentIdentityClass: 'production_user'
        );

        $this->resolver->resolve($validated);

        $externalIdentity->refresh();
        $this->assertSame($originalLinkedAt, $externalIdentity->linked_at->toIso8601String());
    }

    public function test_successful_resolution_does_not_create_any_new_user(): void
    {
        $this->createApprovedIdentity();
        $userCountBefore = User::count();

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'sub-user-12345',
            environmentIdentityClass: 'production_user'
        );

        $this->resolver->resolve($validated);

        $this->assertSame($userCountBefore, User::count());
    }

    public function test_unlinked_resolution_does_not_create_external_identity(): void
    {
        $identityCountBefore = ExternalIdentity::count();

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'unlinked-new-sub',
            environmentIdentityClass: 'production_user'
        );

        try {
            $this->resolver->resolve($validated);
        } catch (OidcCallbackException) {
        }

        $this->assertSame($identityCountBefore, ExternalIdentity::count());
    }

    public function test_missing_user_relation_throws_500_identity_integrity_error(): void
    {
        $fakeExternalIdentity = new ExternalIdentity([
            'user_id' => 999999,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'orphaned-sub',
            'email_at_link' => 'orphan@reltroner.com',
            'linked_at' => now(),
        ]);
        $fakeExternalIdentity->setRelation('user', null);

        $resolver = new class($fakeExternalIdentity) extends OidcIdentityResolver {
            public function __construct(private ExternalIdentity $fake)
            {
            }

            protected function findExternalIdentity(ValidatedOidcIdentity $identity): ?ExternalIdentity
            {
                return $this->fake;
            }
        };

        $validated = new ValidatedOidcIdentity(
            issuer: 'https://auth.reltroner.com/realms/reltroner',
            subject: 'orphaned-sub',
            environmentIdentityClass: 'production_user'
        );

        try {
            $resolver->resolve($validated);
            $this->fail('Expected OidcCallbackException 500 was not thrown.');
        } catch (OidcCallbackException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertSame('identity_integrity_error', $e->getCategory());
            $this->assertSame('Identity resolution failed.', $e->getUserFacingMessage());
        }
    }
}
