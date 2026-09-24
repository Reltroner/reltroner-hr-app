<?php

namespace Tests\Feature\Identity;

use App\Console\Commands\UnlinkKeycloakIdentity;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Modules\Identity\Events\IdentityLinkAuditEvent;
use App\Modules\Identity\Linking\UnlinkExternalIdentity;
use App\Modules\Identity\Models\ExternalIdentity;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class ControlledIdentityUnlinkCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_ISSUER = 'https://auth.reltroner.com/realms/reltroner';

    private const FAKE_SUBJECT = 'test-unlink-subject-001';

    protected function setUp(): void
    {
        parent::setUp();
        config(['oidc.issuer' => self::FAKE_ISSUER]);
    }

    private function createLinkedTarget(): array
    {
        $user = User::factory()->create(['email' => 'unlink-user@example.test']);
        $dept = Department::create(['name' => 'Engineering', 'status' => 'active']);
        $role = Role::create(['title' => 'Staff']);
        $employee = Employee::create([
            'fullname' => 'Unlink Employee',
            'email' => 'unlink-emp@example.test',
            'phone' => '0812345678',
            'address' => 'Jakarta',
            'birth_date' => '1990-01-01',
            'hire_date' => '2020-01-01',
            'department_id' => $dept->id,
            'role_id' => $role->id,
            'status' => 'active',
            'salary' => 5000000,
        ]);

        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => self::FAKE_ISSUER,
            'subject' => self::FAKE_SUBJECT,
            'email_at_link' => $user->email,
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        $fingerprint = hash('sha256', self::FAKE_ISSUER."\0".self::FAKE_SUBJECT);

        return [$user, $identity, $employee, $fingerprint];
    }

    /**
     * 1. Command is registered under identity:unlink-keycloak.
     */
    public function test_command_is_registered_under_identity_unlink_keycloak(): void
    {
        $allCommands = Artisan::all();
        $this->assertArrayHasKey('identity:unlink-keycloak', $allCommands);
        $this->assertInstanceOf(UnlinkKeycloakIdentity::class, $allCommands['identity:unlink-keycloak']);
    }

    /**
     * 2. Invalid external_identity_id fails safely.
     */
    public function test_invalid_external_identity_id_fails_safely(): void
    {
        Log::spy();

        $invalidIds = ['0', '-5', 'abc', '1.2'];

        foreach ($invalidIds as $invalidId) {
            $this->artisan('identity:unlink-keycloak', [
                'external_identity_id' => $invalidId,
                'user_id' => 1,
            ])
                ->expectsOutput('result=failed')
                ->expectsOutput('reason=invalid_external_identity_id')
                ->assertExitCode(Command::FAILURE);
        }

        Log::shouldHaveReceived('warning')
            ->with(
                'identity.unlink.failed',
                \Mockery::on(function (array $context) {
                    return $context['reason'] === 'invalid_external_identity_id';
                })
            );
    }

    /**
     * 3. Invalid user_id fails safely.
     */
    public function test_invalid_user_id_fails_safely(): void
    {
        Log::spy();

        $invalidUserIds = ['0', '-10', 'xyz', '2.5'];

        foreach ($invalidUserIds as $invalidUserId) {
            $this->artisan('identity:unlink-keycloak', [
                'external_identity_id' => 1,
                'user_id' => $invalidUserId,
            ])
                ->expectsOutput('result=failed')
                ->expectsOutput('reason=invalid_user_id')
                ->assertExitCode(Command::FAILURE);
        }

        Log::shouldHaveReceived('warning')
            ->with(
                'identity.unlink.failed',
                \Mockery::on(function (array $context) {
                    return $context['reason'] === 'invalid_user_id';
                })
            );
    }

    /**
     * 4. Missing target identity fails closed (fail-closed absent target rule).
     */
    public function test_missing_identity_fails_closed(): void
    {
        Log::spy();

        $this->artisan('identity:unlink-keycloak', [
            'external_identity_id' => 99999,
            'user_id' => 1,
            '--dry-run' => true,
        ])
            ->expectsOutput('result=failed')
            ->expectsOutput('reason=target_identity_not_found')
            ->assertExitCode(Command::FAILURE);

        Log::shouldHaveReceived('warning')
            ->with(
                'identity.unlink.failed',
                \Mockery::on(function (array $context) {
                    return $context['operation'] === 'unlink'
                        && $context['reason'] === 'target_identity_not_found'
                        && $context['user_id'] === 1
                        && $context['dry_run'] === true;
                })
            )
            ->once();
    }

    /**
     * 5. User mismatch fails safely.
     */
    public function test_user_mismatch_fails_safely(): void
    {
        [$user, $identity] = $this->createLinkedTarget();
        $otherUser = User::factory()->create();

        Log::spy();

        $this->artisan('identity:unlink-keycloak', [
            'external_identity_id' => $identity->id,
            'user_id' => $otherUser->id,
            '--dry-run' => true,
        ])
            ->expectsOutput('result=failed')
            ->expectsOutput('reason=user_mismatch')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(1, ExternalIdentity::where('id', $identity->id)->count());
    }

    /**
     * 6. Provider mismatch fails safely.
     */
    public function test_provider_mismatch_fails_safely(): void
    {
        $user = User::factory()->create();
        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'issuer' => self::FAKE_ISSUER,
            'subject' => 'google-sub-001',
            'email_at_link' => $user->email,
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        $this->artisan('identity:unlink-keycloak', [
            'external_identity_id' => $identity->id,
            'user_id' => $user->id,
            '--dry-run' => true,
        ])
            ->expectsOutput('result=failed')
            ->expectsOutput('reason=provider_mismatch')
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * 7. Issuer mismatch fails safely.
     */
    public function test_issuer_mismatch_fails_safely(): void
    {
        $user = User::factory()->create();
        $identity = ExternalIdentity::create([
            'user_id' => $user->id,
            'provider' => 'keycloak',
            'issuer' => 'https://other-idp.com',
            'subject' => 'sub-mismatch',
            'email_at_link' => $user->email,
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        $this->artisan('identity:unlink-keycloak', [
            'external_identity_id' => $identity->id,
            'user_id' => $user->id,
            '--dry-run' => true,
        ])
            ->expectsOutput('result=failed')
            ->expectsOutput('reason=issuer_mismatch')
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * 8. Dry-run outputs would_unlink, performs NO delete, and emits NO audit event.
     */
    public function test_dry_run_outputs_would_unlink_and_performs_no_mutation_or_audit(): void
    {
        [$user, $identity, $employee, $fingerprint] = $this->createLinkedTarget();

        Event::fake([IdentityLinkAuditEvent::class]);

        $this->artisan('identity:unlink-keycloak', [
            'external_identity_id' => $identity->id,
            'user_id' => $user->id,
            '--dry-run' => true,
        ])
            ->expectsOutput('result=would_unlink')
            ->expectsOutput("user_id={$user->id}")
            ->expectsOutput("external_identity_id={$identity->id}")
            ->expectsOutput("trust_key_fingerprint={$fingerprint}")
            ->expectsOutput('dry_run=true')
            ->assertExitCode(Command::SUCCESS);

        // Target remains in database
        $this->assertDatabaseHas('external_identities', ['id' => $identity->id]);

        // Zero audit events dispatched
        Event::assertNotDispatched(IdentityLinkAuditEvent::class);

        // Safe output
        $output = Artisan::output();
        $this->assertStringNotContainsString(self::FAKE_ISSUER, $output);
        $this->assertStringNotContainsString(self::FAKE_SUBJECT, $output);
    }

    /**
     * 9. Real execution without confirmation fails with confirmation_required.
     */
    public function test_real_execution_without_confirmation_fails(): void
    {
        [$user, $identity] = $this->createLinkedTarget();

        $this->artisan('identity:unlink-keycloak', [
            'external_identity_id' => $identity->id,
            'user_id' => $user->id,
        ])
            ->expectsOutput('result=failed')
            ->expectsOutput('reason=confirmation_required')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseHas('external_identities', ['id' => $identity->id]);
    }

    /**
     * 10. Malformed confirmation fails with invalid_confirmation.
     */
    public function test_malformed_confirmation_fails(): void
    {
        [$user, $identity] = $this->createLinkedTarget();

        $malformedConfirmations = [
            'short-hash',
            str_repeat('G', 64), // invalid hex characters
            str_repeat('a', 63), // too short
            str_repeat('a', 65), // too long
        ];

        foreach ($malformedConfirmations as $badConfirmation) {
            $this->artisan('identity:unlink-keycloak', [
                'external_identity_id' => $identity->id,
                'user_id' => $user->id,
                '--confirm-fingerprint' => $badConfirmation,
            ])
                ->expectsOutput('result=failed')
                ->expectsOutput('reason=invalid_confirmation')
                ->assertExitCode(Command::FAILURE);

            $this->assertDatabaseHas('external_identities', ['id' => $identity->id]);
        }
    }

    /**
     * 11. Confirmation mismatch fails with confirmation_mismatch.
     */
    public function test_confirmation_mismatch_fails(): void
    {
        [$user, $identity] = $this->createLinkedTarget();

        $wrongFingerprint = hash('sha256', 'https://wrong-issuer.com'."\0".'wrong-subject');

        $this->artisan('identity:unlink-keycloak', [
            'external_identity_id' => $identity->id,
            'user_id' => $user->id,
            '--confirm-fingerprint' => $wrongFingerprint,
        ])
            ->expectsOutput('result=failed')
            ->expectsOutput('reason=confirmation_mismatch')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseHas('external_identities', ['id' => $identity->id]);
    }

    /**
     * 12. Correct confirmation deletes target, leaves User, Employee, and unrelated identity untouched.
     */
    public function test_correct_confirmation_deletes_target_and_preserves_unrelated_entities(): void
    {
        [$user, $identity, $employee, $fingerprint] = $this->createLinkedTarget();

        $unrelatedUser = User::factory()->create();
        $unrelatedIdentity = ExternalIdentity::create([
            'user_id' => $unrelatedUser->id,
            'provider' => 'keycloak',
            'issuer' => self::FAKE_ISSUER,
            'subject' => 'unrelated-subject-999',
            'email_at_link' => $unrelatedUser->email,
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        $userCountBefore = User::count();
        $employeeCountBefore = Employee::count();

        Event::fake([IdentityLinkAuditEvent::class]);

        $this->artisan('identity:unlink-keycloak', [
            'external_identity_id' => $identity->id,
            'user_id' => $user->id,
            '--confirm-fingerprint' => $fingerprint,
        ])
            ->expectsOutput('result=unlinked')
            ->expectsOutput("user_id={$user->id}")
            ->expectsOutput("external_identity_id={$identity->id}")
            ->expectsOutput("trust_key_fingerprint={$fingerprint}")
            ->expectsOutput('dry_run=false')
            ->assertExitCode(Command::SUCCESS);

        // Exactly one ExternalIdentity deleted
        $this->assertDatabaseMissing('external_identities', ['id' => $identity->id]);
        $this->assertDatabaseHas('external_identities', ['id' => $unrelatedIdentity->id]);

        // User and Employee remain untouched
        $this->assertSame($userCountBefore, User::count());
        $this->assertSame($employeeCountBefore, Employee::count());
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('employees', ['id' => $employee->id]);

        // Output never leaks raw issuer or subject
        $output = Artisan::output();
        $this->assertStringNotContainsString(self::FAKE_ISSUER, $output);
        $this->assertStringNotContainsString(self::FAKE_SUBJECT, $output);

        // Dispatches exactly one unlinked audit event
        Event::assertDispatchedTimes(IdentityLinkAuditEvent::class, 1);
        Event::assertDispatched(IdentityLinkAuditEvent::class, function (IdentityLinkAuditEvent $event) use ($identity, $user, $fingerprint) {
            return $event->action === IdentityLinkAuditEvent::ACTION_UNLINKED
                && $event->externalIdentityId === $identity->id
                && $event->userId === $user->id
                && $event->trustKeyFingerprint === $fingerprint;
        });
    }

    /**
     * 13. Rollback emits no unlinked audit event.
     */
    public function test_rollback_emits_no_unlinked_audit_event(): void
    {
        [$user, $identity, $employee, $fingerprint] = $this->createLinkedTarget();

        $this->app->bind(UnlinkExternalIdentity::class, function () {
            return new class extends UnlinkExternalIdentity
            {
                public function execute(int $externalIdentityId, int $userId, ?string $confirmFingerprint): array
                {
                    DB::transaction(function () use ($externalIdentityId) {
                        $target = ExternalIdentity::find($externalIdentityId);
                        $target->delete();

                        throw new RuntimeException('Simulated database crash during unlink');
                    });

                    return [];
                }
            };
        });

        Event::fake([IdentityLinkAuditEvent::class]);

        $this->artisan('identity:unlink-keycloak', [
            'external_identity_id' => $identity->id,
            'user_id' => $user->id,
            '--confirm-fingerprint' => $fingerprint,
        ])
            ->expectsOutput('result=failed')
            ->expectsOutput('reason=unexpected_failure')
            ->assertExitCode(Command::FAILURE);

        Event::assertNotDispatched(IdentityLinkAuditEvent::class);
    }

    /**
     * 14. Command definition has no --force or unauthorized options.
     */
    public function test_command_definition_has_no_force_option(): void
    {
        $command = $this->app->make(UnlinkKeycloakIdentity::class);
        $definition = $command->getDefinition();

        $this->assertFalse($definition->hasOption('force'));
        $this->assertFalse($definition->hasOption('subject'));
        $this->assertFalse($definition->hasArgument('subject'));

        $this->assertTrue($definition->hasOption('confirm-fingerprint'));
        $this->assertTrue($definition->hasOption('dry-run'));
        $this->assertTrue($definition->hasArgument('external_identity_id'));
        $this->assertTrue($definition->hasArgument('user_id'));
    }

    /**
     * 15. Delete veto keeps row in database, emits no unlink lifecycle audit, and fails command (C3).
     */
    public function test_delete_veto_keeps_row_emits_no_audit_and_fails_command(): void
    {
        [$user, $identity, $employee, $fingerprint] = $this->createLinkedTarget();

        ExternalIdentity::deleting(function () {
            return false;
        });

        Event::fake([IdentityLinkAuditEvent::class]);
        Log::spy();

        $this->artisan('identity:unlink-keycloak', [
            'external_identity_id' => $identity->id,
            'user_id' => $user->id,
            '--confirm-fingerprint' => $fingerprint,
        ])
            ->expectsOutput('result=failed')
            ->expectsOutput('reason=delete_failed')
            ->assertExitCode(Command::FAILURE);

        // Row must remain in database
        $this->assertDatabaseHas('external_identities', ['id' => $identity->id]);

        // Zero unlink lifecycle audit events dispatched
        Event::assertNotDispatched(IdentityLinkAuditEvent::class);

        // Failure telemetry emitted
        Log::shouldHaveReceived('warning')
            ->with(
                'identity.unlink.failed',
                \Mockery::on(function (array $context) use ($user, $fingerprint) {
                    return $context['operation'] === 'unlink'
                        && $context['reason'] === 'delete_failed'
                        && $context['user_id'] === $user->id
                        && $context['trust_key_fingerprint'] === $fingerprint
                        && $context['dry_run'] === false;
                })
            )
            ->once();
    }

    /**
     * 16. Telemetry never reports operator-supplied confirmation fingerprint when mismatched (C4).
     */
    public function test_telemetry_never_reports_operator_supplied_confirmation_fingerprint_when_mismatched(): void
    {
        [$user, $identity, $employee, $actualFingerprint] = $this->createLinkedTarget();

        $operatorWrongFingerprint = str_repeat('e', 64);

        Log::spy();

        $this->artisan('identity:unlink-keycloak', [
            'external_identity_id' => $identity->id,
            'user_id' => $user->id,
            '--confirm-fingerprint' => $operatorWrongFingerprint,
        ])
            ->expectsOutput('result=failed')
            ->expectsOutput('reason=confirmation_mismatch')
            ->assertExitCode(Command::FAILURE);

        Log::shouldHaveReceived('warning')
            ->with(
                'identity.unlink.failed',
                \Mockery::on(function (array $context) use ($user, $actualFingerprint, $operatorWrongFingerprint) {
                    $serialized = json_encode($context);

                    return $context['operation'] === 'unlink'
                        && $context['reason'] === 'confirmation_mismatch'
                        && $context['user_id'] === $user->id
                        && $context['trust_key_fingerprint'] === $actualFingerprint
                        && $context['trust_key_fingerprint'] !== $operatorWrongFingerprint
                        && ! str_contains($serialized, $operatorWrongFingerprint);
                })
            )
            ->once();
    }

    /**
     * 17. Telemetry reports null fingerprint when target identity was not authoritatively established (C4).
     */
    public function test_telemetry_reports_null_fingerprint_when_target_not_authoritatively_established(): void
    {
        $operatorFingerprint = str_repeat('f', 64);

        Log::spy();

        $this->artisan('identity:unlink-keycloak', [
            'external_identity_id' => 999999,
            'user_id' => 1,
            '--confirm-fingerprint' => $operatorFingerprint,
        ])
            ->expectsOutput('result=failed')
            ->expectsOutput('reason=target_identity_not_found')
            ->assertExitCode(Command::FAILURE);

        Log::shouldHaveReceived('warning')
            ->with(
                'identity.unlink.failed',
                \Mockery::on(function (array $context) use ($operatorFingerprint) {
                    $serialized = json_encode($context);

                    return $context['operation'] === 'unlink'
                        && $context['reason'] === 'target_identity_not_found'
                        && $context['trust_key_fingerprint'] === null
                        && ! str_contains($serialized, $operatorFingerprint);
                })
            )
            ->once();
    }
}
