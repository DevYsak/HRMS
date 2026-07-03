<?php

namespace App\Livewire\Attendance;

use App\Enums\AttendanceMode;
use App\Enums\PunchMethod;
use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\AttendanceSetting;
use App\Models\BiometricDevice;
use App\Models\BreakLog;
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
use App\Services\AttendanceService;
use App\Support\UserAgent;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;

class AttendanceTracker extends Component
{
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

    /** Daily working-hours series for the analytics charts (selected period). */
    public array $chartDaily = [];

    /** Current-week (Sun–Sat) day-by-day summary — independent of the calendar/period filters. */
    public array $weekSummary = [];

    /** Chronological punch/break events for today. */
    public array $todayTimeline = [];

    /** Full Attendance Journey — every biometric punch for today, classified. */
    public array $attendanceJourney = [];

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

    // Regularisation form fields
    public bool $regFixIn = false;

    public bool $regFixOut = true;

    public string $regDate = '';

    public string $regCheckIn = '';

    public string $regCheckOut = '';

    public string $regReason = '';

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
            return;
        }

        $this->shift = $employee->shift ?? ShiftSetting::first();
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
        $this->todayTimeline = $this->buildTodayTimeline();
        $this->attendanceJourney = $this->buildAttendanceJourney($employee);

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
     * Chronological check-in / break / check-out events for today.
     *
     * @return array<int, array{time:string, title:string, type:string, lat?:mixed, lng?:mixed, photo?:?string, device?:array}>
     */
    protected function buildTodayTimeline(): array
    {
        if (! $this->todayAttendance || ! $this->todayAttendance->check_in) {
            return [];
        }

        $events = [[
            'time' => $this->todayAttendance->check_in->format('h:i A'),
            'title' => 'Clocked in'.($this->todayAttendance->is_late ? ' (late)' : ''),
            'type' => $this->todayAttendance->is_late ? 'late' : 'in',
            'lat' => $this->todayAttendance->check_in_lat,
            'lng' => $this->todayAttendance->check_in_lng,
            'photo' => $this->todayAttendance->check_in_photo,
            'device' => UserAgent::parse($this->todayAttendance->check_in_user_agent),
            'method' => PunchMethod::tryFrom((string) $this->todayAttendance->check_in_method),
        ]];

        $breaks = BreakLog::where('attendance_id', $this->todayAttendance->id)
            ->orderBy('break_start')->get();
        foreach ($breaks as $b) {
            if ($b->break_start) {
                $events[] = ['time' => Carbon::parse($b->break_start)->format('h:i A'), 'title' => 'Break started', 'type' => 'break'];
            }
            if ($b->break_end) {
                $events[] = ['time' => Carbon::parse($b->break_end)->format('h:i A'), 'title' => 'Resumed work', 'type' => 'resume'];
            }
        }

        if ($this->todayAttendance->check_out) {
            $events[] = [
                'time' => $this->todayAttendance->check_out->format('h:i A'),
                'title' => 'Clocked out',
                'type' => 'out',
                'lat' => $this->todayAttendance->check_out_lat,
                'lng' => $this->todayAttendance->check_out_lng,
                'photo' => $this->todayAttendance->check_out_photo,
                'device' => UserAgent::parse($this->todayAttendance->check_out_user_agent),
                'method' => PunchMethod::tryFrom((string) $this->todayAttendance->check_out_method),
            ];
        }

        return $events;
    }

    /**
     * Full Attendance Journey — every individual biometric punch of the day,
     * classified into Clock In / Break Start / Break End / Clock Out by the
     * alternating in→out sequence. Empty when no per-punch data has synced
     * (the timeline then falls back to first/last via buildTodayTimeline()).
     *
     * @return array<int, array{time:string, title:string, type:string, method:?PunchMethod, source:?string, location:?string, lat:mixed, lng:mixed}>
     */
    protected function buildAttendanceJourney($employee): array
    {
        $punches = AttendancePunch::where('employee_id', $employee->id)
            ->whereDate('punch_date', Carbon::today()->toDateString())
            ->orderBy('punched_at')
            ->get();

        return $this->classifyPunches($punches);
    }

    /**
     * Dedupe + classify a day's raw punches into timeline events.
     * Shared by today's Journey and the Punch Timeline history.
     *
     * @param  Collection<int, AttendancePunch>  $punches
     */
    protected function classifyPunches($punches): array
    {
        if ($punches->isEmpty()) {
            return [];
        }

        // Devices often log one physical action twice (e.g. a card tap and a
        // face verify seconds apart). Collapse punches within a 3-minute window
        // into the first one so the in/out alternation reflects real movement.
        $deduped = collect();
        foreach ($punches as $p) {
            $last = $deduped->last();
            if ($last && $last->punched_at->diffInMinutes($p->punched_at) < 3) {
                continue;
            }
            $deduped->push($p);
        }
        $punches = $deduped->values();

        $n = $punches->count();

        return $punches->map(function (AttendancePunch $p, int $i) use ($n, $punches) {
            $isEntry = $i % 2 === 0;                 // even index = entered / clocked in
            [$title, $type] = match (true) {
                $i === 0 => ['Clocked in', 'in'],
                $i === $n - 1 && ! $isEntry => ['Clocked out', 'out'],
                $isEntry => ['Returned from break', 'resume'],
                default => ['Break started', 'break'],
            };

            // Classify a break by when it starts and how long until the return punch.
            if ($type === 'break') {
                $next = $punches->get($i + 1);
                $gapMin = $next ? (int) $p->punched_at->diffInMinutes($next->punched_at) : null;
                $hour = (int) $p->punched_at->format('G');
                $kind = match (true) {
                    $gapMin === null => 'Break (no return punch)',
                    $gapMin > 90 => 'Long break',
                    $hour >= 12 && $hour < 16 && $gapMin >= 20 => 'Lunch break',
                    $gapMin < 20 => 'Tea break',
                    default => 'Personal break',
                };
                $title = $kind.($gapMin !== null ? " · {$gapMin}m" : '');
            }

            // Minutes since the previous kept punch (shown as "↓ 37 mins").
            $prev = $i > 0 ? $punches->get($i - 1) : null;

            return [
                'time' => $p->punched_at->format('h:i A'),
                'title' => $title,
                'type' => $type,
                'method' => $p->methodEnum()?->value,   // string (Livewire-safe)
                'source' => $p->source,
                'location' => $p->location,
                'device' => $p->device_serial,
                'lat' => $p->lat,
                'lng' => $p->lng,
                'gap_min' => $prev ? (int) $prev->punched_at->diffInMinutes($p->punched_at) : null,
                'verify' => $p->verify_raw,
            ];
        })->all();
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

        $this->logTimeline = $history->map(function ($item) use ($punchesByDay, $breaksByAttendance) {
            $key = $item->date->toDateString();
            $events = $this->classifyPunches($punchesByDay->get($key, collect()));

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

            $workedMin = ($item->check_in && $item->check_out)
                ? max(0, (int) $item->check_in->diffInMinutes($item->check_out) - (int) ($item->break_minutes ?? 0))
                : 0;

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
            if ($this->todayAttendance->check_in && ! $this->todayAttendance->check_out && $shiftOver) {
                $alerts[] = [
                    'type' => 'missing_checkout',
                    'label' => 'Missing Check-Out',
                    'detail' => 'Today · clocked in at '.$this->todayAttendance->check_in->format('h:i A'),
                    'date' => $today->toDateString(),
                    'action' => true,
                ];
            }

            // Break parity from the journey: an unclosed break = missing return punch.
            $breakStarts = collect($this->attendanceJourney)->where('type', 'break')->count();
            $breakEnds = collect($this->attendanceJourney)->where('type', 'resume')->count();
            if ($breakStarts > $breakEnds && $this->todayAttendance->check_out) {
                $alerts[] = [
                    'type' => 'missing_break_end',
                    'label' => 'Missing Break End',
                    'detail' => 'Today · a break-return punch was not recorded',
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

            // Long break (> 90m single gap, from the journey classification).
            if (collect($this->attendanceJourney)->contains(fn ($e) => $e['type'] === 'break' && str_starts_with($e['title'], 'Long break'))) {
                $alerts[] = [
                    'type' => 'long_break',
                    'label' => 'Long Break',
                    'detail' => 'Today · a single break exceeded 90 minutes',
                    'date' => $today->toDateString(),
                    'action' => false,
                ];
            }

            // Overtime worked today (informational).
            $stdMin = (int) round((float) ($this->shift->standard_hours ?? 9) * 60);
            $workedMin = $this->todayAttendance->check_out
                ? max(0, (int) $this->todayAttendance->check_in->diffInMinutes($this->todayAttendance->check_out) - (int) ($this->todayAttendance->break_minutes ?? 0))
                : 0;
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
        $this->loadData(); // restores the month-based log after a custom range
    }

    public function updatedAnalyticsMode(): void
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
        $employee = Auth::user()->employee;
        if ($employee) {
            $this->history = Attendance::where('employee_id', $employee->id)
                ->whereBetween('date', [$this->rangeFrom, $this->rangeTo])
                ->with('regularisation')
                ->orderByDesc('date')
                ->get();
            $this->buildLogTimeline($employee);
        }
    }

    protected function computeStats(): void
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return;
        }

        [$start, $end] = match ($this->statsPeriod) {
            'today' => [Carbon::today(), Carbon::today()],
            'this_week' => [Carbon::now()->startOfWeek(Carbon::SUNDAY), Carbon::now()->endOfWeek(Carbon::SATURDAY)],
            'last_month' => [Carbon::now()->subMonth()->startOfMonth(), Carbon::now()->subMonth()->endOfMonth()],
            '3_months' => [Carbon::now()->subMonths(2)->startOfMonth(), Carbon::now()->endOfMonth()],
            'year' => [Carbon::now()->startOfYear(), Carbon::now()->endOfMonth()],
            'custom' => [
                Carbon::parse($this->rangeFrom ?? now()->startOfMonth()),
                Carbon::parse($this->rangeTo ?? now()),
            ],
            default => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
        };

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

        $totalMinutes = $attendances->sum(function ($a) {
            if ($a->check_in && $a->check_out) {
                return $a->check_in->diffInMinutes($a->check_out) - ($a->break_minutes ?? 0);
            }

            return 0;
        });

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

        $excessBreaks = $attendances->where('break_minutes', '>', 60)->count();
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

        $this->analytics = [
            'shift_compliance' => $workingBasis > 0 ? (int) round($onTime / $workingBasis * 100) : 100,
            'attendance_score' => (int) round($onTimePct * 0.6 + $presentPct * 0.25 + $breakPct * 0.15),
            'office_days' => $officeDays,
            'wfh_days' => $wfhDays,
            'avg_break' => $avgBreak,
            'excess_breaks' => $excessBreaks,
            'late_trend' => $lateTrend,
            'mode_breakdown' => $modeBreakdown,
        ];

        // ── Daily working-hours series for the trend chart (period up to today) ──
        $seriesEnd = $end->copy()->min(Carbon::today());
        $daily = [];
        if ($start <= $seriesEnd) {
            foreach (CarbonPeriod::create($start, $seriesEnd) as $d) {
                $att = $attendanceDates->get($d->toDateString());
                $hours = 0.0;
                if ($att && $att->check_in && $att->check_out) {
                    $mins = $att->check_in->diffInMinutes($att->check_out) - ($att->break_minutes ?? 0);
                    $hours = round(max(0, $mins) / 60, 1);
                }
                $daily[] = ['label' => $d->format('d M'), 'hours' => $hours, 'break' => $att ? (int) ($att->break_minutes ?? 0) : 0, 'late' => (bool) ($att?->is_late)];
            }
        }
        $this->chartDaily = $daily;

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
            $insights[] = ['good' => $longestBreak <= 60, 'text' => 'Longest break '.($longestBreak >= 60 ? intdiv($longestBreak, 60).'h '.($longestBreak % 60).'m' : $longestBreak.' mins')];
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

        // Trend vs the previous period of the same length.
        $periodDays = max(1, (int) $start->diffInDays($end) + 1);
        $prevStart = $start->copy()->subDays($periodDays);
        $prevEnd = $start->copy()->subDay();
        $prev = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$prevStart->toDateString(), $prevEnd->toDateString()])
            ->when($this->analyticsMode !== '', fn ($q) => $q->where('work_mode', $this->analyticsMode))
            ->get(['check_in', 'is_late']);
        $prevPresent = $prev->whereNotNull('check_in')->count();
        $prevLate = $prev->where('is_late', true)->count();

        $fmtTime = fn (?int $m) => $m === null ? null : sprintf('%02d:%02d %s', (intdiv($m, 60) % 12) ?: 12, $m % 60, $m < 720 ? 'AM' : 'PM');

        $suggestions = [];
        $suggestions[] = $this->analytics['attendance_score'] >= 85
            ? ['good' => true, 'text' => 'Great attendance — keep it up']
            : ['good' => false, 'text' => 'Focus on attendance consistency this period'];
        if ($avgBreak > 60) {
            $suggestions[] = ['good' => false, 'text' => 'Improve break duration — average exceeds 60 minutes'];
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

        $shift = $this->shift instanceof ShiftSetting ? $this->shift : ShiftSetting::first();

        if (! $shift) {
            \Flux::toast('No shift configured. Contact HR to set up your shift.', variant: 'danger');

            return;
        }

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
            // Audit History — every regularisation touching this day.
            'audits' => AttendanceRegularisation::where('employee_id', $employee->id)
                ->whereDate('work_date', $date)
                ->with('reviewer')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($r) => [
                    'requested_in' => Carbon::parse($r->requested_check_in)->format('h:i A'),
                    'requested_out' => Carbon::parse($r->requested_check_out)->format('h:i A'),
                    'reason' => $r->reason,
                    'status' => $r->status,
                    'reviewer' => $r->reviewer?->name,
                    'reviewed_at' => $r->reviewed_at?->format('d M Y h:i A'),
                    'submitted_at' => $r->created_at?->format('d M Y h:i A'),
                ])->all(),
        ];

        $this->dispatch('flux:modal:open', name: 'punch-detail');
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

        $this->dispatch('flux:modal:open', name: 'regularisation-modal');
    }

    public function submitRegularisation()
    {
        $this->validate([
            'regDate' => 'required|date',
            'regCheckIn' => $this->regFixIn ? 'required' : 'nullable',
            'regCheckOut' => $this->regFixOut ? 'required' : 'nullable',
            'regReason' => 'required|min:5',
        ], [
            'regCheckIn.required' => 'Enter the correct check-in time.',
            'regCheckOut.required' => 'Enter the correct check-out time.',
        ]);

        if (! $this->regFixIn && ! $this->regFixOut) {
            \Flux::toast('Tick at least one punch to correct (check-in or check-out).', variant: 'warning');

            return;
        }

        $employee = Auth::user()->employee;
        $attendance = Attendance::where('employee_id', $employee->id)
            ->where('date', $this->regDate)
            ->first();

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

        $regularisation = AttendanceRegularisation::create([
            'employee_id' => $employee->id,
            'attendance_id' => $attendance?->id,
            'work_date' => $this->regDate,
            'requested_check_in' => $this->regDate.' '.$requestedIn.':00',
            'requested_check_out' => $this->regDate.' '.$requestedOut.':00',
            'reason' => $this->regReason,
            'status' => 'pending',
        ]);

        // Notify the manager AND HR/Admin so either can approve.
        $notification = new AttendanceRegularisationNotification(
            Auth::user()->name,
            Carbon::parse($this->regDate)->format('d M Y'),
            'pending',
        );
        $approvers = User::whereIn('role', ['hr_admin', 'super_admin'])->get();
        if ($employee->manager) {
            $approvers->push($employee->manager);
        }
        $approvers->unique('id')->each(fn ($u) => $u->notify($notification));

        // Notify the employee themselves so the request appears in their inbox
        Auth::user()->notify(new RegularisationReviewedNotification($regularisation));

        $this->reset(['regDate', 'regCheckIn', 'regCheckOut', 'regReason', 'regFixIn', 'regFixOut']);
        $this->dispatch('flux:modal:close', name: 'regularisation-modal');
        \Flux::toast('Regularisation request sent to your manager & HR for approval.');
    }

    protected function buildShiftLabel(): ?string
    {
        $employee = Auth::user()->employee;

        // Path 1 — employee has shift_id linked to a ShiftSetting record
        $shiftSetting = $employee->shift ?? null;
        if ($shiftSetting && $shiftSetting->start_time && $shiftSetting->end_time) {
            return sprintf(
                '%s: %s – %s IST | Grace: %d mins',
                $shiftSetting->name ?? 'Shift',
                Carbon::parse($shiftSetting->start_time)->format('g:i A'),
                Carbon::parse($shiftSetting->end_time)->format('g:i A'),
                $shiftSetting->grace_minutes ?? 5,
            );
        }

        // Path 2 — try DB lookup by shift code before using hardcoded fallbacks
        $shiftCode = $employee->getAttribute('shift_code') ?? null;
        if ($shiftCode) {
            $found = ShiftSetting::where('name', 'like', '%'.trim($shiftCode).'%')->first();
            if ($found) {
                return sprintf(
                    '%s: %s – %s IST | Grace: %d mins',
                    $found->name,
                    Carbon::parse($found->start_time)->format('g:i A'),
                    Carbon::parse($found->end_time)->format('g:i A'),
                    $found->grace_minutes ?? 5,
                );
            }

            return match (strtolower(trim($shiftCode))) {
                'it' => 'IT Shift: 10:30 AM – 7:30 PM IST | Grace: 5 mins',
                'uk' => 'UK Sales Shift: 1:00 PM – 10:00 PM IST | Grace: 5 mins',
                default => null,
            };
        }

        // Path 3 — global fallback from AttendanceSetting
        if ($this->attendanceSettings?->shift_start && $this->attendanceSettings?->shift_end) {
            return sprintf(
                'Default Shift: %s – %s IST',
                Carbon::parse($this->attendanceSettings->shift_start)->format('g:i A'),
                Carbon::parse($this->attendanceSettings->shift_end)->format('g:i A'),
            );
        }

        return null;
    }

    public function render()
    {
        return view('attendance.my')
            ->layout('layouts.app', ['title' => 'My Attendance']);
    }
}
