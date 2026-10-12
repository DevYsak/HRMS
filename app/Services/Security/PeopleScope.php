<?php

namespace App\Services\Security;

use App\Enums\DataScope;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\Approvals\ApprovalGuard;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The one answer to "whose people is this?", for every screen.
 *
 *   ROLE        what a user can do
 *   PERMISSION  which feature/action is available
 *   SCOPE       whose data that permission reaches (ScopeResolver)
 *   STRUCTURE   departments, reporting lines and teams decide the actual people
 *
 * Every method names the permission being exercised, so a list, its filters,
 * its header badge and the approve/reject check on the same screen all
 * resolve the same employee set (ApprovalGuard with that permission). Nothing
 * here widens access: it is a thin, explicit front over ApprovalGuard and
 * ScopeResolver.
 */
class PeopleScope
{
    /** Employment statuses of people who are working (counted in "today" figures). */
    public const WORKING_STATUSES = ['onboarding', 'probation', 'confirmed', 'active', 'on-leave', 'notice_period'];

    public function __construct(
        private readonly ApprovalGuard $guard,
        private readonly ScopeResolver $scopes,
    ) {}

    /**
     * Employee ids this permission reaches — NULL means everyone. The user's
     * own record is left out unless asked for (you never approve yourself).
     *
     * @return array<int, int>|null
     */
    public function employeeIds(User $user, string $permission, bool $includeSelf = false): ?array
    {
        $ids = $this->guard->accessibleEmployeeIds($user, $permission);

        if ($ids === null || ! $includeSelf) {
            return $ids;
        }

        $own = $user->employee?->id;

        return $own ? array_values(array_unique([...$ids, (int) $own])) : $ids;
    }

    /**
     * People working today inside the reach (joined, not left) — the
     * population every "today" count uses, so screens cannot drift.
     *
     * @param  array<int, int>|null  $departmentIds  optional narrowing inside the reach
     * @return array<int, int>
     */
    public function workingEmployeeIds(User $user, string $permission, ?array $departmentIds = null, ?CarbonInterface $on = null): array
    {
        $on ??= now();

        return $this->employees($user, $permission)
            ->whereIn('status', self::WORKING_STATUSES)
            ->where(fn ($q) => $q->whereNull('joining_date')->orWhereDate('joining_date', '<=', $on->toDateString()))
            ->when($departmentIds !== null, fn ($q) => $q->whereIn('department_id', $departmentIds))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Employees in reach, as a query (never including the user).
     *
     * @return Builder<Employee>
     */
    public function employees(User $user, string $permission): Builder
    {
        return $this->constrain(Employee::query(), $user, $permission, 'id');
    }

    /**
     * Restrict any employee-owned query to the reach; the user's own rows are
     * excluded unless $includeSelf.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $user, string $permission, string $column = 'employee_id', bool $includeSelf = false): Builder
    {
        $ids = $this->employeeIds($user, $permission, $includeSelf);
        $own = $user->employee?->id;

        if ($ids === null) {
            return $query->when(! $includeSelf && $own, fn ($q) => $q->where($column, '!=', $own));
        }

        return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn($column, $ids);
    }

    public function covers(User $user, string $permission, Employee $employee): bool
    {
        return $this->guard->covers($user, $employee, $permission);
    }

    public function isCompanyWide(User $user, string $permission): bool
    {
        return $this->employeeIds($user, $permission) === null;
    }

    /** The data scope this permission has for the user (None when not held). */
    public function scope(User $user, string $permission): DataScope
    {
        return $this->scopes->scopeFor($user, $permission);
    }

    /**
     * Departments a filter may offer: every department when the reach is
     * company-wide, otherwise only departments that hold people in reach
     * (plus the departments the user heads or belongs to, for a department
     * scope). Never a department the next request would refuse.
     *
     * @return Collection<int, Department>
     */
    public function departmentOptions(User $user, string $permission): Collection
    {
        $ids = $this->employeeIds($user, $permission);

        if ($ids === null) {
            return Department::orderBy('name')->get(['id', 'name', 'head_id']);
        }

        $departmentIds = Employee::whereIn('id', $ids)->whereNotNull('department_id')->distinct()->pluck('department_id');

        if (in_array($this->scope($user, $permission), [DataScope::Department, DataScope::SelectedDepartments], true)) {
            $departmentIds = $departmentIds->merge($this->scopes->departmentIds($user, $permission) ?? []);
        }

        return Department::whereIn('id', $departmentIds->unique()->all())->orderBy('name')->get(['id', 'name', 'head_id']);
    }

    /**
     * A requested department filter, kept inside the options; anything else
     * (tampered id, out of reach) falls back to "no narrowing".
     */
    public function sanitiseDepartment(User $user, string $permission, mixed $departmentId): ?int
    {
        if ($departmentId === null || $departmentId === '' || ! is_numeric($departmentId)) {
            return null;
        }

        return $this->departmentOptions($user, $permission)->contains('id', (int) $departmentId) ? (int) $departmentId : null;
    }

    /**
     * Direct reports (employees.manager_id = this user).
     *
     * @return array<int, int>
     */
    public function directReportIds(User $user): array
    {
        return $user->id === null ? [] : Employee::where('manager_id', $user->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * What the page header shows: the scope in words, how many people it
     * covers, and the departments behind it.
     *
     * @param  array<int, int>|null  $departmentIds  a department filter already applied
     * @return array{kind: string, label: string, detail: string, count: int, departments: array<int, string>, scope: DataScope}
     */
    public function summary(User $user, string $permission, ?array $departmentIds = null): array
    {
        $scope = $this->scope($user, $permission);
        $count = count($this->workingEmployeeIds($user, $permission, $departmentIds));
        $companyWide = $this->isCompanyWide($user, $permission);

        $departments = $departmentIds !== null
            ? Department::whereIn('id', $departmentIds)->orderBy('name')->pluck('name')->all()
            : ($companyWide ? [] : $this->departmentOptions($user, $permission)->pluck('name')->all());

        [$kind, $label] = match (true) {
            $departmentIds !== null => ['department', implode(', ', $departments) ?: 'Selected department'],
            $companyWide => ['company', 'Company-wide'],
            in_array($scope, [DataScope::Department, DataScope::SelectedDepartments], true) => ['department', implode(', ', $departments) ?: 'My department'],
            $scope === DataScope::Own => ['own', 'My records'],
            $scope === DataScope::None && $this->employeeIds($user, $permission) === [] => ['none', 'No access'],
            default => ['team', 'My team'],
        };

        $detail = $kind === 'team' && $count === count($this->directReportIds($user))
            ? $count.' '.($count === 1 ? 'direct report' : 'direct reports')
            : $count.' '.($count === 1 ? 'employee' : 'employees');

        return ['kind' => $kind, 'label' => $label, 'detail' => $detail, 'count' => $count, 'departments' => $departments, 'scope' => $scope];
    }

    public function assertCanView(User $user, string $permission, ?Employee $employee): void
    {
        $this->guard->assertCanView($user, $employee, $permission);
    }

    public function assertCanDecide(User $user, string $permission, ?Employee $employee): void
    {
        $this->guard->assertCanDecide($user, $employee, $permission);
    }
}
