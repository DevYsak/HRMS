<?php

namespace App\Livewire\Attendance;

use App\Enums\AttendanceMode;
use App\Enums\PunchMethod;
use App\Livewire\Concerns\ManagesRegularisations;
use App\Models\Attendance;
use App\Models\AttendanceDailyScore;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\AttendanceSetting;
use App\Models\BiometricDevice;
use App\Models\BreakLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\ShiftSetting;
use App\Models\Task;
use App\Models\WfhReport;
use App\Notifications\AttendanceRegularisationNotification;
use App\Notifications\RegularisationReviewedNotification;
use App\Services\AiAssistant;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\AttendanceCoach;
use App\Services\Attendance\AttendanceScoreEngine;
use App\Services\Attendance\HolidayResolver;
use App\Services\Attendance\PunchClassifier;
use App\Services\Attendance\PunchTimeline as PunchTimelineEngine;
use App\Services\Attendance\ShiftProgress;
use App\Services\Attendance\ShiftResolver;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\AttendanceService;
use App\Services\Leave\LeaveYearResolver;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationRecipients;
use App\Services\WfhService;
use App\Support\UserAgent;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class AttendanceTracker extends Component
{
    use ManagesRegularisations;
    use WithFileUploads;

    public $todayAttendance;

    /** Active BreakLog record (null when not on break). */
    public $activeBreak = null;

    /** Work mode selected at clock-in: office | wfh */
    public string $workMode = 'office';

    public $attendanceSettings;

    public $shift;

    public $stats = [
        'present' => 0,
        'late' => 0,
        'hours' => '0h 0m',
        'leaves' => 0,
        'absent' => 0,
    ];

    /** Phase 6 attendance analytics (compliance, score, work pattern, breaks, late trend). */
    public array $analytics = [
        'shift_compliance' => 100,
        'attendance_score' => 100,
        'office_days' => 0,
        'wfh_days' => 0,
        'avg_break' => 0,
        'excess_breaks' => 0,
        'late_trend' => [],
        'mode_breakdown' => [],
    ];

    /** Phase 6 AI insights (only when OPENAI_API_KEY is configured). */
    public bool $aiEnabled = false;

    public bool $aiLoading = false;

    public ?string $aiInsight = null;

    public $shiftLabel;

    public $calendarMonth;

    public $calendarDays = [];

    public $monthHolidays = [];

    public $history = [];

    public $lastLeave = null;

    public string $statsPeriod = 'this_month';

    /** Comparison window for KPI deltas: prev_period | last_month | last_year. */
    public string $compareMode = 'prev_period';

    /** Real KPI deltas vs the comparison window (null delta = hide the chip). */
    public array $comparison = [];

    /** Daily working-hours series for the analytics charts (selected period). */
    public array $chartDaily = [];

    /** Current-week (Sun–Sat) day-by-day summary — independent of the calendar/period filters. */
    public array $weekSummary = [];

    /**
     * Session-based punch journey for the enterprise timeline: neutral IN/OUT
     * nodes, auto-paired work sessions, live/missing/duplicate flags. Never
     * guesses the reason for an OUT.
     *
     * @var array<string, mixed>
     */
    public array $punchJourney = [];

    /**
     * Today's journey with break semantics applied — Clocked in, Lunch break,
     * Returned from break, Clocked out.
     *
     * The neutral timeline deliberately emits only IN and OUT; this is the
     * classified view the employee reads. Both come from the same deduplicated
     * punches, so the journey and the session totals can never disagree.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $attendanceJourney = [];

    /**
     * Progress through today's shift, as ShiftProgress::toArray().
     *
     * @var array<string, mixed>
     */
    public array $shiftProgress = [];

    /**
     * Today's canonical figures from AttendanceCalculator (worked = final out −
     * first in, breaks informational, approved-only OT). Every "today" widget —
     * Worked Today, Session Summary, Shift Progress, the breakdown — reads this.
     *
     * @var array<string, mixed>
     */
    public array $todayCalc = [];

    /** Smart Attendance Alerts — missing check-in/out/break, past & present. */
    public array $attendanceAlerts = [];

    /** Auto-generated plain-language attendance insights for the period. */
    public array $insights = [];

    /** AI Attendance Insights — stat cards, trends, prediction & suggestions. */
    public array $insightStats = [];

    /** Today's tasks for the logged-in employee. */
    public $tasks = [];

    /** New-task form fields. */
    public string $newTask = '';

    public string $newTaskPriority = 'medium';

    /** Tasks completed within the selected stats period. */
    public int $tasksCompletedPeriod = 0;

    /** Total available leave balance (current year, all types). */
    public float $leaveBalance = 0;

    /** On-time streak + peer benchmarking for the redesigned right rail. */
    public int $onTimeStreak = 0;

    public int $bestStreak = 0;

    public int $myOnTimeRate = 100;

    public int $teamOnTimeRate = 0;

    public int $companyOnTimeRate = 0;

    public ?string $teamName = null;

    /** Today's biometric daily summary (raw punch count, device, sync). */
    public $todaySummary = null;

    /** The biometric device (name, connection, last sync). */
    public $biometricDevice = null;

    /** Recent engine sync history (date, synced time, punch count). */
    public array $syncHistory = [];

    /** Today's WFH daily report (null until submitted). */
    public $wfhReport = null;

    /** WFH report form fields. */
    public array $wfhForm = [
        'work_summary' => '',
        'achievements' => '',
        'blockers' => '',
        'tomorrow_plan' => '',
    ];

    /** Attendance-log filter by work mode ('' = all). */
    public string $logMode = '';

    /** Analytics filter by work mode ('' = all) — narrows every chart/insight. */
    public string $analyticsMode = '';

    /** Custom analytics date range (activates statsPeriod = 'custom'). */
    public ?string $rangeFrom = null;

    public ?string $rangeTo = null;

    /** Full punch detail for the detail modal (null when closed). */
    public ?array $detail = null;

    /** Rule 11 — month-to-date attendance score + previous month comparison. */
    public ?float $monthlyScore = null;

    public ?float $prevMonthlyScore = null;

    /** [rank, poolSize] within the department / company (null = no scores yet). */
    public ?array $deptRank = null;

    public ?array $companyRank = null;

    /** Attendance Decision payload for the "Why?" popup (null when closed). */
    public ?array $decision = null;

    /** AI Attendance Coach analytics payload (dynamic, from the engine). */
    public array $coach = [];

    // Regularisation form fields

    /** Regularisation type: 'punch' (fix in/out) or 'half_day' (mark half day). */
    public string $regType = 'punch';

    /** Which half the half-day request covers: 'first' or 'second'. */
    public string $regHalfDayPeriod = 'first';

    public bool $regFixIn = false;

    public bool $regFixOut = true;

    public string $regDate = '';

    public string $regCheckIn = '';

    public string $regCheckOut = '';

    /** Punch method the corrected check-in / check-out was actually made with. */
    public string $regCheckInMethod = 'id_card';

    public string $regCheckOutMethod = 'id_card';

    public string $regReason = '';

    /** Optional supporting document (gate pass, medical slip, screenshot…). */
    public $regAttachment = null;

    public function mount()
    {
        $this->calendarMonth = Carbon::now()->startOfMonth();
        $this->attendanceSettings = AttendanceSetting::first();
        $this->aiEnabled = Auth::user() ? app(AiAssistant::class)->enabledForUser(Auth::user()) : false;
        $this->loadData();
    }

    public function loadData()
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            // Staff without an employee record (e.g. an HR admin account) still
            // land on this page — give the view the engine's canonical empty
            // shape so every journey key exists instead of blowing up on
            // $punchJourney['live'].
            $this->punchJourney = app(PunchTimelineEngine::class)->emptyResult();

            // Same reason: no employee record means no shift to measure, so
            // give the card its explicit not-measurable shape rather than an
            // empty array the view would dereference.
            $this->shiftProgress = ShiftProgress::unassigned()->toArray();

            return;
        }

        // Assigned shift, else the shift HR nominated as the company default.
        // Never ShiftSetting::first(): that showed an unassigned UK Sales
        // employee the 10:30 IT window and judged their arrivals against it.
        $this->shift = $employee->shift ?? ShiftResolver::companyDefault();
        $this->shiftLabel = $this->buildShiftLabel();

        // 1. Setup boundaries  (week starts Sunday to match S M T W T F S header)
        $start = $this->calendarMonth->copy()->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $gridStart = $start->copy()->startOfWeek(Carbon::SUNDAY);
        $gridEnd = $end->copy()->endOfWeek(Carbon::SATURDAY);

        // 2. Fetch all required data in consolidated queries
        $allAttendances = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->with('regularisation')
            ->get();

        $leaves = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $gridEnd->toDateString())
            ->where('end_date', '>=', $gridStart->toDateString())
            ->get();

        // This employee's calendar and scope, not "any holiday for anyone".
        $holidayMap = app(HolidayResolver::class)->keyedForEmployee($employee, $gridStart, $gridEnd);

        // 3. Process data in memory
        $attendanceMap = $allAttendances->keyBy(fn ($a) => $a->date->toDateString());

        $leaveMap = [];
        foreach ($leaves as $l) {
            $period = CarbonPeriod::create($l->start_date, $l->end_date);
            foreach ($period as $d) {
                $leaveMap[$d->toDateString()] = $l;
            }
        }

        // 4. Assign Today's Attendance + active break from break_logs
        $this->todayAttendance = $attendanceMap->get(Carbon::today()->toDateString());

        $this->activeBreak = $this->todayAttendance
            ? BreakLog::where('attendance_id', $this->todayAttendance->id)
                ->whereNull('break_end')
                ->first()
            : null;

        // 5. Calculate Stats — delegated to computeStats()
        $this->computeStats();

        // 6. Build Calendar Days
        $gridPeriod = CarbonPeriod::create($gridStart, $gridEnd);
        $this->calendarDays = [];

        foreach ($gridPeriod as $d) {
            $dateKey = $d->toDateString();
            $status = 'absent';

            if (isset($attendanceMap[$dateKey])) {
                $att = $attendanceMap[$dateKey];
                $status = app(WorkingDayResolver::class)->isWeeklyOff($d) ? 'weekly_off_worked' : (($att->status === 'late' || $att->is_late) ? 'late' : 'present');
                // Same order as WorkingDayResolver: a holiday or weekly off inside
                // a leave span is not a leave day, and today is not absent yet.
            } elseif (isset($holidayMap[$dateKey])) {
                $status = 'holiday';
            } elseif (app(WorkingDayResolver::class)->isWeeklyOff($d)) {
                $status = 'weekly_off';
            } elseif (isset($leaveMap[$dateKey])) {
                $status = 'leave';
            } elseif ($d->copy()->startOfDay()->gte(Carbon::today())) {
                $status = 'future';
            }

            $this->calendarDays[] = [
                'date' => $dateKey,
                'day' => $d->day,
                'in_month' => $d->month === $start->month,
                'status' => $status,
                'mode' => isset($attendanceMap[$dateKey]) ? ($attendanceMap[$dateKey]->work_mode ?? 'office') : null,
                'is_today' => $d->isToday(),
                'is_holiday' => isset($holidayMap[$dateKey]),
                'regularized' => isset($attendanceMap[$dateKey]) && $attendanceMap[$dateKey]->is_regularized,
            ];
        }

        // 7. Load UI specific lists
        $this->monthHolidays = $holidayMap->filter(fn ($h) => Carbon::parse($h->date)->month === $start->month &&
            Carbon::parse($h->date)->year === $start->year
        )->values();

        $this->history = $allAttendances->filter(fn ($a) => $a->date->month === $start->month && $a->date->year === $start->year
        )->sortByDesc('date')->values();

        // 8. Last leave taken in the current calendar month
        $this->lastLeave = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhereBetween('end_date', [$start->toDateString(), $end->toDateString()]);
            })
            ->orderByDesc('start_date')
            ->first();

        // 9. This-week summary + today's punch/break timeline
        $this->weekSummary = $this->buildWeekSummary($employee);
        $this->punchJourney = $this->buildPunchJourney($employee);
        $this->attendanceJourney = $this->buildAttendanceJourney($employee);
        $this->todayCalc = app(AttendanceCalculator::class)
            ->forDay($employee, Carbon::today(), $this->todayAttendance)
            ->toArray();
        // LIVE / missing checkout come from the shared calculation, not from
        // "the last raw punch is an IN": past the cutoff (shift end + 1h) the
        // journey stops showing as live.
        $this->punchJourney['live'] = (bool) ($this->punchJourney['live'] ?? false) && (bool) $this->todayCalc['live'];
        $this->punchJourney['missing_out'] = (bool) $this->todayCalc['missing_checkout'] || (bool) ($this->punchJourney['missing_out'] ?? false);
        $this->shiftProgress = $this->buildShiftProgress($employee)->toArray();
        $this->loadStreakAndBenchmark($employee);

        // 10. Biometric daily summary + device (needed by the alerts below)
        $this->todaySummary = AttendanceDailySummary::where('employee_id', $employee->id)
            ->whereDate('date', Carbon::today()->toDateString())
            ->first();
        $this->biometricDevice = BiometricDevice::query()
            ->orderByDesc('last_synced_at')
            ->first();

        // Recent engine sync history for the Biometric Status widget.
        $this->syncHistory = AttendanceDailySummary::where('employee_id', $employee->id)
            ->whereNotNull('synced_at')
            ->orderByDesc('date')
            ->limit(5)
            ->get(['date', 'synced_at', 'raw_punch_count', 'device_serial'])
            ->map(fn ($s) => [
                'date' => $s->date->format('d M'),
                'synced' => $s->synced_at->format('h:i A'),
                'punches' => (int) $s->raw_punch_count,
                // The engine stamps the serial of the reader that produced the
                // day's punches; the biometric_devices row may hold none.
                'serial' => $s->device_serial,
            ])->all();

        $this->attendanceAlerts = $this->buildAttendanceAlerts();

        // 11b. Day-grouped punch timeline for the log section
        $this->buildLogTimeline($employee);

        // 12. Total available leave balance (current year)
        $this->leaveBalance = LeaveBalance::where('employee_id', $employee->id)
            ->where('year', app(LeaveYearResolver::class)->legacyYearFor())
            ->get()
            ->sum(fn ($b) => $b->available() + (float) ($b->comp_off_credits ?? 0));

        // 13. Today's tasks
        $this->loadTasks($employee);

        // 11. Today's WFH report
        $this->wfhReport = WfhReport::where('employee_id', $employee->id)
            ->whereDate('date', Carbon::today()->toDateString())
            ->first();
        if ($this->wfhReport) {
            $this->wfhForm = [
                'work_summary' => $this->wfhReport->work_summary,
                'achievements' => $this->wfhReport->achievements ?? '',
                'blockers' => $this->wfhReport->blockers ?? '',
                'tomorrow_plan' => $this->wfhReport->tomorrow_plan ?? '',
            ];
        }
    }

    public function saveWfhReport(): void
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return;
        }

        $this->validate([
            'wfhForm.work_summary' => ['required', 'string', 'min:5', 'max:5000'],
            'wfhForm.achievements' => ['nullable', 'string', 'max:5000'],
            'wfhForm.blockers' => ['nullable', 'string', 'max:5000'],
            'wfhForm.tomorrow_plan' => ['nullable', 'string', 'max:5000'],
        ]);

        WfhReport::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => Carbon::today()->toDateString()],
            [
                'work_summary' => $this->wfhForm['work_summary'],
                'achievements' => $this->wfhForm['achievements'] ?: null,
                'blockers' => $this->wfhForm['blockers'] ?: null,
                'tomorrow_plan' => $this->wfhForm['tomorrow_plan'] ?: null,
            ],
        );

        $this->loadData();
        \Flux::toast('WFH report submitted.', variant: 'success');
    }

    protected function loadTasks($employee): void
    {
        $this->tasks = Task::where('employee_id', $employee->id)
            ->whereDate('date', Carbon::today()->toDateString())
            ->orderByRaw('completed_at is null desc')
            ->orderByDesc('id')
            ->get();
    }

    public function addTask(): void
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return;
        }

        $this->validate([
            'newTask' => ['required', 'string', 'max:255'],
            'newTaskPriority' => ['required', 'in:low,medium,high'],
        ]);

        Task::create([
            'employee_id' => $employee->id,
            'title' => trim($this->newTask),
            'priority' => $this->newTaskPriority,
            'date' => Carbon::today()->toDateString(),
        ]);

        $this->newTask = '';
        $this->newTaskPriority = 'medium';
        $this->loadData();
    }

    public function toggleTask(int $taskId): void
    {
        $employee = Auth::user()->employee;
        $task = Task::where('employee_id', $employee?->id)->find($taskId);
        if (! $task) {
            return;
        }

        $task->update(['completed_at' => $task->completed_at ? null : Carbon::now()]);
        $this->loadData();
    }

    public function deleteTask(int $taskId): void
    {
        $employee = Auth::user()->employee;
        Task::where('employee_id', $employee?->id)->where('id', $taskId)->delete();
        $this->loadData();
    }

    /**
     * Day-by-day summary for the current week (Sun–Sat), independent of the
     * calendar month or the stats period filter.
     *
     * @return array<int, array{date:string, label:string, day:int, status:string, mode:?string, hours:float, is_today:bool, is_future:bool}>
     */
    /**
     * On-time streak (consecutive on-time working days back from the latest
     * one) plus this-month on-time rate for the employee, their department and
     * the company — powers the redesigned right rail (streak + benchmark).
     */
    protected function loadStreakAndBenchmark($employee): void
    {
        $recent = Attendance::where('employee_id', $employee->id)
            ->where('date', '>=', now()->subDays(150)->toDateString())
            ->get(['date', 'is_late', 'check_in'])
            ->keyBy(fn ($a) => Carbon::parse($a->date)->toDateString());

        // Best run of on-time days across the window.
        $best = 0;
        $run = 0;
        foreach ($recent->sortKeys() as $a) {
            if ($a->check_in && ! $a->is_late) {
                $run++;
                $best = max($best, $run);
            } else {
                $run = 0;
            }
        }

        // Current streak: walk working days backward until a late/absent day.
        $streak = 0;
        $cursor = Carbon::today();
        if (! isset($recent[$cursor->toDateString()])) {
            $cursor->subDay(); // today not punched yet — start from yesterday
        }
        for ($i = 0; $i < 150; $i++) {
            if (app(WorkingDayResolver::class)->isWeeklyOff($cursor)) {
                $cursor->subDay();

                continue;
            }
            $a = $recent[$cursor->toDateString()] ?? null;
            if ($a && $a->check_in && ! $a->is_late) {
                $streak++;
            } elseif ($cursor->lt(Carbon::today())) {
                break; // a past working day that was late, incomplete, or absent
            }
            $cursor->subDay();
        }

        $this->onTimeStreak = $streak;
        $this->bestStreak = max($best, $streak);

        // On-time rate = on-time / present, this month.
        $monthStart = now()->startOfMonth()->toDateString();
        $rate = function (array $ids) use ($monthStart): int {
            if ($ids === []) {
                return 0;
            }
            $base = Attendance::whereIn('employee_id', $ids)->where('date', '>=', $monthStart)->whereNotNull('check_in');
            $present = (clone $base)->count();
            if ($present === 0) {
                return 0;
            }
            $late = (clone $base)->where('is_late', true)->count();

            return (int) round(($present - $late) / $present * 100);
        };

        $this->myOnTimeRate = $rate([$employee->id]);
        $teamIds = $employee->department_id
            ? Employee::where('department_id', $employee->department_id)->where('status', 'active')->pluck('id')->all()
            : [$employee->id];
        $this->teamOnTimeRate = $rate($teamIds);
        $this->companyOnTimeRate = $rate(Employee::where('status', 'active')->pluck('id')->all());
        $this->teamName = $employee->department?->name;
    }

    protected function buildWeekSummary($employee): array
    {
        $weekStart = Carbon::today()->startOfWeek(Carbon::SUNDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SATURDAY);

        $attendances = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get()->keyBy(fn ($a) => $a->date->toDateString());

        $leaves = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $weekEnd->toDateString())
            ->where('end_date', '>=', $weekStart->toDateString())
            ->get();
        $leaveDays = [];
        foreach ($leaves as $l) {
            foreach (CarbonPeriod::create($l->start_date, $l->end_date) as $d) {
                $leaveDays[$d->toDateString()] = true;
            }
        }

        $holidayDays = app(HolidayResolver::class)->keyedForEmployee($employee, $weekStart, $weekEnd);

        $summary = [];
        foreach (CarbonPeriod::create($weekStart, $weekEnd) as $d) {
            $key = $d->toDateString();
            $att = $attendances->get($key);
            $hours = 0.0;
            $status = 'absent';
            $mode = null;

            if ($att) {
                $mode = $att->work_mode ?? 'office';
                $status = app(WorkingDayResolver::class)->isWeeklyOff($d) ? 'weekly_off_worked' : (($att->status === 'late' || $att->is_late) ? 'late' : 'present');
                if ($att->check_in && $att->check_out) {
                    $mins = $this->rowWorkedMinutes($att);
                    $hours = round(max(0, $mins) / 60, 1);
                }
            } elseif ($holidayDays->has($key)) {
                $status = 'holiday';
            } elseif (app(WorkingDayResolver::class)->isWeeklyOff($d)) {
                $status = 'weekly_off';
            } elseif (isset($leaveDays[$key])) {
                $status = 'leave';
            } elseif ($d->copy()->startOfDay()->gte(Carbon::today())) {
                $status = 'future';
            }

            $summary[] = [
                'date' => $key,
                'label' => $d->format('D'),
                'day' => $d->day,
                'status' => $status,
                'mode' => $mode,
                'hours' => $hours,
                'is_today' => $d->isToday(),
                'is_future' => $d->isFuture(),
            ];
        }

        return $summary;
    }

    /**
     * Today's processed punch journey — delegated entirely to the shared
     * PunchTimeline engine (the single source of truth for attendance
     * processing). Duplicates, device conflicts, session pairing, totals and
     * missing-punch flags all come from that one place.
     */
    /**
     * Today's punches as a classified journey.
     *
     * Runs the last two stages of the pipeline: the timeline normalises the
     * raw stream into deduplicated IN/OUT events, then the classifier names
     * the gaps between them. Reusing the timeline's output rather than
     * re-reading the punches is what keeps this consistent with the session
     * totals shown beside it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildAttendanceJourney($employee): array
    {
        $raw = AttendancePunch::where('employee_id', $employee->id)
            ->whereDate('punch_date', Carbon::today()->toDateString())
            ->orderBy('punched_at')
            ->get();

        return app(PunchClassifier::class)->enrich(
            app(PunchTimelineEngine::class)->neutralEvents($raw),
        );
    }

    /**
     * Assemble today's Shift Progress from state this page already holds.
     *
     * Deliberately a thin assembly step: the expected duration comes from the
     * resolved shift and the worked minutes from PunchTimeline, both untouched.
     * The arithmetic and the clamping live in ShiftProgress so the rules are
     * testable without a Livewire component around them.
     */
    protected function buildShiftProgress($employee): ShiftProgress
    {
        $today = Carbon::today();

        // Leave and holidays first: a day nobody was expected to work must not
        // read as being behind schedule.
        if ($this->todayLeaveLabel($employee, $today) !== null) {
            return ShiftProgress::nonWorking($this->todayLeaveLabel($employee, $today));
        }

        if (app(WorkingDayResolver::class)->isWeeklyOff($today)) {
            return ShiftProgress::nonWorking('Weekly off');
        }

        $shift = app(ShiftResolver::class)->resolve($employee, $today);

        if (! $shift) {
            return ShiftProgress::unassigned();
        }

        // The canonical figure (first in → final out / now), never recomputed here.
        $worked = (int) ($this->todayCalc['worked_minutes'] ?? $this->punchJourney['working_minutes'] ?? 0);

        return ShiftProgress::of(
            $shift,
            $worked,
            clockedOut: (bool) $this->todayAttendance?->check_out,
        );
    }

    /** Leave or holiday covering today, as a label for the non-working state. */
    protected function todayLeaveLabel($employee, Carbon $day): ?string
    {
        $onLeave = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day->toDateString())
            ->whereDate('end_date', '>=', $day->toDateString())
            ->exists();

        if ($onLeave) {
            return 'On leave';
        }

        return app(HolidayResolver::class)->isHoliday($employee, $day)
            ? 'Public holiday'
            : null;
    }

    protected function buildPunchJourney($employee): array
    {
        $today = Carbon::today();
        $raw = AttendancePunch::where('employee_id', $employee->id)
            ->whereDate('punch_date', $today->toDateString())
            ->orderBy('punched_at')
            ->get();

        $summary = AttendanceDailySummary::where('employee_id', $employee->id)
            ->whereDate('date', $today->toDateString())
            ->first();

        return $this->assemblePunchJourney($raw, $today, $summary);
    }

    /**
     * Thin wrapper over the PunchTimeline engine (kept as a seam for tests).
     *
     * @param  Collection<int, AttendancePunch>  $raw
     * @return array<string, mixed>
     */
    protected function assemblePunchJourney($raw, Carbon $day, ?AttendanceDailySummary $summary = null): array
    {
        return app(PunchTimelineEngine::class)->process(collect($raw), $day, $summary);
    }

    /** Punch Timeline history — every visible day's punches as classified events. */
    public array $logTimeline = [];

    /**
     * Build the day-grouped Punch Timeline for the Attendance Log section:
     * one entry per history day with its classified punch events. Days without
     * per-punch rows (web punches / pre-journey data) synthesise events from
     * the attendance record + its break logs so nothing renders empty.
     */
    protected function buildLogTimeline($employee): void
    {
        $history = collect($this->history);
        if ($history->isEmpty()) {
            $this->logTimeline = [];

            return;
        }

        $dates = $history->map(fn ($i) => $i->date->toDateString())->all();
        $punchesByDay = AttendancePunch::where('employee_id', $employee->id)
            ->whereIn('punch_date', $dates)
            ->orderBy('punched_at')
            ->get()
            ->groupBy(fn ($p) => $p->punch_date->toDateString());
        $breaksByAttendance = BreakLog::whereIn('attendance_id', $history->pluck('id'))
            ->orderBy('break_start')
            ->get()
            ->groupBy('attendance_id');

        // Engine decisions for every visible day — sessions, ignored punches
        // and validated worked minutes, from the same PunchTimeline engine.
        $dayMetrics = $this->engineDayMetrics(
            $employee,
            Carbon::parse(min($dates)),
            Carbon::parse(max($dates)),
        );

        $this->logTimeline = $history->map(function ($item) use ($punchesByDay, $breaksByAttendance, $dayMetrics) {
            $key = $item->date->toDateString();
            $events = app(PunchTimelineEngine::class)->neutralEvents($punchesByDay->get($key, collect()));

            // Fallback: synthesise from the attendance row + break logs.
            if ($events === [] && $item->check_in) {
                $ua = UserAgent::parse($item->check_in_user_agent);
                $events[] = [
                    'time' => $item->check_in->format('h:i A'),
                    'title' => 'Clocked in'.($item->is_late ? ' (late)' : ''),
                    'type' => $item->is_late ? 'late' : 'in',
                    'method' => PunchMethod::tryFrom((string) $item->check_in_method)?->value,
                    'source' => $item->check_in_user_agent ? 'web' : 'biometric',
                    'location' => null,
                    'device' => $ua['browser'] !== 'Unknown' ? $ua['label'] : null,
                    'lat' => $item->check_in_lat,
                    'lng' => $item->check_in_lng,
                    'ip' => $item->check_in_ip,
                    'photo' => $item->check_in_photo,
                ];
                foreach ($breaksByAttendance->get($item->id, collect()) as $b) {
                    if ($b->break_start) {
                        $events[] = ['time' => Carbon::parse($b->break_start)->format('h:i A'), 'title' => 'Break started', 'type' => 'break', 'method' => null, 'source' => 'web', 'location' => null, 'device' => null, 'lat' => null, 'lng' => null];
                    }
                    if ($b->break_end) {
                        $events[] = ['time' => Carbon::parse($b->break_end)->format('h:i A'), 'title' => 'Returned from break', 'type' => 'resume', 'method' => null, 'source' => 'web', 'location' => null, 'device' => null, 'lat' => null, 'lng' => null];
                    }
                }
                if ($item->check_out) {
                    $uaOut = UserAgent::parse($item->check_out_user_agent);
                    $events[] = [
                        'time' => $item->check_out->format('h:i A'),
                        'title' => 'Clocked out',
                        'type' => 'out',
                        'method' => PunchMethod::tryFrom((string) $item->check_out_method)?->value,
                        'source' => $item->check_out_user_agent ? 'web' : 'biometric',
                        'location' => null,
                        'device' => $uaOut['browser'] !== 'Unknown' ? $uaOut['label'] : null,
                        'lat' => $item->check_out_lat,
                        'lng' => $item->check_out_lng,
                        'ip' => $item->check_out_ip,
                        'photo' => $item->check_out_photo,
                    ];
                }
            }

            // Worked minutes from the engine's validated sessions when punch
            // data exists; check_in→check_out math only as the web-punch fallback.
            $m = $dayMetrics[$key] ?? null;
            $workedMin = $m['worked'] ?? (($item->check_in && $item->check_out)
                ? $this->rowWorkedMinutes($item)
                : 0);

            return [
                'date' => $key,
                'label' => $item->date->format('d M Y'),
                'dayname' => $item->date->format('l'),
                'is_today' => $item->date->isToday(),
                'status' => $item->status,
                'is_late' => (bool) $item->is_late,
                // Worked on a weekly off: shown as such, never as late or missing.
                'weekly_off' => app(WorkingDayResolver::class)->isWeeklyOff($item->date),
                'mode' => $item->work_mode,
                'worked' => $workedMin > 0 ? intdiv($workedMin, 60).'h '.($workedMin % 60).'m' : null,
                'break' => (int) ($item->break_minutes ?? 0),
                'missing' => (! $item->check_out && ! $item->date->isToday()) || $item->missing_checkout,
                'reg_status' => $item->regularisation?->status,
                'is_regularized' => (bool) $item->is_regularized,
                // Original punches preserved at regularisation (Rule 9) and the
                // system auto punch-out flag — both surfaced on the day card.
                'original_in' => $item->original_check_in?->format('h:i A'),
                'original_out' => $item->original_check_out?->format('h:i A'),
                'corrected_in' => $item->check_in?->format('h:i A'),
                'corrected_out' => $item->check_out?->format('h:i A'),
                'is_auto_checkout' => (bool) $item->is_auto_checkout,
                // Engine decisions for the expandable day view: validated work
                // sessions, and punches the engine ignored (stray card scans /
                // duplicate reads) shown collapsed with their reason.
                'sessions' => $m['sessions'] ?? [],
                'ignored_events' => collect($m['ignored'] ?? [])->map(fn ($n) => [
                    'time' => $n['time'],
                    'method' => $n['method_label'],
                    'guidance' => $n['guidance'] ?? null,
                    'reason' => $n['reason'] ?? 'Ignored by Attendance Engine',
                ])->all(),
                'noise_count' => (int) ($m['duplicate_count'] ?? 0),
                'events' => $events,
            ];
        })->values()->all();
    }

    /**
     * Smart Attendance Alerts — detect missing Check-In / Check-Out / Break End
     * for today (once the shift is over) plus recent unresolved days. Empty when
     * there are no issues (the widget then shows the all-clear state).
     *
     * @return array<int, array{type:string, label:string, detail:string, date:string}>
     */
    protected function buildAttendanceAlerts(): array
    {
        $alerts = [];
        $today = Carbon::today();

        $shiftEnd = ($this->shift && $this->shift->end_time)
            ? Carbon::parse($this->shift->end_time)->setDate($today->year, $today->month, $today->day)
            : $today->copy()->setTime(20, 0);
        $shiftOver = now()->gt($shiftEnd);
        // Pulse v3.1: a check-out is missing only after shift end + 1 hour
        // (end of day when no shift is assigned).
        $checkoutOverdue = ($this->shift && $this->shift->end_time)
            ? now()->gt($shiftEnd->copy()->addMinutes(AttendanceCalculator::MISSING_CHECKOUT_AFTER_MINUTES))
            : false;

        // ── Today ──
        if ($this->todayAttendance) {
            // Today's missing punches are owned by the Attendance Journey banner
            // (single place per issue) — only alert here when the journey has no
            // punch data to surface it itself.
            $journeyOwnsToday = ($this->punchJourney['raw_count'] ?? 0) > 0;
            if (! $journeyOwnsToday && $this->todayAttendance->check_in && ! $this->todayAttendance->check_out && $checkoutOverdue) {
                $alerts[] = [
                    'type' => 'missing_checkout',
                    'label' => 'Missing Check-Out',
                    'detail' => 'Today · clocked in at '.$this->todayAttendance->check_in->format('h:i A'),
                    'date' => $today->toDateString(),
                    'action' => true,
                ];
            }

            // Late arrival (informational — regularise if the punch was wrong).
            if ($this->todayAttendance->is_late) {
                $alerts[] = [
                    'type' => 'late_arrival',
                    'label' => 'Late Arrival',
                    'detail' => 'Today · '.(int) ($this->todayAttendance->late_minutes ?? 0).'m past grace ('.$this->todayAttendance->check_in->format('h:i A').')',
                    'date' => $today->toDateString(),
                    'action' => true,
                ];
            }

            // Early exit — clocked out noticeably before the shift end.
            if ($this->todayAttendance->check_out && $this->todayAttendance->check_out->lt($shiftEnd->copy()->subMinutes(30))) {
                $alerts[] = [
                    'type' => 'early_exit',
                    'label' => 'Early Exit',
                    'detail' => 'Today · clocked out '.$this->todayAttendance->check_out->format('h:i A').' before shift end '.$shiftEnd->format('h:i A'),
                    'date' => $today->toDateString(),
                    'action' => true,
                ];
            }

            // Long break — informational, never actionable. A long break is not
            // a punch to correct: the punches are right and the employee simply
            // took the time, so offering "Regularize" would invite them to
            // falsify it. Threshold is the classifier's, so a gap the journey
            // labels "Long break" is exactly the gap that raises this.
            $breakMin = $journeyOwnsToday
                ? (int) ($this->punchJourney['break_minutes'] ?? 0)
                : (int) ($this->todayAttendance->break_minutes ?? 0);

            if ($breakMin > PunchClassifier::LONG_BREAK_MINUTES) {
                $alerts[] = [
                    'type' => 'long_break',
                    'label' => 'Long Break',
                    'detail' => 'Today · '.intdiv($breakMin, 60).'h '.($breakMin % 60).'m away, beyond the usual break',
                    'date' => $today->toDateString(),
                    'action' => false,
                ];
            }

            // Overtime worked today — from validated sessions, never raw events.
            $stdMin = (int) ($this->todayCalc['expected_minutes'] ?? round((float) ($this->shift->standard_hours ?? 9) * 60));
            $workedMin = (int) ($this->todayCalc['worked_minutes'] ?? 0);
            if ($workedMin > $stdMin + 30) {
                $otMin = $workedMin - $stdMin;
                // Pulse v3.1: time beyond the standard day is overtime only with
                // an approved OT request; otherwise it is shown, never paid as OT.
                $approvedOt = (Auth::user()->employee && app(AttendanceCalculator::class)->hasApprovedOt(Auth::user()->employee, $today));
                $alerts[] = [
                    'type' => 'overtime',
                    'label' => $approvedOt ? 'Overtime Worked' : 'Worked Beyond Shift',
                    'detail' => 'Today · '.intdiv($otMin, 60).'h '.($otMin % 60).'m beyond your standard day'.($approvedOt ? '' : ' (no approved OT request — not counted as overtime)'),
                    'date' => $today->toDateString(),
                    'action' => false,
                ];
            }
        } elseif ($shiftOver && ! app(WorkingDayResolver::class)->isWeeklyOff($today) && $this->workMode !== 'wfh') {
            $alerts[] = [
                'type' => 'missing_checkin',
                'label' => 'Missing Check-In',
                'detail' => 'No punch recorded for today',
                'date' => $today->toDateString(),
                'action' => true,
            ];
        }

        // ── Recent unresolved days (missing check-out) ──
        foreach (collect($this->history)
            ->filter(fn ($i) => (! $i->check_out && ! $i->date->isToday()) || $i->missing_checkout)
            ->sortByDesc('date')->take(5) as $item) {
            $alerts[] = [
                'type' => 'missing_checkout',
                'label' => 'Missing Check-Out',
                'detail' => $item->date->format('D, d M').' · clocked in at '.$item->check_in->format('h:i A'),
                'date' => $item->date->toDateString(),
                'action' => true,
            ];
        }

        // Biometric device offline (no sync in 30+ minutes on a working day).
        $sync = $this->biometricDevice?->last_synced_at;
        if ($sync && Carbon::parse($sync)->lt(now()->subMinutes(30)) && ! app(WorkingDayResolver::class)->isWeeklyOff($today)) {
            $alerts[] = [
                'type' => 'device_offline',
                'label' => 'Device Sync Delayed',
                'detail' => 'Last biometric sync '.Carbon::parse($sync)->diffForHumans(),
                'date' => $today->toDateString(),
                'action' => false,
            ];
        }

        return $alerts;
    }

    // ── Month-by-month history ──────────────────────────────────────────

    /** The month the history table shows, as Y-m. */
    public string $historyMonth = '';

    public function historyPreviousMonth(): void
    {
        $this->historyMonth = $this->clampHistory($this->historyCursor()->subMonthNoOverflow())->format('Y-m');
        $this->alignStatsToHistoryMonth();
    }

    public function historyNextMonth(): void
    {
        $next = $this->historyCursor()->addMonthNoOverflow();

        if ($next->lte(Carbon::today()->startOfMonth())) {
            $this->historyMonth = $next->format('Y-m');
        }
        $this->alignStatsToHistoryMonth();
    }

    /** Month picker (1–12) — keeps the chosen year. */
    public function setHistoryMonth(int $month): void
    {
        $month = max(1, min(12, $month));
        $this->historyMonth = $this->clampHistory($this->historyCursor()->setDate($this->historyCursor()->year, $month, 1))->format('Y-m');
        $this->alignStatsToHistoryMonth();
    }

    /** Year picker — keeps the chosen month (never a future month). */
    public function setHistoryYear(int $year): void
    {
        $this->historyMonth = $this->clampHistory($this->historyCursor()->setDate($year, $this->historyCursor()->month, 1))->format('Y-m');
        $this->alignStatsToHistoryMonth();
    }

    /**
     * The page has one month selector: the figures (computeStats — the same
     * canonical window, unchanged) follow the month the history shows. The
     * current month uses the standard "this month" period.
     */
    private function alignStatsToHistoryMonth(): void
    {
        $month = $this->historyCursor();

        if ($month->isSameMonth(Carbon::today())) {
            $this->statsPeriod = 'this_month';
            $this->rangeFrom = null;
            $this->rangeTo = null;
        } else {
            $this->statsPeriod = 'custom';
            $this->rangeFrom = $month->copy()->startOfMonth()->toDateString();
            $this->rangeTo = $month->copy()->endOfMonth()->toDateString();
        }

        $this->computeStats();
    }

    /**
     * The selected month's days by calendar week (Mon–Sun) — present, late
     * and absent counts taken straight from monthHistory's statuses (its order
     * decides, e.g. a WFH day is WFH), for the punctuality trend. Counts only;
     * nothing is recalculated.
     *
     * @return array<int, array{label: string, present: int, late: int, absent: int}>
     */
    #[Computed]
    public function monthPunctuality(): array
    {
        $weeks = [];

        foreach ($this->monthHistory['rows'] as $row) {
            $date = Carbon::parse($row['date']);
            if ($date->gt(Carbon::today())) {
                break;   // weeks still to come have nothing to show
            }
            $key = $date->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
            $weeks[$key] ??= ['label' => $date->copy()->startOfWeek(Carbon::MONDAY)->max($date->copy()->startOfMonth())->format('d M'), 'present' => 0, 'late' => 0, 'absent' => 0];

            match ($row['status']) {
                'Present', 'WFH', 'Half day', WorkingDayResolver::WORKED_WEEKLY_OFF_LABEL => $weeks[$key]['present']++,
                'Late' => $weeks[$key]['late']++,
                'Absent' => $weeks[$key]['absent']++,
                default => null,
            };
        }

        return array_values($weeks);
    }

    private function historyCursor(): Carbon
    {
        try {
            return Carbon::createFromFormat('!Y-m', $this->historyMonth ?: now()->format('Y-m'))->startOfMonth();
        } catch (\Throwable) {
            return Carbon::today()->startOfMonth();
        }
    }

    private function clampHistory(Carbon $month): Carbon
    {
        $first = Auth::user()->employee?->joining_date
            ? Carbon::parse(Auth::user()->employee->joining_date)->startOfMonth()
            : Carbon::today()->subYears(5)->startOfMonth();

        return $month->copy()->max($first)->min(Carbon::today()->startOfMonth());
    }

    /**
     * Every day of the chosen month with one status, from the same
     * classification as the stats (holiday > MDL > weekly off > leave >
     * working day, within employment), overlaid with attendance: Present,
     * Late, WFH, Half day, Worked on Weekly Off, Absent, Leave, Holiday, MDL,
     * Weekly off — plus breaks, worked hours, approved OT, regularisation and
     * missing check-out. Weekends are never absences.
     *
     * @return array{month: string, label: string, rows: array<int, array<string, mixed>>, totals: array<string, mixed>, years: array<int, int>}
     */
    #[Computed]
    public function monthHistory(): array
    {
        $employee = Auth::user()->employee;
        $month = $this->historyCursor();
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth()->startOfDay();
        $empty = ['month' => $month->format('Y-m'), 'label' => $month->format('F Y'), 'rows' => [], 'totals' => [], 'years' => [(int) now()->year]];

        if (! $employee) {
            return $empty;
        }

        $resolver = app(WorkingDayResolver::class);
        $states = $resolver->classifyRange($employee, $from, $to);
        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()->keyBy(fn ($a) => $a->date->toDateString());
        $metrics = $this->engineDayMetrics($employee, $from, $to->copy()->min(Carbon::today()));
        $ot = OtRequest::where('employee_id', $employee->id)->where('status', 'approved')
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->get(['work_date', 'requested_hours'])
            ->groupBy(fn ($o) => $o->work_date->toDateString())
            ->map(fn ($g) => round((float) $g->sum('requested_hours'), 1));
        $regularisations = AttendanceRegularisation::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->latest('id')->get()
            ->unique(fn ($r) => Carbon::parse($r->work_date)->toDateString())
            ->keyBy(fn ($r) => Carbon::parse($r->work_date)->toDateString());
        $holidayNames = app(HolidayResolver::class)->keyedForEmployee($employee, $from, $to);

        $today = Carbon::today();
        $rows = [];
        $totals = array_fill_keys(['present', 'late', 'wfh', 'half_day', 'absent', 'leave', 'holiday', 'mdl', 'weekly_off', 'worked_off', 'missing_checkout', 'regularised', 'worked_minutes', 'break_minutes', 'ot_hours'], 0);

        foreach ($states as $key => $day) {
            $date = Carbon::parse($key);
            $a = $attendance->get($key);
            $m = $metrics[$key] ?? null;
            $nonWorking = in_array($day['state'], [WorkingDayResolver::WEEKLY_OFF, WorkingDayResolver::PUBLIC_HOLIDAY, WorkingDayResolver::MDL_SHUTDOWN], true);

            $worked = 0;
            $break = 0;
            if ($a && $a->check_in) {
                $worked = $m !== null ? (int) $m['worked'] : $this->rowWorkedMinutes($a);
                $break = $m !== null ? (int) $m['break'] : (int) ($a->break_minutes ?? 0);
            }

            [$status, $tone] = match (true) {
                in_array($day['state'], [WorkingDayResolver::EMPLOYMENT_NOT_STARTED, WorkingDayResolver::EMPLOYMENT_ENDED], true) => ['Not employed', 'muted'],
                $a !== null && $a->check_in !== null && $nonWorking => [WorkingDayResolver::WORKED_WEEKLY_OFF_LABEL, 'violet'],
                $a !== null && $a->check_in !== null && $a->status === 'half_day' => ['Half day', 'amber'],
                $a !== null && $a->check_in !== null && ($a->work_mode === 'wfh' || $a->status === 'remote') => ['WFH', 'sky'],
                $a !== null && $a->check_in !== null && ($a->is_late || $a->status === 'late') => ['Late', 'amber'],
                $a !== null && $a->check_in !== null => ['Present', 'green'],
                $day['state'] === WorkingDayResolver::PUBLIC_HOLIDAY => ['Holiday', 'rose'],
                $day['state'] === WorkingDayResolver::MDL_SHUTDOWN => ['MDL shutdown', 'rose'],
                $day['state'] === WorkingDayResolver::WEEKLY_OFF => [WorkingDayResolver::WEEKLY_OFF_LABEL, 'muted'],
                $day['state'] === WorkingDayResolver::APPROVED_LEAVE => [$day['leave_days'] < 1 ? 'Leave (½ day)' : 'Leave', 'blue'],
                $date->gt($today) => ['Upcoming', 'muted'],
                $date->eq($today) => ['Today', 'muted'],
                default => ['Absent', 'red'],
            };

            $missing = ($a !== null && $a->check_in && ! $a->check_out && $date->lt($today)) || (bool) ($a?->missing_checkout);
            $reg = $regularisations->get($key);
            $otHours = (float) ($ot[$key] ?? 0);

            $rows[] = [
                'date' => $key,
                'day' => $date->format('D d'),
                'status' => $status,
                'tone' => $tone,
                'holiday' => $holidayNames->get($key)?->name,
                'check_in' => $a?->check_in?->format('H:i'),
                'check_out' => $a?->check_out?->format('H:i'),
                'worked_minutes' => $worked,
                'break_minutes' => $break,
                'ot_hours' => $otHours,
                'mode' => $a?->work_mode,
                'late_minutes' => (int) ($a?->late_minutes ?? 0),
                'missing_checkout' => $missing,
                'regularisation' => $reg?->status,
                'regularised' => (bool) ($a?->is_regularized),
            ];

            $totals['worked_minutes'] += $worked;
            $totals['break_minutes'] += $break;
            $totals['ot_hours'] += $otHours;
            $totals['missing_checkout'] += $missing ? 1 : 0;
            $totals['regularised'] += ($a?->is_regularized) ? 1 : 0;
            match ($status) {
                'Present' => $totals['present']++,
                'Late' => [$totals['present']++, $totals['late']++],
                'WFH' => $totals['wfh']++,
                'Half day' => $totals['half_day']++,
                'Absent' => $totals['absent']++,
                'Holiday' => $totals['holiday']++,
                'MDL shutdown' => $totals['mdl']++,
                WorkingDayResolver::WEEKLY_OFF_LABEL => $totals['weekly_off']++,
                WorkingDayResolver::WORKED_WEEKLY_OFF_LABEL => $totals['worked_off']++,
                default => null,
            };
            $totals['leave'] += $day['leave_days'];
        }

        $totals['ot_hours'] = round($totals['ot_hours'], 1);
        $firstYear = $employee->joining_date ? (int) Carbon::parse($employee->joining_date)->year : (int) now()->subYears(5)->year;

        return [
            'month' => $month->format('Y-m'),
            'label' => $month->format('F Y'),
            'rows' => $rows,
            'totals' => $totals,
            'years' => range((int) now()->year, max($firstYear, (int) now()->year - 10)),
        ];
    }

    public function previousMonth()
    {
        $this->calendarMonth->subMonth();
        $this->loadData();
    }

    public function nextMonth()
    {
        $this->calendarMonth->addMonth();
        $this->loadData();
    }

    public function updatedStatsPeriod(): void
    {
        $this->rangeFrom = null;
        $this->rangeTo = null;
        $this->loadData();

        // The Attendance Log follows the selected period — changing the filter
        // recalculates the history list, not just the charts (Rule 12).
        if ($this->statsPeriod !== 'this_month') {
            [$start, $end] = $this->periodRange();
            $this->syncHistoryToRange($start, $end);
        }
    }

    /** Re-query the history + day-grouped log for an explicit date range. */
    protected function syncHistoryToRange(Carbon $start, Carbon $end): void
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return;
        }

        $this->history = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with('regularisation')
            ->orderByDesc('date')
            ->get();
        $this->buildLogTimeline($employee);
    }

    public function updatedAnalyticsMode(): void
    {
        $this->computeStats();
    }

    public function updatedCompareMode(): void
    {
        $this->computeStats();
    }

    /** A custom From/To range drives every chart, insight and the log. */
    public function updatedRangeFrom(): void
    {
        $this->applyCustomRange();
    }

    public function updatedRangeTo(): void
    {
        $this->applyCustomRange();
    }

    protected function applyCustomRange(): void
    {
        if (! $this->rangeFrom || ! $this->rangeTo) {
            return;
        }

        if (Carbon::parse($this->rangeFrom)->gt(Carbon::parse($this->rangeTo))) {
            [$this->rangeFrom, $this->rangeTo] = [$this->rangeTo, $this->rangeFrom];
        }

        $this->statsPeriod = 'custom';
        $this->computeStats();

        // The Attendance Log follows the selected range too.
        $this->syncHistoryToRange(Carbon::parse($this->rangeFrom), Carbon::parse($this->rangeTo));
    }

    /** Longest custom range a single view may span. */
    public const MAX_CUSTOM_DAYS = 366;

    /**
     * The [start, end] window the current stats period resolves to — the ONE
     * range every filtered section (charts, history, insights) uses. Whole
     * calendar periods: this week (Sun–Sat), this / last month, this quarter,
     * the last three months, this year. Month arithmetic never overflows
     * (last month on 31 March is February, not March).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function periodRange(): array
    {
        $today = Carbon::today();

        return match ($this->statsPeriod) {
            'today' => [$today->copy(), $today->copy()],
            'this_week' => [$today->copy()->startOfWeek(Carbon::SUNDAY), $today->copy()->endOfWeek(Carbon::SATURDAY)->startOfDay()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'quarter' => [$today->copy()->firstOfQuarter(), $today->copy()->lastOfQuarter()],
            '3_months' => [$today->copy()->subMonthsNoOverflow(2)->startOfMonth(), $today->copy()->endOfMonth()->startOfDay()],
            'year' => [$today->copy()->startOfYear(), $today->copy()->endOfYear()->startOfDay()],
            'custom' => $this->customRange(),
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()->startOfDay()],
        };
    }

    /**
     * The window the figures are counted over: the period, ending today at
     * the latest — future days can be neither present nor absent. A period
     * entirely in the future collapses to its first day.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function statsRange(): array
    {
        [$start, $end] = $this->periodRange();
        $end = $end->copy()->min(Carbon::today());

        return [$start, $end->lt($start) ? $start->copy() : $end];
    }

    /**
     * A custom From–To, in order, at most MAX_CUSTOM_DAYS long.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function customRange(): array
    {
        try {
            $from = Carbon::parse($this->rangeFrom ?? now()->startOfMonth())->startOfDay();
            $to = Carbon::parse($this->rangeTo ?? now())->startOfDay();
        } catch (\Throwable) {
            return [Carbon::today()->startOfMonth(), Carbon::today()];
        }

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) >= self::MAX_CUSTOM_DAYS) {
            $from = $to->copy()->subDays(self::MAX_CUSTOM_DAYS - 1);
        }

        return [$from, $to];
    }

    /**
     * The window compared against: the same elapsed length immediately
     * before (previous period), the same days a month earlier, or the same
     * days a year earlier — always as long as the current window.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function comparisonRange(Carbon $start, Carbon $end): array
    {
        $length = (int) $start->diffInDays($end) + 1;

        return match ($this->compareMode) {
            'last_month' => [$start->copy()->subMonthNoOverflow(), $end->copy()->subMonthNoOverflow()],
            'last_year' => [$start->copy()->subYearNoOverflow(), $end->copy()->subYearNoOverflow()],
            default => [$start->copy()->subDays($length), $start->copy()->subDay()],
        };
    }

    /**
     * Present / late / worked minutes / absent / leave for one window, the
     * same way for the current and the comparison window.
     *
     * Present = a check-in on a scheduled working day (the mode filter
     * narrows it). Absent = a past scheduled working day (not a holiday,
     * MDL date, weekly off, leave, or outside employment) with no attendance
     * of ANY mode — a mode filter never turns another mode's day into an
     * absence, and weekends are never absences.
     *
     * @return array{present: int, late: int, minutes: int, absent: int, leave_days: float, weekly_off_worked: int, half_days: int, scheduled: int, has_rows: bool}
     */
    protected function windowSummary($employee, Carbon $start, Carbon $end): array
    {
        $resolver = app(WorkingDayResolver::class);
        $states = $resolver->classifyRange($employee, $start, $end);

        $rows = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();
        $anyMode = $rows->keyBy(fn ($a) => $a->date->toDateString());
        $filtered = $this->analyticsMode !== '' ? $rows->where('work_mode', $this->analyticsMode) : $rows;

        $metrics = $this->engineDayMetrics($employee, $start, $end->copy()->min(Carbon::today()));
        $minutesFor = function (Attendance $a) use ($metrics): int {
            $m = $metrics[$a->date->toDateString()] ?? null;
            if ($m !== null) {
                return (int) $m['worked'];
            }

            return ($a->check_in && $a->check_out)
                ? $this->rowWorkedMinutes($a)
                : 0;
        };

        $scheduledState = fn (?array $day) => $day !== null && in_array($day['state'], [WorkingDayResolver::WORKING_DAY, WorkingDayResolver::APPROVED_LEAVE], true);
        $onScheduled = $filtered->filter(fn ($a) => $a->check_in && $scheduledState($states[$a->date->toDateString()] ?? null));

        $cutoff = Carbon::today()->subDay();
        $absent = 0;
        $leaveDays = 0.0;
        $scheduled = 0;
        foreach ($states as $key => $day) {
            $leaveDays += $day['leave_days'];
            if ($day['state'] === WorkingDayResolver::WORKING_DAY) {
                $isPast = Carbon::parse($key)->lte($cutoff);
                // Today counts once attended; until then it is still open.
                if ($isPast || $anyMode->has($key)) {
                    $scheduled++;
                }
                if ($isPast && ! $anyMode->has($key)) {
                    $absent++;
                }
            }
        }

        return [
            'present' => $onScheduled->count(),
            'late' => $onScheduled->filter(fn ($a) => $a->is_late || $a->status === 'late')->count(),
            'minutes' => (int) $filtered->sum($minutesFor),
            'absent' => $absent,
            'leave_days' => round($leaveDays, 1),
            'weekly_off_worked' => $filtered->filter(fn ($a) => $a->check_in && ! $scheduledState($states[$a->date->toDateString()] ?? null))->count(),
            'half_days' => $onScheduled->where('status', 'half_day')->count(),
            'scheduled' => $scheduled,
            'has_rows' => $rows->isNotEmpty(),
        ];
    }

    /** Memoised engine day-metrics per range so stats + timeline share one computation. */
    protected array $dayMetricsCache = [];

    /**
     * Every day in the range processed through the PunchTimeline engine — the
     * same engine that renders the journey. Worked/break minutes come from
     * validated sessions (never raw check_in→check_out math), so a day whose
     * attendance row was mis-paired by the device still reports correct hours.
     * Days without punch rows are absent from the result; callers fall back to
     * the attendance row (web punches / pre-journey data).
     *
     * @return array<string, array{worked: int, break: int, sessions: array<int, array<string, mixed>>, ignored: array<int, array<string, mixed>>, ignored_count: int, duplicate_count: int, first_in_min: ?int, last_out_min: ?int, missing_out: bool}>
     */
    /**
     * Worked minutes of an attendance row: final clock-out − first clock-in
     * (Pulse v3.1, breaks never deducted). 0 while the day is still open.
     */
    protected function rowWorkedMinutes(?Attendance $attendance): int
    {
        return $attendance ? app(AttendanceCalculator::class)->spanMinutes($attendance->check_in, $attendance->check_out) : 0;
    }

    protected function engineDayMetrics($employee, Carbon $start, Carbon $end): array
    {
        $cacheKey = $start->toDateString().'|'.$end->toDateString();
        if (isset($this->dayMetricsCache[$cacheKey])) {
            return $this->dayMetricsCache[$cacheKey];
        }

        $punchesByDay = AttendancePunch::where('employee_id', $employee->id)
            ->whereBetween('punch_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('punched_at')
            ->get()
            ->groupBy(fn ($p) => $p->punch_date->toDateString());

        $summaries = AttendanceDailySummary::where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn ($s) => $s->date->toDateString());

        $engine = app(PunchTimelineEngine::class);
        $toMinutes = function (?string $formatted): ?int {
            if (! $formatted) {
                return null;
            }
            $t = Carbon::parse($formatted);

            return $t->hour * 60 + $t->minute;
        };

        // A regularised day is what HR approved: its corrected first-in /
        // final-out (the attendance row) win over raw device punches, so the
        // caller's row fallback handles it (Pulse v3.1 rule 13).
        $regularised = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('is_regularized', true)
            ->where(fn ($q) => $q->whereNotNull('original_check_in')->orWhereNotNull('original_check_out'))
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip();

        $metrics = [];
        foreach ($punchesByDay as $day => $dayPunches) {
            if ($regularised->has($day)) {
                continue;
            }
            $r = $engine->process($dayPunches, Carbon::parse($day), $summaries->get($day));
            $metrics[$day] = [
                'worked' => (int) $r['working_minutes'],
                'break' => (int) $r['break_minutes'],
                'sessions' => $r['sessions'],
                'ignored' => $r['ignored'],
                'ignored_count' => (int) $r['ignored_count'],
                'duplicate_count' => (int) $r['duplicate_count'] + (int) $r['conflict_count'],
                'first_in_min' => $toMinutes($r['first_in']),
                'last_out_min' => $toMinutes($r['last_out']),
                'missing_out' => (bool) $r['missing_out'],
            ];
        }

        return $this->dayMetricsCache[$cacheKey] = $metrics;
    }

    protected function computeStats(): void
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return;
        }

        // Counted up to today; the whole period is kept for the prediction.
        [$start, $end] = $this->statsRange();
        $periodEnd = $this->periodRange()[1];

        $this->tasksCompletedPeriod = Task::where('employee_id', $employee->id)
            ->completed()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->count();

        $attendances = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($this->analyticsMode !== '', fn ($q) => $q->where('work_mode', $this->analyticsMode))
            ->get();

        $attendanceDates = $attendances->keyBy(fn ($a) => $a->date->toDateString());

        // One classification per day (holiday > MDL > weekly off > leave >
        // working day, within employment) — shared with the comparison.
        $window = $this->windowSummary($employee, $start, $end);
        $absentCount = $window['absent'];

        // EVERY day's minutes come from the PunchTimeline engine (validated
        // sessions) — never raw check_in→check_out math when punch data exists.
        // Fallback to the attendance row only for pure web-punch days.
        $dayMetrics = $this->engineDayMetrics($employee, $start, $end->copy()->min(Carbon::today()));
        $minutesForDay = function ($a) use ($dayMetrics) {
            $m = $dayMetrics[$a->date->toDateString()] ?? null;
            if ($m !== null) {
                return $m['worked'];
            }
            if ($a->check_in && $a->check_out) {
                return $this->rowWorkedMinutes($a);
            }

            return 0;
        };
        $totalMinutes = (int) $attendances->sum($minutesForDay);

        // Approved overtime for the period (OT is never inferred from long days).
        $approvedOt = OtRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get(['work_date', 'requested_hours']);

        $this->stats = [
            'present' => $window['present'],
            'late' => $window['late'],
            'hours' => intdiv($totalMinutes, 60).'h '.($totalMinutes % 60).'m',
            // Leave DAYS in the window (half days count 0.5), not requests.
            'leaves' => $window['leave_days'],
            'absent' => $absentCount,
            'weekly_off_worked' => $window['weekly_off_worked'],
            'half_days' => $window['half_days'],
            // Scheduled working days elapsed (holidays, MDL, weekly offs and
            // approved leave excluded): the attendance % denominator.
            'scheduled' => $window['scheduled'],
            'ot_hours' => round((float) $approvedOt->sum('requested_hours'), 1),
            'ot_days' => $approvedOt->pluck('work_date')->unique()->count(),
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
        ];

        // ── Phase 6: Attendance analytics ────────────────────────────────
        $present = $this->stats['present'];
        $late = $this->stats['late'];
        $workingBasis = $present + $absentCount;
        $onTime = max(0, $present - $late);

        $wfhDays = $attendances->filter(fn ($a) => $a->work_mode === 'wfh' || $a->status === 'remote')->count();
        $officeDays = $attendances->count() - $wfhDays;

        // Per-mode day counts across all supported attendance modes.
        $modeBreakdown = [];
        foreach (AttendanceMode::cases() as $mode) {
            $count = $attendances->where('work_mode', $mode->value)->count();
            if ($count > 0) {
                $modeBreakdown[$mode->value] = $count;
            }
        }

        // Pulse v3.1: a break is excess only above 60 minutes (informational —
        // breaks never reduce worked hours).
        $breakAllowance = AttendanceCalculator::EXCESS_BREAK_MINUTES;
        $excessBreaks = $attendances->where('break_minutes', '>', $breakAllowance)->count();
        $withBreaks = $attendances->where('break_minutes', '>', 0);
        $avgBreak = $withBreaks->count() > 0 ? (int) round($withBreaks->avg('break_minutes')) : 0;

        $onTimePct = $present > 0 ? ($onTime / $present) * 100 : 100;
        $presentPct = $workingBasis > 0 ? ($present / $workingBasis) * 100 : 100;
        $breakPct = max(0, 100 - min(100, $excessBreaks * 10));

        // Late-arrival trend — fixed last-6-months window (independent of stats period)
        $trendStart = Carbon::now()->subMonths(5)->startOfMonth();
        $trendLate = Attendance::where('employee_id', $employee->id)
            ->whereDate('date', '>=', $trendStart->toDateString())
            ->where('is_late', true)
            ->get(['date']);
        $lateTrend = collect(range(0, 5))->map(function ($i) use ($trendStart, $trendLate) {
            $m = $trendStart->copy()->addMonths($i);

            return [
                'month' => $m->format('M'),
                'late' => $trendLate->filter(fn ($a) => $a->date->month === $m->month && $a->date->year === $m->year)->count(),
            ];
        })->values()->all();

        // ── Rule 10: monthly late-mark tracking ──────────────────────────
        // Below the threshold = normal; at/above = warning (banner + score
        // penalty + HR letter via hrms:issue-late-warnings). The threshold is
        // DB-driven. Consecutive = run of late working days ending at the most
        // recent late day.
        $lateThreshold = max(1, (int) ($this->attendanceSettings->late_warning_threshold ?? 3));
        $monthLateDates = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->toDateString()])
            ->where('is_late', true)
            ->orderByDesc('date')
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString());
        $monthLateCount = $monthLateDates->count();
        $lateSet = array_flip($monthLateDates->all());
        $consecutiveLate = 0;
        if ($monthLateCount > 0) {
            $cursor = Carbon::parse($monthLateDates->first());
            while (isset($lateSet[$cursor->toDateString()])) {
                $consecutiveLate++;
                $cursor->subDay();
                while (app(WorkingDayResolver::class)->isWeeklyOff($cursor)) {
                    $cursor->subDay();
                }
            }
        }
        $lateWarning = $monthLateCount >= $lateThreshold;

        // Warning-level lateness applies an explicit score deduction on top of
        // the punctuality weighting — 2 points per late mark past the threshold.
        $latePenalty = $lateWarning ? min(20, ($monthLateCount - ($lateThreshold - 1)) * 2) : 0;

        $this->analytics = [
            'shift_compliance' => $workingBasis > 0 ? (int) round($onTime / $workingBasis * 100) : 100,
            'attendance_score' => max(0, (int) round($onTimePct * 0.6 + $presentPct * 0.25 + $breakPct * 0.15) - $latePenalty),
            'office_days' => $officeDays,
            'wfh_days' => $wfhDays,
            'avg_break' => $avgBreak,
            'excess_breaks' => $excessBreaks,
            'late_trend' => $lateTrend,
            'mode_breakdown' => $modeBreakdown,
            'late_month_count' => $monthLateCount,
            'late_consecutive' => $consecutiveLate,
            'late_warning' => $lateWarning,
            'late_penalty' => $latePenalty,
            'late_threshold' => $lateThreshold,
        ];

        // ── Daily series for every chart (period up to today), engine-first ──
        // hours/break from validated sessions; in/out minutes power the arrival
        // trend. The mode filter narrows to matching attendance days.
        $seriesEnd = $end->copy()->min(Carbon::today());
        $daily = [];
        if ($start <= $seriesEnd) {
            foreach (CarbonPeriod::create($start, $seriesEnd) as $d) {
                $key = $d->toDateString();
                $att = $attendanceDates->get($key);
                $m = $dayMetrics[$key] ?? null;
                $modeFiltered = $this->analyticsMode !== '' && ! $att;

                $hours = 0.0;
                $break = 0;
                $inMin = null;
                $outMin = null;
                if ($m !== null && ! $modeFiltered) {
                    $hours = round($m['worked'] / 60, 1);
                    $break = $m['break'];
                    $inMin = $m['first_in_min'];
                    $outMin = $m['last_out_min'];
                } elseif ($att && $att->check_in) {
                    if ($att->check_out) {
                        $mins = $this->rowWorkedMinutes($att);
                        $hours = round(max(0, $mins) / 60, 1);
                        $outMin = $att->check_out->hour * 60 + $att->check_out->minute;
                    }
                    $break = (int) ($att->break_minutes ?? 0);
                    $inMin = $att->check_in->hour * 60 + $att->check_in->minute;
                }

                $daily[] = [
                    'date' => $key,
                    'label' => $d->format('d M'),
                    'hours' => $hours,
                    'break' => $break,
                    'late' => (bool) ($att?->is_late),
                    'in_min' => $inMin,
                    'out_min' => $outMin,
                    'score' => null,   // filled from attendance_daily_scores below
                ];
            }
        }

        // Rule 11 — overlay the engine's persisted daily scores on the series
        // (the score chart falls back to an hours-based proxy for unscored days).
        if ($daily !== []) {
            $storedScores = AttendanceDailyScore::where('employee_id', $employee->id)
                ->whereBetween('date', [$start->toDateString(), $seriesEnd->toDateString()])
                ->get()
                ->mapWithKeys(fn ($s) => [$s->date->toDateString() => (float) $s->score]);
            foreach ($daily as &$dayEntry) {
                $dayEntry['score'] = $storedScores[$dayEntry['date']] ?? null;
            }
            unset($dayEntry);
        }
        $this->chartDaily = $daily;

        // Rule 11 — monthly score, previous-month comparison and rankings from
        // the engine's persisted daily scores.
        $scoreEngine = app(AttendanceScoreEngine::class);
        $this->monthlyScore = $scoreEngine->monthlyScore($employee, now());
        $this->prevMonthlyScore = $scoreEngine->monthlyScore($employee, now()->subMonthNoOverflow());
        $companyIds = Employee::where('status', 'active')->pluck('id')->all();
        $deptIds = $employee->department_id
            ? Employee::where('department_id', $employee->department_id)->where('status', 'active')->pluck('id')->all()
            : [];
        $this->companyRank = $scoreEngine->rankAmong($employee, $companyIds, now());
        $this->deptRank = $deptIds !== [] ? $scoreEngine->rankAmong($employee, $deptIds, now()) : null;

        // ── Attendance Insights — auto-generated, plain-language, real data ──
        $withIn = $attendances->filter(fn ($a) => $a->check_in);
        $avgInMin = $withIn->count() > 0
            ? (int) round($withIn->avg(fn ($a) => $a->check_in->hour * 60 + $a->check_in->minute))
            : null;
        $workedDays = collect($daily)->filter(fn ($d) => $d['hours'] > 0);
        $avgHoursMin = $workedDays->count() > 0 ? (int) round($workedDays->avg('hours') * 60) : 0;
        $breakOk = $attendances->count() > 0
            ? (int) round(($attendances->count() - $excessBreaks) / $attendances->count() * 100)
            : 100;
        $missing = $attendances->filter(fn ($a) => (! $a->check_out && ! $a->date->isToday()) || $a->missing_checkout)->count();

        $insights = [];
        if ($present > 0) {
            $insights[] = ['good' => $onTimePct >= 90, 'text' => $onTimePct >= 90 ? 'Excellent punctuality — '.round($onTimePct).'% on-time this period' : round($onTimePct).'% on-time — '.$late.' late arrival(s) this period'];
        }
        $insights[] = ['good' => $missing === 0, 'text' => $missing === 0 ? 'No missing punches this period' : $missing.' day(s) with a missing punch — regularise them'];
        if ($avgInMin !== null) {
            $insights[] = ['good' => true, 'text' => 'Average check-in '.sprintf('%02d:%02d', intdiv($avgInMin, 60), $avgInMin % 60).' '.($avgInMin < 720 ? 'AM' : 'PM')];
        }
        if ($avgHoursMin > 0) {
            $insights[] = ['good' => $avgHoursMin >= (int) (($this->shift->standard_hours ?? 9) * 60 * 0.9), 'text' => 'Average working hours '.intdiv($avgHoursMin, 60).'h '.($avgHoursMin % 60).'m per day'];
        }
        $insights[] = ['good' => $breakOk >= 90, 'text' => 'Break compliance '.$breakOk.'%'.($excessBreaks > 0 ? ' — '.$excessBreaks.' excess-break day(s)' : '')];

        $longestBreak = (int) $attendances->max('break_minutes');
        if ($longestBreak > 0) {
            $insights[] = ['good' => $longestBreak <= $breakAllowance, 'text' => 'Longest break '.($longestBreak >= 60 ? intdiv($longestBreak, 60).'h '.($longestBreak % 60).'m' : $longestBreak.' mins')];
        }

        $bestDay = $withIn->groupBy(fn ($a) => $a->date->format('l'))
            ->map->count()->sortDesc()->keys()->first();
        if ($bestDay) {
            $insights[] = ['good' => true, 'text' => 'Best attendance day: '.$bestDay];
        }

        $totalOtMin = (int) round($this->stats['ot_hours'] * 60);
        if ($totalOtMin > 0) {
            $insights[] = ['good' => true, 'text' => 'Approved overtime '.intdiv($totalOtMin, 60).'h '.($totalOtMin % 60).'m this period'];
        }

        $this->insights = $insights;

        // ── AI Attendance Insights — stat cards, trends, prediction, suggestions ──
        $withOut = $attendances->filter(fn ($a) => $a->check_out);
        $avgOutMin = $withOut->count() > 0
            ? (int) round($withOut->avg(fn ($a) => $a->check_out->hour * 60 + $a->check_out->minute))
            : null;

        $longestDay = collect($daily)->sortByDesc('hours')->first();

        // Longest run of worked days without a late mark (working days only).
        $streak = 0;
        $run = 0;
        foreach ($daily as $d) {
            if ($d['hours'] > 0 && ! $d['late']) {
                $run++;
                $streak = max($streak, $run);
            } elseif ($d['hours'] > 0) {
                $run = 0;
            }
        }

        // Prediction: attendance % if every remaining working day is attended.
        $resolver = app(WorkingDayResolver::class);
        $remainingDays = Carbon::tomorrow()->lte($periodEnd)
            ? $resolver->scheduledDaysBetween($employee, Carbon::tomorrow(), $periodEnd)
            : 0;
        $fullWorkDays = max(1, $window['scheduled'] + $remainingDays);
        $predictedPct = min(100, (int) round(($present + $remainingDays) / $fullWorkDays * 100));

        // Trend vs the selected comparison window (GA4-style): previous period
        // of the same length, the previous month, or the same period last year.
        [$prevStart, $prevEnd] = $this->comparisonRange($start, $end);
        $previous = $this->windowSummary($employee, $prevStart, $prevEnd);
        $prev = collect($previous['has_rows'] ? [1] : []);
        $prevPresent = $previous['present'];
        $prevLate = $previous['late'];
        $prevMinutes = $previous['minutes'];
        $prevOnTimePct = $prevPresent > 0 ? (int) round(max(0, $prevPresent - $prevLate) / $prevPresent * 100) : null;

        // Real deltas for the KPI band — null delta means "no basis to compare"
        // and the UI hides the trend chip rather than inventing one.
        $this->comparison = [
            'label' => match ($this->compareMode) {
                'last_month' => 'vs last month',
                'last_year' => 'vs last year',
                default => 'vs previous period',
            },
            'from' => $prevStart->toDateString(),
            'to' => $prevEnd->toDateString(),
            'has_data' => $prev->isNotEmpty(),
            'present' => $prev->isNotEmpty() ? $present - $prevPresent : null,
            'late' => $prev->isNotEmpty() ? $late - $prevLate : null,
            'hours' => $prev->isNotEmpty() ? (int) round(($totalMinutes - $prevMinutes) / 60) : null,
            'on_time_pct' => ($prevOnTimePct !== null && $workingBasis > 0)
                ? (int) round($onTime / max(1, $present) * 100) - $prevOnTimePct
                : null,
        ];

        $fmtTime = fn (?int $m) => $m === null ? null : sprintf('%02d:%02d %s', (intdiv($m, 60) % 12) ?: 12, $m % 60, $m < 720 ? 'AM' : 'PM');

        $suggestions = [];
        $suggestions[] = $this->analytics['attendance_score'] >= 85
            ? ['good' => true, 'text' => 'Great attendance — keep it up']
            : ['good' => false, 'text' => 'Focus on attendance consistency this period'];
        if ($avgBreak > $breakAllowance) {
            $suggestions[] = ['good' => false, 'text' => "Improve break duration — average exceeds {$breakAllowance} minutes"];
        }
        if ($late > 0 && $this->shift?->start_time) {
            $suggestions[] = ['good' => false, 'text' => 'Arrive before '.Carbon::parse($this->shift->start_time)->addMinutes((int) ($this->shift->grace_minutes ?? 5))->format('g:i A').' to stay on time'];
        } else {
            $suggestions[] = ['good' => true, 'text' => 'Perfect punctuality this period'];
        }
        $attPctNow = $workingBasis > 0 ? round($present / $workingBasis * 100) : 100;
        $suggestions[] = $attPctNow >= 98
            ? ['good' => true, 'text' => 'Maintain attendance above 98%']
            : ['good' => false, 'text' => 'Target 98%+ attendance — currently '.$attPctNow.'%'];
        if ($missing > 0) {
            $suggestions[] = ['good' => false, 'text' => 'Regularise '.$missing.' missing punch '.Str::plural('day', $missing)];
        }

        $this->insightStats = [
            'score' => (int) $this->analytics['attendance_score'],
            'avg_in' => $fmtTime($avgInMin),
            'avg_out' => $fmtTime($avgOutMin),
            'avg_break' => $avgBreak,
            'avg_hours' => $avgHoursMin > 0 ? intdiv($avgHoursMin, 60).'h '.($avgHoursMin % 60).'m' : null,
            'best_day' => $bestDay,
            'longest_day' => $longestDay && $longestDay['hours'] > 0 ? $longestDay['hours'].'h · '.$longestDay['label'] : null,
            'longest_break' => $longestBreak > 0 ? ($longestBreak >= 60 ? intdiv($longestBreak, 60).'h '.($longestBreak % 60).'m' : $longestBreak.'m') : null,
            'streak' => $streak,
            'late_count' => $late,
            'missing_count' => $missing,
            'prediction' => $predictedPct,
            'present_trend' => $present - $prevPresent,
            'late_trend' => $late - $prevLate,
            'suggestions' => $suggestions,
        ];

        // AI Attendance Coach — every insight computed from real engine data
        // for the selected period (Priority 2).
        $this->coach = app(AttendanceCoach::class)->analyze($employee, $start, $end);
    }

    /**
     * Generate plain-language AI insights from the computed analytics.
     * No-op unless OPENAI_API_KEY is configured (panel is hidden otherwise).
     */
    public function generateAiInsight(): void
    {
        $ai = app(AiAssistant::class);
        if (! Auth::user() || ! $ai->enabledForUser(Auth::user())) {
            return;
        }

        $this->aiLoading = true;

        $payload = [
            'attendance_score' => $this->analytics['attendance_score'] ?? null,
            'shift_compliance_pct' => $this->analytics['shift_compliance'] ?? null,
            'present_days' => $this->stats['present'] ?? 0,
            'late_days' => $this->stats['late'] ?? 0,
            'absent_days' => $this->stats['absent'] ?? 0,
            'office_days' => $this->analytics['office_days'] ?? 0,
            'wfh_days' => $this->analytics['wfh_days'] ?? 0,
            'avg_break_minutes' => $this->analytics['avg_break'] ?? 0,
            'excess_break_days' => $this->analytics['excess_breaks'] ?? 0,
            'late_trend_6m' => $this->analytics['late_trend'] ?? [],
        ];

        $system = 'You are an HR attendance analyst for a single company. Given one employee\'s attendance metrics, write 2-4 short bullet-point insights in plain language. Cover attendance anomalies, burnout risk (excess overtime combined with low break time or frequent late arrivals), and repeated late-arrival patterns. Be concise, factual and supportive. Do not invent data beyond what is provided.';

        try {
            $this->aiInsight = $ai->ask($system, json_encode($payload, JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            $this->aiInsight = 'AI insights are temporarily unavailable. Please try again later.';
        } finally {
            $this->aiLoading = false;
        }
    }

    public function startBreak()
    {
        if (! $this->todayAttendance || $this->todayAttendance->check_out) {
            return;
        }

        if (! app(AttendanceService::class)->startBreak($this->todayAttendance)) {
            return;
        }

        $this->loadData();
        \Flux::toast('Break started.');
    }

    public function endBreak()
    {
        if (! $this->todayAttendance) {
            return;
        }

        if (! app(AttendanceService::class)->endBreak($this->todayAttendance)) {
            return;
        }

        $this->loadData();
        \Flux::toast('Break ended. Welcome back!');
    }

    public function checkIn($lat = null, $lng = null, ?string $photo = null)
    {
        if ($this->todayAttendance) {
            return;
        }

        $employee = Auth::user()->employee;

        if (! $employee) {
            \Flux::toast('No employee profile found. Contact HR.', variant: 'danger');

            return;
        }

        // May be null when HR has not assigned a shift. That does not block the
        // punch: being at work is a fact, being late is a judgement. Losing the
        // attendance record over a configuration gap would be the worse
        // outcome, so the day is recorded and simply not judged.
        $shift = $this->shift instanceof ShiftSetting ? $this->shift : ShiftResolver::companyDefault();

        // Enforce admin capture requirements (WFH/field discipline).
        if ($this->attendanceSettings?->requires_location && ($lat === null || $lng === null)) {
            \Flux::toast('Location is required to clock in — please allow location access and try again.', variant: 'danger');

            return;
        }

        if ($this->attendanceSettings?->requires_photo && ! $photo) {
            \Flux::toast('A selfie is required to clock in — please capture a photo and try again.', variant: 'danger');

            return;
        }

        // Working from home is an approved arrangement, not a dropdown choice.
        // Recording it unapproved made the WFH request meaningless: the day was
        // logged as remote whether or not anyone had agreed to it.
        if ($this->workMode === 'wfh' && ! app(WfhService::class)->isApprovedFor($employee, Carbon::today())) {
            \Flux::toast('You have no approved work-from-home request for today. Submit one under Work From Home, or clock in with a different work mode.', variant: 'danger');

            return;
        }

        $this->todayAttendance = app(AttendanceService::class)->checkIn($employee, $shift, [
            'ip' => request()->ip(),
            'lat' => $lat,
            'lng' => $lng,
            'photo' => $this->storePunchPhoto($employee, $photo, 'in'),
            'work_mode' => $this->workMode,
        ]);

        $this->loadData();
        \Flux::toast('Clocked in successfully.');
    }

    public function checkOut($lat = null, $lng = null, ?string $photo = null)
    {
        if (! $this->todayAttendance || $this->todayAttendance->check_out) {
            return;
        }

        $this->todayAttendance = app(AttendanceService::class)->checkOut($this->todayAttendance, [
            'lat' => $lat,
            'lng' => $lng,
            'photo' => $this->storePunchPhoto(Auth::user()->employee, $photo, 'out'),
        ]);

        $this->loadData();
        \Flux::toast('Clocked out successfully. Good work today!');
    }

    /**
     * Persist a base64 selfie captured at punch time and return its public path.
     * Returns null when no (or an invalid) image is supplied — punching stays optional.
     */
    protected function storePunchPhoto($employee, ?string $dataUrl, string $which): ?string
    {
        if (! $employee || ! $dataUrl || ! str_starts_with($dataUrl, 'data:image')) {
            return null;
        }

        [, $encoded] = array_pad(explode(',', $dataUrl, 2), 2, '');
        $binary = base64_decode($encoded, true);

        if ($binary === false || strlen($binary) > 2_000_000) {
            return null;
        }

        $path = "attendance-photos/{$employee->id}/".now()->format('Ymd_His')."_{$which}.jpg";
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    /** Stream the visible attendance log as a CSV download. */
    public function exportLog()
    {
        $rows = collect($this->history);
        if ($this->logMode !== '') {
            $rows = $rows->where('work_mode', $this->logMode);
        }

        $filename = 'attendance-'.$this->calendarMonth->format('Y-m').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Check In', 'Check Out', 'Break (min)', 'Total Hours', 'Status', 'Mode', 'In Method', 'Out Method']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->date->toDateString(),
                    $r->check_in?->format('H:i'),
                    $r->check_out?->format('H:i'),
                    (int) ($r->break_minutes ?? 0),
                    app(AttendanceCalculator::class)->workedHours($r),
                    $r->status,
                    $r->work_mode,
                    $r->check_in_method,
                    $r->check_out_method,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function showPunchDetail(string $date): void
    {
        $employee = Auth::user()->employee;
        $att = Attendance::where('employee_id', $employee?->id)->whereDate('date', $date)->first();

        if (! $att) {
            return;
        }

        $inMethod = PunchMethod::tryFrom((string) $att->check_in_method);
        $outMethod = PunchMethod::tryFrom((string) $att->check_out_method);

        $this->detail = [
            'date' => $att->date->format('l, d M Y'),
            'mode' => $att->work_mode,
            'status' => $att->status,
            'is_late' => (bool) $att->is_late,
            'late_minutes' => (int) ($att->late_minutes ?? 0),
            'break_minutes' => (int) ($att->break_minutes ?? 0),
            'total_hours' => app(AttendanceCalculator::class)->workedHours($att),
            'is_regularized' => (bool) $att->is_regularized,
            'is_auto_checkout' => (bool) $att->is_auto_checkout,
            'auto_checkout_reason' => $att->auto_checkout_reason,
            // Original punches preserved at regularisation — shown next to the
            // corrected times so history is never lost.
            'original_in' => $att->original_check_in?->format('h:i A'),
            'original_out' => $att->original_check_out?->format('h:i A'),
            'in' => [
                'time' => $att->check_in?->format('h:i A'),
                'method' => $inMethod?->label(),
                'guidance' => $inMethod?->guidance('in'),
                'method_icon' => $inMethod?->icon(),
                'photo' => $att->check_in_photo,
                'lat' => $att->check_in_lat,
                'lng' => $att->check_in_lng,
                'ip' => $att->check_in_ip,
                'device' => UserAgent::parse($att->check_in_user_agent)['label'],
            ],
            'out' => [
                'time' => $att->check_out?->format('h:i A'),
                'method' => $outMethod?->label(),
                'guidance' => $outMethod?->guidance('out'),
                'method_icon' => $outMethod?->icon(),
                'photo' => $att->check_out_photo,
                'lat' => $att->check_out_lat,
                'lng' => $att->check_out_lng,
                'ip' => $att->check_out_ip,
                'device' => UserAgent::parse($att->check_out_user_agent)['label'],
            ],
            // Attendance Replay — every raw punch of that day, chronological.
            'punches' => AttendancePunch::where('employee_id', $employee->id)
                ->whereDate('punch_date', $date)
                ->orderBy('punched_at')
                ->get()
                ->map(fn (AttendancePunch $p) => [
                    'time' => $p->punched_at->format('h:i A'),
                    'method' => $p->methodEnum()?->label(),
                    // System-written punches carry an authoritative direction;
                    // a device read shows its method's default guidance.
                    'guidance' => $p->methodEnum()?->guidance(in_array($p->source, ['regularisation', 'system_auto', 'web'], true) ? $p->direction : null),
                    'method_icon' => $p->methodEnum()?->icon(),
                    'source' => $p->source,
                    'device' => $p->device_serial,
                    'location' => $p->location,
                ])->all(),
            // Audit History — every regularisation touching this day, with the
            // full multi-stage approval trail (who acted, when, at which stage).
            'audits' => AttendanceRegularisation::where('employee_id', $employee->id)
                ->whereDate('work_date', $date)
                ->with('reviewer')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($r) => [
                    'type' => $r->regularisation_type ?? 'punch',
                    'requested_in' => $r->requested_check_in ? Carbon::parse($r->requested_check_in)->format('h:i A') : null,
                    'requested_out' => $r->requested_check_out ? Carbon::parse($r->requested_check_out)->format('h:i A') : null,
                    'reason' => $r->reason,
                    'status' => $r->status,
                    'stage' => $r->stageLabel(),
                    'reviewer' => $r->reviewer?->name,
                    'reviewed_at' => $r->reviewed_at?->format('d M Y h:i A'),
                    'submitted_at' => $r->created_at?->format('d M Y h:i A'),
                    'trail' => collect($r->approval_trail ?? [])->map(fn ($t) => [
                        'stage' => str_replace('_', ' ', (string) ($t['stage'] ?? '')),
                        'action' => $t['action'] ?? '',
                        'by' => $t['name'] ?? null,
                        'comment' => $t['comment'] ?? null,
                        'at' => $t['at'] ?? null,
                    ])->all(),
                ])->all(),
        ];

        $this->modal('punch-detail')->show();
    }

    /**
     * "Why?" — the Attendance Decision popup: exactly how the engine reached
     * its verdict for a day (shift window, punch decisions, deductions, score).
     */
    public function showScoreDecision(string $date): void
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return;
        }

        $this->decision = app(AttendanceScoreEngine::class)->explainDay($employee, Carbon::parse($date));

        $this->modal('score-decision')->show();
    }

    /**
     * The employee's own recent regularisations, newest first — pending ones
     * can still be edited or deleted here.
     *
     * @return Collection<int, AttendanceRegularisation>
     */
    #[Computed]
    public function myRegularisations(): Collection
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return collect();
        }

        return AttendanceRegularisation::with('employee.user')
            ->where('employee_id', $employee->id)
            ->where('work_date', '>=', now()->subDays(90)->toDateString())
            ->latest('id')->limit(8)->get();
    }

    public function openRegularisation($date)
    {
        $this->regDate = $date;
        $attendance = Attendance::where('employee_id', Auth::user()->employee->id)->where('date', $date)->first();
        $this->regCheckIn = $attendance?->check_in?->format('H:i') ?? '';
        $this->regCheckOut = $attendance?->check_out?->format('H:i') ?? '';

        // Pre-tick what's actually missing so the employee fixes only that.
        $this->regFixIn = ! $attendance || ! $attendance->check_in;
        $this->regFixOut = ! $attendance || ! $attendance->check_out;
        if (! $this->regFixIn && ! $this->regFixOut) {
            $this->regFixOut = true; // correcting an existing (wrong) punch
        }

        $this->modal('regularisation-modal')->show();
    }

    public function submitRegularisation()
    {
        $isHalfDay = $this->regType === 'half_day';

        // A punch correction is for a day that has happened (spec §3.2: a
        // missed or wrong clock-in/out), with real HH:MM times.
        $time = ['regex:/^\d{2}:\d{2}(:\d{2})?$/'];

        $this->validate([
            'regDate' => $isHalfDay ? 'required|date' : 'required|date|before_or_equal:today',
            'regType' => 'required|in:punch,half_day',
            'regHalfDayPeriod' => $isHalfDay ? 'required|in:first,second' : 'nullable',
            'regCheckIn' => $isHalfDay ? 'nullable' : array_merge([$this->regFixIn ? 'required' : 'nullable'], $time),
            'regCheckOut' => $isHalfDay ? 'nullable' : array_merge([$this->regFixOut ? 'required' : 'nullable'], $time),
            'regReason' => 'required|min:5',
            'regAttachment' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,pdf,webp',
        ], [
            'regCheckIn.required' => 'Enter the correct check-in time.',
            'regCheckOut.required' => 'Enter the correct check-out time.',
            'regDate.before_or_equal' => 'You can only correct a day that has already happened.',
            'regCheckIn.regex' => 'Enter the check-in time as HH:MM.',
            'regCheckOut.regex' => 'Enter the check-out time as HH:MM.',
            'regAttachment.max' => 'The attachment may not exceed 5 MB.',
        ]);

        $employee = Auth::user()->employee;
        $attendance = Attendance::where('employee_id', $employee->id)
            ->where('date', $this->regDate)
            ->first();

        $payload = [
            'employee_id' => $employee->id,
            'attendance_id' => $attendance?->id,
            'work_date' => $this->regDate,
            'regularisation_type' => $this->regType,
            'reason' => $this->regReason,
            'attachment_path' => $this->regAttachment?->store('regularisation-attachments', 'public'),
            'status' => 'pending',
            // Routed directly to HR, whose approval applies it.
            'stage' => 'hr_review',
        ];

        if ($isHalfDay) {
            // Half-day request: no punch times, just which half of the day.
            $payload['half_day_period'] = $this->regHalfDayPeriod;
        } else {
            if (! $this->regFixIn && ! $this->regFixOut) {
                \Flux::toast('Tick at least one punch to correct (check-in or check-out).', variant: 'warning');

                return;
            }

            // Untouched punches keep their recorded time so approval only
            // overrides what the employee asked to fix.
            $requestedIn = $this->regFixIn
                ? $this->regCheckIn
                : ($attendance?->check_in?->format('H:i') ?? $this->regCheckIn);
            $requestedOut = $this->regFixOut
                ? $this->regCheckOut
                : ($attendance?->check_out?->format('H:i') ?? $this->regCheckOut);

            if (! $requestedIn || ! $requestedOut) {
                \Flux::toast('Both times are needed — tick the missing punch and fill it in.', variant: 'warning');

                return;
            }

            // An OUT at or before the IN is only the next morning on a night
            // shift; anywhere else it would become a 20h/24h phantom session.
            if (substr($requestedOut, 0, 5) <= substr($requestedIn, 0, 5)
                && ! app(ShiftResolver::class)->resolve($employee, $this->regDate)?->crossesMidnight()) {
                $this->addError('regCheckOut', 'The check-out must be after the check-in.');

                return;
            }

            $payload['requested_check_in'] = $this->regDate.' '.$requestedIn.':00';
            $payload['requested_check_out'] = $this->regDate.' '.$requestedOut.':00';
            $payload['check_in_method'] = in_array($this->regCheckInMethod, ['face', 'id_card'], true) ? $this->regCheckInMethod : 'id_card';
            $payload['check_out_method'] = in_array($this->regCheckOutMethod, ['face', 'id_card'], true) ? $this->regCheckOutMethod : 'id_card';
        }

        $regularisation = AttendanceRegularisation::create($payload);

        // Notify HR — regularisations are routed directly to HR, whose approval
        // applies them. Notifications are best-effort: the request is already saved, so a mail-transport
        // failure (e.g. SMTP timeout) must not 500 the employee's submit. The
        // in-app database channel is written before mail, so the inbox still
        // updates even when the email send throws.
        try {
            $notification = new AttendanceRegularisationNotification(
                Auth::user()->name,
                Carbon::parse($this->regDate)->format('d M Y'),
                'pending',
            );
            // Everyone holding "Approve Regularisations (HR)" whose scope
            // covers this employee (Super Admins included).
            // Plus anyone the Notifications & Email page adds; minus excluded
            // roles; each person once.
            app(NotificationDispatcher::class)->sendToRecipients(
                AttendanceRegularisationNotification::class,
                app(NotificationRecipients::class)->regularisationApprovers($employee),
                fn () => $notification->forRole('hr_admin'),
                'regularisation:'.$regularisation->id,
                $employee,
            );

            // Notify the employee themselves so the request appears in their inbox
            Auth::user()->notify(new RegularisationReviewedNotification($regularisation));
        } catch (\Throwable $e) {
            report($e); // logged for diagnosis; the regularisation is saved regardless
        }

        $this->reset(['regDate', 'regCheckIn', 'regCheckOut', 'regReason', 'regFixIn', 'regFixOut', 'regAttachment', 'regType', 'regHalfDayPeriod']);
        $this->modal('regularisation-modal')->close();
        \Flux::toast('Regularisation request sent to HR for approval.');
    }

    /**
     * The shift window shown to the employee, from the same source the engine
     * scores against — so the page can never advertise hours the engine is not
     * using.
     *
     * Previously this had two other paths: hardcoded "IT Shift: 10:30 AM…"
     * literals, and a global-setting fallback that told an unassigned employee
     * they worked 9:00–6:00. Both could disagree with the resolver, and the
     * second described a working day the company does not run.
     */
    protected function buildShiftLabel(): ?string
    {
        $employee = Auth::user()->employee;
        $shift = $employee?->shift ?? ShiftResolver::companyDefault();

        if (! $shift || ! $shift->start_time || ! $shift->end_time) {
            return null;   // the view renders the "not assigned" state instead
        }

        return sprintf(
            '%s: %s – %s IST | Grace: %d mins%s',
            $shift->name ?? 'Shift',
            Carbon::parse($shift->start_time)->format('g:i A'),
            Carbon::parse($shift->end_time)->format('g:i A'),
            $shift->grace_minutes ?? 0,
            $employee?->shift_id ? '' : ' (company default)',
        );
    }

    /**
     * Whether attendance can be judged for the signed-in employee at all.
     *
     * Drives the "Shift not assigned" banner. Reads the same policy as the
     * resolver rather than re-deriving it, so the banner and the engine agree.
     */
    public function getShiftUnassignedProperty(): bool
    {
        $employee = Auth::user()->employee;

        return $employee !== null && ! ShiftResolver::hasResolvableShift($employee);
    }

    public function render()
    {
        return view('attendance.my')
            ->layout('layouts.app', ['title' => 'My Attendance']);
    }
}
