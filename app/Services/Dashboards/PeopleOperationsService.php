<?php

namespace App\Services\Dashboards;

use App\Models\Department;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\User;
use App\Services\Approvals\ApprovalQueueService;
use App\Services\Attendance\TodayStatusService;
use App\Services\Performance\PerformanceStatusService;
use App\Services\Profile\ProfileCompletionService;
use App\Services\Security\PeopleScope;
use Illuminate\Support\Collection;

/**
 * "Who needs attention, what is pending, where, and who acts next" — for the
 * HR dashboard, Department Health and the Department Overview.
 *
 * It only composes the canonical services, all over ONE population (the
 * viewer's reach for a permission, optionally narrowed to one department):
 *   attendance  → TodayStatusService (AttendanceStatusResolver)
 *   approvals   → ApprovalQueueService (what this viewer can decide)
 *   reviews     → PerformanceStatusService
 *   OT hours    → approved, payable OvertimeRecord hours (never the request estimate)
 * Changing the department therefore changes every figure together.
 */
class PeopleOperationsService
{
    /** Data-quality checks shown on the HR dashboard, field => label. */
    public const DATA_CHECKS = [
        'department_id' => 'Missing Department',
        'manager_id' => 'Missing Manager',
        'shift' => 'Missing Shift',
        'joining_date' => 'Missing Employment Date',
        'job_title_id' => 'Missing Designation',
        'profile' => 'Missing Profile Required Data',
    ];

    public function __construct(
        private readonly PeopleScope $people,
        private readonly TodayStatusService $today,
        private readonly ApprovalQueueService $approvals,
        private readonly PerformanceStatusService $performance,
    ) {}

    /**
     * The People Operations Today figures.
     *
     * @return array{employee_ids: array<int, int>, attendance: array<string, mixed>, approvals: array<string, int>, reviews: array<string, mixed>, data_issues: int, ot_hours: float}
     */
    public function overview(User $user, string $permission, ?int $departmentId = null): array
    {
        $ids = $this->people->workingEmployeeIds($user, $permission, $departmentId ? [$departmentId] : null);

        return [
            'employee_ids' => $ids,
            'attendance' => $this->today->summary($ids),
            'approvals' => $this->approvalCounts($user, $departmentId),
            'reviews' => $this->performance->summary($ids),
            'data_issues' => $this->dataQuality($ids)['employees_with_issues'],
            'ot_hours' => $this->approvedOtHours($ids),
        ];
    }

    /**
     * Pending requests this viewer can decide, optionally inside one department.
     *
     * @return array<string, int>
     */
    public function approvalCounts(User $user, ?int $departmentId = null): array
    {
        $counts = [];

        foreach (array_keys(ApprovalQueueService::TYPES) as $type) {
            $query = $this->approvals->pendingFor($user, $type);

            if ($query !== null) {
                $counts[$type] = $query
                    ->when($departmentId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $departmentId)))
                    ->count();
            }
        }

        return $counts;
    }

    /**
     * One row per department in the viewer's reach.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function departmentHealth(User $user, string $permission): Collection
    {
        $ids = $this->people->workingEmployeeIds($user, $permission);
        $attendance = $this->today->summary($ids);
        $cycle = $this->performance->currentCycle();

        $employees = Employee::whereIn('id', $ids)->get(['id', 'department_id', 'manager_id', 'job_title_id', 'joining_date', 'shift_id', 'user_id', 'status', 'employment_type_id', 'work_mode_id', 'salary_cycle_id', 'probation_end_date']);
        $departments = $this->people->departmentOptions($user, $permission)->load('head:id,name')->keyBy('id');

        return $employees->groupBy(fn (Employee $e) => $e->department_id ?? 0)
            ->map(function (Collection $people, int|string $departmentId) use ($attendance, $user, $cycle, $departments) {
                $peopleIds = $people->pluck('id')->map(fn ($id) => (int) $id)->all();
                $rows = collect($attendance['rows'])->only($peopleIds);
                $reviews = $this->performance->summary($peopleIds, $cycle);
                $department = $departments->get((int) $departmentId);

                return [
                    'department_id' => (int) $departmentId ?: null,
                    'department' => $department?->name ?? 'No department',
                    'head' => $department?->head?->name,
                    'employees' => count($peopleIds),
                    'present' => $rows->where('bucket', 'present')->count(),
                    'late' => $rows->filter(fn ($r) => $r['bucket'] === 'present' && $r['day']->isLate)->count(),
                    'on_leave' => $rows->where('bucket', 'on_leave')->count(),
                    'missing_checkout' => $rows->where('state', 'missing_checkout')->count(),
                    'pending_approvals' => array_sum($this->approvalCounts($user, (int) $departmentId ?: null)),
                    'ot_hours' => $this->approvedOtHours($peopleIds),
                    'reviews_due' => $reviews['pending'],
                    'avg_score' => $reviews['avg_score'],
                    'data_issues' => $people->filter(fn (Employee $e) => $this->issuesFor($e) !== [])->count(),
                ];
            })
            ->sortBy('department')
            ->values();
    }

    /**
     * Data quality over a set of people: counts per check and the employees
     * behind each, for a filtered list. Never fixes anything.
     *
     * @param  array<int, int>  $employeeIds
     * @return array{checks: array<string, array{label: string, count: int, employee_ids: array<int, int>}>, employees_with_issues: int}
     */
    public function dataQuality(array $employeeIds): array
    {
        $checks = collect(self::DATA_CHECKS)->map(fn ($label) => ['label' => $label, 'count' => 0, 'employee_ids' => []])->all();
        $withIssues = 0;

        foreach (Employee::whereIn('id', $employeeIds)->with('user')->get() as $employee) {
            $issues = $this->issuesFor($employee, withProfile: true);
            $withIssues += $issues === [] ? 0 : 1;

            foreach ($issues as $key) {
                $checks[$key]['count']++;
                $checks[$key]['employee_ids'][] = (int) $employee->id;
            }
        }

        return ['checks' => $checks, 'employees_with_issues' => $withIssues];
    }

    /**
     * Structural problems that break scope and reporting (connection check):
     * people without a department / manager / shift / role / salary cycle,
     * and departments with people but no head.
     *
     * @param  array<int, int>|null  $employeeIds  null = everyone working
     * @return list<array{type: string, subject: string, problem: string}>
     */
    public function connectionProblems(?array $employeeIds = null): array
    {
        $problems = [];

        $employees = Employee::query()
            ->whereIn('status', PeopleScope::WORKING_STATUSES)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('id', $employeeIds))
            ->with(['user', 'department'])
            ->get();

        foreach ($employees as $employee) {
            $name = $employee->user?->name ?? 'Employee #'.$employee->id;
            $missing = $employee->missingHrFields();

            foreach (['department_id', 'manager_id', 'shift', 'salary_cycle_id'] as $field) {
                if (isset($missing[$field])) {
                    $problems[] = ['type' => 'employee', 'subject' => $name, 'problem' => $missing[$field].' not set'];
                }
            }

            if ($employee->department_id && ! $employee->department) {
                $problems[] = ['type' => 'employee', 'subject' => $name, 'problem' => 'Department no longer exists'];
            }

            if ($employee->user && $employee->user->role_id === null && $employee->user->role === null) {
                $problems[] = ['type' => 'employee', 'subject' => $name, 'problem' => 'No valid role'];
            }
        }

        $departmentIds = $employees->pluck('department_id')->filter()->unique();
        Department::whereIn('id', $departmentIds)->whereNull('head_id')->orderBy('name')->get(['id', 'name'])
            ->each(function (Department $department) use (&$problems) {
                $problems[] = ['type' => 'department', 'subject' => $department->name, 'problem' => 'No department head set'];
            });

        return $problems;
    }

    /**
     * Approved, payable OT hours this month (OvertimeRecord, derived from
     * attendance + an approved request) — never the requested estimate.
     *
     * @param  array<int, int>  $employeeIds
     */
    public function approvedOtHours(array $employeeIds): float
    {
        if ($employeeIds === []) {
            return 0.0;
        }

        return round((float) OvertimeRecord::whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->sum('ot_hours'), 1);
    }

    /** @return list<string> keys of DATA_CHECKS that fail for this employee */
    private function issuesFor(Employee $employee, bool $withProfile = false): array
    {
        $missing = $employee->missingHrFields();
        $issues = array_values(array_intersect(array_keys(self::DATA_CHECKS), array_keys($missing)));

        if ($withProfile && app(ProfileCompletionService::class)->for($employee)['missing'] !== []) {
            $issues[] = 'profile';
        }

        return $issues;
    }
}
