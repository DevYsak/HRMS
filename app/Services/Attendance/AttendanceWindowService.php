<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Attendance over a date window for one employee — the figures My Attendance
 * shows (present, late, absent, leave, hours) and the per-day breakdown the
 * Employee 360° page draws, from one definition.
 *
 * Every day is classified once by WorkingDayResolver (holiday > MDL > weekly
 * off > leave > working day, within employment) and every day's minutes come
 * from AttendanceCalculator — never raw check_in → check_out math when punch
 * data exists.
 */
class AttendanceWindowService
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $metricsCache = [];

    public function __construct(
        private WorkingDayResolver $workingDays,
        private AttendanceCalculator $calculator,
        private PunchTimeline $timeline,
    ) {}

    /**
     * Counts for the window. Present = attended scheduled working days
     * (an optional work mode narrows it). Absent = a past scheduled working
     * day (not a holiday, MDL date, weekly off, leave, or outside employment)
     * with no attendance of ANY mode — a mode filter never turns another
     * mode's day into an absence, and weekends are never absences.
     *
     * @param  array<string, array<string, mixed>>|null  $metrics  day metrics already computed for this window
     * @return array{present: int, late: int, minutes: int, absent: int, leave_days: float, weekly_off_worked: int, half_days: int, scheduled: int, has_rows: bool}
     */
    public function summary(Employee $employee, Carbon $start, Carbon $end, string $mode = '', ?array $metrics = null): array
    {
        $states = $this->workingDays->classifyRange($employee, $start, $end);

        $rows = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();
        $anyMode = $rows->keyBy(fn ($a) => $a->date->toDateString());
        $filtered = $mode !== '' ? $rows->where('work_mode', $mode) : $rows;

        $metrics ??= $this->dayMetrics($employee, $start, $end->copy()->min(Carbon::today()));
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
            'late' => $onScheduled->filter(fn ($a) => $this->isLate($a, $metrics))->count(),
            'minutes' => (int) $filtered->sum($minutesFor),
            'absent' => $absent,
            'leave_days' => round($leaveDays, 1),
            'weekly_off_worked' => $filtered->filter(fn ($a) => $a->check_in && ! $scheduledState($states[$a->date->toDateString()] ?? null))->count(),
            'half_days' => $onScheduled->where('status', 'half_day')->count(),
            'scheduled' => $scheduled,
            'has_rows' => $rows->isNotEmpty(),
        ];
    }

    /**
     * One row per calendar day of the window, classified with the same rules
     * as summary(): `status` is present | late | absent | weekly_off |
     * holiday | leave | upcoming | not_employed, so counting a status here
     * gives the matching summary() figure.
     *
     * @return array<string, array{date: string, state: string, status: string, worked: int, first_in_min: ?int, last_out_min: ?int, missing_out: bool, late_minutes: int, mode: ?string, leave_days: float, attended: bool}>
     */
    public function days(Employee $employee, Carbon $start, Carbon $end): array
    {
        $states = $this->workingDays->classifyRange($employee, $start, $end);
        $rows = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn ($a) => $a->date->toDateString());
        $metrics = $this->dayMetrics($employee, $start, $end->copy()->min(Carbon::today()));
        $cutoff = Carbon::today()->subDay();

        $days = [];
        foreach ($states as $key => $day) {
            $row = $rows->get($key);
            $attended = (bool) $row?->check_in;
            $scheduled = in_array($day['state'], [WorkingDayResolver::WORKING_DAY, WorkingDayResolver::APPROVED_LEAVE], true);
            $m = $metrics[$key] ?? null;

            $status = match (true) {
                $attended && $scheduled => $this->isLate($row, $metrics) ? 'late' : 'present',
                $day['state'] === WorkingDayResolver::PUBLIC_HOLIDAY, $day['state'] === WorkingDayResolver::MDL_SHUTDOWN => 'holiday',
                $day['state'] === WorkingDayResolver::WEEKLY_OFF => 'weekly_off',
                $day['state'] === WorkingDayResolver::APPROVED_LEAVE => 'leave',
                $day['state'] === WorkingDayResolver::WORKING_DAY => Carbon::parse($key)->lte($cutoff) ? 'absent' : 'upcoming',
                default => 'not_employed',
            };

            $days[$key] = [
                'date' => $key,
                'state' => $day['state'],
                'status' => $status,
                'worked' => $m !== null ? (int) $m['worked'] : ($row && $row->check_in && $row->check_out ? $this->rowWorkedMinutes($row) : 0),
                'first_in_min' => $m['first_in_min'] ?? null,
                'last_out_min' => $m['last_out_min'] ?? null,
                'missing_out' => (bool) ($m['missing_out'] ?? false),
                'late_minutes' => $status === 'late' ? (int) ($m['late_minutes'] ?? $row?->late_minutes ?? 0) : 0,
                'mode' => $row?->work_mode,
                'leave_days' => (float) $day['leave_days'],
                'attended' => $attended,
            ];
        }

        return $days;
    }

    /**
     * Late by the calculator (shift start + grace) when the day was computed;
     * the stored flag only for a row the calculator did not see.
     *
     * @param  array<string, array<string, mixed>>  $metrics
     */
    private function isLate(Attendance $attendance, array $metrics): bool
    {
        $late = $metrics[$attendance->date->toDateString()]['late'] ?? null;

        return $late !== null ? (bool) $late : ($attendance->is_late || $attendance->status === 'late');
    }

    /**
     * Worked minutes of an attendance row: final clock-out − first clock-in
     * (Pulse v3.1, breaks never deducted). 0 while the day is still open.
     */
    public function rowWorkedMinutes(?Attendance $attendance): int
    {
        return $attendance ? $this->calculator->spanMinutes($attendance->check_in, $attendance->check_out) : 0;
    }

    /**
     * Every day in the range with punches or a clock-in, processed through
     * the PunchTimeline engine — the same engine that renders the journey.
     * Worked, break, first-in, final-out and missing-checkout per day come
     * from AttendanceCalculator — the same calculation as Worked Today, so a
     * day reads the same everywhere (live to now, nothing past the
     * missing-checkout cutoff, the stored shift end for an auto checkout,
     * HR's corrected times for a regularised day). The timeline supplies
     * display detail only (sessions, ignored scans, duplicates). Days without
     * punch rows or a clock-in are absent from the result.
     *
     * @return array<string, array{worked: int, break: int, sessions: array<int, array<string, mixed>>, ignored: array<int, array<string, mixed>>, ignored_count: int, duplicate_count: int, first_in_min: ?int, last_out_min: ?int, missing_out: bool, late: ?bool, late_minutes: ?int}>
     */
    public function dayMetrics(Employee $employee, Carbon $start, Carbon $end): array
    {
        $cacheKey = $employee->id.'|'.$start->toDateString().'|'.$end->toDateString();
        if (isset($this->metricsCache[$cacheKey])) {
            return $this->metricsCache[$cacheKey];
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

        $toMinutes = function (?string $formatted): ?int {
            if (! $formatted) {
                return null;
            }
            $t = Carbon::parse($formatted);

            return $t->hour * 60 + $t->minute;
        };

        $rows = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn ($a) => $a->date->toDateString());
        $days = collect($punchesByDay->keys())
            ->merge($rows->filter(fn ($a) => $a->check_in !== null)->keys())
            ->unique();

        // One approved-leave lookup for the window, not one per attended day.
        $this->calculator->primeLeaveRange($employee, $start, $end);

        $metrics = [];
        foreach ($days as $day) {
            $dayPunches = $punchesByDay->get($day, collect());
            $r = $dayPunches->isNotEmpty() ? $this->timeline->process($dayPunches, Carbon::parse($day), $summaries->get($day)) : null;
            $calc = $this->calculator->forDay($employee, $day, $rows->get($day), $dayPunches, hasApprovedOt: false);
            $metrics[$day] = [
                'worked' => $calc->workedMinutes,
                'break' => $calc->breakMinutes,
                'sessions' => $r['sessions'] ?? [],
                'ignored' => $r['ignored'] ?? [],
                'ignored_count' => (int) ($r['ignored_count'] ?? 0),
                'duplicate_count' => (int) ($r['duplicate_count'] ?? 0) + (int) ($r['conflict_count'] ?? 0),
                'first_in_min' => $calc->firstIn ? $toMinutes($calc->firstIn->format('H:i')) : null,
                'last_out_min' => $calc->lastOut ? $toMinutes($calc->lastOut->format('H:i')) : null,
                'missing_out' => $calc->missingCheckout,
                // The calculator's verdict (shift start + grace) — the same
                // lateness Team View, the HR dashboard and AI count today. Null
                // when no shift resolves: it cannot judge, the stored flag stands.
                'late' => $calc->shift !== null ? $calc->isLate : null,
                'late_minutes' => $calc->shift !== null ? $calc->lateMinutes : null,
            ];
        }

        return $this->metricsCache[$cacheKey] = $metrics;
    }
}
