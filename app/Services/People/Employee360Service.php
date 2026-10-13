<?php

namespace App\Services\People;

use App\Livewire\AuditLogViewer;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\LeaveRequest;
use App\Models\PerformanceReview;
use App\Models\User;
use App\Services\Approvals\ApprovalQueueService;
use App\Services\Attendance\AttendanceWindowService;
use App\Services\Attendance\PunchTimeline;
use App\Services\Attendance\TodayStatusService;
use App\Services\Leave\EmployeeLeaveOverviewService;
use App\Services\OvertimeService;
use App\Services\Performance\PerformanceStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Everything the Employee 360° page and its report show about one employee,
 * section by section. Nothing is calculated here: each figure is read from
 * the service its own module uses —
 *
 *  - live status: TodayStatusService (AttendanceStatusResolver) + PunchTimeline
 *  - attendance: AttendanceWindowService (My Attendance's window counts)
 *  - leave: EmployeeLeaveOverviewService (LeaveBalanceCalculator)
 *  - overtime: OvertimeService (OvertimeRecord = hours actually paid)
 *  - performance: PerformanceStatusService
 *
 * A section the viewer may not see is never loaded (null).
 */
class Employee360Service
{
    /** Longest window loaded at once. */
    public const MAX_RANGE_DAYS = 366;

    public const LEAVE_TABS = ['overview' => 'Overview', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'history' => 'History'];

    public const ATTENDANCE_STATUSES = ['present' => 'Present', 'late' => 'Late', 'absent' => 'Absent', 'weekly_off' => 'Weekly off', 'leave' => 'Leave', 'holiday' => 'Holiday'];

    /** Open leave statuses (the Pending tab). */
    private const OPEN_LEAVE = ['pending', 'pending_hr', 'more_info_requested'];

    /** Audit trail entries grouped by the section that may show them. */
    private const ACTIVITY_SECTIONS = [
        'LeaveRequest' => 'leave', 'LeaveBalance' => 'leave', 'LeaveLedgerEntry' => 'leave', 'LeaveEncashment' => 'leave',
        'Attendance' => 'attendance', 'AttendanceRegularisation' => 'attendance', 'AttendancePunch' => 'attendance', 'WfhRequest' => 'attendance',
        'OtRequest' => 'overtime', 'OvertimeRecord' => 'overtime',
        'PerformanceReview' => 'performance', 'EmployeeKpi' => 'performance', 'ReviewGoal' => 'performance', 'EmployeeScorecard' => 'performance', 'PipRecord' => 'performance',
        'WarningLetter' => 'warnings',
        'Payslip' => 'payable_overtime', 'SalaryStructure' => 'payable_overtime',
    ];

    public function __construct(
        private Employee360Access $access,
        private AttendanceWindowService $window,
        private TodayStatusService $today,
        private PunchTimeline $timeline,
        private EmployeeLeaveOverviewService $leaveOverview,
        private OvertimeService $overtime,
        private PerformanceStatusService $performance,
        private ApprovalQueueService $queue,
    ) {}

    /**
     * @param  array{attendance_status?: string, leave_status?: string}  $filters
     * @return array<string, mixed>
     */
    public function build(User $viewer, Employee $employee, Carbon $from, Carbon $to, array $filters = []): array
    {
        $employee->loadMissing(['user', 'department', 'jobTitle', 'manager', 'shift', 'office', 'workMode', 'employmentType']);
        $can = $this->access->sections($viewer, $employee);

        $data = [
            'can' => $can,
            'identity' => $this->identity($employee),
            'details' => $this->details($employee, $can),
            'live' => $can['attendance'] ? $this->live($employee) : null,
            'attendance' => $can['attendance'] ? $this->attendance($employee, $from, $to, (string) ($filters['attendance_status'] ?? '')) : null,
            'leave' => $can['leave'] ? $this->leave($viewer, $employee, $from, $to, (string) ($filters['leave_status'] ?? 'overview')) : null,
            'overtime' => $can['overtime'] ? $this->overtime($employee, $from, $to, $can['payable_overtime']) : null,
            'performance' => $can['performance'] ? $this->performanceSection($viewer, $employee) : null,
            'warnings' => $can['warnings'] ? $this->warnings($employee) : null,
            'activity' => $can['activity'] ? $this->activity($employee, $can) : null,
        ];

        $data['kpis'] = $this->kpis($data);

        return $data;
    }

    /** @return array<string, mixed> */
    private function identity(Employee $employee): array
    {
        $user = $employee->user;

        return [
            'name' => $user?->name ?? 'Employee #'.$employee->id,
            'avatar' => $employee->photo ? asset('storage/'.$employee->photo) : $user?->avatarUrl(),
            'initials' => Str::of($user?->name ?? '?')->explode(' ')->map(fn ($p) => Str::substr($p, 0, 1))->take(2)->implode(''),
            'status' => $employee->status?->label() ?? '—',
            'status_color' => $employee->status?->color() ?? 'zinc',
            'designation' => $employee->jobTitle?->name,
            'department' => $employee->department?->name,
            'code' => $employee->employee_id,
            'joining_date' => $employee->joining_date,
            'manager' => $employee->manager?->name,
            'shift' => $employee->shift?->name,
            'office' => $employee->office?->name,
            'work_mode' => $employee->workMode?->name,
            'email' => $user?->email,
        ];
    }

    /** @return array<string, mixed> */
    private function details(Employee $employee, array $can): array
    {
        $managerEmployee = $employee->manager_id
            ? Employee::with('user', 'jobTitle')->where('user_id', $employee->manager_id)->first()
            : null;

        return [
            'email' => $can['contact'] ? $employee->user?->email : null,
            'phone' => $can['contact'] ? $employee->phone : null,
            'emergency_contact' => $can['contact'] ? $employee->emergency_contact : null,
            'office' => $employee->office?->name,
            'work_mode' => $employee->workMode?->name,
            'shift' => $employee->shift?->name,
            'department' => $employee->department?->name,
            'designation' => $employee->jobTitle?->name,
            'joining_date' => $employee->joining_date,
            'employment_type' => $employee->employmentType?->name,
            'manager' => $employee->manager ? [
                'name' => $employee->manager->name,
                'title' => $managerEmployee?->jobTitle?->name,
                'employee_id' => $managerEmployee?->id,
            ] : null,
            'reports' => Employee::where('manager_id', $employee->user_id)->count(),
        ];
    }

    /**
     * Right now: one bucket from TodayStatusService and, while a session is
     * open, the time since the latest IN from PunchTimeline.
     *
     * @return array<string, mixed>
     */
    private function live(Employee $employee): array
    {
        $row = $this->today->summary([$employee->id])['rows'][$employee->id] ?? null;
        $state = $row['state'] ?? 'not_in';
        $bucket = $row['bucket'] ?? 'no_checkin';

        [$key, $label, $tone] = match (true) {
            $state === 'working' => ['working', 'At work', 'green'],
            $state === 'on_break' => ['on_break', 'On break', 'amber'],
            $state === 'completed' => ['checked_out', 'Checked out', 'sky'],
            $state === 'missing_checkout' => ['missing_checkout', 'Missing checkout', 'red'],
            $bucket === 'on_leave' => ['on_leave', 'On leave', 'violet'],
            $bucket === 'weekly_off' => ['weekly_off', 'Weekly off', 'zinc'],
            $bucket === 'holiday' => ['holiday', 'Holiday', 'teal'],
            $bucket === 'not_employed' => ['not_employed', 'Not employed today', 'zinc'],
            default => ['not_in', 'Not in', 'zinc'],
        };

        $session = null;
        if ($row && ($row['live'] ?? false)) {
            $day = Carbon::today();
            $punches = AttendancePunch::where('employee_id', $employee->id)->whereDate('punch_date', $day)->orderBy('punched_at')->get();
            $summary = AttendanceDailySummary::where('employee_id', $employee->id)->whereDate('date', $day)->first();
            $journey = $this->timeline->process($punches, $day, $summary);
            $session = $journey['live'] ? [
                'start_ms' => $journey['live_start_ms'],
                'start_label' => $journey['live_start_label'],
                'elapsed_minutes' => (int) $journey['live_elapsed_minutes'],
            ] : null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'tone' => $tone,
            'first_in' => $row['first_in'] ?? null,
            'last_out' => $row['last_out'] ?? null,
            'worked_minutes' => (int) ($row['worked_minutes'] ?? 0),
            'session' => $session,
        ];
    }

    /** @return array<string, mixed> */
    private function attendance(Employee $employee, Carbon $from, Carbon $to, string $statusFilter): array
    {
        // Counted up to today, exactly like My Attendance (the future holds no attendance).
        $until = $to->copy()->min(Carbon::today());
        $until = $until->lt($from) ? $from->copy() : $until;

        $summary = $this->window->summary($employee, $from, $until);
        $days = $this->window->days($employee, $from, $until);
        $counts = collect($days)->countBy('status');

        $punches = AttendancePunch::where('employee_id', $employee->id)
            ->whereBetween('punch_date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('punched_at')
            ->limit(8)
            ->get()
            ->map(fn (AttendancePunch $p) => [
                'at' => $p->punched_at,
                'direction' => $this->timeline->directionOf($p),
                'source' => $p->methodEnum()?->label() ?? Str::headline((string) ($p->source ?: $p->method ?: 'device')),
                'status' => match (true) {
                    $p->source === 'system_auto' => 'Auto checkout',
                    $this->timeline->directionCorrected($p) => 'Corrected',
                    default => 'Recorded',
                },
            ]);

        $visibleDays = collect($days)
            ->filter(fn ($d) => $d['status'] !== 'upcoming' && $d['status'] !== 'not_employed')
            ->when($statusFilter !== '', fn ($c) => $c->where('status', $statusFilter))
            ->sortKeysDesc()
            ->values();

        return [
            'summary' => $summary,
            'counts' => [
                'present' => $summary['present'] - $summary['late'],
                'late' => $summary['late'],
                'absent' => $summary['absent'],
                'weekly_off' => (int) ($counts['weekly_off'] ?? 0),
                'leave' => (int) ($counts['leave'] ?? 0),
                'holiday' => (int) ($counts['holiday'] ?? 0),
            ],
            'rate' => $summary['scheduled'] > 0 ? (int) round($summary['present'] / $summary['scheduled'] * 100) : null,
            'missing_checkouts' => collect($days)->where('missing_out', true)->count(),
            'days' => $days,
            'day_log' => $visibleDays->take(14)->all(),
            'day_log_total' => $visibleDays->count(),
            'punches' => $punches->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function leave(User $viewer, Employee $employee, Carbon $from, Carbon $to, string $tab): array
    {
        $tab = array_key_exists($tab, self::LEAVE_TABS) ? $tab : 'overview';
        $overview = $this->leaveOverview->for($employee);

        $types = collect([$overview['csl'], $overview['comp_off']])
            ->filter()
            ->map(fn (array $card) => ['type' => $card['type'], 'summary' => $card['summary']])
            ->merge($overview['others'])
            ->map(fn (array $card) => [
                'name' => $card['type']->name,
                'color' => $card['type']->color,
                'used' => (float) $card['summary']['used'],
                'available' => (float) $card['summary']['available_to_request'],
                'pending' => (float) $card['summary']['pending'],
                'credits' => (float) $card['summary']['credits'],
            ])
            ->values()
            ->all();

        $overlapping = fn ($q) => $q->whereDate('start_date', '<=', $to->toDateString())->whereDate('end_date', '>=', $from->toDateString());

        $requests = LeaveRequest::with(['leaveType', 'reviewer', 'hrReviewer'])
            ->where('employee_id', $employee->id)
            ->when($tab === 'pending', fn ($q) => $q->whereIn('status', self::OPEN_LEAVE))
            ->when($tab === 'approved', fn ($q) => $overlapping($q->where('status', 'approved')))
            ->when($tab === 'rejected', fn ($q) => $overlapping($q->where('status', 'rejected')))
            ->when($tab === 'overview', $overlapping)
            ->orderByDesc('start_date')
            ->limit($tab === 'history' ? 15 : 8)
            ->get()
            ->map(fn (LeaveRequest $r) => [
                'model' => $r,
                'id' => $r->id,
                'start' => $r->start_date,
                'end' => $r->end_date,
                'type' => $r->leaveType?->name ?? 'Leave',
                'days' => (float) $r->days,
                'status' => $r->status,
                'approver' => $r->hrReviewer?->name ?? $r->reviewer?->name ?? $this->queue->approverFor($employee),
                'can_decide' => $this->queue->canDecide($viewer, $r),
                'inline' => $r->status === 'pending' && $this->queue->canDecide($viewer, $r),
            ]);

        $inRange = LeaveRequest::where('employee_id', $employee->id)->where($overlapping);

        return [
            'tab' => $tab,
            'year' => $overview['year'],
            'available' => (float) $overview['available_to_request'],
            'approved_balance' => (float) $overview['approved_balance'],
            'taken_this_year' => (float) $overview['taken_this_year'],
            'pending_requests' => (int) $overview['pending_requests'],
            'types' => $types,
            'requests' => $requests->all(),
            'range' => [
                'approved_days' => round((float) (clone $inRange)->where('status', 'approved')->sum('days'), 2),
                'approved' => (clone $inRange)->where('status', 'approved')->count(),
                'rejected' => (clone $inRange)->where('status', 'rejected')->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function overtime(Employee $employee, Carbon $from, Carbon $to, bool $payable): array
    {
        $summary = $this->overtime->rangeSummary($employee, $from, $to);

        return [
            'pending' => $summary['pending'],
            'approved' => $summary['approved'],
            'rejected' => $summary['rejected'],
            'requested_hours_approved' => $summary['requested_hours_approved'],
            'worked_hours' => $summary['ot_hours'],
            'payable' => $payable ? ['hours' => $summary['unpaid_hours'], 'amount' => $summary['unpaid_amount'], 'paid_amount' => round($summary['ot_amount'] - $summary['unpaid_amount'], 2)] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function performanceSection(User $viewer, Employee $employee): array
    {
        $cycle = $this->performance->currentCycle();
        $summary = $this->performance->summary([$employee->id], $cycle);
        $state = $summary['by_employee'][$employee->id] ?? PerformanceStatusService::NOT_STARTED;

        $reviews = $cycle
            ? PerformanceReview::with(['goals', 'participants', 'performanceCycle'])->where('performance_cycle_id', $cycle->id)->where('employee_id', $employee->id)->get()
            : collect();
        $review = $this->performance->primaryReview($reviews);
        $status = $review ? $this->performance->statusFor($review) : null;

        $goals = $reviews->flatMap->goals;
        $kpis = $cycle ? EmployeeKpi::where('employee_id', $employee->id)->where('performance_cycle_id', $cycle->id)->get() : collect();

        $selfState = match ($status['state'] ?? PerformanceStatusService::NOT_STARTED) {
            PerformanceStatusService::NOT_STARTED => 'Not started',
            PerformanceStatusService::SELF_PENDING => 'Pending',
            default => 'Submitted',
        };
        $managerState = match ($status['state'] ?? PerformanceStatusService::NOT_STARTED) {
            PerformanceStatusService::COMPLETED => 'Completed',
            PerformanceStatusService::MANAGER_PENDING => 'Pending',
            default => 'Waiting for self review',
        };

        return [
            'cycle' => $cycle,
            'state' => $state,
            'label' => PerformanceStatusService::LABELS[$state],
            'due' => $status['due'] ?? null,
            'score' => $state === PerformanceStatusService::COMPLETED || ($review?->status === 'locked') ? $review?->final_score : null,
            'grade' => $review?->grade,
            'review_id' => $review?->id,
            'url' => $this->performanceUrl($viewer, $employee, $review),
            'self' => $selfState,
            'manager' => $managerState,
            'goals' => ['total' => $goals->count(), 'completed' => $goals->where('is_completed', true)->count()],
            'kpis' => ['total' => $kpis->count(), 'progress' => $kpis->isEmpty() ? null : (int) round((float) $kpis->avg('progress_percent'))],
        ];
    }

    /**
     * Where "View performance" goes: the scorecard when it would open for
     * this viewer (locked, and theirs, reviewed by them or in reach), else
     * the performance list they can open, else nowhere.
     */
    private function performanceUrl(User $viewer, Employee $employee, ?PerformanceReview $review): ?string
    {
        $ownId = $viewer->employee?->id;
        $opensScorecard = $review !== null && $review->status === 'locked' && (
            ($ownId !== null && ((int) $review->employee_id === (int) $ownId || (int) $review->reviewer_id === (int) $ownId))
            || (($viewer->canManageEmployees() || $viewer->canReviewPerformance()) && $viewer->coversEmployee($employee))
        );

        return match (true) {
            $opensScorecard => route('performance.scorecard', $review->id),
            $viewer->canManageEmployees() => route('performance.employees'),
            $viewer->canReviewPerformance() => route('performance.team'),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function warnings(Employee $employee): array
    {
        $letters = $employee->warningLetters()->with('issuedBy')->orderByDesc('issue_date')->limit(5)->get();

        return [
            'active' => $employee->activeWarnings()->count(),
            'total' => $employee->warningLetters()->count(),
            'latest' => $letters->first(),
            'timeline' => $letters->all(),
        ];
    }

    /**
     * Recent audited actions about this employee, kept to the sections the
     * viewer may see (everything with the Activity Log permission).
     *
     * @return array{entries: array<int, array<string, mixed>>, full: bool}
     */
    private function activity(Employee $employee, array $can): array
    {
        $logs = AuditLog::with('user')
            ->where(fn ($q) => $q->where('subject_employee_id', $employee->id)
                ->orWhere(fn ($q) => $q->where('auditable_type', Employee::class)->where('auditable_id', $employee->id)))
            ->orderByDesc('created_at')
            ->limit(60)
            ->get();

        $entries = $logs
            ->filter(function (AuditLog $log) use ($can) {
                if ($can['full_activity']) {
                    return true;
                }
                $section = self::ACTIVITY_SECTIONS[class_basename((string) $log->auditable_type)] ?? 'contact';

                return (bool) ($can[$section] ?? false);
            })
            ->take(8)
            ->map(fn (AuditLog $log) => [
                'at' => $log->created_at,
                'action' => AuditLogViewer::eventLabel($log),
                'details' => $this->activityDetails($log),
                'by' => $log->user?->name ?? 'System',
            ])
            ->values()
            ->all();

        return ['entries' => $entries, 'full' => $can['full_activity']];
    }

    private function activityDetails(AuditLog $log): string
    {
        $subject = Str::headline(class_basename((string) $log->auditable_type));
        $keys = collect(AuditLogViewer::diffKeys($log))->take(3)->map(fn ($k) => Str::headline((string) $k))->implode(', ');

        return trim($subject.($keys !== '' ? ' — '.$keys : '').($log->reason ? ' · '.Str::limit((string) $log->reason, 60) : ''));
    }

    /**
     * The headline cards, from the sections already loaded (null = hidden).
     *
     * @return array<int, array{key: string, label: string, value: string, hint: ?string, tone: string}>
     */
    private function kpis(array $data): array
    {
        $cards = [];

        if ($a = $data['attendance']) {
            $cards[] = ['key' => 'present', 'label' => 'Present days', 'value' => (string) $a['summary']['present'], 'hint' => $a['rate'] !== null ? $a['rate'].'% attendance rate' : null, 'tone' => 'green'];
            $cards[] = ['key' => 'late', 'label' => 'Late days', 'value' => (string) $a['summary']['late'], 'hint' => $a['missing_checkouts'] > 0 ? $a['missing_checkouts'].' missing checkout'.($a['missing_checkouts'] === 1 ? '' : 's') : 'No missing checkouts', 'tone' => 'amber'];
        }
        if ($l = $data['leave']) {
            $cards[] = ['key' => 'leave', 'label' => 'Leave used / balance', 'value' => self::number($l['taken_this_year']).' / '.self::number($l['available']), 'hint' => $l['pending_requests'] > 0 ? $l['pending_requests'].' pending' : 'Leave year '.$l['year']->label, 'tone' => 'violet'];
        }
        if ($p = $data['performance']) {
            $cards[] = ['key' => 'performance', 'label' => 'Performance score', 'value' => $p['score'] !== null ? self::number((float) $p['score']) : '—', 'hint' => $p['label'], 'tone' => 'sky'];
        }
        if ($w = $data['warnings']) {
            $cards[] = ['key' => 'warnings', 'label' => 'Active warnings', 'value' => (string) $w['active'], 'hint' => $w['total'].' on record', 'tone' => $w['active'] > 0 ? 'red' : 'zinc'];
        }
        if ($o = $data['overtime']) {
            $cards[] = ['key' => 'overtime', 'label' => 'Overtime hours', 'value' => self::number($o['worked_hours']), 'hint' => $o['pending'] > 0 ? $o['pending'].' request'.($o['pending'] === 1 ? '' : 's').' pending' : 'Worked, approved', 'tone' => 'orange'];
        }

        return $cards;
    }

    /**
     * The window to show: valid Y-m-d dates (the current month by default),
     * in order, at most MAX_RANGE_DAYS apart.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function range(?string $from, ?string $to): array
    {
        // A date that does not survive the round trip (2026-02-31) is invalid, not "March 3".
        $parse = function (?string $value): ?Carbon {
            $date = filled($value) ? rescue(fn () => Carbon::createFromFormat('!Y-m-d', (string) $value), null, false) : null;

            return $date && $date->format('Y-m-d') === $value ? $date : null;
        };

        $start = $parse($from);
        $end = $parse($to) ?? ($start ? $start->copy()->endOfMonth()->startOfDay() : Carbon::now()->endOfMonth()->startOfDay());
        $start ??= Carbon::now()->startOfMonth();

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diffInDays($end) > self::MAX_RANGE_DAYS) {
            $start = $end->copy()->subDays(self::MAX_RANGE_DAYS);
        }

        return [$start, $end];
    }

    public static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
