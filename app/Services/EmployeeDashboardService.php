<?php

namespace App\Services;

use App\Enums\AttendanceMode;
use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\AttendanceSetting;
use App\Models\BreakLog;
use App\Models\DecemberMandatoryDay;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeScorecard;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OnboardingTask;
use App\Models\OtRequest;
use App\Models\Payslip;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\PublicHoliday;
use App\Models\ReviewGoal;
use App\Models\User;
use App\Models\WfhRequest;
use App\Services\Attendance\HolidayResolver;
use App\Services\Attendance\PunchTimeline;
use App\Services\Attendance\ResolvedShift;
use App\Services\Attendance\ShiftProgress;
use App\Services\Attendance\ShiftResolver;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\Help\GettingStarted;
use App\Services\Leave\EmployeeLeaveOverviewService;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveManagementService;
use App\Services\Leave\LeaveYearResolver;
use App\Services\Profile\ProfileCompletionService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Everything the employee self-service dashboard shows, loaded once.
 *
 * The dashboard used to assemble its figures inline in the Livewire component
 * and in Blade, and two of them drifted from the screens they summarise: the
 * leave tile summed every leave type and floored the result at zero, and the
 * timeline invented a nine-hour day for employees with no shift. This class
 * computes nothing of its own. Each figure is read from the service the owning
 * screen already uses:
 *
 *  - leave       EmployeeLeaveOverviewService → LeaveBalanceCalculator
 *                (the same rows and buckets as HR's Employee Leave Detail and
 *                My Time Off; never floored, so an overdraw stays visible)
 *  - worked time PunchTimeline, falling back to the attendance row for web
 *                punches exactly as My Attendance does
 *  - shift       ShiftResolver + ShiftProgress (no invented expected hours)
 *  - calendar    HolidayResolver + AttendanceSetting::isWeeklyOff
 *
 * Every query is scoped to the signed-in user's own employee record — under
 * impersonation that is the impersonated account, which is the point.
 */
class EmployeeDashboardService
{
    /** Leave statuses an employee is still waiting on. */
    private const OPEN_LEAVE_STATUSES = ['pending', 'pending_hr', 'more_info_requested'];

    /** Heroicons the notification payloads use that Flux ships. */
    private const NOTIFICATION_ICONS = [
        'adjustments-horizontal', 'arrow-path', 'arrow-right-start-on-rectangle', 'arrow-trending-up',
        'banknotes', 'bell', 'bell-alert', 'briefcase', 'calendar', 'calendar-days', 'chart-bar', 'check',
        'check-badge', 'check-circle', 'clipboard-document-check', 'clock', 'currency-rupee', 'document-text',
        'exclamation-triangle', 'flag', 'gift', 'hand-raised', 'home', 'inbox', 'information-circle',
        'play-circle', 'question-mark-circle', 'receipt-refund', 'star', 'x-circle',
    ];

    public function __construct(
        private readonly LeaveManagementService $leaveManagement,
        private readonly LeaveYearResolver $leaveYears,
        private readonly HolidayResolver $holidays,
        private readonly ShiftResolver $shifts,
        private readonly PunchTimeline $punchTimeline,
        private readonly ProfileCompletionService $profileCompletion,
        private readonly EmployeeMenu $employeeMenu,
        private readonly EmployeeLeaveOverviewService $leaveOverview,
    ) {}

    /**
     * The full dashboard payload, one key per card.
     *
     * The legacy keys at the end (leaveBalances, nextPublicHoliday,
     * upcomingHolidays, workingDaysElapsed, profileCompletion,
     * myOnboardingOpen) are the view data the previous dashboard exposed;
     * existing tests and anything reading the view data rely on them.
     *
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $employee = $user->employee?->loadMissing([
            'department:id,name', 'jobTitle:id,name', 'shift', 'workMode:id,name',
            'manager.employee.jobTitle:id,name',
        ]);

        // Permission AND the Payroll & Payslips module: with payslips switched
        // off the card, KPI, quick action and activity entry all drop out.
        $canViewPayslips = $user->hasPermission('view_payslips') && app(ModuleFeatureService::class)->payslipsEnabled();

        if (! $employee) {
            return $this->withoutEmployee($user);
        }

        $now = Carbon::now();
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();

        // ── Attendance: one query for the month, today's row taken from it ──
        $monthAttendance = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->get()
            ->keyBy(fn (Attendance $a) => $a->date->toDateString());

        $todayAttendance = $monthAttendance->get($today->toDateString());
        $todayAttendance?->load('breakLogs');

        // Approved leave touching this month — past days for the counts,
        // future ones so planned leave appears on the month strip.
        $approvedLeave = LeaveRequest::with('leaveType:id,name')
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $monthEnd->toDateString())
            ->whereDate('end_date', '>=', $monthStart->toDateString())
            ->get();

        $leaveByDate = [];
        foreach ($approvedLeave as $leave) {
            for ($d = Carbon::parse($leave->start_date); $d->lte($leave->end_date); $d = $d->addDay()) {
                $leaveByDate[$d->toDateString()] = $leave;
            }
        }

        $holidayByDate = $this->holidays->keyedForEmployee($employee, $monthStart, $monthEnd);

        // Mandatory December Leave shutdown days (spec §3.3): not working days,
        // not absences, not leave taken.
        $mdlDates = DecemberMandatoryDay::whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->pluck('date')
            ->mapWithKeys(fn ($date) => [Carbon::parse($date)->toDateString() => true])
            ->all();

        $approvedWfhToday = WfhRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $today->toDateString())
            ->whereDate('end_date', '>=', $today->toDateString())
            ->exists();

        $resolvedShift = $this->shifts->resolve($employee, $today);
        $upcomingHolidays = $this->holidays->upcomingHolidays($employee, 4, $today);

        $attendance = $this->attendanceOverview($monthAttendance, $leaveByDate, $holidayByDate, $monthStart, $monthEnd, $today, $mdlDates);
        $todayCard = $this->today($employee, $todayAttendance, $leaveByDate[$today->toDateString()] ?? null,
            $holidayByDate->get($today->toDateString()), isset($mdlDates[$today->toDateString()]), $approvedWfhToday, $resolvedShift, $now, $today);

        // ── Leave ──────────────────────────────────────────────────────────
        $leave = $this->leaveSummary($employee);

        $openLeave = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', self::OPEN_LEAVE_STATUSES)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // ── Requests still waiting on someone else ─────────────────────────
        $pendingRequests = (int) $openLeave->sum()
            + OtRequest::where('employee_id', $employee->id)->pending()->count()
            + WfhRequest::where('employee_id', $employee->id)->pending()->count()
            + AttendanceRegularisation::where('employee_id', $employee->id)->pending()->count();

        $otHours = (float) OtRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereBetween('work_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->sum('requested_hours');

        // ── Payroll: only for an account allowed to see payslips ───────────
        // Same statuses My Payslips lists, so the card can never surface a
        // payslip the payslips page would hide.
        $latestPayslip = $canViewPayslips
            ? Payslip::with('payroll:id,month,year,cycle,finance_approved_at')
                ->where('employee_id', $employee->id)
                ->whereIn('status', ['paid', 'draft'])
                ->latest()
                ->first()
            : null;

        $performance = $this->performance($employee);
        $documents = $this->documents($user, $employee);
        $profile = $this->profile($user, $employee, $resolvedShift, $todayAttendance);

        // Getting-started figures: the employee's own onboarding tasks only —
        // an IT or HR task is not something they can act on.
        $profileCompletion = $this->profileCompletion->for($employee);
        $onboardingTasks = OnboardingTask::where('employee_id', $employee->id)->onboarding()
            ->where('owner_role', 'employee')
            ->get(['id', 'is_completed']);

        return [
            'employee' => $employee,
            'profile' => $profile,
            'today' => $todayCard,
            'alerts' => $this->alerts($monthAttendance, $today, $profileCompletion, $onboardingTasks, (int) ($openLeave['more_info_requested'] ?? 0), $documents['pending_acknowledgement'], $performance['pending_self_reviews'], app(GettingStarted::class)->isNewJoiner($user)),
            'kpis' => $this->kpis($todayCard, $attendance, $leave, $otHours, $performance, $pendingRequests, $canViewPayslips, $latestPayslip, $upcomingHolidays->first()),
            'attendance' => $attendance,
            'leave' => $leave,
            'payroll' => $canViewPayslips ? $this->payroll($latestPayslip) : null,
            'performance' => $performance,
            'quickActions' => $this->quickActions($canViewPayslips),
            'announcements' => $this->announcements($user, $upcomingHolidays),
            'activity' => $this->activity($employee, $documents['items'], $latestPayslip),
            'documents' => $documents,
            'team' => $this->team($employee),

            'leaveBalances' => $leave['balances'],
            'nextPublicHoliday' => $upcomingHolidays->first(),
            'upcomingHolidays' => $upcomingHolidays,
            'workingDaysElapsed' => $attendance['working_days'],
            'profileCompletion' => $profileCompletion,
            'myOnboardingOpen' => $onboardingTasks->where('is_completed', false)->count(),
        ];
    }

    /** Days as an employee reads them: 4, 4.5, -2 — never 4.00. */
    public static function formatDays(float|int|null $days): string
    {
        $rounded = round((float) $days, 2);
        $text = rtrim(rtrim(number_format(abs($rounded), 2, '.', ''), '0'), '.');

        return ($rounded < 0 ? '-' : '').$text;
    }

    /** "4h 05m" from minutes. */
    public static function formatMinutes(?int $minutes): string
    {
        $minutes = max(0, (int) $minutes);

        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }

    // ── Sections ────────────────────────────────────────────────────────────

    /**
     * Identity and context for the welcome card.
     *
     * @return array<string, mixed>
     */
    private function profile(User $user, Employee $employee, ?ResolvedShift $shift, ?Attendance $todayAttendance): array
    {
        $mode = $todayAttendance?->work_mode ? AttendanceMode::tryFrom($todayAttendance->work_mode) : null;

        $hour = (int) now()->format('G');

        $quotes = [
            'Small steps every day add up to big results.',
            'Focus on progress, not perfection.',
            'Consistency beats intensity — show up.',
            'Great work leads to great results.',
            'Your effort today shapes tomorrow.',
        ];

        return [
            'name' => $user->name,
            'first_name' => Str::of($user->name ?? 'there')->trim()->explode(' ')->first(),
            'initials' => $user->initials(),
            'email' => $user->email,
            'photo_url' => $employee->photo ? Storage::url($employee->photo) : null,
            'designation' => $employee->jobTitle?->name,
            'department' => $employee->department?->name,
            'employee_code' => $employee->employee_code ?? null,
            'greeting' => $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'),
            'shift_label' => $shift
                ? $shift->name.' · '.$shift->start->format('g:i A').' – '.$shift->end->format('g:i A')
                : null,
            'work_mode' => $mode?->label() ?? $employee->workMode?->name,
            'message' => $quotes[now()->dayOfYear % count($quotes)],
        ];
    }

    /**
     * Today's status, live duration, timeline and shift progress.
     *
     * @return array<string, mixed>
     */
    private function today(
        Employee $employee,
        ?Attendance $attendance,
        ?LeaveRequest $leaveToday,
        ?PublicHoliday $holidayToday,
        bool $mdlToday,
        bool $approvedWfhToday,
        ?ResolvedShift $shift,
        Carbon $now,
        Carbon $today,
    ): array {
        $clockedIn = (bool) $attendance?->check_in;
        $clockedOut = (bool) $attendance?->check_out;
        $breaks = $attendance?->breakLogs?->sortBy('break_start')->values() ?? collect();
        /** @var BreakLog|null $activeBreak */
        $activeBreak = $breaks->first(fn (BreakLog $b) => $b->break_end === null);

        // PunchTimeline is the canonical processor for device punches. Web
        // punches write no punch rows, so — like My Attendance — those days
        // fall back to the attendance row, net of breaks.
        $journey = $this->punchTimeline->process(
            AttendancePunch::where('employee_id', $employee->id)->whereDate('punch_date', $today->toDateString())->orderBy('punched_at')->get(),
            $today,
            AttendanceDailySummary::where('employee_id', $employee->id)->whereDate('date', $today->toDateString())->first(),
        );

        if (($journey['kept_count'] ?? 0) > 0) {
            $worked = (int) $journey['working_minutes'];
        } elseif ($clockedIn) {
            $end = $attendance->check_out ?? $now;
            $breakMinutes = (int) $breaks->whereNotNull('break_end')->sum('duration_minutes')
                + ($activeBreak && ! $clockedOut ? (int) $activeBreak->break_start->diffInMinutes($now) : 0);
            $worked = max(0, (int) $attendance->check_in->diffInMinutes($end) - $breakMinutes);
        } else {
            $worked = 0;
        }

        $isWeeklyOff = app(WorkingDayResolver::class)->isWeeklyOff($today);

        // Same precedence as My Attendance: a day nobody was expected to work
        // is never measured against a shift.
        $nonWorkingLabel = match (true) {
            $leaveToday !== null => 'On leave',
            $holidayToday !== null => 'Public holiday',
            $mdlToday => 'MDL shutdown',
            $isWeeklyOff => 'Weekly off',
            default => null,
        };

        $progress = match (true) {
            $nonWorkingLabel !== null => ShiftProgress::nonWorking($nonWorkingLabel),
            $shift === null => ShiftProgress::unassigned(),
            default => ShiftProgress::of($shift, $worked, clockedOut: $clockedOut),
        };

        $shiftEnded = $shift !== null && $now->gt($shift->end);

        [$state, $label, $detail] = match (true) {
            $clockedIn && $attendance->work_mode === AttendanceMode::Wfh->value => ['wfh', 'Working from home', null],
            $clockedIn && $attendance->status === 'half_day' => ['half_day', 'Half day', null],
            $clockedIn && $attendance->is_late => ['late', 'Present', 'Late by '.self::lateLabel((int) $attendance->late_minutes)],
            $clockedIn => ['present', 'Present', null],
            $leaveToday !== null => ['leave', 'On leave', ($leaveToday->leaveType?->name ?? 'Approved leave').($leaveToday->is_half_day ? ' · half day' : '')],
            $holidayToday !== null => ['holiday', 'Holiday', $holidayToday->name],
            // Mandatory December Leave (spec §3.3): a company shutdown day,
            // never an absence.
            $mdlToday => ['mdl', 'MDL shutdown', 'Company shutdown day'],
            $isWeeklyOff => ['weekly_off', 'Weekly off', 'Enjoy your day off'],
            $approvedWfhToday => ['wfh', 'WFH approved', 'You have not clocked in yet'],
            $shiftEnded => ['absent', 'Absent', 'No clock-in recorded today'],
            default => ['not_started', 'Not clocked in', $shift ? 'Shift starts at '.$shift->start->format('g:i A') : null],
        };

        if ($clockedIn && $detail === null) {
            $detail = $clockedOut
                ? 'Clocked out at '.$attendance->check_out->format('g:i A')
                : 'Clocked in at '.$attendance->check_in->format('g:i A');
        }

        $working = $clockedIn && ! $clockedOut;

        return [
            'state' => $state,
            'label' => $label,
            'detail' => $detail,
            'clocked_in' => $clockedIn,
            'clocked_out' => $clockedOut,
            'working' => $working,
            'on_break' => $working && $activeBreak !== null,
            'break_since' => $activeBreak?->break_start?->format('g:i A'),
            'is_working_day' => $nonWorkingLabel === null,
            'worked_minutes' => $worked,
            // Seconds-epoch the live counter counts up from; null when the
            // clock should stand still (not started, on a break, or done).
            'live_base' => $working && ! $activeBreak ? $now->timestamp - ($worked * 60) : null,
            'check_in' => $attendance?->check_in?->format('g:i A') ?? $journey['first_in'] ?? null,
            'check_out' => $attendance?->check_out?->format('g:i A') ?? $journey['last_out'] ?? null,
            'break_start' => $breaks->first()?->break_start?->format('g:i A'),
            'break_end' => $activeBreak ? null : $breaks->last()?->break_end?->format('g:i A'),
            'break_count' => $breaks->count(),
            'progress' => $progress->toArray() + [
                'worked_label' => $progress->workedLabel(),
                'expected_label' => $progress->expectedLabel(),
                'remaining_label' => $progress->remainingLabel(),
                'status_label' => $progress->statusLabel(),
                'overtime_minutes' => $progress->overtimeMinutes(),
            ],
            'shift_start' => $shift?->start->format('g:i A'),
            'shift_end' => $shift?->end->format('g:i A'),
            // Spec §3.2: the work mode is chosen at clock-in; an approved WFH
            // day starts on "wfh".
            'default_mode' => $approvedWfhToday ? AttendanceMode::Wfh->value : AttendanceMode::Office->value,
        ];
    }

    /**
     * Month-to-date attendance counts and a day-by-day strip.
     *
     * @param  Collection<string, Attendance>  $monthAttendance
     * @param  array<string, LeaveRequest>  $leaveByDate
     * @param  Collection<string, PublicHoliday>  $holidayByDate
     * @return array<string, mixed>
     */
    private function attendanceOverview(Collection $monthAttendance, array $leaveByDate, Collection $holidayByDate, Carbon $monthStart, Carbon $monthEnd, Carbon $today, array $mdlDates = []): array
    {
        $counts = ['present' => 0, 'absent' => 0, 'wfh' => 0, 'leave' => 0, 'late' => 0, 'half_day' => 0, 'holiday' => 0, 'weekly_off' => 0, 'mdl' => 0];
        $days = [];
        $workingDays = 0;

        for ($d = $monthStart->copy(); $d->lte($monthEnd); $d = $d->addDay()) {
            $key = $d->toDateString();
            $record = $monthAttendance->get($key);
            $worked = $record && $record->check_in;
            $holiday = $holidayByDate->get($key);
            $weeklyOff = app(WorkingDayResolver::class)->isWeeklyOff($d);
            $shutdown = isset($mdlDates[$key]);
            $isPast = $d->lt($today);
            $elapsed = $d->lte($today);

            // The same working-day rule the dashboard has always used for the
            // attendance percentage: the company week, minus holidays — and
            // minus the December shutdown days.
            if ($elapsed && ! $weeklyOff && ! $holiday && ! $shutdown) {
                $workingDays++;
            }

            $state = match (true) {
                $worked && $record->work_mode === AttendanceMode::Wfh->value => 'wfh',
                $worked && $record->status === 'half_day' => 'half_day',
                $worked && $record->is_late => 'late',
                (bool) $worked => 'present',
                $holiday !== null => 'holiday',
                $shutdown => 'mdl',
                $weeklyOff => 'weekly_off',
                isset($leaveByDate[$key]) => 'leave',
                ! $elapsed => 'future',
                ! $isPast => 'today',
                default => 'absent',
            };

            if ($elapsed) {
                if ($worked) {
                    $counts['present']++;
                    $counts['late'] += $record->is_late ? 1 : 0;
                    $counts['wfh'] += $state === 'wfh' ? 1 : 0;
                    $counts['half_day'] += $record->status === 'half_day' ? 1 : 0;
                } elseif (isset($counts[$state])) {
                    $counts[$state]++;
                }
            }

            $days[] = [
                'date' => $key,
                'day' => $d->day,
                'weekday' => $d->format('D'),
                'state' => $state,
                'is_today' => $d->isSameDay($today),
                'planned' => ! $elapsed && $state === 'leave',
                'title' => $d->format('D, j M').' · '.self::stateLabel($state)
                    .($worked ? ' · in '.$record->check_in->format('g:i A') : '')
                    .($holiday ? ' · '.$holiday->name : ''),
            ];
        }

        // Month-to-date habits, from days the employee actually clocked in.
        // Descriptive only: no rule here decides anything.
        $worked = $monthAttendance->filter(fn (Attendance $a) => $a->check_in && $a->date->lte($today));
        $closed = $worked->filter(fn (Attendance $a) => $a->check_out !== null);
        $avgInMinutes = $worked->isNotEmpty()
            ? (int) round($worked->avg(fn (Attendance $a) => $a->check_in->hour * 60 + $a->check_in->minute))
            : null;
        $avgWorkedMinutes = $closed->isNotEmpty()
            ? (int) round($closed->avg(fn (Attendance $a) => max(0, (int) $a->check_in->diffInMinutes($a->check_out) - (int) $a->break_minutes)))
            : null;

        return [
            'month_label' => $today->format('F Y'),
            'range_label' => $monthStart->format('j M').' – '.$today->format('j M'),
            'counts' => $counts,
            'working_days' => $workingDays,
            'present_days' => $counts['present'],
            'percent' => $workingDays > 0 ? min(100, (int) round($counts['present'] / $workingDays * 100)) : 0,
            'days' => $days,
            'has_data' => $monthAttendance->isNotEmpty() || $counts['leave'] > 0,
            'avg_check_in' => $avgInMinutes !== null ? $today->copy()->startOfDay()->addMinutes($avgInMinutes)->format('g:i A') : null,
            'avg_worked' => $avgWorkedMinutes !== null ? self::formatMinutes($avgWorkedMinutes) : null,
            'on_time_rate' => $counts['present'] > 0 ? (int) round(($counts['present'] - $counts['late']) / $counts['present'] * 100) : null,
        ];
    }

    /**
     * The Conexus leave position for the current leave year.
     *
     * Read through EmployeeLeaveOverviewService — the same object My Time Off
     * renders — so the dashboard, My Time Off and HR's Employee Leave Detail
     * show one figure (LeaveBalanceCalculator, never floored). CSL leads; the
     * retired 28-day Annual Leave is never shown; Comp Off and any special
     * leave are listed but only CSL + Comp Off count as Available Leave.
     *
     * @return array<string, mixed>
     */
    private function leaveSummary(Employee $employee): array
    {
        $overview = $this->leaveOverview->for($employee);
        $year = $overview['year'];

        $shape = function (LeaveType $type, array $s): array {
            return [
                'id' => $type->id,
                'name' => $type->name,
                'code' => $type->code,
                'color' => $type->color,
                'credit' => round($s['base'] + $s['accrued'], 2),
                'carry_forward' => $s['carry_forward'],
                'adjustments' => round($s['add_on'] + $s['adjustment_credit'] - $s['adjustment_debit'] + $s['opening'], 2),
                'expired' => $s['expired'],
                'used' => $s['used'],
                'encashed' => $s['encashed'],
                'credits' => $s['credits'],
                'available' => $s['approved_available'],
                'pending' => $s['pending'],
                'available_to_request' => $s['available_to_request'],
            ];
        };

        $csl = $overview['csl'];
        $primary = $csl && $csl['balance'] ? $shape($csl['type'], $csl['summary']) : null;

        $others = collect([$overview['comp_off']])
            ->filter(fn ($c) => $c && $c['balance'])
            ->map(fn ($c) => $shape($c['type'], $c['summary']))
            ->merge($overview['others']->map(fn ($o) => $shape($o['type'], $o['summary'])))
            ->filter(fn (array $t) => $t['credits'] != 0 || $t['used'] != 0 || $t['pending'] != 0 || $t['available'] != 0)
            ->values();

        return [
            'year_label' => $year->label,
            'primary' => $primary,
            'others' => $others,
            'available_leave' => $overview['available_leave'],
            'policy' => $overview['policy'],
            'mdl' => $overview['mdl'],
            // Every row the employee holds this year (legacy view data).
            'balances' => $this->leaveManagement->employeeDetail($employee, $year)
                ->filter(fn (array $row) => $row['leave_type'] !== null)
                ->pluck('balance')->values(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function payroll(?Payslip $payslip): ?array
    {
        if (! $payslip) {
            return ['payslip' => null];
        }

        $period = trim(($payslip->payroll?->month ?? '').' '.($payslip->payroll?->year ?? ''));

        return [
            'payslip' => $payslip,
            'period' => $period !== '' ? $period : $payslip->created_at?->format('F Y'),
            'is_paid' => $payslip->status === 'paid',
            'status_label' => $payslip->status === 'paid' ? 'Paid' : 'Processing',
            'net' => (float) $payslip->net_salary,
            'download_url' => Route::has('payroll.payslips.download') ? route('payroll.payslips.download', $payslip) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function performance(Employee $employee): array
    {
        $cycle = PerformanceCycle::whereIn('status', ['active', 'completed', 'locked'])
            ->latest('start_date')
            ->first();

        $scorecard = $cycle
            ? EmployeeScorecard::where('employee_id', $employee->id)->where('performance_cycle_id', $cycle->id)->first()
            : null;

        $review = $cycle
            ? PerformanceReview::where('employee_id', $employee->id)->where('performance_cycle_id', $cycle->id)->latest('id')->first()
            : null;

        $pendingSelf = PerformanceReview::with('cycle:id,name')
            ->where('employee_id', $employee->id)
            ->where('type', 'self')
            ->where('status', 'pending')
            ->get();

        $goals = ReviewGoal::where('employee_id', $employee->id)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END) as completed')
            ->first();

        $score = $scorecard?->final_score ?? $review?->final_score;

        return [
            'cycle' => $cycle ? ['name' => $cycle->name, 'status' => Str::headline((string) $cycle->status)] : null,
            'score' => is_numeric($score) ? round((float) $score, 1) : null,
            'grade' => $scorecard?->grade ?? $review?->grade,
            'review_status' => $review ? Str::headline((string) $review->status) : null,
            'goals_total' => (int) ($goals->total ?? 0),
            'goals_completed' => (int) ($goals->completed ?? 0),
            'pending_self_reviews' => $pendingSelf,
        ];
    }

    /**
     * Recent documents, with the same visibility DocumentManager applies to a
     * non-manager: company-wide, policies, and the employee's own.
     *
     * @return array{items: Collection<int, array<string, mixed>>, pending_acknowledgement: int}
     */
    private function documents(User $user, Employee $employee): array
    {
        $visible = function ($q) use ($user, $employee) {
            $q->where('visibility', 'all')
                ->orWhere(fn ($policy) => $policy->where('category', 'policy')->whereNull('employee_id'))
                ->orWhere('employee_id', $employee->id);

            // Same rule as DocumentPolicy: payslips are payroll staff's, not a Director's.
            if ($user->canRunPayroll()) {
                $q->orWhere('category', 'payslip');
            }
        };

        $items = Document::whereNull('parent_id')
            ->where($visible)
            ->latest()
            ->take(5)
            ->get(['id', 'title', 'category', 'file_name', 'employee_id', 'created_at'])
            ->map(fn (Document $doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
                'type' => Str::headline((string) ($doc->category ?: 'document')),
                'date' => $doc->created_at,
                'is_mine' => $doc->employee_id === $employee->id,
                // Five minutes, as on the Documents page — the link is minted
                // per render, never stored.
                'view_url' => URL::temporarySignedRoute('documents.view', now()->addMinutes(5), ['document' => $doc->id]),
                'download_url' => URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $doc->id]),
            ]);

        // The sidebar's acknowledgement badge rule, so the two never disagree.
        $pendingAcknowledgement = Document::whereNull('parent_id')
            ->where('requires_acknowledgement', true)
            ->where(fn ($q) => $q->where('visibility', 'all')
                ->orWhere(fn ($policy) => $policy->where('category', 'policy')->whereNull('employee_id'))
                ->orWhere('employee_id', $employee->id))
            ->whereDoesntHave('acknowledgements', fn ($q) => $q->where('employee_id', $employee->id))
            ->count();

        return ['items' => $items, 'pending_acknowledgement' => $pendingAcknowledgement];
    }

    /**
     * Things waiting on the employee. Empty when there is nothing to do, so
     * the strip disappears instead of showing placeholder cards.
     *
     * @param  Collection<string, Attendance>  $monthAttendance
     * @param  array{percent: int, missing: array<int, mixed>}  $profile
     * @param  Collection<int, OnboardingTask>  $tasks
     * @param  Collection<int, PerformanceReview>  $pendingSelfReviews
     * @return Collection<int, array<string, mixed>>
     */
    private function alerts(Collection $monthAttendance, Carbon $today, array $profile, Collection $tasks, int $moreInfoRequests, int $pendingAcknowledgement, Collection $pendingSelfReviews, bool $newJoiner = false): Collection
    {
        $alerts = collect();

        // First weeks: point new joiners at the getting-started tutorial.
        if ($newJoiner && Route::has('help.getting-started')) {
            $alerts->push([
                'icon' => 'academic-cap',
                'title' => 'New here? Start the tutorial',
                'status' => 'Sign-in, profile and leave in a few steps',
                'progress' => null,
                'url' => route('help.getting-started'),
            ]);
        }

        if (($profile['percent'] ?? 100) < 100) {
            $missing = count($profile['missing'] ?? []);
            $alerts->push([
                'icon' => 'user-circle',
                'title' => 'Complete your profile',
                'status' => $missing.' '.Str::plural('field', $missing).' to fill in',
                'progress' => (int) $profile['percent'],
                'url' => route('profile.me'),
            ]);
        }

        $openTasks = $tasks->where('is_completed', false)->count();
        if ($openTasks > 0) {
            $alerts->push([
                'icon' => 'clipboard-document-check',
                'title' => 'Finish your onboarding',
                'status' => $openTasks.' '.Str::plural('task', $openTasks).' waiting',
                'progress' => (int) round(($tasks->count() - $openTasks) / max(1, $tasks->count()) * 100),
                'url' => Route::has('onboarding.my') ? route('onboarding.my') : null,
            ]);
        }

        if ($pendingAcknowledgement > 0) {
            $alerts->push([
                'icon' => 'document-check',
                'title' => 'Review documents',
                'status' => $pendingAcknowledgement.' '.Str::plural('document', $pendingAcknowledgement).' to acknowledge',
                'progress' => null,
                'url' => route('documents.index'),
            ]);
        }

        $missingCheckout = $monthAttendance
            ->filter(fn (Attendance $a) => $a->check_in && ! $a->check_out && $a->date->lt($today))
            ->count();
        if ($missingCheckout > 0) {
            $alerts->push([
                'icon' => 'clock',
                'title' => 'Missing clock-out',
                'status' => $missingCheckout.' '.Str::plural('day', $missingCheckout).' to regularise',
                'progress' => null,
                'url' => route('attendance.my'),
            ]);
        }

        if ($moreInfoRequests > 0) {
            $alerts->push([
                'icon' => 'chat-bubble-left-ellipsis',
                'title' => 'Reply on leave request',
                'status' => $moreInfoRequests.' '.Str::plural('request', $moreInfoRequests).' needs details',
                'progress' => null,
                'url' => route('time-off.my'),
            ]);
        }

        foreach ($pendingSelfReviews as $review) {
            $alerts->push([
                'icon' => 'star',
                'title' => 'Self-assessment due',
                'status' => $review->cycle?->name ?? 'Performance review',
                'progress' => null,
                'url' => route('performance.my'),
            ]);
        }

        return $alerts->values();
    }

    /**
     * @param  array<string, mixed>  $today
     * @param  array<string, mixed>  $attendance
     * @param  array<string, mixed>  $leave
     * @param  array<string, mixed>  $performance
     * @return array<int, array<string, mixed>>
     */
    private function kpis(array $today, array $attendance, array $leave, float $otHours, array $performance, int $pendingRequests, bool $canViewPayslips, ?Payslip $payslip, ?PublicHoliday $nextHoliday): array
    {
        $primary = $leave['primary'];

        $kpis = [
            [
                'label' => 'Today', 'icon' => 'check-badge', 'value' => $today['label'],
                'sub' => $today['detail'] ?? ($today['check_in'] ? 'In at '.$today['check_in'] : 'No punch yet'),
                'tone' => match ($today['state']) {
                    'present', 'wfh' => 'success',
                    'late', 'half_day' => 'warning',
                    'absent' => 'danger',
                    default => 'neutral',
                },
                'href' => route('attendance.my'),
            ],
            [
                'label' => 'Attendance', 'icon' => 'chart-pie', 'value' => $attendance['percent'].'%',
                'sub' => $attendance['present_days'].' of '.$attendance['working_days'].' working days',
                'tone' => 'neutral', 'href' => route('attendance.my'),
            ],
            [
                // CSL + Comp Off available to request — never MDL, never the
                // retired Annual Leave.
                'label' => 'Available Leave', 'icon' => 'calendar-days',
                'value' => $primary ? self::formatDays($leave['available_leave']).' '.Str::plural('day', abs($leave['available_leave']) == 1 ? 1 : 2) : 'Not set',
                'sub' => $primary ? 'CSL + Comp Off · '.$leave['year_label'] : 'No CSL for '.$leave['year_label'],
                'tone' => $primary && $leave['available_leave'] < 0 ? 'danger' : 'neutral',
                'href' => route('time-off.my'),
            ],
            [
                'label' => 'Late arrivals', 'icon' => 'exclamation-triangle', 'value' => (string) $attendance['counts']['late'],
                'sub' => 'This month', 'tone' => $attendance['counts']['late'] > 0 ? 'warning' : 'neutral',
                'href' => route('attendance.my'),
            ],
            [
                'label' => 'Overtime', 'icon' => 'bolt', 'value' => self::formatDays($otHours).'h',
                'sub' => 'Approved this month', 'tone' => 'neutral',
                'href' => route('overtime.my'),
            ],
            [
                'label' => 'Performance', 'icon' => 'star',
                'value' => $performance['score'] !== null ? self::formatDays($performance['score']) : 'Not rated',
                'sub' => $performance['grade'] ? 'Grade '.$performance['grade'] : ($performance['cycle']['name'] ?? 'No active cycle'),
                'tone' => 'neutral', 'href' => route('performance.dashboard'),
            ],
            [
                'label' => 'Pending', 'icon' => 'inbox-stack', 'value' => (string) $pendingRequests,
                'sub' => $pendingRequests === 1 ? 'Request awaiting approval' : 'Requests awaiting approval',
                'tone' => 'neutral', 'href' => route('time-off.my'),
            ],
        ];

        $kpis[] = $canViewPayslips
            ? [
                'label' => 'Salary', 'icon' => 'banknotes',
                'value' => $payslip ? trim(Str::substr((string) $payslip->payroll?->month, 0, 3).' '.$payslip->payroll?->year) : 'No payslip',
                'sub' => $payslip ? ($payslip->status === 'paid' ? 'Payslip paid' : 'Payroll processing') : 'Not generated yet',
                'tone' => 'neutral', 'href' => route('payroll.payslips'),
            ]
            : [
                'label' => 'Next holiday', 'icon' => 'sun',
                'value' => $nextHoliday ? Carbon::parse($nextHoliday->date)->format('j M') : 'None',
                'sub' => $nextHoliday?->name ?? 'No upcoming holiday',
                'tone' => 'neutral', 'href' => route('time-off.my'),
            ];

        return $kpis;
    }

    /**
     * Shortcuts, filtered by what this account may open: payslips need the
     * view_payslips permission, and an entry HR hid from the employee
     * sidebar (Settings › Sidebar Menu) is hidden here too.
     *
     * @return array<int, array{label: string, icon: string, href: string}>
     */
    private function quickActions(bool $canViewPayslips): array
    {
        $visibleMenu = collect($this->employeeMenu->visible())->pluck('key')->flip();

        $candidates = [
            ['menu' => 'attendance', 'label' => 'Clock In / Out', 'icon' => 'finger-print', 'route' => 'attendance.my'],
            ['menu' => 'leave', 'label' => 'Apply Leave', 'icon' => 'calendar-days', 'route' => 'time-off.my'],
            ['menu' => 'overtime', 'label' => 'Log Overtime', 'icon' => 'bolt', 'route' => 'overtime.my'],
            ['menu' => 'wfh', 'label' => 'WFH Request', 'icon' => 'home-modern', 'route' => 'wfh.my'],
            ['menu' => 'payroll', 'label' => 'My Payslips', 'icon' => 'banknotes', 'route' => 'payroll.payslips', 'allowed' => $canViewPayslips],
            ['menu' => 'documents', 'label' => 'Documents', 'icon' => 'document-text', 'route' => 'documents.index'],
            ['menu' => 'performance', 'label' => 'Performance', 'icon' => 'arrow-trending-up', 'route' => 'performance.dashboard'],
            ['menu' => 'help', 'label' => 'Help Desk', 'icon' => 'lifebuoy', 'route' => 'help.employee-guide'],
        ];

        return collect($candidates)
            ->filter(fn (array $a) => ($a['allowed'] ?? true) && $visibleMenu->has($a['menu']) && Route::has($a['route']))
            ->map(fn (array $a) => ['label' => $a['label'], 'icon' => $a['icon'], 'href' => route($a['route'])])
            ->values()
            ->all();
    }

    /**
     * Holidays and the employee's own notifications, made readable.
     *
     * HR balance changes arrive one notification per posting, so a single
     * reconciliation reads as "HR added 7 days… HR deducted 2 days…". Those
     * are folded into one event per leave type per day, and the balance
     * figure quoted inside each — stale the moment the next one lands — is
     * not repeated. The full history is one click away on My Time Off.
     *
     * @param  Collection<int, PublicHoliday>  $upcomingHolidays
     * @return array{holidays: Collection<int, array<string, mixed>>, updates: Collection<int, array<string, mixed>>, unread: int}
     */
    private function announcements(User $user, Collection $upcomingHolidays): array
    {
        $holidays = $upcomingHolidays->take(3)->map(fn (PublicHoliday $h) => [
            'name' => $h->name,
            'date' => Carbon::parse($h->date),
            'days_away' => (int) Carbon::today()->diffInDays(Carbon::parse($h->date)),
        ])->values();

        $notifications = $user->notifications()->latest()->take(20)->get();

        $updates = collect();
        $groups = [];

        foreach ($notifications as $n) {
            $data = (array) $n->data;
            $type = $data['type'] ?? null;

            if ($type === 'leave_balance_changed') {
                preg_match('/your (.+?) balance/i', (string) ($data['body'] ?? ''), $m);
                $leaveType = $m[1] ?? 'leave';
                $groupKey = Str::lower($leaveType).'|'.$n->created_at?->toDateString();

                if (isset($groups[$groupKey])) {
                    $index = $groups[$groupKey];
                    $existing = $updates[$index];
                    $existing['count']++;
                    $existing['unread'] = $existing['unread'] || $n->read_at === null;
                    $existing['sub'] = $existing['count'].' changes · View leave history';
                    $updates[$index] = $existing;

                    continue;
                }

                $groups[$groupKey] = $updates->count();
                $updates->push([
                    'icon' => 'adjustments-horizontal',
                    'title' => 'Your '.$leaveType.' balance was updated by HR',
                    'sub' => 'View leave history',
                    'time' => $n->created_at,
                    'url' => route('time-off.my'),
                    'unread' => $n->read_at === null,
                    'count' => 1,
                ]);

                continue;
            }

            $updates->push([
                // Notification payloads name their own icon; one that Flux does
                // not ship would fail the whole render, so fall back to a bell.
                'icon' => in_array($data['icon'] ?? null, self::NOTIFICATION_ICONS, true) ? $data['icon'] : 'bell',
                'title' => $data['title'] ?? 'Notification',
                'sub' => isset($data['body']) ? Str::limit(strip_tags((string) $data['body']), 110) : null,
                'time' => $n->created_at,
                'url' => $data['url'] ?? null,
                'unread' => $n->read_at === null,
                'count' => 1,
            ]);
        }

        return [
            'holidays' => $holidays,
            'updates' => $updates->take(5)->values(),
            'unread' => $notifications->whereNull('read_at')->count(),
        ];
    }

    /**
     * The employee's own recent events, from their records — never the audit
     * log, which carries internal metadata an employee should not see.
     *
     * @param  Collection<int, array<string, mixed>>  $documents
     * @return Collection<int, array<string, mixed>>
     */
    private function activity(Employee $employee, Collection $documents, ?Payslip $payslip): Collection
    {
        $items = collect();

        LeaveRequest::with('leaveType:id,name')
            ->where('employee_id', $employee->id)
            ->latest('updated_at')->take(5)->get()
            ->each(function (LeaveRequest $r) use ($items) {
                $items->push([
                    'icon' => 'calendar-days',
                    'title' => match ($r->status) {
                        'approved' => 'Leave approved',
                        'rejected' => 'Leave request declined',
                        'cancelled' => 'Leave request cancelled',
                        'pending_hr' => 'Leave request with HR',
                        'more_info_requested' => 'More information requested',
                        default => 'Leave request submitted',
                    },
                    'meta' => ($r->leaveType?->name ?? 'Leave').' · '.self::range($r->start_date, $r->end_date).' · '.self::formatDays((float) $r->days).' '.Str::plural('day', (float) $r->days == 1.0 ? 1 : 2),
                    'tone' => self::statusTone($r->status),
                    'time' => $r->updated_at,
                    'url' => route('time-off.my'),
                ]);
            });

        WfhRequest::where('employee_id', $employee->id)->latest('updated_at')->take(3)->get()
            ->each(fn (WfhRequest $r) => $items->push([
                'icon' => 'home-modern',
                'title' => 'WFH request '.self::statusVerb($r->status),
                'meta' => self::range($r->start_date, $r->end_date),
                'tone' => self::statusTone($r->status),
                'time' => $r->updated_at,
                'url' => Route::has('wfh.my') ? route('wfh.my') : null,
            ]));

        OtRequest::where('employee_id', $employee->id)->latest('updated_at')->take(3)->get()
            ->each(fn (OtRequest $r) => $items->push([
                'icon' => 'bolt',
                'title' => $r->status === 'pending' ? 'Overtime logged' : 'Overtime '.self::statusVerb($r->status),
                'meta' => self::formatDays((float) $r->requested_hours).'h on '.$r->work_date?->format('j M'),
                'tone' => self::statusTone($r->status),
                'time' => $r->updated_at,
                'url' => route('overtime.my'),
            ]));

        AttendanceRegularisation::where('employee_id', $employee->id)->latest('updated_at')->take(3)->get()
            ->each(fn (AttendanceRegularisation $r) => $items->push([
                'icon' => 'arrow-path',
                'title' => 'Attendance regularisation '.self::statusVerb((string) $r->status),
                'meta' => $r->work_date ? Carbon::parse($r->work_date)->format('D, j M') : 'Attendance',
                'tone' => self::statusTone((string) $r->status),
                'time' => $r->updated_at,
                'url' => route('attendance.my'),
            ]));

        if ($payslip && $payslip->status === 'paid') {
            $items->push([
                'icon' => 'banknotes',
                'title' => 'Payslip published',
                'meta' => trim(($payslip->payroll?->month ?? '').' '.($payslip->payroll?->year ?? '')),
                'tone' => 'success',
                'time' => $payslip->payroll?->finance_approved_at ?? $payslip->updated_at,
                'url' => route('payroll.payslips'),
            ]);
        }

        $documents->where('is_mine', true)->each(fn (array $d) => $items->push([
            'icon' => 'document-arrow-up',
            'title' => 'Document added',
            'meta' => $d['title'],
            'tone' => 'neutral',
            'time' => $d['date'],
            'url' => route('documents.index'),
        ]));

        return $items->filter(fn (array $i) => $i['time'] !== null)
            ->sortByDesc(fn (array $i) => $i['time']->getTimestamp())
            ->take(6)
            ->values();
    }

    /** @return array<string, mixed> */
    private function team(Employee $employee): array
    {
        $manager = $employee->manager;

        return [
            'manager' => $manager ? [
                'name' => $manager->name,
                'email' => $manager->email,
                'initials' => $manager->initials(),
                'designation' => $manager->employee?->jobTitle?->name,
                'photo_url' => $manager->employee?->photo ? Storage::url($manager->employee->photo) : null,
            ] : null,
            'department' => $employee->department?->name,
            'designation' => $employee->jobTitle?->name,
            'org_chart_url' => Route::has('employees.org-chart') ? route('employees.org-chart') : null,
            'on_leave_this_week' => $this->colleaguesOnLeaveThisWeek($employee),
        ];
    }

    /**
     * Spec §5.4 team calendar: colleagues (same department) on approved leave
     * this week — name and dates ONLY. No leave type, reason or balance: that
     * is the colleague's own business.
     *
     * @return Collection<int, array{name: string, dates: string}>
     */
    private function colleaguesOnLeaveThisWeek(Employee $employee): Collection
    {
        if (! $employee->department_id) {
            return collect();
        }

        $weekStart = Carbon::today()->startOfWeek();
        $weekEnd = Carbon::today()->endOfWeek();

        return LeaveRequest::with('employee.user:id,name')
            ->where('status', 'approved')
            ->where('employee_id', '!=', $employee->id)
            ->whereHas('employee', fn ($q) => $q->where('department_id', $employee->department_id))
            ->whereDate('start_date', '<=', $weekEnd->toDateString())
            ->whereDate('end_date', '>=', $weekStart->toDateString())
            ->orderBy('start_date')
            ->get(['id', 'employee_id', 'start_date', 'end_date'])
            ->map(fn (LeaveRequest $leave) => [
                'name' => $leave->employee?->user?->name ?? 'A colleague',
                'dates' => $leave->start_date->isSameDay($leave->end_date)
                    ? $leave->start_date->format('D j M')
                    : $leave->start_date->format('j M').' – '.$leave->end_date->format('j M'),
            ])
            ->values();
    }

    /**
     * An account with no employee record — the dashboard still renders, it
     * just has nothing of the employee's to show.
     *
     * @return array<string, mixed>
     */
    private function withoutEmployee(User $user): array
    {
        $hour = (int) now()->format('G');

        return [
            'employee' => null,
            'profile' => [
                'name' => $user->name,
                'first_name' => Str::of($user->name ?? 'there')->trim()->explode(' ')->first(),
                'initials' => $user->initials(),
                'email' => $user->email,
                'photo_url' => null,
                'designation' => null,
                'department' => null,
                'employee_code' => null,
                'greeting' => $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'),
                'shift_label' => null,
                'work_mode' => null,
                'message' => null,
            ],
            'today' => null,
            'alerts' => collect(),
            'kpis' => [],
            'attendance' => null,
            'leave' => null,
            'payroll' => null,
            'performance' => null,
            'quickActions' => [],
            'announcements' => ['holidays' => collect(), 'updates' => collect(), 'unread' => 0],
            'activity' => collect(),
            'documents' => ['items' => collect(), 'pending_acknowledgement' => 0],
            'team' => null,
            'leaveBalances' => collect(),
            'nextPublicHoliday' => null,
            'upcomingHolidays' => collect(),
            'workingDaysElapsed' => 0,
            'profileCompletion' => ['percent' => 100, 'missing' => [], 'completed' => 0, 'total' => 0],
            'myOnboardingOpen' => 0,
        ];
    }

    // ── Formatting ──────────────────────────────────────────────────────────

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'present' => 'Present',
            'late' => 'Late',
            'wfh' => 'Work from home',
            'half_day' => 'Half day',
            'leave' => 'Leave',
            'holiday' => 'Holiday',
            'weekly_off' => 'Weekly off',
            'mdl' => 'MDL shutdown',
            'absent' => 'Absent',
            'today' => 'Today',
            default => 'Upcoming',
        };
    }

    private static function lateLabel(int $minutes): string
    {
        return $minutes >= 60 ? self::formatMinutes($minutes) : $minutes.' min';
    }

    private static function range(?CarbonInterface $start, ?CarbonInterface $end): string
    {
        if (! $start) {
            return '';
        }

        return ! $end || $start->isSameDay($end)
            ? $start->format('j M')
            : $start->format('j M').' – '.$end->format('j M');
    }

    private static function statusVerb(string $status): string
    {
        return match ($status) {
            'approved' => 'approved',
            'rejected' => 'declined',
            'cancelled' => 'cancelled',
            default => 'submitted',
        };
    }

    private static function statusTone(string $status): string
    {
        return match ($status) {
            'approved' => 'success',
            'rejected', 'cancelled' => 'danger',
            default => 'warning',
        };
    }
}
