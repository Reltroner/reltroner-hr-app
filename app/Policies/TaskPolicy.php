<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
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
     * Determine whether the user can view any tasks index.
     * True for all seven supported Employee-linked roles.
     */
    public function viewAny(User $user): bool
    {
        return $this->isSupportedMember($user);
    }

    /**
     * Determine whether the user can view all tasks across the organization.
     * True only for Admin / HR Manager.
     */
    public function viewAll(User $user): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can view the specific task record.
     * Privileged: any Task.
     * Supported self-service: only own assigned Task.
     * Otherwise false.
     */
    public function view(User $user, Task $task): bool
    {
        if ($this->isPrivileged($user)) {
            return true;
        }

        if ($this->isSelfService($user)) {
            return ! empty($user->employee_id)
                && ! empty($task->assigned_to)
                && (string) $user->employee_id === (string) $task->assigned_to;
        }

        return false;
    }

    /**
     * Determine whether the user can create tasks.
     * True only for Admin / HR Manager.
     */
    public function create(User $user): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can update the task record.
     * True only for Admin / HR Manager.
     */
    public function update(User $user, Task $task): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can delete the task record.
     * True only for Admin / HR Manager.
     */
    public function delete(User $user, Task $task): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can transition the task status (mark-complete / mark-pending).
     * Privileged: any Task.
     * Supported self-service: only own assigned Task.
     * Otherwise false.
     */
    public function transitionStatus(User $user, Task $task): bool
    {
        if ($this->isPrivileged($user)) {
            return true;
        }

        if ($this->isSelfService($user)) {
            return ! empty($user->employee_id)
                && ! empty($task->assigned_to)
                && (string) $user->employee_id === (string) $task->assigned_to;
        }

        return false;
    }
}
