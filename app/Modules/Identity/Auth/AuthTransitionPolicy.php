<?php

namespace App\Modules\Identity\Auth;

class AuthTransitionPolicy
{
    /**
     * Determine if the application is running in the production environment.
     * Defensive production semantics: checks application environment or app.env config.
     */
    public function isProduction(): bool
    {
        return app()->environment('production') || config('app.env') === 'production';
    }

    /**
     * Determine if legacy credential login is enabled.
     * In production, legacy login is strictly disabled regardless of configuration.
     * Outside production, evaluates to true only when configuration value is literal boolean true.
     */
    public function legacyLoginEnabled(): bool
    {
        if ($this->isProduction()) {
            return false;
        }

        return config('auth_transition.legacy_login_enabled') === true;
    }

    /**
     * Determine if legacy self-registration is enabled.
     * In production, self-registration is strictly disabled regardless of configuration.
     * Outside production, evaluates to true only when configuration value is literal boolean true.
     */
    public function legacyRegistrationEnabled(): bool
    {
        if ($this->isProduction()) {
            return false;
        }

        return config('auth_transition.legacy_registration_enabled') === true;
    }

    /**
     * Determine if legacy password reset is enabled.
     * Architectural invariant: password reset availability strictly follows legacy login.
     */
    public function legacyPasswordResetEnabled(): bool
    {
        return $this->legacyLoginEnabled();
    }

    /**
     * Determine if local password management (confirmation and update) is enabled.
     * In production, local password management is strictly disabled.
     * Outside production, local password management remains enabled.
     */
    public function localPasswordManagementEnabled(): bool
    {
        return ! $this->isProduction();
    }

    /**
     * Determine if self-service profile deletion is enabled.
     * In production, self-service account deletion is strictly disabled.
     * Outside production, self-service account deletion remains enabled.
     */
    public function profileDeletionEnabled(): bool
    {
        return ! $this->isProduction();
    }
}
