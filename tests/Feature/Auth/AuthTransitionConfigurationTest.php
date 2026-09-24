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
     * 1. Default legacy login is false.
     */
    public function test_default_legacy_login_is_false(): void
    {
        $this->assertFalse(config('auth_transition.legacy_login_enabled'));
        $this->assertFalse($this->policy->legacyLoginEnabled());
    }

    /**
     * 2. Default legacy registration is false.
     */
    public function test_default_legacy_registration_is_false(): void
    {
        $this->assertFalse(config('auth_transition.legacy_registration_enabled'));
        $this->assertFalse($this->policy->legacyRegistrationEnabled());
    }

    /**
     * 3. Default password reset availability is false.
     */
    public function test_default_password_reset_availability_is_false(): void
    {
        $this->assertFalse($this->policy->legacyPasswordResetEnabled());
        $this->assertSame($this->policy->legacyLoginEnabled(), $this->policy->legacyPasswordResetEnabled());
    }

    /**
     * 4. Explicit login=true enables login/password-reset compatibility.
     */
    public function test_explicit_login_true_enables_login_and_password_reset(): void
    {
        config(['auth_transition.legacy_login_enabled' => true]);

        $this->assertTrue($this->policy->legacyLoginEnabled());
        $this->assertTrue($this->policy->legacyPasswordResetEnabled());
    }

    /**
     * 5. Explicit registration=true can enable registration outside production.
     */
    public function test_explicit_registration_true_enables_registration_outside_production(): void
    {
        config(['auth_transition.legacy_registration_enabled' => true]);

        $this->assertTrue($this->policy->legacyRegistrationEnabled());
    }

    /**
     * 6. production + registration=true still makes legacyRegistrationEnabled() false.
     */
    public function test_production_and_registration_true_still_makes_legacy_registration_disabled(): void
    {
        config([
            'app.env' => 'production',
            'auth_transition.legacy_registration_enabled' => true,
        ]);
        $this->app['env'] = 'production';

        $this->assertFalse($this->policy->legacyRegistrationEnabled());
    }

    /**
     * 7. Setting legacy_login_enabled = false disables login and password reset.
     */
    public function test_disabling_legacy_login_disables_login_and_password_reset(): void
    {
        config(['auth_transition.legacy_login_enabled' => false]);

        $this->assertFalse($this->policy->legacyLoginEnabled());
        $this->assertFalse($this->policy->legacyPasswordResetEnabled());
    }

    /**
     * 8. Setting legacy_registration_enabled = false disables registration without disabling legacy login.
     */
    public function test_disabling_legacy_registration_disables_registration_without_disabling_legacy_login(): void
    {
        config([
            'auth_transition.legacy_login_enabled' => true,
            'auth_transition.legacy_registration_enabled' => false,
        ]);

        $this->assertFalse($this->policy->legacyRegistrationEnabled());
        $this->assertTrue($this->policy->legacyLoginEnabled());
        $this->assertTrue($this->policy->legacyPasswordResetEnabled());
    }

    /**
     * 9. Login false and registration true outside production are independent.
     */
    public function test_login_false_and_registration_true_outside_production_are_independent(): void
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
     * 10. Malformed/non-boolean values do not enable a capability (fail-closed).
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
            new \stdClass,
            '',
        ];

        foreach ($malformedValues as $value) {
            config([
                'auth_transition.legacy_login_enabled' => $value,
                'auth_transition.legacy_registration_enabled' => $value,
            ]);

            $this->assertFalse(
                $this->policy->legacyLoginEnabled(),
                'Expected legacyLoginEnabled() to be false for value: '.var_export($value, true)
            );
            $this->assertFalse(
                $this->policy->legacyPasswordResetEnabled(),
                'Expected legacyPasswordResetEnabled() to be false for value: '.var_export($value, true)
            );
            $this->assertFalse(
                $this->policy->legacyRegistrationEnabled(),
                'Expected legacyRegistrationEnabled() to be false for value: '.var_export($value, true)
            );
        }
    }
}
