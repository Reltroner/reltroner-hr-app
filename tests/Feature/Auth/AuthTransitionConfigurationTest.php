<?php

namespace Tests\Feature\Auth;

use App\Modules\Identity\Auth\AuthTransitionPolicy;
use Tests\TestCase;

class AuthTransitionConfigurationTest extends TestCase
{
    private AuthTransitionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = app(AuthTransitionPolicy::class);
    }

    /**
     * 1. Default configuration preserves legacy login.
     */
    public function test_default_configuration_preserves_legacy_login(): void
    {
        $this->assertTrue(config('auth_transition.legacy_login_enabled'));
        $this->assertTrue($this->policy->legacyLoginEnabled());
    }

    /**
     * 2. Default configuration preserves legacy registration.
     */
    public function test_default_configuration_preserves_legacy_registration(): void
    {
        $this->assertTrue(config('auth_transition.legacy_registration_enabled'));
        $this->assertTrue($this->policy->legacyRegistrationEnabled());
    }

    /**
     * 3. Default password-reset availability follows login and is enabled.
     */
    public function test_default_password_reset_availability_follows_login_and_is_enabled(): void
    {
        $this->assertTrue($this->policy->legacyPasswordResetEnabled());
        $this->assertSame($this->policy->legacyLoginEnabled(), $this->policy->legacyPasswordResetEnabled());
    }

    /**
     * 4. Setting legacy_login_enabled = false disables login and password reset.
     */
    public function test_disabling_legacy_login_disables_login_and_password_reset(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $this->assertFalse($this->policy->legacyLoginEnabled());
        $this->assertFalse($this->policy->legacyPasswordResetEnabled());
    }

    /**
     * 5. Setting legacy_registration_enabled = false disables registration without disabling legacy login.
     */
    public function test_disabling_legacy_registration_disables_registration_without_disabling_legacy_login(): void
    {
        config(['auth_transition.legacy_registration_enabled' => false]);

        $this->assertFalse($this->policy->legacyRegistrationEnabled());
        $this->assertTrue($this->policy->legacyLoginEnabled());
        $this->assertTrue($this->policy->legacyPasswordResetEnabled());
    }

    /**
     * 6. Login false and registration true are independent.
     */
    public function test_login_false_and_registration_true_are_independent(): void
    {
        config([
            'auth_transition.legacy_login_enabled' => false,
            'auth_transition.legacy_registration_enabled' => true,
        ]);

        $this->assertFalse($this->policy->legacyLoginEnabled());
        $this->assertFalse($this->policy->legacyPasswordResetEnabled());
        $this->assertTrue($this->policy->legacyRegistrationEnabled());
    }

    /**
     * 7. Registration false and login true are independent.
     */
    public function test_registration_false_and_login_true_are_independent(): void
    {
        config([
            'auth_transition.legacy_login_enabled' => true,
            'auth_transition.legacy_registration_enabled' => false,
        ]);

        $this->assertTrue($this->policy->legacyLoginEnabled());
        $this->assertTrue($this->policy->legacyPasswordResetEnabled());
        $this->assertFalse($this->policy->legacyRegistrationEnabled());
    }

    /**
     * 8. Malformed/non-boolean values do not enable a capability (fail-closed).
     */
    public function test_malformed_or_non_boolean_values_do_not_enable_a_capability(): void
    {
        $malformedValues = [
            'true',
            'false',
            '1',
            '0',
            1,
            0,
            null,
            [],
            new \stdClass(),
            '',
        ];

        foreach ($malformedValues as $value) {
            config([
                'auth_transition.legacy_login_enabled' => $value,
                'auth_transition.legacy_registration_enabled' => $value,
            ]);

            $this->assertFalse(
                $this->policy->legacyLoginEnabled(),
                'Expected legacyLoginEnabled() to be false for value: ' . var_export($value, true)
            );
            $this->assertFalse(
                $this->policy->legacyPasswordResetEnabled(),
                'Expected legacyPasswordResetEnabled() to be false for value: ' . var_export($value, true)
            );
            $this->assertFalse(
                $this->policy->legacyRegistrationEnabled(),
                'Expected legacyRegistrationEnabled() to be false for value: ' . var_export($value, true)
            );
        }
    }
}
