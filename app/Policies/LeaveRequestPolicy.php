<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
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
     * Determine whether the user can view any leave requests index.
     */
    public function viewAny(User $user): bool
    {
        return $this->isSupportedMember($user);
    }

    /**
     * Determine whether the user can view all leave requests across the organization.
     */
    public function viewAll(User $user): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can view the specific leave request.
     */
    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        if ($this->isPrivileged($user)) {
            return true;
        }

        if ($this->isSelfService($user)) {
            return ! empty($user->employee_id)
                && ! empty($leaveRequest->employee_id)
                && (string) $user->employee_id === (string) $leaveRequest->employee_id;
        }

        return false;
    }

    /**
     * Determine whether the user can create leave requests.
     */
    public function create(User $user): bool
    {
        return $this->isSupportedMember($user);
    }

    /**
     * Determine whether the user has administrator authority over sensitive leave fields.
     */
    public function administer(User $user): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can update the leave request.
     */
    public function update(User $user, LeaveRequest $leaveRequest): bool
    {
        if ($this->isPrivileged($user)) {
            return true;
        }

        if ($this->isSelfService($user)) {
            return ! empty($user->employee_id)
                && ! empty($leaveRequest->employee_id)
                && (string) $user->employee_id === (string) $leaveRequest->employee_id;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the leave request.
     */
    public function delete(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can approve the leave request.
     */
    public function approve(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can reject the leave request.
     */
    public function reject(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->isPrivileged($user);
    }
}
