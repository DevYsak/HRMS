<?php

namespace App\Services\Approvals;

use App\Enums\UserRole;
use App\Exceptions\ApprovalNotPermitted;
use App\Models\Department;
use App\Models\DepartmentTeam;
use App\Models\DepartmentTeamMember;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Audit\AuditService;
use App\Services\Security\ScopeResolver;

/**
 * The single source of truth for "may this user see / decide this
 * employee's records?" (Phase 1 safety). Fails closed:
 *
 *   - Super Admin                              → every employee.
 *   - Employee-management authority (HR Admin,
 *     Director — `manage_employees`) with no
 *     department/shift scope                   → every employee.
 *   - Anyone with a department/shift scope     → employees inside that scope,
 *                                                plus their own reporting line.
 *   - Everyone else (managers, custom approver
 *     roles) with no scope                     → ONLY their reporting line:
 *       direct reports (employees.manager_id = users.id), members of teams
 *       they lead or second-lead, and departments they head.
 *
 * An empty scope therefore never means company-wide for a plain approver.
 * Deciding additionally forbids acting on your own record.
 *
 * Passing the permission being exercised (e.g. 'approve_leave') applies that
 * permission's configured data scope (ScopeResolver) for anyone who holds it.
 * Someone routed a request without holding the permission (a team lead on an
 * employee role) keeps the reporting-line reach above; someone whose
 * permission was revoked for them personally reaches no one.
 */
class ApprovalGuard
{
    private const SCOPED = 'scoped';

    private const REVOKED = 'revoked';

    private const LEGACY = 'legacy';

    /** Whether the user reaches every employee (no filter needed). */
    public function isCompanyWide(User $user): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $this->hasNoScope($user) && $this->hasEmployeeManagementAuthority($user);
    }

    /**
     * HR Admin / Director-level authority: the `manage_employees` permission,
     * or — for an account whose DB role was never linked — the legacy
     * HR Admin / Director role it still carries.
     */
    private function hasEmployeeManagementAuthority(User $user): bool
    {
        if ($user->hasPermission('manage_employees')) {
            return true;
        }

        return $user->role_id === null
            && in_array($user->role?->value, [UserRole::HrAdmin->value, UserRole::Director->value], true);
    }

    /** Does the user's scope or reporting line cover this employee? */
    public function covers(User $user, Employee $employee, ?string $permission = null): bool
    {
        $reach = $this->reachFor($user, $permission);

        if ($reach === self::SCOPED) {
            return $this->scopes()->covers($user, $permission, $employee);
        }

        if ($reach === self::REVOKED) {
            return false;
        }

        if ($this->isCompanyWide($user)) {
            return true;
        }

        if (! $this->hasNoScope($user) && $this->withinExplicitScope($user, $employee)) {
            return true;
        }

        return in_array($employee->id, $this->reportingLineIds($user), true);
    }

    /**
     * Employee ids the user may see/approve, or NULL when company-wide.
     *
     * @return array<int, int>|null
     */
    public function accessibleEmployeeIds(User $user, ?string $permission = null): ?array
    {
        $reach = $this->reachFor($user, $permission);

        if ($reach === self::SCOPED) {
            return $this->scopes()->employeeIds($user, $permission, includeSelf: false);
        }

        if ($reach === self::REVOKED) {
            return [];
        }

        if ($this->isCompanyWide($user)) {
            return null;
        }

        $ids = $this->reportingLineIds($user);

        if (! $this->hasNoScope($user)) {
            $scoped = Employee::query()
                ->when($user->scope_departments, fn ($q, $d) => $q->whereIn('department_id', $d))
                ->when($user->scope_shifts, fn ($q, $s) => $q->whereIn('shift_id', $s))
                ->pluck('id')
                ->all();

            $ids = array_merge($ids, $scoped);
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Is this employee record the user's own? */
    public function isSelf(User $user, ?Employee $employee): bool
    {
        return $employee !== null && (int) $employee->user_id === (int) $user->id;
    }

    /**
     * The actor may open/read the employee's request — their own, or one
     * inside their reach.
     *
     * @throws ApprovalNotPermitted
     */
    public function assertCanView(User $user, ?Employee $employee, ?string $permission = null): void
    {
        if ($employee === null) {
            throw ApprovalNotPermitted::outOfScope();
        }

        if ($this->isSelf($user, $employee) || $this->covers($user, $employee, $permission)) {
            return;
        }

        throw $this->denied(ApprovalNotPermitted::outOfScope(), $user, $employee, 'view');
    }

    /**
     * The actor may decide (approve / reject / edit / override) the
     * employee's request: never their own, and only inside their reach.
     *
     * @throws ApprovalNotPermitted
     */
    public function assertCanDecide(User|int|null $user, ?Employee $employee, ?string $permission = null): void
    {
        $user = $user instanceof User ? $user : ($user ? User::find($user) : null);

        if ($user === null || $employee === null) {
            throw ApprovalNotPermitted::outOfScope();
        }

        if ($this->isSelf($user, $employee)) {
            throw $this->denied(ApprovalNotPermitted::selfApproval(), $user, $employee, 'decide');
        }

        if (! $this->covers($user, $employee, $permission)) {
            throw $this->denied(ApprovalNotPermitted::outOfScope(), $user, $employee, 'decide');
        }
    }

    /**
     * Self-approval prohibition alone, for company-wide functional sign-offs
     * (e.g. Finance) that are not tied to an employee's reporting line.
     *
     * @throws ApprovalNotPermitted
     */
    public function assertNotSelf(User|int|null $user, ?Employee $employee): void
    {
        $user = $user instanceof User ? $user : ($user ? User::find($user) : null);

        if ($user === null) {
            throw ApprovalNotPermitted::outOfScope();
        }

        if ($this->isSelf($user, $employee)) {
            throw $this->denied(ApprovalNotPermitted::selfApproval(), $user, $employee, 'decide');
        }
    }

    /**
     * Ids of employees in the user's own reporting line (never including
     * the user's own employee record).
     *
     * @return array<int, int>
     */
    public function reportingLineIds(User $user): array
    {
        // An unsaved user has no reporting line — and a null id must never
        // turn `manager_id = ?` into `manager_id IS NULL`.
        if ($user->id === null) {
            return [];
        }

        // Queried rather than read via the relation, so a null is never cached
        // on a user instance that is still being set up.
        $ownEmployeeId = Employee::where('user_id', $user->id)->value('id');

        $direct = Employee::where('manager_id', $user->id)->pluck('id');

        $teamIds = $ownEmployeeId
            ? DepartmentTeam::where('status', 'active')
                ->where(fn ($q) => $q->where('team_lead_id', $ownEmployeeId)->orWhere('secondary_lead_id', $ownEmployeeId))
                ->pluck('id')
            : collect();

        $teamMembers = $teamIds->isNotEmpty()
            ? DepartmentTeamMember::whereIn('department_team_id', $teamIds)->where('is_active', true)->pluck('employee_id')
            : collect();

        $headedDepartments = Department::where('head_id', $user->id)->pluck('id');

        $departmentMembers = $headedDepartments->isNotEmpty()
            ? Employee::whereIn('department_id', $headedDepartments)->pluck('id')
            : collect();

        return $direct->merge($teamMembers)->merge($departmentMembers)
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === (int) $ownEmployeeId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Record a refused attempt as a security event (feeds the Super Admin
     * "failed / suspicious actions" view) and hand the exception back.
     */
    private function denied(ApprovalNotPermitted $exception, User $user, Employee $employee, string $attempt): ApprovalNotPermitted
    {
        rescue(fn () => app(AuditService::class)->event(
            'APPROVAL_DENIED',
            AuditService::SECURITY,
            $employee,
            new: ['attempt' => $attempt, 'actor_user_id' => $user->id, 'reason' => $exception->getMessage()],
            subjectEmployeeId: $employee->id,
            module: AuditService::APPROVALS,
            action: 'denied',
        ), report: false);

        return $exception;
    }

    /** Which reach applies for this permission: its configured scope, none, or the legacy rules. */
    private function reachFor(User $user, ?string $permission): string
    {
        if ($permission === null) {
            return self::LEGACY;
        }

        if ($user->hasPermission($permission)) {
            return self::SCOPED;
        }

        return ($user->permissionOverride($permission)['effect'] ?? null) === UserPermissionOverride::REVOKE
            ? self::REVOKED
            : self::LEGACY;
    }

    private function scopes(): ScopeResolver
    {
        return app(ScopeResolver::class);
    }

    private function isSuperAdmin(User $user): bool
    {
        return $user->isSuperAdmin() || $user->assignedRole?->slug === 'super_admin';
    }

    private function hasNoScope(User $user): bool
    {
        return empty($user->scope_departments) && empty($user->scope_shifts);
    }

    private function withinExplicitScope(User $user, Employee $employee): bool
    {
        $deptOk = empty($user->scope_departments) || in_array($employee->department_id, $user->scope_departments);
        $shiftOk = empty($user->scope_shifts) || in_array($employee->shift_id, $user->scope_shifts);

        return $deptOk && $shiftOk;
    }
}
