<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Models\ExternalIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExternalIdentityPersistenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Prove a User can have an ExternalIdentity.
     */
    public function test_user_can_have_an_external_identity(): void
    {
        $user = User::factory()->create();

        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => '0535359a-df62-4217-a083-d58e3ca36506',
            'email_at_link' => 'user@reltroner.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        $this->assertDatabaseHas('external_identities', [
            'id' => $identity->id,
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => '0535359a-df62-4217-a083-d58e3ca36506',
            'email_at_link' => 'user@reltroner.com',
        ]);

        $this->assertInstanceOf(ExternalIdentity::class, $identity);
        $this->assertInstanceOf(Carbon::class, $identity->linked_at);
        $this->assertNull($identity->last_login_at);
    }

    /**
     * 2. Prove User::externalIdentities relationship resolves correctly.
     */
    public function test_user_external_identities_relationship_resolves_correctly(): void
    {
        $user = User::factory()->create();

        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'reltroner-sub-100',
            'email_at_link' => 'employee@reltroner.com',
            'linked_at' => now(),
        ]);

        $this->assertTrue($user->externalIdentities->contains($identity));
        $this->assertCount(1, $user->externalIdentities);
        $this->assertInstanceOf(ExternalIdentity::class, $user->externalIdentities->first());
        $this->assertSame('reltroner-sub-100', $user->externalIdentities->first()->subject);
    }

    /**
     * 3. Prove ExternalIdentity::user relationship resolves correctly.
     */
    public function test_external_identity_user_relationship_resolves_correctly(): void
    {
        $user = User::factory()->create();

        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'reltroner-sub-200',
            'email_at_link' => 'employee@reltroner.com',
            'linked_at' => now(),
        ]);

        $this->assertInstanceOf(User::class, $identity->user);
        $this->assertSame($user->id, $identity->user->id);
    }

    /**
     * 4. Prove duplicate (issuer, subject) is rejected by the database,
     *    even when attempting to attach it to another User.
     */
    public function test_duplicate_issuer_and_subject_is_rejected_by_database(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        ExternalIdentity::create([
            'user_id' => $userA->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'unique-sub-uuid',
            'email_at_link' => 'userA@reltroner.com',
            'linked_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        ExternalIdentity::create([
            'user_id' => $userB->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'unique-sub-uuid',
            'email_at_link' => 'userB@reltroner.com',
            'linked_at' => now(),
        ]);
    }

    /**
     * 5. Prove the same subject under a DIFFERENT issuer is allowed.
     *    This verifies subject is namespaced by issuer.
     */
    public function test_same_subject_under_different_issuer_is_allowed(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $identityA = ExternalIdentity::create([
            'user_id' => $userA->id,
            'provider' => 'keycloak-prod',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'shared-sub-uuid',
            'email_at_link' => 'userA@reltroner.com',
            'linked_at' => now(),
        ]);

        $identityB = ExternalIdentity::create([
            'user_id' => $userB->id,
            'provider' => 'keycloak-demo',
            'issuer' => 'https://auth-demo.reltroner.com/realms/reltroner',
            'subject' => 'shared-sub-uuid',
            'email_at_link' => 'userB@reltroner.com',
            'linked_at' => now(),
        ]);

        $this->assertDatabaseHas('external_identities', [
            'id' => $identityA->id,
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'shared-sub-uuid',
        ]);

        $this->assertDatabaseHas('external_identities', [
            'id' => $identityB->id,
            'issuer' => 'https://auth-demo.reltroner.com/realms/reltroner',
            'subject' => 'shared-sub-uuid',
        ]);
    }

    /**
     * 6. Prove one User may have multiple different external identities.
     */
    public function test_one_user_may_have_multiple_different_external_identities(): void
    {
        $user = User::factory()->create();

        $identity1 = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-primary',
            'email_at_link' => 'user@reltroner.com',
            'linked_at' => now(),
        ]);

        $identity2 = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'issuer' => 'https://accounts.google.com',
            'subject' => 'sub-secondary',
            'email_at_link' => 'user@gmail.com',
            'linked_at' => now(),
        ]);

        $user->refresh();

        $this->assertCount(2, $user->externalIdentities);
        $this->assertTrue($user->externalIdentities->contains($identity1));
        $this->assertTrue($user->externalIdentities->contains($identity2));
    }

    /**
     * 7. Prove deleting the local User deletes its ExternalIdentity rows
     *    through database cascade.
     */
    public function test_deleting_local_user_deletes_its_external_identity_rows_through_database_cascade(): void
    {
        $user = User::factory()->create();

        $identity1 = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://auth.reltroner.com/realms/reltroner',
            'subject' => 'sub-cascade-1',
            'email_at_link' => 'user@reltroner.com',
            'linked_at' => now(),
        ]);

        $identity2 = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'issuer' => 'https://accounts.google.com',
            'subject' => 'sub-cascade-2',
            'email_at_link' => 'user@gmail.com',
            'linked_at' => now(),
        ]);

        $user->delete();

        $this->assertDatabaseMissing('external_identities', ['id' => $identity1->id]);
        $this->assertDatabaseMissing('external_identities', ['id' => $identity2->id]);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}
