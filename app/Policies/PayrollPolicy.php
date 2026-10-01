<?php

namespace App\Policies;

use App\Models\Payroll;
use App\Models\User;

class PayrollPolicy
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
     * Determine whether the user can view any payrolls index.
     */
    public function viewAny(User $user): bool
    {
        return $this->isSupportedMember($user);
    }

    /**
     * Determine whether the user can view all payrolls across the organization.
     */
    public function viewAll(User $user): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can view the specific payroll record.
     */
    public function view(User $user, Payroll $payroll): bool
    {
        if ($this->isPrivileged($user)) {
            return true;
        }

        if ($this->isSelfService($user)) {
            return ! empty($user->employee_id)
                && ! empty($payroll->employee_id)
                && (string) $user->employee_id === (string) $payroll->employee_id;
        }

        return false;
    }

    /**
     * Determine whether the user can create payroll records.
     */
    public function create(User $user): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can update the payroll record.
     */
    public function update(User $user, Payroll $payroll): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can delete the payroll record.
     */
    public function delete(User $user, Payroll $payroll): bool
    {
        return $this->isPrivileged($user);
    }
}
