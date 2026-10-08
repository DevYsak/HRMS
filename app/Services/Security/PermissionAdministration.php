<?php

namespace App\Services\Security;

use App\Enums\DataScope;
use App\Models\Department;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionOverride;

/**
 * The rules for changing who may do what, shared by Roles & Permissions
 * (scope on a role's grant) and per-user overrides. Every refusal is a
 * sentence for the admin; null means allowed.
 *
 *  - A scope only exists for a permission that reaches employee data.
 *  - "Selected departments" needs at least one department.
 *  - "No access" on a granted permission is contradictory — untick it on the
 *    role, or revoke it for the user.
 *  - Nobody hands out more reach than they have: a scope may not be broader
 *    than the actor's own for that permission, and chosen departments must be
 *    inside the actor's reach. Super Admin is exempt.
 *  - Overrides follow the role-assignment rules: never yourself, never a Super
 *    Admin, never someone above your delegation level, never a permission
 *    outside your ceiling (RoleDelegationGuard).
 */
class PermissionAdministration
{
    public function __construct(
        private readonly RoleDelegationGuard $delegation,
        private readonly ScopeResolver $scopes,
    ) {}

    /**
     * @param  array<int, int>  $departmentIds
     */
    public function refusalForScope(User $actor, Permission $permission, ?DataScope $scope, array $departmentIds = []): ?string
    {
        if ($scope === null) {
            return null;
        }

        if (! PermissionScopes::isScoped($permission->key)) {
            return "{$permission->label} does not reach employee data, so it has no data scope.";
        }

        if ($scope === DataScope::None) {
            return "\"No access\" with {$permission->label} granted is contradictory — remove the permission instead.";
        }

        if ($scope === DataScope::SelectedDepartments && $departmentIds === []) {
            return "Choose at least one department for {$permission->label}.";
        }

        if ($scope === DataScope::SelectedDepartments
            && Department::whereIn('id', $departmentIds)->count() !== count(array_unique($departmentIds))) {
            return 'One of the chosen departments no longer exists.';
        }

        if ($this->delegation->isSuperAdmin($actor)) {
            return null;
        }

        $own = $this->scopes->resolve($actor, $permission->key);

        if ($own->isAll()) {
            return null;
        }

        if ($scope->rank() > $own->scope->rank()) {
            return "You can grant {$permission->label} only as far as your own reach ({$own->scope->label()}).";
        }

        // Department-wide reach is measured from the *target's* department,
        // which may be outside the actor's — only company-wide actors give it.
        if ($scope === DataScope::Department) {
            return "Only someone with company-wide {$permission->label} can grant \"Own department\".";
        }

        if ($scope === DataScope::SelectedDepartments) {
            $mine = $this->scopes->departmentIds($actor, $permission->key) ?? [];
            $outside = array_diff($departmentIds, $mine);

            if ($outside !== []) {
                return "You can only choose departments inside your own {$permission->label} reach.";
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $departmentIds
     */
    public function refusalForOverride(User $actor, User $target, Permission $permission, string $effect, ?DataScope $scope, array $departmentIds = []): ?string
    {
        if (! in_array($effect, [UserPermissionOverride::GRANT, UserPermissionOverride::REVOKE], true)) {
            return 'Choose grant or revoke.';
        }

        if ($target->is($actor)) {
            return 'You cannot change your own permissions.';
        }

        if ($this->delegation->isSuperAdmin($target)) {
            return 'A Super Admin always holds every permission; overrides do not apply.';
        }

        if (! $this->delegation->isSuperAdmin($actor)) {
            if ($target->effectiveRole() && $this->delegation->beyondCeiling($actor, $target->effectiveRole()->permissionKeys()) !== []) {
                return 'This user holds a role above your delegation level; only a Super Admin can change their permissions.';
            }

            if ($this->delegation->beyondCeiling($actor, [$permission->key]) !== []) {
                return "{$permission->label} is outside what you can delegate.";
            }
        }

        $roleGrants = $target->effectiveRole()?->hasPermission($permission->key) ?? false;

        if ($effect === UserPermissionOverride::REVOKE) {
            if ($scope !== null) {
                return 'A revoke removes the permission entirely, so it has no scope.';
            }

            return $roleGrants ? null : "Nothing to revoke: {$target->name}'s role does not grant {$permission->label}.";
        }

        if ($roleGrants && $scope === null) {
            return "{$target->name}'s role already grants {$permission->label}. Choose a different scope, or no override is needed.";
        }

        return $this->refusalForScope($actor, $permission, $scope, $departmentIds);
    }

    /**
     * What the user can do, permission by permission, and why: the role's
     * grant, a personal grant, or a personal revoke — with the reach that
     * results.
     *
     * @return array<int, array{key: string, label: string, module: string, held: bool, source: string, scope: ?string, departments: array<int, string>}>
     */
    public function effectivePermissions(User $user): array
    {
        $overrides = $user->permissionOverrideMap();
        $roleKeys = $user->effectiveRole()?->permissionKeys() ?? [];
        $superAdmin = $this->delegation->isSuperAdmin($user);
        $departmentNames = Department::pluck('name', 'id');

        return Permission::orderBy('module')->orderBy('label')->get()
            ->filter(fn (Permission $p) => $superAdmin || in_array($p->key, $roleKeys, true) || isset($overrides[$p->key]))
            ->map(function (Permission $p) use ($user, $overrides, $superAdmin, $departmentNames) {
                $held = $user->hasPermission($p->key);
                $resolved = $held ? $this->scopes->resolve($user, $p->key) : null;

                return [
                    'key' => $p->key,
                    'label' => $p->label,
                    'module' => $p->module,
                    'held' => $held,
                    'source' => match (true) {
                        $superAdmin => 'Super Admin',
                        ($overrides[$p->key]['effect'] ?? null) === UserPermissionOverride::REVOKE => 'Revoked for this user',
                        ($overrides[$p->key]['effect'] ?? null) === UserPermissionOverride::GRANT => 'Granted to this user',
                        default => 'From role',
                    },
                    'scope' => $resolved && $p->is_scoped ? $resolved->scope->label() : null,
                    'departments' => $resolved && $resolved->scope === DataScope::SelectedDepartments
                        ? collect($resolved->departmentIds)->map(fn ($id) => $departmentNames[$id] ?? "#{$id}")->values()->all()
                        : [],
                ];
            })
            ->values()
            ->all();
    }
}
