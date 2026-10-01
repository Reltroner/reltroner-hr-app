<?php

namespace App\Policies;

use App\Models\Presence;
use App\Models\User;

class PresencePolicy
{
    private const PRIVILEGED_ROLES = [
        'admin',
        'hr manager',
    ];

    private const SELF_SERVICE_ROLES = [
        'developer',
        'accountant',
        'data entry',
        'animator',
        'marketer',
    ];

    /**
     * Resolve the normalized role title from User -> Employee -> Role.
     */
    private function resolveRole(User $user): ?string
    {
        $employee = $user->employee;
        if (! $employee) {
            return null;
        }

        $role = $employee->role;
        if (! $role || empty($role->title)) {
            return null;
        }

        return strtolower(trim((string) $role->title));
    }

    private function isPrivileged(User $user): bool
    {
        $role = $this->resolveRole($user);

        return $role !== null && in_array($role, self::PRIVILEGED_ROLES, true);
    }

    private function isSelfService(User $user): bool
    {
        $role = $this->resolveRole($user);

        return $role !== null && in_array($role, self::SELF_SERVICE_ROLES, true);
    }

    private function isSupportedMember(User $user): bool
    {
        return $this->isPrivileged($user) || $this->isSelfService($user);
    }

    /**
     * Determine whether the user can view any presence records.
     * True for any of the seven supported Employee-linked roles.
     */
    public function viewAny(User $user): bool
    {
        return $this->isSupportedMember($user);
    }

    /**
     * Determine whether the user can view all presence records across the organization.
     * True only for Admin / HR Manager.
     */
    public function viewAll(User $user): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can view the specific presence record.
     * Privileged: any presence.
     * Supported self-service member: only own presence.
     */
    public function view(User $user, Presence $presence): bool
    {
        if ($this->isPrivileged($user)) {
            return true;
        }

        if ($this->isSelfService($user)) {
            return ! empty($user->employee_id)
                && ! empty($presence->employee_id)
                && (string) $user->employee_id === (string) $presence->employee_id;
        }

        return false;
    }

    /**
     * Determine whether the user can create presence records.
     * True for all seven supported Employee-linked roles.
     */
    public function create(User $user): bool
    {
        return $this->isSupportedMember($user);
    }

    /**
     * Determine whether the user can administer presence records.
     * True only for Admin / HR Manager.
     */
    public function administer(User $user): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can update the presence record.
     * True only for Admin / HR Manager.
     */
    public function update(User $user, Presence $presence): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can delete the presence record.
     * True only for Admin / HR Manager.
     */
    public function delete(User $user, Presence $presence): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can perform self check-in.
     * True for all seven supported Employee-linked roles.
     */
    public function selfCheckIn(User $user): bool
    {
        return $this->isSupportedMember($user);
    }
}
