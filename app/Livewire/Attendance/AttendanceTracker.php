<?php

namespace App\Livewire\Attendance;

use App\Enums\AttendanceMode;
use App\Enums\PunchMethod;
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
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use App\Models\Task;
use App\Models\User;
use App\Models\WfhReport;
use App\Notifications\AttendanceRegularisationNotification;
use App\Notifications\RegularisationReviewedNotification;
use App\Services\AiAssistant;
use App\Services\Attendance\AttendanceCoach;
use App\Services\Attendance\AttendanceScoreEngine;
use App\Services\Attendance\PunchTimeline as PunchTimelineEngine;
use App\Services\Attendance\ShiftResolver;
use App\Services\AttendanceService;
use App\Support\UserAgent;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class AttendanceTracker extends Component
{
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

        $holidays = PublicHoliday::whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->get();

        // 3. Process data in memory
        $attendanceMap = $allAttendances->keyBy(fn ($a) => $a->date->toDateString());
        $holidayMap = $holidays->keyBy(fn ($h) => Carbon::parse($h->date)->toDateString());

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
                $status = ($att->status === 'late' || $att->is_late) ? 'late' : 'present';
            } elseif (isset($leaveMap[$dateKey])) {
                $status = 'leave';
            } elseif (isset($holidayMap[$dateKey])) {
                $status = 'holiday';
            } elseif ($d->isWeekend()) {
                $status = 'weekend';
            } elseif ($d->isFuture()) {
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
        $this->monthHolidays = $holidays->filter(fn ($h) => Carbon::parse($h->date)->month === $start->month &&
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
            ->get(['date', 'synced_at', 'raw_punch_count'])
            ->map(fn ($s) => [
                'date' => $s->date->format('d M'),
                'synced' => $s->synced_at->format('h:i A'),
                'punches' => (int) $s->raw_punch_count,
            ])->all();

        $this->attendanceAlerts = $this->buildAttendanceAlerts();

        // 11b. Day-grouped punch timeline for the log section
        $this->buildLogTimeline($employee);

        // 12. Total available leave balance (current year)
        $this->leaveBalance = LeaveBalance::where('employee_id', $employee->id)
            ->where('year', now()->year)
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
            if ($cursor->isSunday()) {
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

        $holidayDays = PublicHoliday::whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get()->keyBy(fn ($h) => Carbon::parse($h->date)->toDateString());

        $summary = [];
        foreach (CarbonPeriod::create($weekStart, $weekEnd) as $d) {
            $key = $d->toDateString();
            $att = $attendances->get($key);
            $hours = 0.0;
            $status = 'absent';
            $mode = null;

            if ($att) {
                $mode = $att->work_mode ?? 'office';
                $status = ($att->status === 'late' || $att->is_late) ? 'late' : 'present';
                if ($att->check_in && $att->check_out) {
                    $mins = $att->check_in->diffInMinutes($att->check_out) - (int) ($att->break_minutes ?? 0);
                    $hours = round(max(0, $mins) / 60, 1);
                }
            } elseif (isset($leaveDays[$key])) {
                $status = 'leave';
            } elseif ($holidayDays->has($key)) {
                $status = 'holiday';
            } elseif ($d->isWeekend()) {
                $status = 'weekend';
            } elseif ($d->isFuture()) {
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
                ? max(0, (int) $item->check_in->diffInMinutes($item->check_out) - (int) ($item->break_minutes ?? 0))
                : 0);

            return [
                'date' => $key,
                'label' => $item->date->format('d M Y'),
                'dayname' => $item->date->format('l'),
                'is_today' => $item->date->isToday(),
                'status' => $item->status,
                'is_late' => (bool) $item->is_late,
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

        // ── Today ──
        if ($this->todayAttendance) {
            // Today's missing punches are owned by the Attendance Journey banner
            // (single place per issue) — only alert here when the journey has no
            // punch data to surface it itself.
            $journeyOwnsToday = ($this->punchJourney['raw_count'] ?? 0) > 0;
            if (! $journeyOwnsToday && $this->todayAttendance->check_in && ! $this->todayAttendance->check_out && $shiftOver) {
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

            // Overtime worked today — from validated sessions, never raw events.
            $stdMin = (int) round((float) ($this->shift->standard_hours ?? 9) * 60);
            $workedMin = $journeyOwnsToday
                ? (int) ($this->punchJourney['working_minutes'] ?? 0)
                : ($this->todayAttendance->check_out
                    ? max(0, (int) $this->todayAttendance->check_in->diffInMinutes($this->todayAttendance->check_out) - (int) ($this->todayAttendance->break_minutes ?? 0))
                    : 0);
            if ($workedMin > $stdMin + 30) {
                $otMin = $workedMin - $stdMin;
                $alerts[] = [
                    'type' => 'overtime',
                    'label' => 'Overtime Worked',
                    'detail' => 'Today · '.intdiv($otMin, 60).'h '.($otMin % 60).'m beyond your standard day',
                    'date' => $today->toDateString(),
                    'action' => false,
                ];
            }
        } elseif ($shiftOver && ! $today->isWeekend() && $this->workMode !== 'wfh') {
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
        if ($sync && Carbon::parse($sync)->lt(now()->subMinutes(30)) && ! $today->isWeekend()) {
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

    /**
     * The [start, end] window the current stats period resolves to — the ONE
     * range every filtered section (stats, charts, history, insights) uses.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function periodRange(): array
    {
        return match ($this->statsPeriod) {
            'today' => [Carbon::today(), Carbon::today()],
            'this_week' => [Carbon::now()->startOfWeek(Carbon::SUNDAY), Carbon::now()->endOfWeek(Carbon::SATURDAY)],
            'last_month' => [Carbon::now()->subMonth()->startOfMonth(), Carbon::now()->subMonth()->endOfMonth()],
            'quarter' => [Carbon::now()->firstOfQuarter(), Carbon::now()->endOfMonth()],
            '3_months' => [Carbon::now()->subMonths(2)->startOfMonth(), Carbon::now()->endOfMonth()],
            'year' => [Carbon::now()->startOfYear(), Carbon::now()->endOfMonth()],
            'custom' => [
                Carbon::parse($this->rangeFrom ?? now()->startOfMonth()),
                Carbon::parse($this->rangeTo ?? now()),
            ],
            default => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
        };
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

        $metrics = [];
        foreach ($punchesByDay as $day => $dayPunches) {
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

        [$start, $end] = $this->periodRange();

        $this->tasksCompletedPeriod = Task::where('employee_id', $employee->id)
            ->completed()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->count();

        $attendances = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($this->analyticsMode !== '', fn ($q) => $q->where('work_mode', $this->analyticsMode))
            ->get();

        $leaves = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->get();

        $holidays = PublicHoliday::whereBetween('date', [$start->toDateString(), $end->toDateString()])->get();

        // Build lookup maps
        $attendanceDates = $attendances->keyBy(fn ($a) => $a->date->toDateString());
        $holidayDates = $holidays->keyBy(fn ($h) => Carbon::parse($h->date)->toDateString());
        $leaveMap = [];
        foreach ($leaves as $l) {
            $lPeriod = CarbonPeriod::create($l->start_date, $l->end_date);
            foreach ($lPeriod as $d) {
                $leaveMap[$d->toDateString()] = true;
            }
        }

        // Count absent: past weekdays with no attendance, leave, or holiday
        $absentCount = 0;
        $cutoff = Carbon::today()->subDay(); // exclude today (may still clock in)
        if ($start <= $cutoff) {
            $absentPeriod = CarbonPeriod::create($start, min($end, $cutoff));
            foreach ($absentPeriod as $d) {
                $dateKey = $d->toDateString();
                if ($d->dayOfWeek !== Carbon::SUNDAY && $d->dayOfWeek !== Carbon::SATURDAY
                    && ! isset($attendanceDates[$dateKey])
                    && ! isset($leaveMap[$dateKey])
                    && ! isset($holidayDates[$dateKey])) {
                    $absentCount++;
                }
            }
        }

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
                return max(0, $a->check_in->diffInMinutes($a->check_out) - ($a->break_minutes ?? 0));
            }

            return 0;
        };
        $totalMinutes = $attendances->sum($minutesForDay);

        $this->stats = [
            'present' => $attendances->where('status', 'on_time')->count() + $attendances->where('status', 'late')->count(),
            'late' => $attendances->where('is_late', true)->count(),
            'hours' => floor($totalMinutes / 60).'h '.($totalMinutes % 60).'m',
            'leaves' => $leaves->count(),
            'absent' => $absentCount,
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

        // Break allowance comes from the shift (break_duration), not a literal.
        $breakAllowance = (int) ($this->shift->break_duration ?? 60);
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
                while ($cursor->isSunday()) {
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
                        $mins = $att->check_in->diffInMinutes($att->check_out) - ($att->break_minutes ?? 0);
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

        $stdH = (float) ($this->shift->standard_hours ?? 9);
        $totalOtMin = (int) round(collect($daily)->sum(fn ($d) => max(0, (float) $d['hours'] - $stdH)) * 60);
        if ($totalOtMin > 0) {
            $insights[] = ['good' => true, 'text' => 'Total overtime '.intdiv($totalOtMin, 60).'h '.($totalOtMin % 60).'m this period'];
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
        $fullWorkDays = max(1, (int) $start->diffInDaysFiltered(fn ($d) => ! $d->isSunday(), $end->copy()->endOfDay()));
        $remainingDays = Carbon::tomorrow()->lte($end)
            ? (int) Carbon::tomorrow()->diffInDaysFiltered(fn ($d) => ! $d->isSunday(), $end->copy()->endOfDay())
            : 0;
        $predictedPct = min(100, (int) round(($present + $remainingDays) / $fullWorkDays * 100));

        // Trend vs the selected comparison window (GA4-style): previous period
        // of the same length, the previous month, or the same period last year.
        $periodDays = max(1, (int) $start->diffInDays($end) + 1);
        [$prevStart, $prevEnd] = match ($this->compareMode) {
            'last_month' => [$start->copy()->subMonthNoOverflow(), $end->copy()->subMonthNoOverflow()],
            'last_year' => [$start->copy()->subYear(), $end->copy()->subYear()],
            default => [$start->copy()->subDays($periodDays), $start->copy()->subDay()],
        };
        $prev = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
            ->when($this->analyticsMode !== '', fn ($q) => $q->where('work_mode', $this->analyticsMode))
            ->get(['check_in', 'check_out', 'break_minutes', 'is_late']);
        $prevPresent = $prev->whereNotNull('check_in')->count();
        $prevLate = $prev->where('is_late', true)->count();
        $prevMinutes = (int) $prev->sum(fn ($a) => $a->check_in && $a->check_out
            ? max(0, $a->check_in->diffInMinutes($a->check_out) - ($a->break_minutes ?? 0))
            : 0);
        $prevOnTimePct = $prevPresent > 0 ? (int) round(max(0, $prevPresent - $prevLate) / $prevPresent * 100) : null;

        // Real deltas for the KPI band — null delta means "no basis to compare"
        // and the UI hides the trend chip rather than inventing one.
        $this->comparison = [
            'label' => match ($this->compareMode) {
                'last_month' => 'vs last month',
                'last_year' => 'vs last year',
                default => 'vs previous period',
            },
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
                    $r->total_hours,
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
            'total_hours' => $att->total_hours,
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

        $this->validate([
            'regDate' => 'required|date',
            'regType' => 'required|in:punch,half_day',
            'regHalfDayPeriod' => $isHalfDay ? 'required|in:first,second' : 'nullable',
            'regCheckIn' => $isHalfDay ? 'nullable' : ($this->regFixIn ? 'required' : 'nullable'),
            'regCheckOut' => $isHalfDay ? 'nullable' : ($this->regFixOut ? 'required' : 'nullable'),
            'regReason' => 'required|min:5',
            'regAttachment' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,pdf,webp',
        ], [
            'regCheckIn.required' => 'Enter the correct check-in time.',
            'regCheckOut.required' => 'Enter the correct check-out time.',
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
            'stage' => 'manager_review',
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

            $payload['requested_check_in'] = $this->regDate.' '.$requestedIn.':00';
            $payload['requested_check_out'] = $this->regDate.' '.$requestedOut.':00';
            $payload['check_in_method'] = in_array($this->regCheckInMethod, ['face', 'id_card'], true) ? $this->regCheckInMethod : 'id_card';
            $payload['check_out_method'] = in_array($this->regCheckOutMethod, ['face', 'id_card'], true) ? $this->regCheckOutMethod : 'id_card';
        }

        $regularisation = AttendanceRegularisation::create($payload);

        // Notify the manager AND HR/Admin so either can approve. Notifications
        // are best-effort: the request is already saved, so a mail-transport
        // failure (e.g. SMTP timeout) must not 500 the employee's submit. The
        // in-app database channel is written before mail, so the inbox still
        // updates even when the email send throws.
        try {
            $notification = new AttendanceRegularisationNotification(
                Auth::user()->name,
                Carbon::parse($this->regDate)->format('d M Y'),
                'pending',
            );
            // Route to HR whose department/shift scope covers this employee
            // (company-wide HR + super admins always included), plus the manager.
            $approvers = User::whereIn('role', ['hr_admin', 'super_admin'])->get()
                ->filter(fn ($u) => $u->coversEmployee($employee));
            if ($employee->manager) {
                $approvers->push($employee->manager);
            }
            $approvers->unique('id')->each(fn ($u) => $u->notify($notification));

            // Notify the employee themselves so the request appears in their inbox
            Auth::user()->notify(new RegularisationReviewedNotification($regularisation));
        } catch (\Throwable $e) {
            report($e); // logged for diagnosis; the regularisation is saved regardless
        }

        $this->reset(['regDate', 'regCheckIn', 'regCheckOut', 'regReason', 'regFixIn', 'regFixOut', 'regAttachment', 'regType', 'regHalfDayPeriod']);
        $this->modal('regularisation-modal')->close();
        \Flux::toast('Regularisation request sent to your manager & HR for approval.');
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
