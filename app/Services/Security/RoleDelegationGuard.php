<?php

namespace App\Services\Security;

use App\Enums\UserRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Server-side privilege-escalation guard for every path that assigns a role
 * or changes a role's permissions (Employee create/edit, import, Role
 * Manager create/edit/clone/toggle/delete).
 *
 * Rules (Super Admin is exempt from all of them):
 *
 *   1. Nobody changes their own role, or edits / clones / deletes the role
 *      they themselves hold.
 *   2. Nobody but a Super Admin assigns, removes or edits Super Admin.
 *   3. Delegation ceiling: a non-Super-Admin may only grant a role — or put
 *      permissions on a role — whose every permission is one they hold
 *      themselves or one carried by a default delegable role (Employee,
 *      Manager, Finance), AND which carries none of the PRIVILEGED
 *      permissions.
 *
 * So an HR Admin can hand out Employee / Manager / Finance-style roles, but
 * can never mint a role that reaches beyond that ceiling, nor touch the
 * permissions that guard the guard.
 */
class RoleDelegationGuard
{
    /**
     * Permissions only a Super Admin may grant. Keys that do not exist yet
     * are listed so they are protected the moment they are introduced.
     *
     * @var array<int, string>
     */
    public const PRIVILEGED = [
        'manage_roles',
        'unlock_payroll',
        'delete_payslip',
        'manage_security',
        'manage_role_permissions',
        'data_purge',
        'impersonate',
        'force_delete_employee',
        'manage_ai_settings',
        'audit.view_all',
        'settings.roles',
        'settings.permissions',
        // Year-wide leave operations: they move every employee's balance.
        'run_leave_rollover',
        'reconcile_leave',
    ];

    public function isSuperAdmin(?User $user): bool
    {
        return $user !== null && ($user->isSuperAdmin() || $user->assignedRole?->slug === 'super_admin');
    }

    /**
     * Built-in operational roles a role manager may always hand out (and
     * whose permissions may therefore appear on roles they build). Phase 6
     * makes this ceiling configurable by the Super Admin.
     *
     * @var array<int, string>
     */
    public const DEFAULT_DELEGABLE_ROLES = ['employee', 'manager', 'finance', 'coordinator'];

    /**
     * Permission keys the actor may hand to others: their own permissions
     * plus those of the default delegable roles, never a PRIVILEGED one.
     *
     * @return array<int, string>
     */
    public function delegableKeys(User $actor): array
    {
        if ($this->isSuperAdmin($actor)) {
            return Permission::pluck('key')->all();
        }

        $own = $actor->assignedRole?->permissionKeys() ?? [];

        $operational = Role::whereIn('slug', self::DEFAULT_DELEGABLE_ROLES)->get()
            ->flatMap(fn (Role $role) => $role->permissionKeys())
            ->all();

        return array_values(array_diff(array_unique([...$own, ...$operational]), self::PRIVILEGED));
    }

    /**
     * May the actor give $role to $target (null target = a brand-new user)?
     * Returns null when allowed, otherwise the reason it is refused.
     */
    public function refusalToAssign(User $actor, Role $role, ?User $target = null): ?string
    {
        if ($this->isSuperAdmin($actor)) {
            return null;
        }

        if ($target !== null && $target->is($actor)) {
            if ((int) $target->role_id === (int) $role->id) {
                return null; // re-saving your own record without changing role
            }

            return 'You cannot change your own role.';
        }

        if ($target !== null && $this->isSuperAdmin($target)) {
            return 'Only a Super Admin can change a Super Admin\'s role.';
        }

        if ($target !== null && (int) $target->role_id === (int) $role->id) {
            return null; // unchanged — keeping an existing assignment is not a grant
        }

        if ($role->slug === UserRole::SuperAdmin->value) {
            return 'Only a Super Admin can assign the Super Admin role.';
        }

        if ($beyond = $this->beyondCeiling($actor, $role->permissionKeys())) {
            return 'This role carries permissions you cannot delegate: '.implode(', ', $beyond).'.';
        }

        if ($target !== null && $target->assignedRole
            && $this->beyondCeiling($actor, $target->assignedRole->permissionKeys())) {
            return 'This user holds a role above your delegation level; only a Super Admin can change it.';
        }

        return null;
    }

    /** Same as refusalToAssign() for the legacy enum (employee import). */
    public function refusalToAssignLegacy(User $actor, UserRole $role): ?string
    {
        $dbRole = Role::where('slug', $role->value)->first();

        if ($dbRole === null) {
            return $this->isSuperAdmin($actor) ? null : "Role '{$role->label()}' is not configured.";
        }

        return $this->refusalToAssign($actor, $dbRole);
    }

    /**
     * May the actor set $role's permissions to $permissionIds (null role =
     * creating a new one)? Returns null when allowed, otherwise the reason.
     *
     * @param  array<int, int>  $permissionIds
     */
    public function refusalToEditRole(User $actor, ?Role $role, array $permissionIds): ?string
    {
        if ($this->isSuperAdmin($actor)) {
            return null;
        }

        if ($role !== null && ($refusal = $this->refusalToManageRole($actor, $role))) {
            return $refusal;
        }

        $keys = Permission::whereIn('id', $permissionIds)->pluck('key')->all();

        if ($beyond = $this->beyondCeiling($actor, $keys)) {
            return 'You cannot grant permissions outside your delegation level: '.implode(', ', $beyond).'.';
        }

        return null;
    }

    /**
     * May the actor touch this role at all (edit, clone, (de)activate,
     * delete)? Returns null when allowed, otherwise the reason.
     */
    public function refusalToManageRole(User $actor, Role $role): ?string
    {
        if ($this->isSuperAdmin($actor)) {
            return null;
        }

        if ($role->slug === UserRole::SuperAdmin->value) {
            return 'Only a Super Admin can manage the Super Admin role.';
        }

        if ((int) $actor->role_id === (int) $role->id) {
            return 'You cannot change the role you hold yourself.';
        }

        if ($beyond = $this->beyondCeiling($actor, $role->permissionKeys())) {
            return 'This role is above your delegation level ('.implode(', ', $beyond).'); only a Super Admin can manage it.';
        }

        return null;
    }

    /**
     * Permission keys in $keys the actor may not delegate.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    public function beyondCeiling(User $actor, array $keys): array
    {
        if ($this->isSuperAdmin($actor)) {
            return [];
        }

        return array_values(array_diff($keys, $this->delegableKeys($actor)));
    }
}
