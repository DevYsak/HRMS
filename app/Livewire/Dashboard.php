<?php

namespace App\Livewire;

use App\Enums\AttendanceMode;
use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Document;
use App\Models\Employee;
use App\Models\LeaveEncashment;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\Payroll;
use App\Models\PipRecord;
use App\Models\PromotionRecommendation;
use App\Models\User;
use App\Models\WarningLetter;
use App\Services\Attendance\ShiftResolver;
use App\Services\AttendanceService;
use App\Services\EmployeeDashboardService;
use App\Services\WfhService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

class Dashboard extends Component
{
    /**
     * Managers, Finance and Directors land on their own dashboard page.
     *
     * Each of those is a full-page component with its own layout and route
     * gate. Rendering one from here sent its approve / reject clicks to this
     * component, and mounting it as a child broke the app-shell grid — so
     * "/" forwards to the page itself.
     */
    public function mount(): void
    {
        $landing = $this->roleLanding(Auth::user());

        if ($landing !== null) {
            $this->redirectRoute($landing, navigate: true);
        }
    }

    public function render()
    {
        $role = Auth::user()->role;

        // Super Admin / HR Admin → HR overview
        if ($role === UserRole::SuperAdmin || $role === UserRole::HrAdmin) {
            return $this->renderHrAdmin();
        }

        // Default: Employee self-service — also for a Manager, Finance user or
        // Director whose role cannot open their own dashboard page.
        return $this->renderEmployee();
    }

    /**
     * The page a role lands on instead of "/", or null to stay here.
     *
     * Director / Department Head (spec §5.2): a Director scoped to a
     * department or shift gets that team's dashboard; an unscoped Director
     * keeps the executive view. A customised role without the permission its
     * page requires stays on self-service rather than being sent to a 403.
     */
    private function roleLanding(User $user): ?string
    {
        $landing = match ($user->role) {
            UserRole::Manager => 'dashboard.manager',
            UserRole::Finance => 'dashboard.finance',
            UserRole::Director => $user->isDepartmentScoped() ? 'dashboard.manager' : 'dashboard.director',
            default => null,
        };

        $canOpen = match ($landing) {
            'dashboard.manager' => $user->canApproveLeave(),
            'dashboard.finance' => $user->canRunPayroll() || $user->canApproveFinance(),
            'dashboard.director' => $user->can('view_executive_dashboard'),
            default => false,
        };

        return $canOpen ? $landing : null;
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
        $pendingLeavesCount = LeaveRequest::whereIn('status', ['pending', 'pending_hr'])->count();
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

    /**
     * Clock in from the dashboard.
     *
     * The button here has always been a link to the attendance page, so the
     * most common action in the product took two screens. It now punches
     * directly — through AttendanceService, the same call the attendance page
     * makes. No second engine: lateness, shift resolution and comp-off all stay
     * where they are.
     *
     * The exception is capture policy. When HR requires a selfie or a location
     * fix, the dashboard has no camera or geolocation prompt, and punching
     * without them would quietly defeat the control. Those employees are handed
     * to the attendance page, which does have the capture UI.
     */
    public function clockIn(?float $lat = null, ?float $lng = null, ?string $workMode = null): void
    {
        $employee = Auth::user()?->employee;

        if (! $employee) {
            \Flux::toast('No employee profile found. Contact HR.', variant: 'danger');

            return;
        }

        if ($this->punchNeedsCapture($lat, $lng)) {
            $this->redirect(route('attendance.my'), navigate: true);

            return;
        }

        // Already punched in today — do nothing rather than open a second day.
        if ($this->todayAttendanceFor($employee)) {
            return;
        }

        // Working from home is an approved arrangement, the same rule the
        // attendance page enforces. The card only offers WFH on an approved
        // day; this refuses a stale or forged call.
        if ($workMode === AttendanceMode::Wfh->value && ! app(WfhService::class)->isApprovedFor($employee, Carbon::today())) {
            \Flux::toast('You have no approved work-from-home request for today. Submit one under Work From Home, or clock in from the office.', variant: 'danger');

            return;
        }

        app(AttendanceService::class)->checkIn(
            $employee,
            $employee->shift ?? ShiftResolver::companyDefault(),
            [
                'ip' => request()->ip(), 'lat' => $lat, 'lng' => $lng,
                // Spec §3.2: chosen at clock-in. Only Office / WFH from the
                // dashboard; anything else falls back to Office in the service.
                'work_mode' => in_array($workMode, [AttendanceMode::Office->value, AttendanceMode::Wfh->value], true)
                    ? $workMode : AttendanceMode::Office->value,
            ],
        );

        \Flux::toast('Clocked in successfully.');
    }

    /** Clock out from the dashboard. Mirrors clockIn(); see its note. */
    public function clockOut(?float $lat = null, ?float $lng = null): void
    {
        $employee = Auth::user()?->employee;

        if (! $employee) {
            \Flux::toast('No employee profile found. Contact HR.', variant: 'danger');

            return;
        }

        if ($this->punchNeedsCapture($lat, $lng)) {
            $this->redirect(route('attendance.my'), navigate: true);

            return;
        }

        $attendance = $this->todayAttendanceFor($employee);

        if (! $attendance || $attendance->check_out) {
            return;
        }

        app(AttendanceService::class)->checkOut($attendance, ['ip' => request()->ip(), 'lat' => $lat, 'lng' => $lng]);

        \Flux::toast('Clocked out successfully. Good work today!');
    }

    /**
     * Whether HR requires evidence this screen cannot supply.
     *
     * The dashboard button asks the browser for a location, so a location
     * requirement is satisfiable here — but only if the browser actually
     * returned one. A selfie is not: the camera modal lives on the attendance
     * page, and punching without the photo would defeat the control rather
     * than enforce it. Either way the employee is handed to that page, which
     * can collect what is missing.
     */
    private function punchNeedsCapture(?float $lat, ?float $lng): bool
    {
        $settings = AttendanceSetting::first();

        if ($settings?->requires_photo) {
            return true;
        }

        return (bool) $settings?->requires_location && ($lat === null || $lng === null);
    }

    /**
     * Start a break from the dashboard — the same AttendanceService call the
     * attendance page's break button makes. Ignored once the day is closed.
     */
    public function startBreak(): void
    {
        $employee = Auth::user()?->employee;
        $attendance = $employee ? $this->todayAttendanceFor($employee) : null;

        if (! $attendance || $attendance->check_out) {
            return;
        }

        if (app(AttendanceService::class)->startBreak($attendance)) {
            \Flux::toast('Break started.');
        }
    }

    /** End the open break. Mirrors startBreak(). */
    public function endBreak(): void
    {
        $employee = Auth::user()?->employee;
        $attendance = $employee ? $this->todayAttendanceFor($employee) : null;

        if (! $attendance) {
            return;
        }

        if (app(AttendanceService::class)->endBreak($attendance)) {
            \Flux::toast('Break ended. Welcome back!');
        }
    }

    private function todayAttendanceFor(Employee $employee): ?Attendance
    {
        return Attendance::where('employee_id', $employee->id)
            ->whereDate('date', Carbon::today()->toDateString())
            ->first();
    }

    /**
     * Employee self-service dashboard.
     *
     * Every figure comes from EmployeeDashboardService, which reads the same
     * services the owning screens use (leave calculator, punch timeline, shift
     * resolver, holiday resolver) — this component only chooses the view.
     */
    private function renderEmployee()
    {
        $data = app(EmployeeDashboardService::class)->build(Auth::user());

        return view('livewire.employee-dashboard', $data)
            ->layout('layouts.app', ['title' => 'My Dashboard']);
    }
}
