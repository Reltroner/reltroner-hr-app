<?php

namespace App\Modules\Identity\Auth;

class AuthTransitionPolicy
{
    /**
     * Determine if legacy credential login is enabled.
     * Evaluates to true only when configuration value is literal boolean true.
     */
    public function legacyLoginEnabled(): bool
    {
        return config('auth_transition.legacy_login_enabled') === true;
    }

    /**
     * Determine if legacy self-registration is enabled.
     * Evaluates to true only when configuration value is literal boolean true.
     */
    public function legacyRegistrationEnabled(): bool
    {
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
}
