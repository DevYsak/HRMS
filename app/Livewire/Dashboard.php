<?php

namespace App\Livewire;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentAcknowledgement;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PerformanceReview;
use App\Models\PublicHoliday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $user = Auth::user();
        $role = $user->role;

        // Super Admin / HR Admin → HR overview
        if ($role === UserRole::SuperAdmin || $role === UserRole::HrAdmin) {
            return $this->renderHrAdmin();
        }

        // Manager → team dashboard
        if ($role === UserRole::Manager) {
            return $this->renderManager();
        }

        // Finance → finance dashboard
        if ($role === UserRole::Finance) {
            return $this->renderFinance();
        }

        // Director / Executive
        if ($role === UserRole::Director) {
            return $this->renderExecutive();
        }

        // Default: Employee self-service
        return $this->renderEmployee();
    }

    private function renderHrAdmin()
    {
        $today = Carbon::today();
        $month = $today->month;
        $year = $today->year;

        $activeEmployees = Employee::where('status', EmployeeStatus::Active)->get();
        $totalActive = $activeEmployees->count();

        $onboarding = Employee::where('status', EmployeeStatus::Onboarding)->count();
        $probation = Employee::where('status', EmployeeStatus::Probation)->count();
        $newEmployeesCount = Employee::whereMonth('joining_date', $month)->whereYear('joining_date', $year)->count();
        $resignedCount = Employee::where('status', EmployeeStatus::Resigned)->count();

        // Today's Attendance KPI
        $presentToday = Attendance::where('date', $today)->whereNotNull('check_in')->count();
        $attendancePercent = $totalActive > 0 ? round(($presentToday / $totalActive) * 100) : 0;

        // Employees on Leave Today
        $onLeaveTodayCount = LeaveRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->count();

        // Pending Approvals
        $pendingLeavesCount = LeaveRequest::where('status', 'pending')->count();
        $pendingOtCount = OtRequest::where('status', 'pending')->count();

        // Active Payroll Cycles
        $activePayrolls = Payroll::where('status', 'draft')
            ->where('year', $year)
            ->get();

        // Attendance Heatmap Data (Last 7 Days)
        $days = collect();
        for ($i = 6; $i >= 0; $i--) {
            $days->push(now()->subDays($i)->startOfDay());
        }

        $heatmapData = Employee::whereIn('status', [EmployeeStatus::Active, EmployeeStatus::Probation])
            ->with(['user', 'attendances' => function ($q) use ($days) {
                $q->whereBetween('date', [$days->first(), $days->last()]);
            }])
            ->get()
            ->map(function ($emp) use ($days) {
                $row = [
                    'name' => $emp->user?->name ?? 'Unknown',
                    'avatar' => $emp->user?->profile_photo_url,
                    'initials' => collect(explode(' ', $emp->user?->name ?? 'U'))->map(fn ($n) => $n[0] ?? '')->take(2)->join(''),
                    'days' => $days->map(function ($d) use ($emp) {
                        $att = $emp->attendances->first(fn ($a) => Carbon::parse($a->date)->isSameDay($d));
                        if ($att) {
                            if ($att->is_late) {
                                return 'late';
                            }
                            if ($att->check_in) {
                                return 'present';
                            }

                            return 'absent';
                        }
                        if ($d->isWeekend()) {
                            return 'weekend';
                        }
                        if ($d->isPast()) {
                            return 'absent';
                        }

                        return 'future';
                    }),
                ];

                return $row;
            });

        // Recent Activity Feed (Formatted)
        $recentAuditLogs = AuditLog::with('user')
            ->latest('id')
            ->take(10)
            ->get()
            ->map(function ($log) {
                $modelName = strtolower(Str::afterLast($log->auditable_type, '\\'));
                $log->display_action = match ($log->action) {
                    'created' => "created new {$modelName} record",
                    'updated' => "updated {$modelName} details",
                    'deleted' => "removed a {$modelName} record",
                    default => $log->action
                };

                return $log;
            });

        // Critical Alerts
        $expiringDocuments = Document::whereNotNull('expires_at')
            ->where('expires_at', '<=', $today->copy()->addDays(30))
            ->where('expires_at', '>=', $today)
            ->get();

        $upcomingProbations = Employee::where('status', EmployeeStatus::Probation)
            ->whereNotNull('probation_end_date')
            ->where('probation_end_date', '<=', $today->copy()->addDays(30))
            ->get();

        // Workforce Composition (Department-wise distribution)
        $workforceComposition = Department::withCount(['employees' => function ($q) {
            $q->where('status', EmployeeStatus::Active);
        }])->get()->map(fn ($dept) => [
            'name' => $dept->name,
            'count' => $dept->employees_count,
            'color' => match (strtolower($dept->name)) {
                'engineering' => '#3b82f6', // blue
                'operations' => '#8b5cf6',  // purple
                'sales' => '#10b981',       // green
                'hr & admin' => '#f59e0b',  // amber
                'finance' => '#ef4444',     // red
                default => '#6366f1'        // indigo
            },
        ])->filter(fn ($d) => $d['count'] > 0)->values();

        return view('dashboard', compact(
            'totalActive',
            'onboarding',
            'probation',
            'newEmployeesCount',
            'resignedCount',
            'attendancePercent',
            'presentToday',
            'onLeaveTodayCount',
            'pendingLeavesCount',
            'pendingOtCount',
            'activePayrolls',
            'heatmapData',
            'days',
            'recentAuditLogs',
            'expiringDocuments',
            'upcomingProbations',
            'workforceComposition'
        ))->layout('layouts.app', ['title' => 'Admin Dashboard']);
    }

    private function renderManager()
    {
        $manager = Auth::user()->employee;
        $today = Carbon::today();

        $teamIds = $manager
            ? Employee::where('manager_id', $manager->id)->pluck('id')
            : collect();

        $teamAttendance = Attendance::with('employee.user')
            ->where('date', $today)
            ->whereIn('employee_id', $teamIds)
            ->get();

        $presentCount = $teamAttendance->whereNotNull('check_in')->count();
        $lateCount = $teamAttendance->where('is_late', true)->count();
        $absentCount = $teamIds->count() - $presentCount;

        $teamAttendanceList = Employee::with(['user', 'department'])
            ->whereIn('id', $teamIds)
            ->get()
            ->map(function ($emp) use ($teamAttendance) {
                $record = $teamAttendance->firstWhere('employee_id', $emp->id);

                return [
                    'name' => $emp->user->name,
                    'department' => $emp->department?->name,
                    'check_in' => $record?->check_in?->format('H:i'),
                    'check_out' => $record?->check_out?->format('H:i'),
                    'status' => $record?->status ?? 'absent',
                    'is_late' => $record?->is_late ?? false,
                ];
            });

        $pendingLeaves = LeaveRequest::with(['employee.user', 'leaveType'])
            ->whereIn('employee_id', $teamIds)
            ->where('status', 'pending')
            ->latest()->take(10)->get();

        $pendingOt = OtRequest::with('employee.user')
            ->whereIn('employee_id', $teamIds)
            ->where('status', 'pending')
            ->latest()->take(10)->get();

        $reviewsPending = 0;
        $reviewsSubmitted = 0;

        return view('livewire.manager-dashboard', compact(
            'teamAttendanceList',
            'presentCount',
            'lateCount',
            'absentCount',
            'pendingLeaves',
            'pendingOt',
            'reviewsPending',
            'reviewsSubmitted',
        ))->layout('layouts.app', ['title' => 'Manager Dashboard']);
    }

    private function renderFinance()
    {
        return app(FinanceDashboard::class)->render();
    }

    private function renderExecutive()
    {
        return app(ExecutiveDashboard::class)->render();
    }

    private function renderEmployee()
    {
        $user = Auth::user();
        $employee = $user->employee;
        $today = Carbon::today();

        // Today's attendance
        $todayAttendance = $employee
            ? Attendance::where('employee_id', $employee->id)->where('date', $today)->first()
            : null;

        // Leave balances (using allocated_days / used_days from schema)
        $leaveBalances = $employee
            ? LeaveBalance::with('leaveType')->where('employee_id', $employee->id)->whereYear('year', now()->year)->get()
            : collect();

        // My pending OT requests
        $myOtRequests = $employee
            ? OtRequest::where('employee_id', $employee->id)->latest()->take(5)->get()
            : collect();

        // Recent payslips (last 3)
        $myPayslips = $employee
            ? Payslip::where('employee_id', $employee->id)->with('payroll')->latest()->take(3)->get()
            : collect();

        // Pending leave requests
        $pendingLeaveRequests = $employee
            ? LeaveRequest::with('leaveType')->where('employee_id', $employee->id)->where('status', 'pending')->latest()->get()
            : collect();
        $pendingLeaveCount = $pendingLeaveRequests->count();

        // Weekly Attendance (Mon-Fri)
        $startOfWeek = now()->startOfWeek(Carbon::MONDAY);
        $endOfWeek = now()->endOfWeek(Carbon::FRIDAY);

        $currentWeekAttendance = $employee
            ? Attendance::where('employee_id', $employee->id)
                ->whereBetween('date', [$startOfWeek, $endOfWeek])
                ->get()
            : collect();

        // Next Public Holiday
        $nextPublicHoliday = PublicHoliday::where('date', '>=', $today)
            ->orderBy('date', 'asc')
            ->first();

        // Pending Actions (Documents to Acknowledge, Reviews Due)
        $pendingActions = collect();

        if ($employee) {
            $pendingDocs = DocumentAcknowledgement::with('document')
                ->where('employee_id', $employee->id)
                ->whereNull('acknowledged_at')
                ->get();

            foreach ($pendingDocs as $doc) {
                $pendingActions->push([
                    'type' => 'document',
                    'title' => 'Acknowledge: '.$doc->document->title,
                    'date' => $doc->created_at,
                    'url' => route('documents.index'),
                ]);
            }

            $pendingReviews = PerformanceReview::with('cycle')
                ->where('employee_id', $employee->id)
                ->where('type', 'self')
                ->where('status', 'pending')
                ->get();

            foreach ($pendingReviews as $rev) {
                $pendingActions->push([
                    'type' => 'review',
                    'title' => 'Self Assessment Due: '.$rev->cycle->name,
                    'date' => $rev->created_at,
                    'url' => route('performance.my'),
                ]);
            }
        }

        $pendingActions = $pendingActions->sortByDesc('date');

        return view('livewire.employee-dashboard', compact(
            'todayAttendance',
            'leaveBalances',
            'myOtRequests',
            'myPayslips',
            'pendingLeaveCount',
            'pendingLeaveRequests',
            'currentWeekAttendance',
            'nextPublicHoliday',
            'pendingActions'
        ))->layout('layouts.app', ['title' => 'My Dashboard']);
    }
}
