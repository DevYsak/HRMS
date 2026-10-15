<?php

namespace App\Services\DataQuality;

use App\Enums\DataScope;
use App\Enums\UserRole;
use App\Models\CoordinatorAssignment;
use App\Models\Department;
use App\Models\DepartmentTeam;
use App\Models\DepartmentTeamMember;
use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Attendance\ShiftResolver;
use App\Services\Qa\RoleAccessMatrix;
use App\Services\Security\PermissionScopes;
use App\Services\Security\RoleDelegationGuard;
use App\Services\Security\ScopeResolver;
use App\Services\Teams\ApprovalRoutingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Org structure & data-quality audit. READ-ONLY: it loads employees, users,
 * departments, teams, roles and grants, and reports what is missing,
 * contradictory or unroutable. It never writes, never "fixes" — every
 * finding is for HR (data) or for a decision (policy).
 *
 * It reuses the rules the app already runs on rather than re-deriving them:
 * Employee::missingHrFields() / ShiftResolver for completeness, ApprovalGuard
 * and ApprovalRoutingService for who decides, ScopeResolver for reach, and
 * RolesAndPermissionsSeeder + PermissionScopes for the expected role grants.
 */
class DataQualityAuditor
{
    public const CRITICAL = 'critical';

    public const HIGH = 'high';

    public const WARNING = 'warning';

    public const INFO = 'info';

    /** @var array<int, string> */
    public const SEVERITIES = [self::CRITICAL, self::HIGH, self::WARNING, self::INFO];

    /**
     * Every check: label, severity, area, nature (data = HR correction,
     * policy = decision needed), suggested action.
     *
     * @var array<string, array{label: string, severity: string, area: string, nature: string, action: string}>
     */
    public const CHECKS = [
        // Master data
        'no_department' => ['label' => 'Active employee without department', 'severity' => self::HIGH, 'area' => 'Master data', 'nature' => 'data', 'action' => 'Assign the department on the employee record.'],
        'department_missing' => ['label' => 'Employee assigned to a department that does not exist', 'severity' => self::CRITICAL, 'area' => 'Master data', 'nature' => 'data', 'action' => 'Re-assign the employee to an existing department.'],
        'no_manager' => ['label' => 'Active employee without reporting manager', 'severity' => self::HIGH, 'area' => 'Reporting', 'nature' => 'data', 'action' => 'REVIEW REQUIRED: set the reporting manager. Only Super Admin, Director, HR Admin and Department Heads / heads of function are exempt (policy, 15 Oct).'],
        'no_shift' => ['label' => 'Active employee without a shift', 'severity' => self::HIGH, 'area' => 'Master data', 'nature' => 'data', 'action' => 'Assign a shift (or set a company default) — attendance cannot be scored without one.'],
        'no_job_title' => ['label' => 'Active employee without job title', 'severity' => self::WARNING, 'area' => 'Master data', 'nature' => 'data', 'action' => 'Set the designation.'],
        'no_employment_type' => ['label' => 'Active employee without employment type', 'severity' => self::WARNING, 'area' => 'Master data', 'nature' => 'data', 'action' => 'Set the employment type (drives probation, notice and eligibility).'],
        'no_work_mode' => ['label' => 'Active employee without work mode', 'severity' => self::WARNING, 'area' => 'Master data', 'nature' => 'data', 'action' => 'Set the work mode.'],
        'placeholder_email' => ['label' => 'Employee still on a placeholder email', 'severity' => self::INFO, 'area' => 'Identity', 'nature' => 'data', 'action' => 'Record the real work email.'],
        // Reporting
        'self_manager' => ['label' => 'Employee reports to themselves', 'severity' => self::CRITICAL, 'area' => 'Reporting', 'nature' => 'data', 'action' => 'Set a different reporting manager.'],
        'circular_chain' => ['label' => 'Circular reporting chain', 'severity' => self::CRITICAL, 'area' => 'Reporting', 'nature' => 'data', 'action' => 'Break the loop: one person in the chain must report outside it.'],
        'inactive_manager' => ['label' => 'Employee reports to an inactive manager', 'severity' => self::HIGH, 'area' => 'Reporting', 'nature' => 'data', 'action' => 'Re-assign to an active manager.'],
        'manager_invalid' => ['label' => 'Reporting manager cannot act for this employee', 'severity' => self::HIGH, 'area' => 'Reporting', 'nature' => 'data', 'action' => 'The manager holds no approval permission: change the manager or their role.'],
        'manager_cross_department' => ['label' => 'Manager in another department', 'severity' => self::INFO, 'area' => 'Reporting', 'nature' => 'policy', 'action' => 'REVIEW REQUIRED: confirm the cross-department line is intended (allowed when the manager can approve). Unverified people are classified by HR / Super Admin: real, demo, test / leftover, or archive / delete.'],
        // Departments
        'department_no_head' => ['label' => 'Department without department head', 'severity' => self::WARNING, 'area' => 'Departments', 'nature' => 'data', 'action' => 'HR Data Correction Required: record the real department head. Heads are never assigned automatically.'],
        'department_head_inactive' => ['label' => 'Department head is inactive', 'severity' => self::HIGH, 'area' => 'Departments', 'nature' => 'data', 'action' => 'Set an active department head.'],
        'department_head_outside' => ['label' => 'Department head belongs to another department', 'severity' => self::INFO, 'area' => 'Departments', 'nature' => 'policy', 'action' => 'Confirm the head is meant to lead this department from outside it.'],
        'department_head_unauthorised' => ['label' => 'Department head lacks Department Head / Director authorisation', 'severity' => self::WARNING, 'area' => 'Departments', 'nature' => 'data', 'action' => 'Give the head the Department Head (or Director) role, or choose another head.'],
        'department_multiple_heads' => ['label' => 'Several Department Heads in one department', 'severity' => self::WARNING, 'area' => 'Departments', 'nature' => 'policy', 'action' => 'Only one head is recorded per department; confirm who leads it.'],
        'department_empty' => ['label' => 'Department with no active employees', 'severity' => self::INFO, 'area' => 'Departments', 'nature' => 'data', 'action' => 'Archive it if unused.'],
        // Teams
        'team_no_lead' => ['label' => 'Team without a team lead', 'severity' => self::WARNING, 'area' => 'Teams', 'nature' => 'data', 'action' => 'Set the team lead.'],
        'team_lead_inactive' => ['label' => 'Team lead is inactive', 'severity' => self::HIGH, 'area' => 'Teams', 'nature' => 'data', 'action' => 'Set an active team lead.'],
        'team_orphan_membership' => ['label' => 'Orphaned team membership', 'severity' => self::WARNING, 'area' => 'Teams', 'nature' => 'data', 'action' => 'End the membership (the employee or team no longer exists / is inactive).'],
        'team_department_mismatch' => ['label' => 'Team member in another department', 'severity' => self::WARNING, 'area' => 'Teams', 'nature' => 'data', 'action' => 'Move the employee to a team in their own department, or confirm.'],
        'team_multiple' => ['label' => 'Employee in more than one active team', 'severity' => self::WARNING, 'area' => 'Teams', 'nature' => 'data', 'action' => 'Keep one active team per employee.'],
        // Org chart / routing consistency
        'team_lead_not_manager' => ['label' => 'Team lead differs from reporting manager', 'severity' => self::WARNING, 'area' => 'Consistency', 'nature' => 'data', 'action' => 'Align the reporting manager with the team lead, or confirm both should be notified.'],
        'review_reviewer_not_manager' => ['label' => 'Open performance review assigned to someone other than the manager', 'severity' => self::WARNING, 'area' => 'Consistency', 'nature' => 'data', 'action' => 'Re-assign the reviewer, or confirm the reviewer was chosen on purpose.'],
        'routing_unresolved' => ['label' => 'Approval chain cannot be resolved', 'severity' => self::CRITICAL, 'area' => 'Approvals', 'nature' => 'data', 'action' => 'Nobody can approve these requests: set a manager / head or check HR coverage.'],
        'routing_first_line' => ['label' => 'First approver cannot decide some requests', 'severity' => self::WARNING, 'area' => 'Approvals', 'nature' => 'data', 'action' => 'The request is routed to someone without that approval permission; it falls to the next approver.'],
        // Identity
        'duplicate_employee_code' => ['label' => 'Duplicate employee code', 'severity' => self::CRITICAL, 'area' => 'Identity', 'nature' => 'data', 'action' => 'Give each employee a unique code (biometric sync matches on it).'],
        'duplicate_email' => ['label' => 'Duplicate work email', 'severity' => self::CRITICAL, 'area' => 'Identity', 'nature' => 'data', 'action' => 'Give each account a unique email.'],
        'employee_without_user' => ['label' => 'Active employee without a login account', 'severity' => self::HIGH, 'area' => 'Identity', 'nature' => 'data', 'action' => 'Create or restore the employee\'s user account.'],
        'user_without_employee' => ['label' => 'Login account without an employee record', 'severity' => self::WARNING, 'area' => 'Identity', 'nature' => 'data', 'action' => 'REVIEW REQUIRED: HR / Super Admin decide whether it is real, demo, test / leftover, or to archive / delete. Nothing is changed automatically.'],
        // Roles & scope
        'role_no_users' => ['label' => 'Role with no users', 'severity' => self::INFO, 'area' => 'Roles', 'nature' => 'policy', 'action' => 'Keep (built-in) or archive (custom) the role.'],
        'custom_role_unused' => ['label' => 'Custom role with no users', 'severity' => self::WARNING, 'area' => 'Roles', 'nature' => 'policy', 'action' => 'REVIEW REQUIRED: HR / Super Admin decide whether it is real, demo, test / leftover, or to archive / delete. Nothing is changed automatically.'],
        'custom_role_no_purpose' => ['label' => 'Custom role with no description', 'severity' => self::INFO, 'area' => 'Roles', 'nature' => 'policy', 'action' => 'Describe what the role is for. REVIEW REQUIRED: HR / Super Admin decide whether it is real, demo, test / leftover, or to archive / delete. Nothing is changed automatically.'],
        'role_drift' => ['label' => 'Role permissions differ from the built-in defaults', 'severity' => self::WARNING, 'area' => 'Roles', 'nature' => 'policy', 'action' => 'Review the difference in Roles & Permissions. Nothing is reset automatically.'],
        'user_override' => ['label' => 'Per-user permission override', 'severity' => self::INFO, 'area' => 'Roles', 'nature' => 'policy', 'action' => 'Confirm the per-user grant / revoke is still wanted.'],
        'user_override_privileged' => ['label' => 'Per-user grant of a privileged permission', 'severity' => self::HIGH, 'area' => 'Roles', 'nature' => 'policy', 'action' => 'Confirm a Super Admin approved this grant.'],
        'role_bucket_mismatch' => ['label' => 'User role and legacy role disagree', 'severity' => self::WARNING, 'area' => 'Roles', 'nature' => 'data', 'action' => 'Re-save the user\'s role so both agree.'],
        'scope_without_departments' => ['label' => 'Department-scoped grant with no departments', 'severity' => self::HIGH, 'area' => 'Scope', 'nature' => 'data', 'action' => 'Choose the departments, or widen the scope — today it reaches nobody.'],
        'scope_unknown_department' => ['label' => 'Scope names a department that does not exist', 'severity' => self::HIGH, 'area' => 'Scope', 'nature' => 'data', 'action' => 'Remove the missing department from the scope.'],
        'manager_no_reports' => ['label' => 'Manager with no direct reports', 'severity' => self::WARNING, 'area' => 'Roles', 'nature' => 'data', 'action' => 'Assign reports, or change the role.'],
        'department_head_no_department' => ['label' => 'Department Head heading no department', 'severity' => self::HIGH, 'area' => 'Departments', 'nature' => 'data', 'action' => 'Set them as head of their department — Department View shows nothing until then.'],
        'coordinator_no_assignment' => ['label' => 'Coordinator with nothing assigned', 'severity' => self::INFO, 'area' => 'Roles', 'nature' => 'data', 'action' => 'Assign the departments or people they coordinate.'],
    ];

    /** Request types and the permission that decides them. */
    public const REQUEST_TYPES = [
        'leave' => 'approve_leave',
        'overtime' => 'approve_overtime',
        'wfh' => 'approve_wfh',
        'regularisation' => 'approve_regularisation',
        'performance' => 'review_performance',
        'probation' => 'probation',
    ];

    /** Review statuses that are finished (the reviewer no longer matters). */
    private const CLOSED_REVIEW_STATUSES = ['submitted', 'completed', 'finalized', 'finalised', 'closed', 'acknowledged', 'approved', 'cancelled'];

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    /** @var Collection<int, DataQualityIssue>|null */
    private ?Collection $issues = null;

    public function __construct(
        private readonly ApprovalGuard $guard,
        private readonly ScopeResolver $scopes,
        private readonly ApprovalRoutingService $routing,
    ) {}

    /**
     * Every issue, worst first.
     *
     * @return Collection<int, DataQualityIssue>
     */
    public function issues(): Collection
    {
        return $this->issues ??= collect([
            ...$this->employeeChecks(),
            ...$this->reportingChecks(),
            ...$this->departmentChecks(),
            ...$this->teamChecks(),
            ...$this->consistencyChecks(),
            ...$this->identityChecks(),
            ...$this->roleChecks(),
            ...$this->routingChecks(),
        ])->sortBy(fn (DataQualityIssue $i) => [array_search($i->severity, self::SEVERITIES, true), $i->check, $i->subjectLabel])->values();
    }

    /** @return array{employees: int, departments: int, managers: int, roles: int, users: int, teams: int} */
    public function checkedCounts(): array
    {
        $d = $this->data();

        return [
            'employees' => $d['active']->count(),
            'departments' => $d['departments']->count(),
            'managers' => count($this->managerHealth()),
            'roles' => $d['roles']->count(),
            'users' => $d['users']->whereNull('deleted_at')->count(),
            'teams' => $d['teams']->count(),
        ];
    }

    /**
     * The reporting structure for every active employee.
     *
     * @return array<int, array<string, mixed>>
     */
    public function structure(): array
    {
        $d = $this->data();

        return $d['active']->map(function (Employee $e) use ($d) {
            $manager = $e->manager_id ? $d['usersById']->get($e->manager_id) : null;
            $managerEmployee = $e->manager_id ? $d['employeesByUser']->get($e->manager_id) : null;
            $department = $d['departments']->get($e->department_id);
            $user = $e->user;

            return [
                'employee_id' => $e->id,
                'employee' => $this->name($e),
                'code' => $e->employee_code,
                'status' => $e->status->value,
                'department_id' => $e->department_id,
                'department' => $department?->name,
                'job_title' => $e->jobTitle?->name,
                'manager' => $manager?->name,
                'manager_department' => $managerEmployee?->department_id ? $d['departments']->get($managerEmployee->department_id)?->name : null,
                'department_head' => $department?->head_id ? $d['usersById']->get($department->head_id)?->name : null,
                'shift' => $e->shift?->name ?? (ShiftResolver::hasResolvableShift($e) ? 'Company default' : null),
                'employment_type' => $e->employmentType?->name,
                'role' => $user?->assignedRole?->name ?? ($user?->role?->value ?? null),
                'scope' => $user ? (RoleAccessMatrix::scopeLabel($this->scopes->scopeFor($user, 'view_employee')) ?? 'None') : null,
                'line' => $this->managerLine($e),
            ];
        })->values()->all();
    }

    /**
     * VALID / REVIEW REQUIRED / INVALID for the employee's reporting line.
     * No manager: VALID only for a genuinely top-level employee
     * (Employee::isTopOfHierarchy, policy 15 Oct), otherwise REVIEW REQUIRED.
     * A cross-department manager is fine when policy lets them act: they
     * head the department, are company-wide, or hold approval rights.
     */
    public function managerLine(Employee $employee): ?string
    {
        if ($employee->manager_id === null) {
            return $employee->isTopOfHierarchy() ? 'VALID' : 'REVIEW REQUIRED';
        }

        $d = $this->data();
        $manager = $d['usersById']->get($employee->manager_id);
        if ($manager === null || $manager->deleted_at !== null || $employee->manager_id === $employee->user_id || ! $this->isActiveManager($manager)) {
            return 'INVALID';
        }

        if (! $this->canAct($manager)) {
            return 'INVALID';
        }

        $managerEmployee = $d['employeesByUser']->get($manager->id);
        $department = $d['departments']->get($employee->department_id);

        return match (true) {
            $managerEmployee !== null && $managerEmployee->department_id === $employee->department_id => 'VALID',
            $department !== null && (int) $department->head_id === (int) $manager->id => 'VALID',
            $this->isTopLevel($manager) => 'VALID',
            default => 'REVIEW REQUIRED',
        };
    }

    /**
     * One row per department.
     *
     * @return array<int, array<string, mixed>>
     */
    public function departmentHealth(): array
    {
        $d = $this->data();
        $issues = $this->issues()->where('subjectType', 'department')->groupBy('subjectId');

        return $d['departments']->map(function (Department $dept) use ($d, $issues) {
            $head = $dept->head_id ? $d['usersById']->get($dept->head_id) : null;
            $members = $d['active']->where('department_id', $dept->id);

            return [
                'department_id' => $dept->id,
                'department' => $dept->name,
                'head' => $head?->name,
                'head_user_id' => $head?->id,
                'head_role' => $head ? ($head->assignedRole?->name ?? $head->role?->value) : null,
                'head_scope' => $head ? RoleAccessMatrix::scopeLabel($this->scopes->scopeFor($head, 'view_employee')) : null,
                'employees' => $members->count(),
                'managers' => $members->pluck('manager_id')->filter()->unique()->map(fn ($id) => $d['usersById']->get($id)?->name)->filter()->values()->all(),
                'teams' => $d['teams']->where('department_id', $dept->id)->count(),
                'flags' => ($issues->get($dept->id) ?? collect())->map(fn (DataQualityIssue $i) => $i->label())->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * Everyone who manages people: the Manager role, or anyone with direct reports.
     *
     * @return array<int, array<string, mixed>>
     */
    public function managerHealth(): array
    {
        $d = $this->data();
        $issues = $this->issues();

        $managerIds = $d['users']->whereNull('deleted_at')->filter(fn (User $u) => $u->assignedRole?->slug === 'manager')->pluck('id')
            ->merge($d['employees']->pluck('manager_id')->filter())
            ->unique();

        return $managerIds->map(function (int $id) use ($d, $issues) {
            $user = $d['usersById']->get($id);
            if ($user === null) {
                return null;
            }
            $own = $d['employeesByUser']->get($id);
            $reports = $d['active']->where('manager_id', $id);
            $flags = $issues->filter(fn (DataQualityIssue $i) => ($i->subjectType === 'user' && $i->subjectId === $id)
                || ($i->check === 'manager_invalid' && $reports->contains('id', $i->employeeId)))->map(fn ($i) => $i->label())->unique()->values()->all();
            $outOfScope = $reports->reject(fn (Employee $e) => $this->guard->covers($user, $e, 'approve_leave'))->count();
            if ($outOfScope > 0) {
                $flags[] = "{$outOfScope} report(s) outside their approval scope";
            }
            $exited = $d['employees']->where('manager_id', $id)->filter(fn (Employee $e) => $e->status->isExited())->count();
            if ($exited > 0) {
                $flags[] = "{$exited} former employee(s) still list them as manager";
            }
            $starting = $d['employees']->where('manager_id', $id)->filter(fn (Employee $e) => ! $e->status->isActive() && ! $e->status->isExited())->count();
            if ($starting > 0 && ! $this->canAct($user)) {
                $flags[] = "{$starting} joiner(s) will report to someone with no approval permission";
            }

            return [
                'user_id' => $id,
                'manager' => $user->name,
                'role' => $user->assignedRole?->name ?? $user->role?->value,
                'department_id' => $own?->department_id,
                'department' => $own?->department_id ? $d['departments']->get($own->department_id)?->name : null,
                'direct_reports' => $reports->count(),
                'teams' => $own ? $d['teams']->where('team_lead_id', $own->id)->pluck('name')->values()->all() : [],
                'permissions' => collect(['approve_leave', 'approve_overtime', 'approve_wfh', 'approve_regularisation', 'review_performance'])->filter(fn ($p) => $user->hasPermission($p))->values()->all(),
                'scope' => RoleAccessMatrix::scopeLabel($this->scopes->scopeFor($user, 'approve_leave')) ?? 'None',
                'flags' => array_values(array_unique($flags)),
            ];
        })->filter()->sortBy('manager')->values()->all();
    }

    /**
     * Built-in roles: expected (seeder defaults) vs actual (database).
     *
     * @return array<int, array{role: string, slug: string, users: int, expected: int, actual: int, missing: array<int, string>, extra: array<int, string>, reason: ?string}>
     */
    public function roleDrift(): array
    {
        $d = $this->data();
        $allKeys = $d['permissionKeys'];
        $policyApplied = $this->migrationRan('2026_10_15_111021_apply_phase1_role_policy_decisions');

        return $d['roles']->map(function (Role $role) use ($allKeys, $policyApplied, $d) {
            $actual = $role->permissions->pluck('key')->sort()->values()->all();
            $expected = $this->expectedKeys($role->slug, $allKeys);
            if ($expected === null) {
                return null;   // custom role: no built-in default to compare against
            }
            $missing = array_values(array_diff($expected, $actual));
            $extra = array_values(array_diff($actual, $expected));

            return [
                'role' => $role->name,
                'slug' => $role->slug,
                'users' => $d['users']->whereNull('deleted_at')->where('role_id', $role->id)->count(),
                'expected' => count($expected),
                'actual' => count($actual),
                'missing' => $missing,
                'extra' => $extra,
                'reason' => ($missing === [] && $extra === []) ? null : collect([
                    ...array_map(fn ($k) => $this->driftReason($role->slug, $k, true, $policyApplied), $missing),
                    ...array_map(fn ($k) => $this->driftReason($role->slug, $k, false, $policyApplied), $extra),
                ])->unique()->implode(' '),
            ];
        })->filter()->values()->all();
    }

    /**
     * Per-user overrides, with whether the role already grants the key.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overrides(): array
    {
        $d = $this->data();

        return $d['overrides']->map(fn (UserPermissionOverride $o) => [
            'user' => $d['usersById']->get($o->user_id)?->name ?? "#{$o->user_id}",
            'user_id' => $o->user_id,
            'role' => $d['usersById']->get($o->user_id)?->assignedRole?->name,
            'permission' => $o->permission?->key,
            'effect' => $o->effect,
            'scope' => $o->scope,
            'role_has_it' => in_array($o->permission?->key, $d['usersById']->get($o->user_id)?->assignedRole?->permissions->pluck('key')->all() ?? [], true),
        ])->values()->all();
    }

    /**
     * Expected first approver and who can decide, per active employee and request type.
     *
     * @return array<int, array<string, mixed>>
     */
    public function routing(): array
    {
        return $this->data()['active']->map(function (Employee $e) {
            [$first, $chain] = $this->chainFor($e);
            $types = [];
            foreach (self::REQUEST_TYPES as $type => $permission) {
                $decider = $chain->first(fn (User $u) => $this->canDecide($u, $e, $permission));
                $types[$type] = [
                    'first_can_decide' => $first !== null && $this->canDecide($first, $e, $permission),
                    'decider' => $decider?->name,
                ];
            }

            return [
                'employee_id' => $e->id,
                'employee' => $this->name($e),
                'department' => $this->data()['departments']->get($e->department_id)?->name,
                'first_approver' => $first?->name,
                'types' => $types,
            ];
        })->values()->all();
    }

    // ─────────────────────────────── Checks ───────────────────────────────

    /** @return array<int, DataQualityIssue> */
    private function employeeChecks(): array
    {
        $d = $this->data();
        $issues = [];
        foreach ($d['active'] as $e) {
            $missing = $e->missingHrFields();
            if ($e->department_id !== null && ! $d['departments']->has($e->department_id)) {
                $issues[] = $this->employeeIssue('department_missing', $e, "Department #{$e->department_id} does not exist.");
            } elseif (isset($missing['department_id'])) {
                $issues[] = $this->employeeIssue('no_department', $e, 'No department recorded.');
            }
            foreach (['manager_id' => 'no_manager', 'shift' => 'no_shift', 'job_title_id' => 'no_job_title', 'employment_type_id' => 'no_employment_type', 'work_mode_id' => 'no_work_mode'] as $field => $check) {
                if (isset($missing[$field])) {
                    $issues[] = $this->employeeIssue($check, $e, "{$missing[$field]} not recorded.");
                }
            }
            if ($e->has_placeholder_email) {
                $issues[] = $this->employeeIssue('placeholder_email', $e, 'The login email is a placeholder.');
            }
        }

        return $issues;
    }

    /** @return array<int, DataQualityIssue> */
    private function reportingChecks(): array
    {
        $d = $this->data();
        $issues = [];
        foreach ($d['active'] as $e) {
            if ($e->manager_id === null) {
                continue;
            }
            $manager = $d['usersById']->get($e->manager_id);
            if ($e->manager_id === $e->user_id) {
                $issues[] = $this->employeeIssue('self_manager', $e, 'The reporting manager is the employee\'s own account.');

                continue;
            }
            if ($manager === null || ! $this->isActiveManager($manager)) {
                $issues[] = $this->employeeIssue('inactive_manager', $e, 'Reports to '.($manager?->name ?? "user #{$e->manager_id}").', who is no longer active.');

                continue;
            }
            $line = $this->managerLine($e);
            if ($line === 'INVALID') {
                $issues[] = $this->employeeIssue('manager_invalid', $e, "{$manager->name} holds no approval permission for their reports.");
            } elseif ($line === 'REVIEW REQUIRED') {
                $managerDept = $d['departments']->get($d['employeesByUser']->get($manager->id)?->department_id)?->name ?? 'no department';
                $issues[] = $this->employeeIssue('manager_cross_department', $e, "Manager {$manager->name} is in {$managerDept}.");
            }
        }

        // Circular chains: follow manager links from each active employee.
        $next = $d['employees']->filter(fn (Employee $e) => $e->user_id && $e->manager_id && $e->manager_id !== $e->user_id)
            ->mapWithKeys(fn (Employee $e) => [$e->user_id => $e->manager_id]);
        $reported = [];
        foreach ($d['active'] as $e) {
            if (! $e->user_id) {
                continue;
            }
            $seen = [$e->user_id];
            $cursor = $next->get($e->user_id);
            while ($cursor !== null && count($seen) <= $next->count() + 1) {
                if ($cursor === $e->user_id) {
                    $loop = $seen;
                    sort($loop);
                    $key = implode('-', $loop);
                    if (! isset($reported[$key])) {
                        $reported[$key] = true;
                        $names = collect($seen)->map(fn ($id) => $d['usersById']->get($id)?->name ?? "#{$id}")->implode(' → ');
                        $issues[] = $this->employeeIssue('circular_chain', $e, "{$names} → back to the start.");
                    }
                    break;
                }
                if (in_array($cursor, $seen, true)) {
                    break;   // a loop further up, reported from its own members
                }
                $seen[] = $cursor;
                $cursor = $next->get($cursor);
            }
        }

        return $issues;
    }

    /** @return array<int, DataQualityIssue> */
    private function departmentChecks(): array
    {
        $d = $this->data();
        $issues = [];
        foreach ($d['departments'] as $dept) {
            $label = $dept->name;
            $issue = fn (string $check, string $message) => new DataQualityIssue($check, self::CHECKS[$check]['severity'], 'department', $dept->id, $label, $message, $dept->id, $dept->name);
            $active = $d['active']->where('department_id', $dept->id);

            if ($dept->head_id === null) {
                $issues[] = $issue('department_no_head', 'No department head recorded.');
            } else {
                $head = $d['usersById']->get($dept->head_id);
                if ($head === null || ! $this->isActiveManager($head)) {
                    $issues[] = $issue('department_head_inactive', ($head?->name ?? "User #{$dept->head_id}").' is no longer active.');
                } else {
                    $headEmployee = $d['employeesByUser']->get($head->id);
                    if ($headEmployee !== null && $headEmployee->department_id !== $dept->id) {
                        $issues[] = $issue('department_head_outside', "{$head->name} belongs to ".($d['departments']->get($headEmployee->department_id)?->name ?? 'no department').'.');
                    }
                    if (! $this->canHead($head)) {
                        $issues[] = $issue('department_head_unauthorised', "{$head->name} is ".($head->assignedRole?->name ?? 'unassigned').' and cannot open Department View.');
                    }
                }
            }

            $headRoleHolders = $active->filter(fn (Employee $e) => $e->user?->assignedRole?->slug === 'department_head');
            if ($headRoleHolders->count() > 1) {
                $issues[] = $issue('department_multiple_heads', $headRoleHolders->count().' people hold the Department Head role here: '.$headRoleHolders->map(fn ($e) => $this->name($e))->implode(', ').'.');
            }
            if ($active->isEmpty()) {
                $issues[] = $issue('department_empty', 'No active employees.');
            }
        }

        return $issues;
    }

    /** @return array<int, DataQualityIssue> */
    private function teamChecks(): array
    {
        $d = $this->data();
        $issues = [];
        foreach ($d['teams'] as $team) {
            $issue = fn (string $check, string $message, ?Employee $about = null) => new DataQualityIssue(
                $check, self::CHECKS[$check]['severity'], 'team', $team->id, $team->name, $message, $team->department_id,
                $d['departments']->get($team->department_id)?->name, $about?->id, $about?->status?->value,
            );
            if ($team->status !== 'active') {
                continue;
            }
            $lead = $team->team_lead_id ? $d['employeesById']->get($team->team_lead_id) : null;
            if ($team->team_lead_id === null) {
                $issues[] = $issue('team_no_lead', 'No team lead.');
            } elseif ($lead === null || ! $lead->status->isActive()) {
                $issues[] = $issue('team_lead_inactive', 'Team lead '.($lead ? $this->name($lead) : "#{$team->team_lead_id}").' is not active.');
            }
        }

        foreach ($d['memberships'] as $m) {
            $team = $d['teams']->get($m->department_team_id);
            $member = $d['employeesById']->get($m->employee_id);
            if ($team === null || $team->status !== 'active' || $member === null || ! $member->status->isActive()) {
                $issues[] = new DataQualityIssue('team_orphan_membership', self::WARNING, 'team', (int) $m->department_team_id, $team?->name ?? "Team #{$m->department_team_id}",
                    ($member ? $this->name($member) : "Employee #{$m->employee_id}").' is an active member of a '.($team === null ? 'deleted' : ($team->status !== 'active' ? 'inactive' : 'live')).' team'.($member === null || ! $member->status->isActive() ? ' but is no longer active' : '').'.',
                    $team?->department_id, null, $member?->id);

                continue;
            }
            if ($team->department_id !== null && $member->department_id !== null && $team->department_id !== $member->department_id) {
                $issues[] = $this->employeeIssue('team_department_mismatch', $member, "Member of {$team->name} (".($d['departments']->get($team->department_id)?->name ?? '—').').');
            }
        }

        $d['memberships']->groupBy('employee_id')->filter(fn ($rows) => $rows->pluck('department_team_id')->unique()->count() > 1)
            ->each(function ($rows, $employeeId) use (&$issues, $d) {
                if ($e = $d['employeesById']->get($employeeId)) {
                    $issues[] = $this->employeeIssue('team_multiple', $e, 'Active in '.$rows->map(fn ($r) => $d['teams']->get($r->department_team_id)?->name)->filter()->implode(', ').'.');
                }
            });

        return $issues;
    }

    /** @return array<int, DataQualityIssue> */
    private function consistencyChecks(): array
    {
        $d = $this->data();
        $issues = [];

        foreach ($d['active'] as $e) {
            $membership = $d['memberships']->firstWhere('employee_id', $e->id);
            $team = $membership ? $d['teams']->get($membership->department_team_id) : null;
            $lead = $team?->team_lead_id ? $d['employeesById']->get($team->team_lead_id) : null;
            if ($lead !== null && $lead->id !== $e->id && $lead->user_id !== null && $e->manager_id !== null && $lead->user_id !== $e->manager_id) {
                $issues[] = $this->employeeIssue('team_lead_not_manager', $e,
                    'Reporting manager is '.($d['usersById']->get($e->manager_id)?->name ?? '—').", team lead ({$team->name}) is ".$this->name($lead).'.');
            }
        }

        foreach ($d['openReviews'] as $review) {
            $e = $d['employeesById']->get($review->employee_id);
            if ($e === null || ! $e->status->isActive() || $e->manager_id === null || $review->reviewer_id === null || (int) $review->reviewer_id === (int) $e->manager_id) {
                continue;
            }
            $issues[] = $this->employeeIssue('review_reviewer_not_manager', $e,
                'Review #'.$review->id.' ('.$review->status.') is with '.($d['usersById']->get($review->reviewer_id)?->name ?? '—').'; the manager is '.($d['usersById']->get($e->manager_id)?->name ?? '—').'.');
        }

        return $issues;
    }

    /** @return array<int, DataQualityIssue> */
    private function identityChecks(): array
    {
        $d = $this->data();
        $issues = [];

        $d['employees']->whereNotNull('employee_code')->groupBy('employee_code')->filter(fn ($g) => $g->count() > 1)
            ->each(function ($group, $code) use (&$issues) {
                foreach ($group as $e) {
                    $issues[] = $this->employeeIssue('duplicate_employee_code', $e, "Code {$code} is shared by ".$group->count().' employees.');
                }
            });

        $d['users']->whereNull('deleted_at')->groupBy(fn (User $u) => strtolower(trim((string) $u->email)))->filter(fn ($g) => $g->count() > 1)
            ->each(function ($group, $email) use (&$issues) {
                foreach ($group as $u) {
                    $issues[] = new DataQualityIssue('duplicate_email', self::CRITICAL, 'user', $u->id, $u->name, "{$email} is used by ".$group->count().' accounts.');
                }
            });

        foreach ($d['active'] as $e) {
            if ($e->user_id === null || $e->user === null || $e->user->deleted_at !== null) {
                $issues[] = $this->employeeIssue('employee_without_user', $e, $e->user_id === null ? 'No user account linked.' : 'The linked account is deleted.');
            }
        }

        foreach ($d['users']->whereNull('deleted_at') as $u) {
            if (! $d['employeesByUser']->has($u->id) && ! $this->isSystemAccount($u)) {
                $issues[] = new DataQualityIssue('user_without_employee', self::WARNING, 'user', $u->id, $u->name, ($u->assignedRole?->name ?? 'No role').' account with no employee record.');
            }
        }

        return $issues;
    }

    /** @return array<int, DataQualityIssue> */
    private function roleChecks(): array
    {
        $d = $this->data();
        $issues = [];
        $liveUsers = $d['users']->whereNull('deleted_at');

        foreach ($d['roles'] as $role) {
            $count = $liveUsers->where('role_id', $role->id)->count();
            $roleIssue = fn (string $check, string $message) => new DataQualityIssue($check, self::CHECKS[$check]['severity'], 'role', $role->id, $role->name, $message);
            if (! $role->is_system) {
                if ($count === 0) {
                    $issues[] = $roleIssue('custom_role_unused', "Custom role \"{$role->name}\" ({$role->slug}) has no users.");
                }
                if (blank($role->description)) {
                    $issues[] = $roleIssue('custom_role_no_purpose', "Custom role \"{$role->name}\" has no description.");
                }
            } elseif ($count === 0) {
                $issues[] = $roleIssue('role_no_users', "{$role->name} has no users.");
            }

            foreach ($role->permissions as $permission) {
                if ($permission->pivot->scope === DataScope::SelectedDepartments->value && json_decode((string) $permission->pivot->department_ids, true) === []
                    || ($permission->pivot->scope === DataScope::SelectedDepartments->value && blank($permission->pivot->department_ids))) {
                    $issues[] = $roleIssue('scope_without_departments', "{$permission->key} is limited to selected departments but none are chosen.");
                }
            }
        }

        foreach ($this->roleDrift() as $drift) {
            if ($drift['missing'] !== [] || $drift['extra'] !== []) {
                $role = $d['roles']->firstWhere('slug', $drift['slug']);
                $issues[] = new DataQualityIssue('role_drift', self::WARNING, 'role', $role->id, $role->name,
                    trim(($drift['missing'] ? 'Missing: '.implode(', ', $drift['missing']).'. ' : '').($drift['extra'] ? 'Extra: '.implode(', ', $drift['extra']).'.' : '')));
            }
        }

        foreach ($d['overrides'] as $o) {
            $user = $d['usersById']->get($o->user_id);
            $key = $o->permission?->key;
            if ($user === null || $key === null) {
                continue;
            }
            $privileged = $o->effect === 'grant' && in_array($key, RoleDelegationGuard::PRIVILEGED, true);
            $issues[] = new DataQualityIssue($privileged ? 'user_override_privileged' : 'user_override', $privileged ? self::HIGH : self::INFO, 'user', $user->id, $user->name,
                ucfirst($o->effect)." {$key}".($o->scope ? " ({$o->scope})" : '').' on top of the '.($user->assignedRole?->name ?? 'unassigned').' role.');
            if ($o->scope === DataScope::SelectedDepartments->value && empty(json_decode((string) $o->department_ids, true))) {
                $issues[] = new DataQualityIssue('scope_without_departments', self::HIGH, 'user', $user->id, $user->name, "Per-user {$key} is limited to selected departments but none are chosen.");
            }
        }

        foreach ($liveUsers as $u) {
            $slug = $u->assignedRole?->slug;
            if ($u->assignedRole !== null && $u->role !== null && $u->assignedRole->legacyBucket()->value !== $u->role->value) {
                $issues[] = new DataQualityIssue('role_bucket_mismatch', self::WARNING, 'user', $u->id, $u->name, "Role is {$u->assignedRole->name} but the legacy role says {$u->role->value}.");
            }
            foreach (array_map('intval', $u->scope_departments ?? []) as $deptId) {
                if (! $d['departments']->has($deptId)) {
                    $issues[] = new DataQualityIssue('scope_unknown_department', self::HIGH, 'user', $u->id, $u->name, "User scope lists department #{$deptId}, which does not exist.");
                }
            }
            $own = $d['employeesByUser']->get($u->id);
            if ($slug === 'manager' && $d['active']->where('manager_id', $u->id)->isEmpty() && ($own === null || $d['teams']->where('team_lead_id', $own->id)->isEmpty())) {
                $issues[] = new DataQualityIssue('manager_no_reports', self::WARNING, 'user', $u->id, $u->name, 'Holds the Manager role but nobody reports to them.', $own?->department_id, $d['departments']->get($own?->department_id)?->name);
            }
            if ($slug === 'department_head' && $d['departments']->where('head_id', $u->id)->isEmpty()) {
                $issues[] = new DataQualityIssue('department_head_no_department', self::HIGH, 'user', $u->id, $u->name, 'Holds the Department Head role but heads no department.', $own?->department_id, $d['departments']->get($own?->department_id)?->name);
            }
            if ($slug === 'coordinator' && $d['coordinatorAssignments']->where('coordinator_user_id', $u->id)->isEmpty() && empty($u->scope_departments)) {
                $issues[] = new DataQualityIssue('coordinator_no_assignment', self::INFO, 'user', $u->id, $u->name, 'Coordinator with no assigned people or departments (falls back to their own department).');
            }
        }

        return $issues;
    }

    /** @return array<int, DataQualityIssue> */
    private function routingChecks(): array
    {
        $issues = [];
        foreach ($this->routing() as $row) {
            $e = $this->data()['employeesById']->get($row['employee_id']);
            $nobody = collect($row['types'])->filter(fn ($t) => $t['decider'] === null)->keys();
            $notFirst = collect($row['types'])->filter(fn ($t) => $t['decider'] !== null && ! $t['first_can_decide'])->keys();
            if ($nobody->isNotEmpty()) {
                $issues[] = $this->employeeIssue('routing_unresolved', $e, 'Nobody in the approval chain can decide: '.$nobody->implode(', ').'.');
            }
            if ($row['first_approver'] !== null && $notFirst->isNotEmpty()) {
                $issues[] = $this->employeeIssue('routing_first_line', $e, "{$row['first_approver']} receives the request but cannot decide: ".$notFirst->implode(', ').'.');
            }
        }

        return $issues;
    }

    // ─────────────────────────────── Helpers ───────────────────────────────

    /** @return array<string, mixed> */
    private function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $users = User::withTrashed()->with(['assignedRole.permissions'])->get();
        $employees = Employee::query()->with(['user.assignedRole.permissions', 'jobTitle', 'employmentType', 'workMode', 'shift', 'department'])->get();
        $active = $employees->filter(fn (Employee $e) => $e->status->isActive())->values();

        return $this->data = [
            'users' => $users,
            'usersById' => $users->keyBy('id'),
            'employees' => $employees,
            'employeesById' => $employees->keyBy('id'),
            'employeesByUser' => $employees->whereNotNull('user_id')->keyBy('user_id'),
            'active' => $active,
            'departments' => Department::query()->orderBy('name')->get()->keyBy('id'),
            'teams' => DepartmentTeam::query()->get()->keyBy('id'),
            'memberships' => DepartmentTeamMember::query()->where('is_active', true)->whereNull('left_at')->get(),
            'roles' => Role::query()->with('permissions')->orderByDesc('is_system')->orderBy('id')->get(),
            'permissionKeys' => Permission::query()->pluck('key')->all(),
            'overrides' => UserPermissionOverride::query()->with('permission:id,key')->get(),
            'coordinatorAssignments' => Schema::hasTable('coordinator_assignments') ? CoordinatorAssignment::query()->get() : collect(),
            'openReviews' => PerformanceReview::query()->whereNotIn('status', self::CLOSED_REVIEW_STATUSES)->get(['id', 'employee_id', 'reviewer_id', 'status']),
        ];
    }

    /** @return array{0: ?User, 1: Collection<int, User>} first-line approver and the full chain */
    private function chainFor(Employee $e): array
    {
        $d = $this->data();
        $manager = $e->manager_id ? $d['usersById']->get($e->manager_id) : null;
        $manager = $manager !== null && $this->isActiveManager($manager) && $manager->id !== $e->user_id ? $manager : null;
        // Nobody approves their own request: the HR / Super Admin escalation rung can include the requester.
        $chain = collect([$manager])->filter()->merge($this->routing->getApproverChain($e))->unique('id')
            ->reject(fn (User $u) => $u->id === $e->user_id)->values();

        return [$manager ?? $chain->first(), $chain];
    }

    private function canDecide(User $user, Employee $e, string $permission): bool
    {
        if ($user->deleted_at !== null || $user->id === $e->user_id) {
            return false;
        }

        if ($permission === 'probation') {
            // ProbationConfirmation: approve-leave or manage-employees, within reach.
            return ($user->canApproveLeave() || $user->canManageEmployees()) && $user->coversEmployee($e);
        }

        return $user->hasPermission($permission) && $this->guard->covers($user, $e, $permission);
    }

    /** Holds any approval right, so can act for their reports. */
    private function canAct(User $user): bool
    {
        return collect(['approve_leave', 'approve_overtime', 'approve_wfh', 'approve_regularisation'])->contains(fn ($p) => $user->hasPermission($p));
    }

    private function canHead(User $user): bool
    {
        return in_array($user->assignedRole?->slug, ['department_head', 'director', 'hr_admin', 'super_admin'], true)
            || $user->hasPermission('view_department_dashboard');
    }

    private function isTopLevel(User $user): bool
    {
        return in_array($user->role, [UserRole::SuperAdmin, UserRole::Director, UserRole::HrAdmin], true)
            || $this->guard->isCompanyWide($user);
    }

    /** The user exists, is not deleted, and (if they have an employee record) still works here. */
    private function isActiveManager(User $user): bool
    {
        if ($user->deleted_at !== null) {
            return false;
        }
        $employee = $this->data()['employeesByUser']->get($user->id);

        return $employee === null || $employee->status->isActive() || $employee->status->value === 'notice_period';
    }

    private function isSystemAccount(User $user): bool
    {
        return $user->assignedRole?->slug === 'super_admin' || $user->role === UserRole::SuperAdmin;
    }

    /**
     * Seeder defaults for a built-in role; null for a custom role.
     *
     * @param  array<int, string>  $allKeys
     * @return array<int, string>|null
     */
    private function expectedKeys(string $slug, array $allKeys): ?array
    {
        if ($slug === 'super_admin') {
            return collect($allKeys)->sort()->values()->all();
        }
        if (! array_key_exists($slug, RolesAndPermissionsSeeder::ROLE_DEFAULTS)) {
            return null;
        }

        return collect(RolesAndPermissionsSeeder::ROLE_DEFAULTS[$slug])
            ->merge(PermissionScopes::NEW_ROLE_GRANTS[$slug] ?? [])
            ->intersect($allKeys)->unique()->sort()->values()->all();
    }

    /** Why a built-in role differs from its default, where the history is known. */
    private function driftReason(string $slug, string $key, bool $missing, bool $policyApplied): string
    {
        $phase1 = [
            'coordinator' => ['view_team_dashboard' => true, 'view_main_dashboard' => false],
            'department_head' => ['view_department_dashboard' => true],
            'finance' => ['review_performance' => false, 'manage_pip' => false, 'manage_promotions' => false],
        ];

        return match (true) {
            ! $policyApplied && isset($phase1[$slug][$key]) => 'Phase 1 role-policy migration (2026_10_15_111021) not applied on this database.',
            $slug === 'super_admin' && $key === 'view_department_dashboard' && $missing => 'Decided 15 Oct: Super Admin keeps every company-wide capability; Department View opens only once a department is chosen. Grant it back in Roles & Permissions.',
            in_array($key, RoleDelegationGuard::PRIVILEGED, true) && ! $missing => "{$key} is privileged and was added in Roles & Permissions.",
            $missing => "{$key} is a default for this role but is not granted here (removed in Roles & Permissions, or the role predates the grant).",
            default => "{$key} was added in Roles & Permissions.",
        };
    }

    private function migrationRan(string $migration): bool
    {
        return Schema::hasTable('migrations') && DB::table('migrations')->where('migration', $migration)->exists();
    }

    private function employeeIssue(string $check, Employee $e, string $message): DataQualityIssue
    {
        $d = $this->data();

        return new DataQualityIssue($check, self::CHECKS[$check]['severity'], 'employee', $e->id, $this->name($e), $message,
            $e->department_id, $d['departments']->get($e->department_id)?->name, $e->id, $e->status->value);
    }

    private function name(Employee $e): string
    {
        return ($e->user?->name ?? 'Employee #'.$e->id).($e->employee_code ? " ({$e->employee_code})" : '');
    }
}
