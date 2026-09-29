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
     * In production, legacy login is permanently disabled.
     * Outside production, local credential login remains enabled.
     */
    public function legacyLoginEnabled(): bool
    {
        return ! $this->isProduction();
    }

    /**
     * Determine if legacy self-registration is enabled.
     * In production, self-registration is permanently disabled.
     * Outside production, local self-registration remains enabled.
     */
    public function legacyRegistrationEnabled(): bool
    {
        return ! $this->isProduction();
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
