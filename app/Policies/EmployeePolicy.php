<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    private const PRIVILEGED_ROLES = [
        'admin',
        'hr manager',
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

    /**
     * Determine whether the user can view any employees index.
     * Admin / HR Manager only.
     */
    public function viewAny(User $user): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can view the specific employee.
     * Admin / HR Manager only.
     */
    public function view(User $user, Employee $employee): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can create employees.
     * Admin / HR Manager only.
     */
    public function create(User $user): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can update the employee.
     * Admin / HR Manager only.
     */
    public function update(User $user, Employee $employee): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can delete the employee.
     * Admin / HR Manager only, but authoritative self-delete MUST be denied.
     */
    public function delete(User $user, Employee $employee): bool
    {
        if (! $this->isPrivileged($user)) {
            return false;
        }

        if ((string) $user->employee_id === (string) $employee->id) {
            return false;
        }

        return true;
    }
}
