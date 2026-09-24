<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Events\IdentityLinkAuditEvent;
use App\Modules\Identity\Linking\LinkExternalIdentity;
use App\Modules\Identity\Models\ExternalIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class IdentityLinkFailureTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_ISSUER = 'https://auth.reltroner.com/realms/reltroner';

    private const FAKE_SUBJECT = 'test-sub-telemetry-01';

    protected function setUp(): void
    {
        parent::setUp();
        config(['oidc.issuer' => self::FAKE_ISSUER]);
    }

    /**
     * 1. Invalid user_id emits identity.link.failed warning with safe context.
     */
    public function test_invalid_user_id_emits_failure_telemetry(): void
    {
        Log::spy();

        $this->artisan('identity:link-keycloak', [
            'user_id' => 'abc',
            'subject' => self::FAKE_SUBJECT,
        ])->assertExitCode(1);

        Log::shouldHaveReceived('warning')
            ->with(
                'identity.link.failed',
                \Mockery::on(function (array $context) {
                    return $context['operation'] === 'link'
                        && $context['reason'] === 'invalid_user_id'
                        && $context['user_id'] === null
                        && $context['trust_key_fingerprint'] === null
                        && $context['dry_run'] === false
                        && $context['actor_type'] === 'system'
                        && ! array_key_exists('exception_class', $context);
                })
            )
            ->once();
    }

    /**
     * 2. Target user not found emits identity.link.failed warning with computed fingerprint.
     */
    public function test_target_user_not_found_emits_failure_telemetry(): void
    {
        Log::spy();

        $expectedFingerprint = hash('sha256', self::FAKE_ISSUER."\0".self::FAKE_SUBJECT);

        $this->artisan('identity:link-keycloak', [
            'user_id' => 999999,
            'subject' => self::FAKE_SUBJECT,
        ])->assertExitCode(1);

        Log::shouldHaveReceived('warning')
            ->with(
                'identity.link.failed',
                \Mockery::on(function (array $context) use ($expectedFingerprint) {
                    return $context['operation'] === 'link'
                        && $context['reason'] === 'target_user_not_found'
                        && $context['user_id'] === 999999
                        && $context['trust_key_fingerprint'] === $expectedFingerprint
                        && $context['dry_run'] === false
                        && $context['actor_type'] === 'system'
                        && ! array_key_exists('exception_class', $context);
                })
            )
            ->once();
    }

    /**
     * 3. Invalid issuer emits identity.link.failed with null fingerprint.
     */
    public function test_invalid_issuer_emits_failure_telemetry(): void
    {
        config(['oidc.issuer' => 'invalid-url']);
        Log::spy();

        $user = User::factory()->create();

        $this->artisan('identity:link-keycloak', [
            'user_id' => $user->id,
            'subject' => self::FAKE_SUBJECT,
        ])->assertExitCode(1);

        Log::shouldHaveReceived('warning')
            ->with(
                'identity.link.failed',
                \Mockery::on(function (array $context) use ($user) {
                    return $context['operation'] === 'link'
                        && $context['reason'] === 'invalid_issuer'
                        && $context['user_id'] === $user->id
                        && $context['trust_key_fingerprint'] === null
                        && $context['dry_run'] === false
                        && $context['actor_type'] === 'system'
                        && ! array_key_exists('exception_class', $context);
                })
            )
            ->once();
    }

    /**
     * 4. Invalid subject emits identity.link.failed with null fingerprint.
     */
    public function test_invalid_subject_emits_failure_telemetry(): void
    {
        Log::spy();

        $user = User::factory()->create();

        $this->artisan('identity:link-keycloak', [
            'user_id' => $user->id,
            'subject' => '   ',
        ])->assertExitCode(1);

        Log::shouldHaveReceived('warning')
            ->with(
                'identity.link.failed',
                \Mockery::on(function (array $context) use ($user) {
                    return $context['operation'] === 'link'
                        && $context['reason'] === 'invalid_subject'
                        && $context['user_id'] === $user->id
                        && $context['trust_key_fingerprint'] === null
                        && $context['dry_run'] === false
                        && $context['actor_type'] === 'system'
                        && ! array_key_exists('exception_class', $context);
                })
            )
            ->once();
    }

    /**
     * 5. Trust key conflict emits identity.link.failed with fingerprint.
     */
    public function test_trust_key_conflict_emits_failure_telemetry(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Create link for userA
        ExternalIdentity::create([
            'user_id' => $userA->id,
            'provider' => 'keycloak',
            'issuer' => self::FAKE_ISSUER,
            'subject' => self::FAKE_SUBJECT,
            'email_at_link' => 'a@example.com',
            'linked_at' => now(),
            'last_login_at' => null,
        ]);

        Log::spy();

        $expectedFingerprint = hash('sha256', self::FAKE_ISSUER."\0".self::FAKE_SUBJECT);

        $this->artisan('identity:link-keycloak', [
            'user_id' => $userB->id,
            'subject' => self::FAKE_SUBJECT,
        ])->assertExitCode(1);

        Log::shouldHaveReceived('warning')
            ->with(
                'identity.link.failed',
                \Mockery::on(function (array $context) use ($userB, $expectedFingerprint) {
                    return $context['operation'] === 'link'
                        && $context['reason'] === 'trust_key_conflict'
                        && $context['user_id'] === $userB->id
                        && $context['trust_key_fingerprint'] === $expectedFingerprint
                        && $context['dry_run'] === false
                        && $context['actor_type'] === 'system'
                        && ! array_key_exists('exception_class', $context);
                })
            )
            ->once();
    }

    /**
     * 6. Unexpected Throwable emits identity.link.failed at error level with exception_class only.
     */
    public function test_unexpected_throwable_emits_error_telemetry_with_exception_class_only(): void
    {
        $sensitiveSecret = 'super-secret-database-token-12345';
        $user = User::factory()->create();

        $this->app->bind(LinkExternalIdentity::class, function () use ($sensitiveSecret) {
            return new class($sensitiveSecret) extends LinkExternalIdentity
            {
                public function __construct(private string $secret) {}

                public function execute(int $userId, string $subject): ExternalIdentity
                {
                    throw new RuntimeException($this->secret);
                }
            };
        });

        Log::spy();

        $this->artisan('identity:link-keycloak', [
            'user_id' => $user->id,
            'subject' => self::FAKE_SUBJECT,
        ])->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->with(
                'identity.link.failed',
                \Mockery::on(function (array $context) use ($user, $sensitiveSecret) {
                    $serialized = json_encode($context);

                    return $context['operation'] === 'link'
                        && $context['reason'] === 'unexpected_failure'
                        && $context['user_id'] === $user->id
                        && $context['exception_class'] === RuntimeException::class
                        && ! str_contains($serialized, $sensitiveSecret)
                        && ! array_key_exists('message', $context)
                        && ! array_key_exists('exception', $context);
                })
            )
            ->once();
    }

    /**
     * 7. Safe context never contains issuer, subject, email, token, secret, session, cookie.
     */
    public function test_safe_context_contains_no_sensitive_data(): void
    {
        $conspicuousSubject = 'conspicuous-subject-xyz';
        $conspicuousEmail = 'conspicuous@example.com';
        $user = User::factory()->create(['email' => $conspicuousEmail]);

        $capturedContexts = [];
        Log::listen(function ($message) use (&$capturedContexts) {
            if ($message->message === 'identity.link.failed') {
                $capturedContexts[] = $message->context;
            }
        });

        $this->artisan('identity:link-keycloak', [
            'user_id' => 'invalid-id',
            'subject' => $conspicuousSubject,
        ]);

        $this->artisan('identity:link-keycloak', [
            'user_id' => 999998,
            'subject' => $conspicuousSubject,
        ]);

        $this->assertNotEmpty($capturedContexts);

        $forbiddenKeys = [
            'issuer', 'subject', 'email', 'email_at_link', 'token',
            'client_secret', 'secret', 'session_id', 'cookie', 'pkce', 'code',
        ];

        foreach ($capturedContexts as $context) {
            $serialized = json_encode($context);
            $this->assertStringNotContainsString($conspicuousSubject, $serialized);
            $this->assertStringNotContainsString(self::FAKE_ISSUER, $serialized);
            $this->assertStringNotContainsString($conspicuousEmail, $serialized);

            foreach ($forbiddenKeys as $forbiddenKey) {
                $this->assertArrayNotHasKey($forbiddenKey, $context);
            }
        }
    }

    /**
     * 8. Dry-run failure has dry_run=true.
     */
    public function test_dry_run_failure_records_dry_run_true(): void
    {
        Log::spy();

        $this->artisan('identity:link-keycloak', [
            'user_id' => 999999,
            'subject' => self::FAKE_SUBJECT,
            '--dry-run' => true,
        ])->assertExitCode(1);

        Log::shouldHaveReceived('warning')
            ->with(
                'identity.link.failed',
                \Mockery::on(function (array $context) {
                    return $context['operation'] === 'link'
                        && $context['dry_run'] === true;
                })
            )
            ->once();
    }

    /**
     * 9. Successful link audit remains exactly as before and emits no failure telemetry.
     */
    public function test_successful_link_emits_audit_and_no_failure_telemetry(): void
    {
        $user = User::factory()->create();

        Log::spy();
        Event::fake([IdentityLinkAuditEvent::class]);

        $this->artisan('identity:link-keycloak', [
            'user_id' => $user->id,
            'subject' => self::FAKE_SUBJECT,
        ])->assertExitCode(0);

        Event::assertDispatched(IdentityLinkAuditEvent::class);

        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    /**
     * 10. Successful dry-run emits no false linked lifecycle audit and no failure telemetry.
     */
    public function test_successful_dry_run_emits_no_audit_and_no_failure_telemetry(): void
    {
        $user = User::factory()->create();

        Log::spy();
        Event::fake([IdentityLinkAuditEvent::class]);

        $this->artisan('identity:link-keycloak', [
            'user_id' => $user->id,
            'subject' => self::FAKE_SUBJECT,
            '--dry-run' => true,
        ])->assertExitCode(0);

        Event::assertNotDispatched(IdentityLinkAuditEvent::class);

        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    /**
     * 11. Malformed non-string config issuer fails closed safely without TypeError.
     */
    public function test_malformed_config_issuer_emits_failure_telemetry_safely_without_type_error(): void
    {
        $malformedIssuers = [
            ['https://invalid-nested-array-issuer.example.com'],
            12345,
            true,
        ];

        foreach ($malformedIssuers as $malformedIssuer) {
            config(['oidc.issuer' => $malformedIssuer]);
            Log::spy();

            $user = User::factory()->create();

            $this->artisan('identity:link-keycloak', [
                'user_id' => $user->id,
                'subject' => self::FAKE_SUBJECT,
            ])
                ->expectsOutput('result=failed')
                ->expectsOutput('reason=invalid_issuer')
                ->assertExitCode(1);

            Log::shouldHaveReceived('warning')
                ->with(
                    'identity.link.failed',
                    \Mockery::on(function (array $context) use ($user) {
                        return $context['operation'] === 'link'
                            && $context['reason'] === 'invalid_issuer'
                            && $context['user_id'] === $user->id
                            && $context['trust_key_fingerprint'] === null
                            && $context['dry_run'] === false
                            && $context['actor_type'] === 'system'
                            && ! array_key_exists('exception_class', $context);
                    })
                )
                ->once();

            Log::shouldNotHaveReceived('error');
        }
    }
}
