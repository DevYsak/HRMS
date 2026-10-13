<?php

namespace App\Livewire\Employees;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Approvals\ApprovalQueueService;
use App\Services\LeaveService;
use App\Services\People\Employee360Access;
use App\Services\People\Employee360Service;
use App\Services\Security\PeopleScope;
use App\Support\DateDisplay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Employee 360° — one page answering "how is this person doing?" for HR,
 * Department Heads and Managers: live status, attendance, leave, overtime,
 * performance, warnings and recent activity, each from its own module's
 * service and only when the viewer may see that module for this employee.
 */
class Employee360 extends Component
{
    #[Locked]
    public Employee $employee;

    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    #[Url(as: 'department', except: null)]
    public ?int $department = null;

    #[Url(as: 'attendance', except: '')]
    public string $attendanceStatus = '';

    #[Url(as: 'leave', except: 'overview')]
    public string $leaveStatus = 'overview';

    public ?int $rejectingId = null;

    public string $rejectReason = '';

    public function mount(Employee $employee): void
    {
        abort_unless(app(Employee360Access::class)->allows(Auth::user(), $employee), 403);

        $this->employee = $employee;
        $this->normaliseFilters();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', 'department', 'attendanceStatus', 'leaveStatus'], true)) {
            $this->normaliseFilters();
        }
    }

    /** Open another employee in reach, keeping the filters. */
    public function selectEmployee(int|string $employeeId): void
    {
        $employee = Employee::find((int) $employeeId);
        abort_unless($employee && app(Employee360Access::class)->allows(Auth::user(), $employee), 403);

        $this->redirectRoute('employees.360', array_filter([
            'employee' => $employee->id,
            'from' => $this->from, 'to' => $this->to,
            'department' => $this->department,
        ]), navigate: true);
    }

    public function setLeaveTab(string $tab): void
    {
        $this->leaveStatus = array_key_exists($tab, Employee360Service::LEAVE_TABS) ? $tab : 'overview';
    }

    public function thisMonth(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->endOfMonth()->toDateString();
    }

    /** Approve a request at the reporting-approver stage — the same call as Team Time Off. */
    public function approveLeave(int $requestId): void
    {
        $request = $this->decidableLeave($requestId);

        try {
            app(LeaveService::class)->reviewRequest($request, $this->reviewData($request), 'approved', (int) Auth::id());
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast('Leave approved.', variant: 'success');
    }

    public function startReject(int $requestId): void
    {
        $this->decidableLeave($requestId);
        $this->rejectingId = $requestId;
        $this->rejectReason = '';
        $this->resetErrorBag();
    }

    public function cancelReject(): void
    {
        $this->reset(['rejectingId', 'rejectReason']);
    }

    public function confirmReject(): void
    {
        $this->validate(['rejectReason' => ['required', 'string', 'min:5', 'max:1000']], [
            'rejectReason.required' => 'Please provide a reason for the rejection.',
            'rejectReason.min' => 'Reason must be at least 5 characters.',
        ]);

        $request = $this->decidableLeave((int) $this->rejectingId);

        try {
            app(LeaveService::class)->reviewRequest($request, $this->reviewData($request), 'rejected', (int) Auth::id(), $this->rejectReason);
        } catch (\DomainException $exception) {
            $this->addError('rejectReason', $exception->getMessage());

            return;
        }

        $this->cancelReject();
        \Flux::toast('Leave rejected.', variant: 'success');
    }

    /** A pending request of THIS employee that the user may decide now. */
    private function decidableLeave(int $requestId): LeaveRequest
    {
        $request = LeaveRequest::where('employee_id', $this->employee->id)->findOrFail($requestId);

        abort_unless(
            $request->status === 'pending' && app(ApprovalQueueService::class)->canDecide(Auth::user(), $request),
            403,
        );

        return $request;
    }

    /** @return array{leave_type_id: int, start_date: string, end_date: string, reason: ?string, is_half_day: bool} */
    private function reviewData(LeaveRequest $request): array
    {
        return [
            'leave_type_id' => $request->leave_type_id,
            'start_date' => $request->start_date->format('Y-m-d'),
            'end_date' => $request->end_date->format('Y-m-d'),
            'reason' => $request->reason,
            'is_half_day' => (bool) $request->is_half_day,
        ];
    }

    /** Valid dates (current month by default) and filters from their lists. */
    private function normaliseFilters(): void
    {
        [$from, $to] = Employee360Service::range($this->from, $this->to);

        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
        $this->department = app(PeopleScope::class)->sanitiseDepartment(Auth::user(), Employee360Access::PERMISSION, $this->department);
        $this->attendanceStatus = array_key_exists($this->attendanceStatus, Employee360Service::ATTENDANCE_STATUSES) ? $this->attendanceStatus : '';
        $this->leaveStatus = array_key_exists($this->leaveStatus, Employee360Service::LEAVE_TABS) ? $this->leaveStatus : 'overview';
    }

    public function render()
    {
        $user = Auth::user();
        $access = app(Employee360Access::class);
        $from = Carbon::parse($this->from);
        $to = Carbon::parse($this->to);

        $data = app(Employee360Service::class)->build($user, $this->employee, $from, $to, [
            'attendance_status' => $this->attendanceStatus,
            'leave_status' => $this->leaveStatus,
        ]);

        return view('livewire.employees.employee-360', [
            ...$data,
            'scopeLabel' => $access->scopeLabel($user),
            'employeeOptions' => $this->employeeOptions($access),
            'departmentOptions' => app(PeopleScope::class)->departmentOptions($user, Employee360Access::PERMISSION),
            'charts' => $data['attendance'] ? $this->charts($data['attendance']['days']) : null,
            'rangeLabel' => app(DateDisplay::class)->date($from).' – '.app(DateDisplay::class)->date($to),
        ])->layout('layouts.app', ['title' => ($data['identity']['name'] ?? 'Employee').' · Employee 360°']);
    }

    /** @return array<int, string> employee id => "Name · ID", only people in reach */
    private function employeeOptions(Employee360Access $access): array
    {
        $reach = $access->reach(Auth::user());

        return Employee::query()
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->when($reach !== null, fn ($q) => $q->whereIn('employees.id', $reach))
            ->when($this->department, fn ($q) => $q->where(fn ($q) => $q->where('employees.department_id', $this->department)->orWhere('employees.id', $this->employee->id)))
            ->whereNull('users.deleted_at')
            ->orderBy('users.name')
            ->limit(500)
            ->get(['employees.id', 'employees.employee_id', 'users.name'])
            ->mapWithKeys(fn ($e) => [$e->id => $e->name.($e->employee_id ? ' · '.$e->employee_id : '')])
            ->all();
    }

    /**
     * Attendance Trend (worked hours per day) and Punctuality (per week:
     * on time / late / absent) from the same day rows as the counts.
     *
     * @param  array<string, array<string, mixed>>  $days
     * @return array{trend: array<string, mixed>, punctuality: array<string, mixed>}
     */
    private function charts(array $days): array
    {
        $display = app(DateDisplay::class);
        $rows = collect($days)->filter(fn ($d) => $d['status'] !== 'upcoming');

        $trend = [
            'chart' => ['type' => 'area', 'height' => 220, 'toolbar' => ['show' => false], 'zoom' => ['enabled' => false], 'fontFamily' => 'inherit'],
            'colors' => ['#f97316'],
            'dataLabels' => ['enabled' => false],
            'stroke' => ['curve' => 'smooth', 'width' => 2],
            'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.35, 'opacityTo' => 0.02]],
            // Labels live in the points ({x, y}): the light/dark switch re-applies xaxis and would drop categories.
            'series' => [['name' => 'Worked hours', 'data' => $rows->map(fn ($d) => ['x' => $display->shortDate(Carbon::parse($d['date'])), 'y' => round($d['worked'] / 60, 1)])->values()->all()]],
            'xaxis' => ['type' => 'category', 'labels' => ['rotate' => 0, 'hideOverlappingLabels' => true], 'tickAmount' => 6],
            'yaxis' => ['min' => 0],
            'grid' => ['strokeDashArray' => 4],
        ];

        $weeks = $rows->groupBy(fn ($d) => Carbon::parse($d['date'])->startOfWeek()->toDateString());
        $weekPoints = fn (string $status) => $weeks->map(fn ($week, $start) => ['x' => 'Wk '.$display->shortDate(Carbon::parse($start)), 'y' => $week->where('status', $status)->count()])->values()->all();
        $punctuality = [
            'chart' => ['type' => 'bar', 'height' => 220, 'stacked' => true, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit'],
            'colors' => ['#22c55e', '#f59e0b', '#ef4444'],
            'plotOptions' => ['bar' => ['columnWidth' => '45%', 'borderRadius' => 4]],
            'dataLabels' => ['enabled' => false],
            'series' => [
                ['name' => 'On time', 'data' => $weekPoints('present')],
                ['name' => 'Late', 'data' => $weekPoints('late')],
                ['name' => 'Absent', 'data' => $weekPoints('absent')],
            ],
            'xaxis' => ['type' => 'category'],
            'yaxis' => ['min' => 0, 'forceNiceScale' => true],
            'legend' => ['position' => 'top', 'horizontalAlign' => 'left'],
            'grid' => ['strokeDashArray' => 4],
        ];

        return ['trend' => $trend, 'punctuality' => $punctuality];
    }
}
