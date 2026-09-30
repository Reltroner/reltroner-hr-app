<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    private const PRIVILEGED_ROLES = [
        'admin',
        'hr manager',
    ];

    private const PROTECTED_ROLES = [
        'admin',
        'hr manager',
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

    /**
     * Determine whether the given title matches any protected role namespace.
     */
    private function isProtectedTitle(?string $title): bool
    {
        if ($title === null) {
            return false;
        }

        return in_array(strtolower(trim($title)), self::PROTECTED_ROLES, true);
    }

    /**
     * Determine whether the user can view any roles index.
     * Admin / HR Manager only.
     */
    public function viewAny(User $user): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can view the specific role.
     * Admin / HR Manager only; protected Roles remain viewable.
     */
    public function view(User $user, Role $role): bool
    {
        return $this->isPrivileged($user);
    }

    /**
     * Determine whether the user can create roles.
     * Behavior:
     * - deny unless real linked Admin / HR Manager
     * - when $title is null: allow privileged actor to enter create operation/form
     * - when $title is supplied: deny if normalized title is protected; allow if ordinary/custom
     */
    public function create(User $user, ?string $title = null): bool
    {
        if (! $this->isPrivileged($user)) {
            return false;
        }

        if ($title !== null && $this->isProtectedTitle($title)) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can update the role.
     * Behavior:
     * - deny unless real linked Admin / HR Manager
     * - deny if CURRENT role title is protected
     * - if $newTitle is supplied: deny if normalized new title is protected
     * - otherwise allow ordinary custom role update
     */
    public function update(User $user, Role $role, ?string $newTitle = null): bool
    {
        if (! $this->isPrivileged($user)) {
            return false;
        }

        if ($this->isProtectedTitle($role->title)) {
            return false;
        }

        if ($newTitle !== null && $this->isProtectedTitle($newTitle)) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can delete the role.
     * Behavior:
     * - deny unless real linked Admin / HR Manager
     * - deny if current title normalizes to any protected role
     * - allow custom role deletion
     */
    public function delete(User $user, Role $role): bool
    {
        if (! $this->isPrivileged($user)) {
            return false;
        }

        if ($this->isProtectedTitle($role->title)) {
            return false;
        }

        return true;
    }
}
