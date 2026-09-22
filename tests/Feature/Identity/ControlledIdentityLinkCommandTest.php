<?php

namespace Tests\Feature\Identity;

use App\Console\Commands\LinkKeycloakIdentity;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Identity\Events\IdentityLinkAuditEvent;
use App\Modules\Identity\Linking\LinkExternalIdentity;
use App\Modules\Identity\Models\ExternalIdentity;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class ControlledIdentityLinkCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_ISSUER = 'https://fake-operator-idp.test/realms/op-realm';
    private const FAKE_SUBJECT = 'fake-operator-sub-001';

    protected function setUp(): void
    {
        parent::setUp();
        config(['oidc.issuer' => self::FAKE_ISSUER]);
    }

    /**
     * 1. Command is registered under identity:link-keycloak.
     */
    public function test_command_is_registered_under_identity_link_keycloak(): void
    {
        $allCommands = Artisan::all();
        $this->assertArrayHasKey('identity:link-keycloak', $allCommands);
        $this->assertInstanceOf(LinkKeycloakIdentity::class, $allCommands['identity:link-keycloak']);
    }

    /**
     * 2-9. Valid existing user + subject creates link, exits 0, outputs safe fields, no leaks.
     */
    public function test_valid_existing_user_and_subject_creates_link_with_safe_output(): void
    {
        $user = User::factory()->create(['email' => 'operator-local@example.test']);
        $expectedFingerprint = hash('sha256', self::FAKE_ISSUER . "\0" . self::FAKE_SUBJECT);

        $this->artisan('identity:link-keycloak', [
            'user_id' => $user->id,
            'subject' => self::FAKE_SUBJECT,
        ])
            ->expectsOutput('result=linked')
            ->expectsOutput("user_id={$user->id}")
            ->expectsOutput("trust_key_fingerprint={$expectedFingerprint}")
            ->expectsOutput('dry_run=false')
            ->assertExitCode(Command::SUCCESS);

        // 2. Exactly one ExternalIdentity created
        $this->assertSame(1, ExternalIdentity::where('issuer', self::FAKE_ISSUER)->where('subject', self::FAKE_SUBJECT)->count());
        $identity = ExternalIdentity::where('issuer', self::FAKE_ISSUER)->where('subject', self::FAKE_SUBJECT)->first();
        $this->assertSame($user->id, (int) $identity->user_id);

        // 7-9. Output does NOT contain raw subject, raw issuer, or user email
        $output = Artisan::output();
        $this->assertStringNotContainsString(self::FAKE_SUBJECT, $output);
        $this->assertStringNotContainsString(self::FAKE_ISSUER, $output);
        $this->assertStringNotContainsString('operator-local@example.test', $output);
    }

    /**
     * 10. Successful creation emits exactly one existing IdentityLinkAuditEvent ACTION_LINKED.
     */
    public function test_successful_creation_emits_exactly_one_existing_audit_event(): void
    {
        $user = User::factory()->create();
        Event::fake([IdentityLinkAuditEvent::class]);

        $this->artisan('identity:link-keycloak', [
            'user_id' => $user->id,
            'subject' => self::FAKE_SUBJECT,
        ])->assertExitCode(Command::SUCCESS);

        Event::assertDispatchedTimes(IdentityLinkAuditEvent::class, 1);
        Event::assertDispatched(IdentityLinkAuditEvent::class, function (IdentityLinkAuditEvent $event) use ($user) {
            return $event->action === IdentityLinkAuditEvent::ACTION_LINKED
                && $event->userId === $user->id;
        });
    }

    /**
     * 11-14. Running same command twice for same user/trust key is idempotent.
     */
    public function test_running_same_command_twice_for_same_user_is_idempotent(): void
    {
        $user = User::factory()->create();
        Event::fake([IdentityLinkAuditEvent::class]);

        // First invocation
        $this->artisan('identity:link-keycloak', [
            'user_id' => $user->id,
            'subject' => self::FAKE_SUBJECT,
        ])
            ->expectsOutput('result=linked')
            ->assertExitCode(Command::SUCCESS);

        Event::assertDispatchedTimes(IdentityLinkAuditEvent::class, 1);

        // 11-14. Second invocation
        $this->artisan('identity:link-keycloak', [
            'user_id' => $user->id,
            'subject' => self::FAKE_SUBJECT,
        ])
            ->expectsOutput('result=already_linked')
            ->expectsOutput("user_id={$user->id}")
            ->expectsOutput('dry_run=false')
            ->assertExitCode(Command::SUCCESS);

        // 12. Creates no additional ExternalIdentity
        $this->assertSame(1, ExternalIdentity::where('issuer', self::FAKE_ISSUER)->where('subject', self::FAKE_SUBJECT)->count());

        // Zero additional audit events
        Event::assertDispatchedTimes(IdentityLinkAuditEvent::class, 1);
    }

    /**
     * 15-18. Different user + same trust key returns failure, reason=trust_key_conflict.
     */
    public function test_different_user_same_trust_key_fails_closed(): void
    {
        $userA = User::factory()->create(['email' => 'user-a@example.test']);
        $userB = User::factory()->create(['email' => 'user-b@example.test']);

        // Link User A
        $this->artisan('identity:link-keycloak', [
            'user_id' => $userA->id,
            'subject' => self::FAKE_SUBJECT,
        ])->assertExitCode(Command::SUCCESS);

        // 15-17. Link User B with same subject fails
        $this->artisan('identity:link-keycloak', [
            'user_id' => $userB->id,
            'subject' => self::FAKE_SUBJECT,
        ])
            ->expectsOutput('result=failed')
            ->expectsOutput('reason=trust_key_conflict')
            ->assertExitCode(Command::FAILURE);

        $output = Artisan::output();
        $this->assertStringNotContainsString(self::FAKE_SUBJECT, $output);
        $this->assertStringNotContainsString(self::FAKE_ISSUER, $output);
        $this->assertStringNotContainsString('user-a@example.test', $output);
        $this->assertStringNotContainsString('user-b@example.test', $output);

        // 18. Conflict preserves original identity ownership
        $persisted = ExternalIdentity::where('issuer', self::FAKE_ISSUER)->where('subject', self::FAKE_SUBJECT)->first();
        $this->assertSame($userA->id, (int) $persisted->user_id);
        $this->assertSame(1, ExternalIdentity::where('issuer', self::FAKE_ISSUER)->where('subject', self::FAKE_SUBJECT)->count());
    }

    /**
     * 19. Missing user returns failure with reason=target_user_not_found.
     */
    public function test_missing_user_returns_safe_failure(): void
    {
        $this->artisan('identity:link-keycloak', [
            'user_id' => 999999,
            'subject' => self::FAKE_SUBJECT,
        ])
            ->expectsOutput('result=failed')
            ->expectsOutput('reason=target_user_not_found')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, ExternalIdentity::count());
    }

    /**
     * 20. Invalid configured issuer returns safe failure reason=invalid_issuer.
     */
    public function test_invalid_configured_issuer_returns_safe_failure(): void
    {
        config(['oidc.issuer' => 'not-a-valid-url']);
        $user = User::factory()->create();

        $this->artisan('identity:link-keycloak', [
            'user_id' => $user->id,
            'subject' => self::FAKE_SUBJECT,
        ])
            ->expectsOutput('result=failed')
            ->expectsOutput('reason=invalid_issuer')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, ExternalIdentity::count());
    }

    /**
     * 21. Invalid subject returns safe failure reason=invalid_subject.
     */
    public function test_invalid_subject_returns_safe_failure(): void
    {
        $user = User::factory()->create();

        $this->artisan('identity:link-keycloak', [
            'user_id' => $user->id,
            'subject' => '   ',
        ])
            ->expectsOutput('result=failed')
            ->expectsOutput('reason=invalid_subject')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, ExternalIdentity::count());
    }

    /**
     * 22-23. Invalid user_id values fail safely and create no ExternalIdentity.
     */
    public function test_invalid_user_id_values_fail_safely(): void
    {
        $invalidUserIds = ['0', '-1', 'abc', '1.5'];

        foreach ($invalidUserIds as $invalidId) {
            $this->artisan('identity:link-keycloak', [
                'user_id' => $invalidId,
                'subject' => self::FAKE_SUBJECT,
            ])
                ->expectsOutput('result=failed')
                ->expectsOutput('reason=invalid_user_id')
                ->assertExitCode(Command::FAILURE);
        }

        $this->assertSame(0, ExternalIdentity::count());
    }

    /**
     * 24-31. --dry-run on a new valid mapping exits success with safe output and no persistence.
     */
    public function test_dry_run_new_mapping_does_not_persist_or_emit_events(): void
    {
        $user = User::factory()->create(['email' => 'dryrun@example.test']);
        $user = $user->fresh();
        $expectedFingerprint = hash('sha256', self::FAKE_ISSUER . "\0" . self::FAKE_SUBJECT);

        $userCountBefore = User::count();
        $employeeCountBefore = Employee::count();
        $userAttributesBefore = $user->getAttributes();

        Event::fake([IdentityLinkAuditEvent::class]);

        $this->artisan('identity:link-keycloak', [
            'user_id' => $user->id,
            'subject' => self::FAKE_SUBJECT,
            '--dry-run' => true,
        ])
            ->expectsOutput('result=would_link')
            ->expectsOutput("user_id={$user->id}")
            ->expectsOutput("trust_key_fingerprint={$expectedFingerprint}")
            ->expectsOutput('dry_run=true')
            ->assertExitCode(Command::SUCCESS);

        // 26. Zero persistent ExternalIdentity rows
        $this->assertSame(0, ExternalIdentity::count());

        // 27. Zero linked audit events
        Event::assertDispatchedTimes(IdentityLinkAuditEvent::class, 0);

        // 28. Does not mutate User
        $this->assertSame($userCountBefore, User::count());
        $this->assertSame($userAttributesBefore, $user->fresh()->getAttributes());

        // 29. Does not create Employee
        $this->assertSame($employeeCountBefore, Employee::count());

        // 31. Output does not contain raw issuer or subject
        $output = Artisan::output();
        $this->assertStringNotContainsString(self::FAKE_ISSUER, $output);
        $this->assertStringNotContainsString(self::FAKE_SUBJECT, $output);
        $this->assertStringNotContainsString('dryrun@example.test', $output);
        $this->assertStringNotContainsString('external_identity_id=', $output);
    }

    /**
     * 32-34. --dry-run on an already-existing same mapping outputs already_linked without changing timestamps.
     */
    public function test_dry_run_on_existing_mapping_outputs_already_linked_without_mutation(): void
    {
        $user = User::factory()->create();

        // Create persistent link
        $this->artisan('identity:link-keycloak', [
            'user_id' => $user->id,
            'subject' => self::FAKE_SUBJECT,
        ])->assertExitCode(Command::SUCCESS);

        $existing = ExternalIdentity::first();
        $originalLinkedAt = $existing->linked_at;
        $originalLastLoginAt = $existing->last_login_at;

        // Advance time
        $this->travel(1)->hours();

        // Run dry-run
        $this->artisan('identity:link-keycloak', [
            'user_id' => $user->id,
            'subject' => self::FAKE_SUBJECT,
            '--dry-run' => true,
        ])
            ->expectsOutput('result=already_linked')
            ->expectsOutput("user_id={$user->id}")
            ->expectsOutput("external_identity_id={$existing->id}")
            ->expectsOutput('dry_run=true')
            ->assertExitCode(Command::SUCCESS);

        $fresh = $existing->fresh();
        // 33. Does not change linked_at
        $this->assertEquals($originalLinkedAt->timestamp, $fresh->linked_at->timestamp);
        // 34. Does not change last_login_at
        $this->assertSame($originalLastLoginAt, $fresh->last_login_at);
    }

    /**
     * 35-38. Command definition contains no unauthorized options.
     */
    public function test_command_definition_contains_no_unauthorized_options(): void
    {
        $command = $this->app->make(LinkKeycloakIdentity::class);
        $definition = $command->getDefinition();

        $this->assertFalse($definition->hasOption('force'));
        $this->assertFalse($definition->hasOption('issuer'));
        $this->assertFalse($definition->hasOption('email'));
        $this->assertFalse($definition->hasOption('provider'));

        $this->assertTrue($definition->hasOption('dry-run'));
        $this->assertTrue($definition->hasArgument('user_id'));
        $this->assertTrue($definition->hasArgument('subject'));
    }

    /**
     * 39-40. Unexpected Throwable is converted to unexpected_failure without leaking sensitive info.
     */
    public function test_unexpected_throwable_fails_safely_without_leaks(): void
    {
        $secretErrorMessage = 'SELECT * FROM users WHERE password="super-secret-password-123"';

        $this->app->bind(LinkExternalIdentity::class, function () use ($secretErrorMessage) {
            return new class($secretErrorMessage) extends LinkExternalIdentity {
                public function __construct(private string $secret)
                {
                }

                public function execute(int $userId, string $subject): ExternalIdentity
                {
                    throw new RuntimeException($this->secret);
                }
            };
        });

        $user = User::factory()->create();

        $this->artisan('identity:link-keycloak', [
            'user_id' => $user->id,
            'subject' => self::FAKE_SUBJECT,
        ])
            ->expectsOutput('result=failed')
            ->expectsOutput('reason=unexpected_failure')
            ->assertExitCode(Command::FAILURE);

        $output = Artisan::output();
        $this->assertStringNotContainsString($secretErrorMessage, $output);
        $this->assertStringNotContainsString(self::FAKE_ISSUER, $output);
        $this->assertStringNotContainsString(self::FAKE_SUBJECT, $output);
    }

    public function test_user_id_larger_than_php_integer_range_fails_safely(): void
    {
        config(['oidc.issuer' => self::FAKE_ISSUER]);

        $hugeUserId = str_repeat('9', 100);

        $this->artisan('identity:link-keycloak', [
            'user_id' => $hugeUserId,
            'subject' => self::FAKE_SUBJECT,
        ])
            ->expectsOutput('result=failed')
            ->expectsOutput('reason=invalid_user_id')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, ExternalIdentity::count());
    }

    public function test_dry_run_rolls_back_only_its_own_transaction_level(): void
    {
        config(['oidc.issuer' => self::FAKE_ISSUER]);

        $user = User::factory()->create();

        DB::beginTransaction();

        try {
            $callerTransactionLevel = DB::transactionLevel();

            $this->artisan('identity:link-keycloak', [
                'user_id' => $user->id,
                'subject' => self::FAKE_SUBJECT,
                '--dry-run' => true,
            ])
                ->expectsOutput('result=would_link')
                ->expectsOutput('dry_run=true')
                ->assertExitCode(Command::SUCCESS);

            $this->assertSame(
                $callerTransactionLevel,
                DB::transactionLevel()
            );

            $this->assertSame(0, ExternalIdentity::count());
        } finally {
            DB::rollBack();
        }
    }
}
