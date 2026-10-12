<?php

namespace App\Livewire\Departments;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OvertimeRecord;
use App\Services\Approvals\ApprovalQueueService;
use App\Services\Attendance\TodayStatusService;
use App\Services\Dashboards\PeopleOperationsService;
use App\Services\Performance\PerformanceStatusService;
use App\Services\Security\PeopleScope;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One department's workspace: today, approvals, people, leave, overtime,
 * performance and reports — every tab over the SAME people (this department
 * inside the viewer's view_attendance reach), from the canonical services.
 * Reachable only for a department the viewer's scope covers; HR sees all.
 */
class DepartmentOverview extends Component
{
    public const PERMISSION = 'view_attendance';

    public const TABS = [
        'overview' => 'Overview',
        'people' => 'People',
        'attendance' => 'Attendance',
        'leave' => 'Leave',
        'overtime' => 'Overtime',
        'performance' => 'Performance',
        'reports' => 'Reports',
    ];

    public Department $department;

    #[Url]
    public string $tab = 'overview';

    public function mount(Department $department): void
    {
        $user = Auth::user();

        abort_unless(
            app(PeopleScope::class)->departmentOptions($user, self::PERMISSION)->contains('id', $department->id),
            403,
        );

        $this->department = $department;

        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'overview';
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'overview';
    }

    public function render()
    {
        $user = Auth::user();
        $people = app(PeopleScope::class);
        $operations = app(PeopleOperationsService::class);

        $ids = $people->workingEmployeeIds($user, self::PERMISSION, [$this->department->id]);
        $today = app(TodayStatusService::class)->summary($ids);
        $reviews = app(PerformanceStatusService::class)->summary($ids);

        $employees = Employee::whereIn('id', $ids)
            ->with(['user', 'jobTitle', 'manager', 'shift'])
            ->get()
            ->sortBy(fn (Employee $e) => $e->user?->name)
            ->values();

        $statusCounts = $employees->countBy(fn (Employee $e) => is_object($e->status) ? $e->status->value : $e->status);

        $data = [
            'scope' => $people->summary($user, self::PERMISSION, [$this->department->id]),
            'head' => $this->department->head,
            'employees' => $employees,
            'today' => $today,
            'reviews' => $reviews,
            'approvals' => $operations->approvalCounts($user, $this->department->id),
            'people' => [
                'active' => ($statusCounts['active'] ?? 0) + ($statusCounts['confirmed'] ?? 0),
                'probation' => $statusCounts['probation'] ?? 0,
                'notice_period' => $statusCounts['notice_period'] ?? 0,
                'onboarding' => $statusCounts['onboarding'] ?? 0,
            ],
            'otHours' => $operations->approvedOtHours($ids),
        ];

        if ($this->tab === 'leave') {
            $data['onLeave'] = LeaveRequest::with(['employee.user', 'leaveType'])
                ->whereIn('employee_id', $ids)->where('status', 'approved')
                ->whereDate('end_date', '>=', today())->orderBy('start_date')->limit(25)->get();
            $data['pendingLeave'] = (app(ApprovalQueueService::class)->pendingFor($user, 'leave') ?? LeaveRequest::whereRaw('1 = 0'))
                ->whereIn('employee_id', $ids)->with(['employee.user', 'leaveType'])->latest()->limit(25)->get();
        }

        if ($this->tab === 'overtime') {
            $data['otByEmployee'] = OvertimeRecord::whereIn('employee_id', $ids)
                ->whereBetween('work_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->selectRaw('employee_id, SUM(ot_hours) as hours')->groupBy('employee_id')->pluck('hours', 'employee_id');
        }

        return view('livewire.departments.department-overview', $data)
            ->layout('layouts.app', ['title' => $this->department->name]);
    }
}
