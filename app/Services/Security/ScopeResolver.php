<?php

namespace App\Services\Security;

use App\Enums\DataScope;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\Approvals\ApprovalGuard;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that answers "for this permission, whose data may this user
 * reach?". Screens and services ask here instead of filtering departments
 * themselves.
 *
 * Resolution, first match wins:
 *
 *  1. Permission not held (or revoked for this user)        → None
 *  2. Super Admin, or a permission that is not data-scoped   → All
 *  3. A per-user override that names a scope                 → that scope
 *  4. The scope set on the role's grant, else the role default
 *     (PermissionScopes::defaultFor — matches pre-scope behaviour)
 *  5. HR's per-user department/shift narrowing (users.scope_departments /
 *     scope_shifts) caps any department-wide or company-wide result to
 *     those departments/shifts. It never widens Own or Team.
 *
 * Every scope also reaches the user's own record and their reporting line
 * (Team ⊂ Department ⊂ All). Deciding on one's own record is refused
 * separately by ApprovalGuard.
 */
class ScopeResolver
{
    public function __construct(private readonly ApprovalGuard $guard) {}

    public function resolve(User $user, string $permission): ResolvedScope
    {
        if (! $user->hasPermission($permission)) {
            return new ResolvedScope(DataScope::None, source: 'not_held');
        }

        if ($this->isSuperAdmin($user) || ! PermissionScopes::isScoped($permission)) {
            return new ResolvedScope(DataScope::All, source: 'unrestricted');
        }

        $override = $user->permissionOverride($permission);

        if ($override !== null && $override['scope'] !== null) {
            return new ResolvedScope(DataScope::from($override['scope']), $override['department_ids'], source: 'user_override');
        }

        $role = $user->effectiveRole();
        $grant = $role?->permissionScopes()[$permission] ?? null;

        $resolved = $grant !== null && $grant['scope'] !== null
            ? new ResolvedScope(DataScope::from($grant['scope']), $grant['department_ids'], source: 'role')
            : new ResolvedScope(PermissionScopes::defaultFor($role?->slug, $permission, $role?->permissionKeys() ?? []));

        if ($user->isDepartmentScoped() && $resolved->scope->rank() >= DataScope::Department->rank()) {
            return new ResolvedScope(
                DataScope::SelectedDepartments,
                array_map('intval', $user->scope_departments ?? []),
                array_map('intval', $user->scope_shifts ?? []),
                source: 'user_scope',
            );
        }

        return $resolved;
    }

    public function scopeFor(User $user, string $permission): DataScope
    {
        return $this->resolve($user, $permission)->scope;
    }

    /**
     * Employee ids the user reaches with this permission. NULL means every
     * employee (no filter); an empty array means none.
     *
     * @return array<int, int>|null
     */
    public function employeeIds(User $user, string $permission, bool $includeSelf = true): ?array
    {
        $resolved = $this->resolve($user, $permission);

        if ($resolved->isAll()) {
            return null;
        }

        if ($resolved->isNone()) {
            return [];
        }

        $ownEmployeeId = $this->ownEmployeeId($user);
        $ids = $includeSelf && $ownEmployeeId ? [$ownEmployeeId] : [];

        if ($resolved->scope === DataScope::Own) {
            return $ids;
        }

        $ids = array_merge($ids, $this->guard->reportingLineIds($user));

        $inDepartments = match ($resolved->scope) {
            DataScope::Department => $this->employeesIn($this->ownDepartmentIds($user), []),
            DataScope::SelectedDepartments => $this->employeesIn($resolved->departmentIds, $resolved->shiftIds),
            default => [],
        };

        if (! $includeSelf && $ownEmployeeId) {
            $inDepartments = array_values(array_diff($inDepartments, [$ownEmployeeId]));
        }

        return array_values(array_unique(array_map('intval', array_merge($ids, $inDepartments))));
    }

    /** Does this permission reach this employee for this user? */
    public function covers(User $user, string $permission, Employee $employee): bool
    {
        $ids = $this->employeeIds($user, $permission);

        return $ids === null || in_array((int) $employee->id, $ids, true);
    }

    /**
     * Department ids the user reaches department-wide with this permission
     * (for per-department figures). NULL means every department; Own / Team
     * reach no whole department.
     *
     * @return array<int, int>|null
     */
    public function departmentIds(User $user, string $permission): ?array
    {
        $resolved = $this->resolve($user, $permission);

        return match ($resolved->scope) {
            DataScope::All => null,
            DataScope::Department => $this->ownDepartmentIds($user),
            DataScope::SelectedDepartments => $resolved->shiftIds === [] ? $resolved->departmentIds : [],
            default => [],
        };
    }

    /**
     * Restrict a query on employee-owned rows to what the user reaches.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $user, string $permission, string $column = 'employee_id'): Builder
    {
        $ids = $this->employeeIds($user, $permission);

        if ($ids === null) {
            return $query;
        }

        return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn($column, $ids);
    }

    /**
     * The user's own department and every department they head.
     *
     * @return array<int, int>
     */
    public function ownDepartmentIds(User $user): array
    {
        if ($user->id === null) {
            return [];
        }

        $own = Employee::where('user_id', $user->id)->value('department_id');
        $headed = Department::where('head_id', $user->id)->pluck('id')->all();

        return array_values(array_unique(array_map('intval', array_filter([$own, ...$headed]))));
    }

    /**
     * @param  array<int, int>  $departmentIds
     * @param  array<int, int>  $shiftIds
     * @return array<int, int>
     */
    private function employeesIn(array $departmentIds, array $shiftIds): array
    {
        if ($departmentIds === [] && $shiftIds === []) {
            return [];
        }

        return Employee::query()
            ->when($departmentIds !== [], fn ($q) => $q->whereIn('department_id', $departmentIds))
            ->when($shiftIds !== [], fn ($q) => $q->whereIn('shift_id', $shiftIds))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function ownEmployeeId(User $user): ?int
    {
        if ($user->id === null) {
            return null;
        }

        $id = Employee::where('user_id', $user->id)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function isSuperAdmin(User $user): bool
    {
        return $user->isSuperAdmin() || $user->assignedRole?->slug === 'super_admin';
    }
}
