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

    protected function tearDown(): void
    {
        config(['app.env' => 'testing']);
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    /**
     * Non-production enables all local auth capabilities by default without feature flags.
     */
    public function test_non_production_enables_all_local_auth_capabilities_by_default(): void
    {
        config(['app.env' => 'testing']);
        $this->app['env'] = 'testing';

        $this->assertFalse($this->policy->isProduction());
        $this->assertTrue($this->policy->legacyLoginEnabled());
        $this->assertTrue($this->policy->legacyRegistrationEnabled());
        $this->assertTrue($this->policy->legacyPasswordResetEnabled());
        $this->assertTrue($this->policy->localPasswordManagementEnabled());
        $this->assertTrue($this->policy->profileDeletionEnabled());
    }

    /**
     * Password reset availability strictly follows legacy login.
     */
    public function test_password_reset_availability_strictly_follows_legacy_login(): void
    {
        // In non-production: both true
        config(['app.env' => 'testing']);
        $this->app['env'] = 'testing';
        $this->assertSame($this->policy->legacyLoginEnabled(), $this->policy->legacyPasswordResetEnabled());
        $this->assertTrue($this->policy->legacyPasswordResetEnabled());

        // In production: both false
        config(['app.env' => 'production']);
        $this->app['env'] = 'production';
        $this->assertSame($this->policy->legacyLoginEnabled(), $this->policy->legacyPasswordResetEnabled());
        $this->assertFalse($this->policy->legacyPasswordResetEnabled());
    }

    /**
     * Production via app()->environment('production') strictly denies all local capabilities.
     */
    public function test_production_via_app_environment_denies_all_local_auth_capabilities(): void
    {
        config(['app.env' => 'local']);
        $this->app['env'] = 'production';

        $this->assertTrue($this->policy->isProduction());
        $this->assertFalse($this->policy->legacyLoginEnabled());
        $this->assertFalse($this->policy->legacyRegistrationEnabled());
        $this->assertFalse($this->policy->legacyPasswordResetEnabled());
        $this->assertFalse($this->policy->localPasswordManagementEnabled());
        $this->assertFalse($this->policy->profileDeletionEnabled());
    }

    /**
     * Production via config('app.env') === 'production' strictly denies all local capabilities.
     */
    public function test_production_via_config_app_env_denies_all_local_auth_capabilities(): void
    {
        config(['app.env' => 'production']);
        $this->app['env'] = 'testing';

        $this->assertTrue($this->policy->isProduction());
        $this->assertFalse($this->policy->legacyLoginEnabled());
        $this->assertFalse($this->policy->legacyRegistrationEnabled());
        $this->assertFalse($this->policy->legacyPasswordResetEnabled());
        $this->assertFalse($this->policy->localPasswordManagementEnabled());
        $this->assertFalse($this->policy->profileDeletionEnabled());
    }

    /**
     * No configuration value can reopen local authentication in production.
     */
    public function test_no_config_value_can_reopen_production_local_authentication(): void
    {
        config(['app.env' => 'production']);
        $this->app['env'] = 'production';

        $arbitraryConfigs = [
            ['auth.legacy_login' => true],
            ['auth.legacy_registration' => true],
            ['auth.allow_local' => true],
            ['auth.local_login' => true],
            ['auth.allow_registration' => true],
            ['custom.enable_passwords' => true],
        ];

        foreach ($arbitraryConfigs as $override) {
            config($override);

            $this->assertFalse(
                $this->policy->legacyLoginEnabled(),
                'legacyLoginEnabled must remain false in production despite config: '.json_encode($override)
            );
            $this->assertFalse(
                $this->policy->legacyRegistrationEnabled(),
                'legacyRegistrationEnabled must remain false in production despite config: '.json_encode($override)
            );
            $this->assertFalse(
                $this->policy->legacyPasswordResetEnabled(),
                'legacyPasswordResetEnabled must remain false in production despite config: '.json_encode($override)
            );
            $this->assertFalse(
                $this->policy->localPasswordManagementEnabled(),
                'localPasswordManagementEnabled must remain false in production despite config: '.json_encode($override)
            );
            $this->assertFalse(
                $this->policy->profileDeletionEnabled(),
                'profileDeletionEnabled must remain false in production despite config: '.json_encode($override)
            );
        }
    }
}
