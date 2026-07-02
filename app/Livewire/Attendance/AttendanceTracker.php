<?php

namespace App\Livewire\Attendance;

use App\Enums\AttendanceMode;
use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AttendanceSetting;
use App\Models\BreakLog;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Notifications\AttendanceRegularisationNotification;
use App\Notifications\RegularisationReviewedNotification;
use App\Services\AiAssistant;
use App\Services\AttendanceService;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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

    // Regularisation form fields
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
     * @return array<int, array{time:string, title:string, type:string, lat?:mixed, lng?:mixed, photo?:?string}>
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
            ];
        }

        return $events;
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
        $this->computeStats();
    }

    protected function computeStats(): void
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return;
        }

        [$start, $end] = match ($this->statsPeriod) {
            'this_week' => [Carbon::now()->startOfWeek(Carbon::SUNDAY), Carbon::now()->endOfWeek(Carbon::SATURDAY)],
            'last_month' => [Carbon::now()->subMonth()->startOfMonth(), Carbon::now()->subMonth()->endOfMonth()],
            '3_months' => [Carbon::now()->subMonths(2)->startOfMonth(), Carbon::now()->endOfMonth()],
            'year' => [Carbon::now()->startOfYear(), Carbon::now()->endOfMonth()],
            default => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
        };

        $attendances = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
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

    public function openRegularisation($date)
    {
        $this->regDate = $date;
        $attendance = Attendance::where('employee_id', Auth::user()->employee->id)->where('date', $date)->first();
        if ($attendance) {
            $this->regCheckIn = $attendance->check_in?->format('H:i') ?? '';
            $this->regCheckOut = $attendance->check_out?->format('H:i') ?? '';
        }
        $this->dispatch('flux:modal:open', name: 'regularisation-modal');
    }

    public function submitRegularisation()
    {
        $this->validate([
            'regDate' => 'required|date',
            'regCheckIn' => 'required',
            'regCheckOut' => 'required',
            'regReason' => 'required|min:5',
        ]);

        $employee = Auth::user()->employee;
        $attendance = Attendance::where('employee_id', $employee->id)
            ->where('date', $this->regDate)
            ->first();

        $regularisation = AttendanceRegularisation::create([
            'employee_id' => $employee->id,
            'attendance_id' => $attendance?->id,
            'work_date' => $this->regDate,
            'requested_check_in' => $this->regDate.' '.$this->regCheckIn.':00',
            'requested_check_out' => $this->regDate.' '.$this->regCheckOut.':00',
            'reason' => $this->regReason,
            'status' => 'pending',
        ]);

        // Notify manager (or HR) about the regularisation request
        $notification = new AttendanceRegularisationNotification(
            Auth::user()->name,
            Carbon::parse($this->regDate)->format('d M Y'),
            'pending',
        );
        $manager = $employee->manager;
        if ($manager) {
            $manager->notify($notification);
        } else {
            User::whereIn('role', ['hr_admin', 'super_admin'])
                ->each(fn ($hr) => $hr->notify($notification));
        }

        // Notify the employee themselves so the request appears in their inbox
        Auth::user()->notify(new RegularisationReviewedNotification($regularisation));

        $this->reset(['regDate', 'regCheckIn', 'regCheckOut', 'regReason']);
        $this->dispatch('flux:modal:close', name: 'regularisation-modal');
        \Flux::toast('Regularisation request submitted successfully.');
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
