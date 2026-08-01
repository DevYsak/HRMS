<?php

namespace App\Livewire;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentAcknowledgement;
use App\Models\Employee;
use App\Models\EmployeeScorecard;
use App\Models\LeaveBalance;
use App\Models\LeaveEncashment;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\PipRecord;
use App\Models\PromotionRecommendation;
use App\Models\PublicHoliday;
use App\Models\WarningLetter;
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

        $totalActive = Employee::where('status', EmployeeStatus::Active)->count();

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
        $pendingLeaveRequests = LeaveRequest::with(['employee.user', 'leaveType'])
            ->where('status', 'pending')
            ->latest()
            ->take(3)
            ->get();

        // Active Payroll Cycles
        $activePayrolls = Payroll::where('status', 'draft')
            ->where('year', $year)
            ->get();

        // Attendance Heatmap — current week Mon–Sun
        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $days = collect(range(0, 6))->map(fn ($i) => $monday->copy()->addDays($i));

        $dayStrings = $days->map(fn ($d) => $d->toDateString());

        $heatmapData = Employee::whereIn('status', [EmployeeStatus::Active, EmployeeStatus::Probation])
            ->with(['user', 'attendances' => function ($q) use ($days) {
                $q->whereBetween('date', [$days->first()->toDateString(), $days->last()->toDateString()])
                    ->select(['id', 'employee_id', 'date', 'check_in', 'is_late']);
            }])
            ->select(['id', 'user_id'])
            ->limit(50)
            ->get()
            ->map(function ($emp) use ($days, $dayStrings) {
                $attByDate = $emp->attendances->keyBy(
                    fn ($a) => Carbon::parse($a->date)->toDateString()
                );

                return [
                    'name' => $emp->user?->name ?? 'Unknown',
                    'initials' => collect(explode(' ', $emp->user?->name ?? 'U'))->map(fn ($n) => $n[0] ?? '')->take(2)->join(''),
                    'days' => $dayStrings->map(function ($dateStr) use ($attByDate, $days) {
                        $d = $days->first(fn ($day) => $day->toDateString() === $dateStr);
                        $att = $attByDate->get($dateStr);
                        $isWeekend = $d !== null && AttendanceSetting::isWeeklyOff($d);

                        if ($att && $att->check_in) {
                            // Clocked in even on weekend — show actual status
                            return $att->is_late ? 'late' : 'present';
                        }

                        if ($isWeekend) {
                            return 'off'; // Sat/Sun with no attendance = off day
                        }

                        if (! $d || $d->isFuture()) {
                            return 'future';
                        }

                        return $d->isToday() ? 'today' : 'absent';
                    }),
                ];
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
            ->with('user')
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

        // ── Workforce risk / people-ops counters ───────────────────────────
        $activeWarnings = WarningLetter::whereIn('status', ['issued', 'acknowledged', 'under_review'])->count();
        $onPipCount = PipRecord::whereIn('status', ['active', 'under_review', 'extended'])->count();
        $pendingPromotions = PromotionRecommendation::whereIn('status', [
            'pending_hr', 'pending_dept_head', 'pending_super_admin',
        ])->count();

        // ── Pending approvals breakdown (leave / OT / regularisation / encashment) ──
        $pendingRegularisations = AttendanceRegularisation::where('status', 'pending')->count();
        $pendingEncashments = LeaveEncashment::where('status', 'pending')->count();
        $pendingApprovals = collect([
            ['label' => 'Leave', 'count' => $pendingLeavesCount, 'href' => route('time-off.employees')],
            ['label' => 'Overtime', 'count' => $pendingOtCount, 'href' => route('overtime.manage')],
            ['label' => 'Regularisations', 'count' => $pendingRegularisations, 'href' => route('attendance.employees')],
            ['label' => 'Encashments', 'count' => $pendingEncashments, 'href' => route('time-off.employees')],
        ]);

        $complianceAlerts = collect([
            [
                'label' => 'Pending leave approvals awaiting action',
                'status' => $pendingLeavesCount > 0 ? 'Action required' : 'Clear',
                'tone' => $pendingLeavesCount > 0 ? 'rose' : 'emerald',
                'count' => $pendingLeavesCount,
                'href' => route('time-off.employees'),
            ],
            [
                'label' => 'Documents expiring in the next 30 days',
                'status' => $expiringDocuments->isNotEmpty() ? 'Due soon' : 'Current',
                'tone' => $expiringDocuments->isNotEmpty() ? 'amber' : 'emerald',
                'count' => $expiringDocuments->count(),
                'href' => route('documents.index'),
            ],
            [
                'label' => 'Probation reviews approaching deadline',
                'status' => $upcomingProbations->isNotEmpty() ? 'Upcoming' : 'On track',
                'tone' => $upcomingProbations->isNotEmpty() ? 'blue' : 'emerald',
                'count' => $upcomingProbations->count(),
                'href' => route('employees.index'),
            ],
            [
                'label' => 'Active warning letters',
                'status' => $activeWarnings > 0 ? 'Open' : 'None',
                'tone' => $activeWarnings > 0 ? 'amber' : 'emerald',
                'count' => $activeWarnings,
                'href' => route('employees.index'),
            ],
            [
                'label' => 'Employees on a PIP',
                'status' => $onPipCount > 0 ? 'Monitoring' : 'None',
                'tone' => $onPipCount > 0 ? 'rose' : 'emerald',
                'count' => $onPipCount,
                'href' => route('employees.index'),
            ],
            [
                'label' => 'Promotions awaiting review',
                'status' => $pendingPromotions > 0 ? 'In pipeline' : 'Clear',
                'tone' => $pendingPromotions > 0 ? 'blue' : 'emerald',
                'count' => $pendingPromotions,
                'href' => route('employees.index'),
            ],
        ]);

        $actionRequiredCount = $pendingLeavesCount + $pendingOtCount + $expiringDocuments->count();

        // ── Upcoming Birthdays (next 30 days) ──────────────────────────────
        $upcomingBirthdays = Employee::with(['user', 'department'])
            ->where('status', EmployeeStatus::Active)
            ->whereNotNull('date_of_birth')
            ->get()
            ->map(function ($emp) {
                $dob = Carbon::parse($emp->date_of_birth);
                $next = now()->copy()->setDay($dob->day)->setMonth($dob->month);
                if ($next->lt(now()->startOfDay())) {
                    $next->addYear();
                }

                return [
                    'emp' => $emp,
                    'name' => $emp->user?->name ?? '—',
                    'dept' => $emp->department?->name ?? '',
                    'dob_fmt' => $dob->format('d M'),
                    'age' => $dob->age,
                    'days' => (int) now()->startOfDay()->diffInDays($next),
                    'is_today' => $next->isToday(),
                ];
            })
            ->filter(fn ($b) => $b['days'] <= 30)
            ->sortBy('days')
            ->take(6)
            ->values();

        // ── Monthly Attendance Trend (last 6 months) ───────────────────────
        $attendanceTrend = collect();
        for ($i = 5; $i >= 0; $i--) {
            $d = now()->subMonths($i);
            $workDays = AttendanceSetting::workingDaysBetween($d->copy()->startOfMonth(), $d->copy()->endOfMonth());
            $present = Attendance::whereYear('date', $d->year)
                ->whereMonth('date', $d->month)
                ->whereNotNull('check_in')
                ->count();
            $rate = ($totalActive > 0 && $workDays > 0)
                ? min(100, round(($present / ($totalActive * $workDays)) * 100))
                : 0;
            $attendanceTrend->push([
                'month' => $d->format('M'),
                'present' => $present,
                'rate' => $rate,
            ]);
        }

        // ── Today's Live Check-ins ─────────────────────────────────────────
        $liveCheckins = Attendance::with('employee.user')
            ->where('date', $today)
            ->whereNotNull('check_in')
            ->orderByDesc('check_in')
            ->take(6)
            ->get();

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
            'pendingLeaveRequests',
            'activePayrolls',
            'heatmapData',
            'days',
            'recentAuditLogs',
            'expiringDocuments',
            'upcomingProbations',
            'workforceComposition',
            'complianceAlerts',
            'actionRequiredCount',
            'upcomingBirthdays',
            'attendanceTrend',
            'liveCheckins',
            'activeWarnings',
            'onPipCount',
            'pendingPromotions',
            'pendingApprovals',
            'pendingRegularisations',
            'pendingEncashments',
        ))->layout('layouts.app', ['title' => 'Admin Dashboard']);
    }

    private function renderManager()
    {
        // Delegate to the canonical ManagerDashboard component (single source of
        // truth — owns the team KPI widget, review counts and reject-modal flow).
        return app(ManagerDashboard::class)->render();
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

        // My KPIs (latest performance cycle scorecard)
        $latestCycle = PerformanceCycle::whereIn('status', ['active', 'completed', 'locked'])
            ->latest('start_date')
            ->first();
        $myScorecard = ($employee && $latestCycle)
            ? EmployeeScorecard::where('employee_id', $employee->id)
                ->where('performance_cycle_id', $latestCycle->id)
                ->first()
            : null;

        // Recent notifications feed (top 5)
        $recentNotifications = $user->notifications()->latest()->take(5)->get();

        // ── Profile / team ─────────────────────────────────────────────────
        $shift = $employee?->shift;
        $shiftName = $shift?->name;
        $department = $employee?->department?->name;
        $designation = $employee?->jobTitle?->name;
        $manager = $employee?->manager;

        // ── This-month attendance metrics ──────────────────────────────────
        $monthStart = now()->startOfMonth();
        $monthAttendance = $employee
            ? Attendance::where('employee_id', $employee->id)
                ->whereBetween('date', [$monthStart->toDateString(), $today->toDateString()])
                ->get()
            : collect();

        $presentDays = $monthAttendance->whereNotNull('check_in')->count();
        $lateCount = $monthAttendance->where('is_late', true)->count();

        $workingDaysElapsed = 0;
        for ($d = $monthStart->copy(); $d->lte($today); $d = $d->addDay()) {
            if (! $d->isWeekend()) {
                $workingDaysElapsed++;
            }
        }
        $attendancePct = $workingDaysElapsed > 0 ? min(100, (int) round(($presentDays / $workingDaysElapsed) * 100)) : 0;

        // Overtime hours this month (approved requests)
        $otHours = $employee
            ? (float) OtRequest::where('employee_id', $employee->id)
                ->where('status', 'approved')
                ->whereMonth('work_date', now()->month)
                ->whereYear('work_date', now()->year)
                ->sum('requested_hours')
            : 0.0;

        // Daily working-hours series for the current month (area chart)
        $dailyHours = collect();
        for ($d = $monthStart->copy(); $d->lte($today); $d = $d->addDay()) {
            $rec = $monthAttendance->first(fn ($a) => Carbon::parse($a->date)->isSameDay($d));
            $dailyHours->push([
                'label' => $d->format('d'),
                'hours' => $rec && $rec->total_hours ? round((float) $rec->total_hours, 1) : 0,
            ]);
        }

        // Minutes worked today (live-timer base)
        $workedTodayMinutes = ($todayAttendance && $todayAttendance->check_in)
            ? (int) ($todayAttendance->check_out ?? now())->diffInMinutes($todayAttendance->check_in)
            : 0;

        // ── Leave totals ───────────────────────────────────────────────────
        $totalLeaveAllocated = (float) $leaveBalances->sum('allocated_days');
        $totalLeaveUsed = (float) $leaveBalances->sum('used_days');
        $totalLeaveRemaining = max(0, $totalLeaveAllocated - $totalLeaveUsed);

        // ── My documents (own + company-wide policies) ─────────────────────
        $myDocuments = $employee
            ? Document::whereNull('parent_id')
                ->where(function ($q) use ($employee) {
                    $q->where('employee_id', $employee->id)
                        ->orWhere('visibility', 'all')
                        ->orWhere('category', 'policy');
                })
                ->latest()
                ->take(6)
                ->get()
            : collect();

        // ── Performance ────────────────────────────────────────────────────
        $performanceScore = $myScorecard?->final_score;
        $performanceGrade = $myScorecard?->grade;

        // ── Upcoming holidays (next 4) ─────────────────────────────────────
        $upcomingHolidays = PublicHoliday::where('date', '>=', $today)->orderBy('date')->take(4)->get();

        // Quote of the day (rotates daily)
        $quotes = [
            'Great work leads to great results. Keep going!',
            'Small steps every day add up to big results.',
            'Focus on progress, not perfection.',
            'Your effort today shapes tomorrow.',
            'Consistency beats intensity — show up.',
        ];
        $quote = $quotes[now()->dayOfYear % count($quotes)];

        return view('livewire.employee-dashboard', compact(
            'employee',
            'todayAttendance',
            'leaveBalances',
            'myOtRequests',
            'myPayslips',
            'pendingLeaveCount',
            'pendingLeaveRequests',
            'currentWeekAttendance',
            'nextPublicHoliday',
            'pendingActions',
            'myScorecard',
            'latestCycle',
            'recentNotifications',
            'shift',
            'shiftName',
            'department',
            'designation',
            'manager',
            'presentDays',
            'lateCount',
            'workingDaysElapsed',
            'attendancePct',
            'otHours',
            'dailyHours',
            'workedTodayMinutes',
            'totalLeaveAllocated',
            'totalLeaveUsed',
            'totalLeaveRemaining',
            'myDocuments',
            'performanceScore',
            'performanceGrade',
            'upcomingHolidays',
            'quote',
        ))->layout('layouts.app', ['title' => 'My Dashboard']);
    }
}
