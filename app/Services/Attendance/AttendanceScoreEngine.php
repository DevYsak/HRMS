<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceDailyScore;
use App\Models\AttendancePunch;
use App\Models\AttendanceScoreSetting;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Attendance Score Engine (Rule 11).
 *
 * Produces one 0–100 score per employee per working day, from the same
 * PunchTimeline + ResolvedShift pipeline every other attendance figure uses.
 * Every deduction/bonus is persisted as a breakdown line — the audit trail the
 * "Why?" decision popup renders. Weights are HR-configurable
 * (attendance_score_settings); nothing is hardcoded.
 *
 * Day semantics:
 *  - Worked day     → 100 minus deductions plus bonuses (floored 0, capped 100).
 *  - Absent workday → 0 with an `absent` line.
 *  - Leave/holiday/weekend without work → no score row (excluded from averages);
 *    working a holiday/weekend earns the holiday-work bonus instead.
 */
class AttendanceScoreEngine
{
    public function __construct(
        protected ShiftResolver $shifts,
        protected PunchTimeline $timeline,
        protected HolidayResolver $holidays,
    ) {}

    /**
     * Score one day and persist it. Returns null for days that shouldn't be
     * scored (future, leave/holiday/weekend without any work).
     */
    public function scoreDay(Employee $employee, CarbonInterface|string $date): ?AttendanceDailyScore
    {
        $day = $this->normalize($date);
        if ($day->isFuture() || $day->isToday()) {
            return null;    // only closed days are scored (today is still in motion)
        }

        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('date', $day->toDateString())
            ->first();

        $onLeave = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $day->toDateString())
            ->where('end_date', '>=', $day->toDateString())
            ->exists();
        // Whose holiday? This asked "is it a holiday for anyone", so a UK bank
        // holiday excused an India employee's absence and vice versa.
        $isHoliday = $this->holidays->isHoliday($employee, $day);
        $isOffDay = $onLeave || $isHoliday || AttendanceSetting::isWeeklyOff($day);

        if (! $attendance || ! $attendance->check_in) {
            if ($isOffDay) {
                return null;    // legitimate non-working day — not scored
            }

            return $this->persist($employee, $day, 0.0, 'absent', [[
                'factor' => 'absent',
                'label' => 'Absent — no attendance recorded on a working day',
                'points' => -100.0,
                'detail' => 'No punch, approved leave, or holiday covers this day.',
            ]]);
        }

        $settings = AttendanceScoreSetting::current();
        $shift = $this->shifts->resolve($employee, $day);

        // Engine-processed punch metrics for the day (validated sessions).
        $punches = AttendancePunch::where('employee_id', $employee->id)
            ->whereDate('punch_date', $day->toDateString())
            ->orderBy('punched_at')
            ->get();
        $metrics = $punches->isNotEmpty() ? $this->timeline->process($punches, $day) : null;

        $workedMin = $metrics['working_minutes']
            ?? (($attendance->check_out)
                ? max(0, (int) $attendance->check_in->diffInMinutes($attendance->check_out) - (int) ($attendance->break_minutes ?? 0))
                : 0);
        $breakMin = $metrics['break_minutes'] ?? (int) ($attendance->break_minutes ?? 0);

        $lines = [];
        $score = 100.0;
        $apply = function (string $factor, string $label, float $points, string $detail) use (&$lines, &$score): void {
            if ($points == 0.0) {
                return;
            }
            $lines[] = compact('factor', 'label', 'points', 'detail');
            $score += $points;
        };

        // Late arrival — flat penalty plus a per-30-minutes surcharge.
        if ($attendance->is_late) {
            $lateMin = (int) ($attendance->late_minutes ?? 0);
            $penalty = $settings->late_arrival_penalty + intdiv($lateMin, 30) * $settings->late_per_30m_penalty;
            $apply('late_arrival', 'Late arrival', -$penalty,
                $lateMin.'m past the grace cutoff'.($shift ? ' ('.$shift->lateCutoff()->format('h:i A').')' : '').'.');
        }

        // Early exit — left before shift end minus grace (real OUT only).
        if ($shift && $attendance->check_out && ! $attendance->is_auto_checkout) {
            $earlyBoundary = $shift->end->copy()->subMinutes($shift->graceMinutes);
            if ($attendance->check_out->lt($earlyBoundary)) {
                $earlyMin = (int) $attendance->check_out->diffInMinutes($shift->end);
                $apply('early_exit', 'Early exit', -$settings->early_exit_penalty,
                    'Clocked out '.$earlyMin.'m before shift end ('.$shift->end->format('h:i A').').');
            }
        }

        // Missing punch — an unresolved gap the engine flagged.
        $missingPunch = ($metrics['needs_regularization'] ?? false)
            || ($metrics['missing_out'] ?? false)
            || (! $attendance->check_out);
        if ($missingPunch && ! $attendance->is_regularized && ! $attendance->is_auto_checkout) {
            $apply('missing_punch', 'Missing punch', -$settings->missing_punch_penalty,
                'An IN/OUT punch is missing and has not been regularized.');
        }

        // Auto punch-out — the system had to close the day.
        if ($attendance->is_auto_checkout) {
            $apply('auto_punch_out', 'Auto punch-out', -$settings->auto_punch_out_penalty,
                'No OUT punch — system closed the day at '.$attendance->check_out?->format('h:i A').'.');
        }

        // Regularized day — corrected legitimately, small tracked deduction.
        if ($attendance->is_regularized) {
            $apply('regularization', 'Regularized day', -$settings->regularization_penalty,
                'Punches were corrected via an approved regularization.');
        }

        // Break violation — beyond the shift's break allowance.
        $allowance = $shift?->breakMinutes ?? 0;
        if ($allowance > 0 && $breakMin > $allowance) {
            $apply('break_violation', 'Break allowance exceeded', -$settings->break_violation_penalty,
                $breakMin.'m of breaks vs the '.$allowance.'m shift allowance.');
        }

        // Short hours — worked under 90% of the standard day (half-days exempt).
        $standardMin = $shift?->expectedMinutes() ?? 0;
        if ($standardMin > 0 && $attendance->status !== 'half_day' && ! $missingPunch
            && $workedMin < (int) round($standardMin * 0.9)) {
            $apply('short_hours', 'Short working hours', -$settings->short_hours_penalty,
                $this->hm($workedMin).' worked vs the '.$this->hm($standardMin).' standard day.');
        }

        // Overtime — beyond the shift's OT threshold earns a bonus.
        $otThresholdMin = $shift ? (int) round($shift->otThresholdHours * 60) : 0;
        if ($otThresholdMin > 0 && $workedMin > $otThresholdMin) {
            $apply('overtime', 'Overtime worked', +$settings->overtime_bonus,
                $this->hm($workedMin - $otThresholdMin).' beyond the '.$this->hm($otThresholdMin).' threshold.');
        }

        // Working a holiday/weekend earns the holiday-work bonus.
        if ($isHoliday || AttendanceSetting::isWeeklyOff($day)) {
            $apply('holiday_work', 'Worked a holiday/weekend', +$settings->holiday_work_bonus,
                ($isHoliday ? 'Public holiday' : 'Weekend').' attendance.');
        }

        $score = max(0.0, min(100.0, round($score, 2)));

        return $this->persist($employee, $day, $score, (string) $attendance->status, $lines);
    }

    /** Score every closed day in a range (skips unscoreable days). */
    public function scoreRange(Employee $employee, CarbonInterface $start, CarbonInterface $end): int
    {
        $end = $this->normalize($end);
        $scored = 0;
        for ($d = $this->normalize($start); $d->lte($end); $d->addDay()) {
            if ($this->scoreDay($employee, $d) !== null) {
                $scored++;
            }
        }

        return $scored;
    }

    /**
     * The full decision payload for one day — everything the "Why?" popup
     * shows: shift context, engine punch decisions, totals, and the persisted
     * score breakdown.
     *
     * @return array<string, mixed>
     */
    public function explainDay(Employee $employee, CarbonInterface|string $date): array
    {
        $day = $this->normalize($date);
        $shift = $this->shifts->resolve($employee, $day);
        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('date', $day->toDateString())
            ->first();

        $punches = AttendancePunch::where('employee_id', $employee->id)
            ->whereDate('punch_date', $day->toDateString())
            ->orderBy('punched_at')
            ->get();
        $metrics = $punches->isNotEmpty() ? $this->timeline->process($punches, $day) : null;

        $scoreRow = AttendanceDailyScore::where('employee_id', $employee->id)
            ->whereDate('date', $day->toDateString())
            ->first();

        $workedMin = $metrics['working_minutes']
            ?? (($attendance?->check_in && $attendance?->check_out)
                ? max(0, (int) $attendance->check_in->diffInMinutes($attendance->check_out) - (int) ($attendance->break_minutes ?? 0))
                : 0);

        return [
            'date' => $day->format('l, d M Y'),
            'shift' => $shift ? [
                'name' => $shift->name,
                'window' => $shift->start->format('h:i A').' – '.$shift->end->format('h:i A'),
                'grace' => $shift->graceMinutes.' minutes',
                'standard' => $this->hm($shift->expectedMinutes()),
            ] : null,
            'first_in' => $metrics['first_in'] ?? $attendance?->check_in?->format('h:i A'),
            'last_out' => $metrics['last_out'] ?? $attendance?->check_out?->format('h:i A'),
            'ignored' => collect($metrics['ignored'] ?? [])->map(fn ($n) => $n['time'].' '.($n['method_label'] ?? '').' — ignored')->all(),
            'duplicates' => (int) (($metrics['duplicate_count'] ?? 0) + ($metrics['conflict_count'] ?? 0)),
            'sessions' => $metrics['session_count'] ?? null,
            'late' => $attendance?->is_late ? ((int) $attendance->late_minutes).'m late' : null,
            'break' => $this->hm((int) ($metrics['break_minutes'] ?? $attendance?->break_minutes ?? 0)),
            'worked' => $this->hm((int) $workedMin),
            'auto_punch_out' => (bool) ($attendance?->is_auto_checkout),
            'regularized' => (bool) ($attendance?->is_regularized),
            'status' => $attendance?->status ?? 'absent',
            'score' => $scoreRow?->score,
            'breakdown' => $scoreRow?->breakdown ?? [],
        ];
    }

    /** Monthly score = average of the month's daily scores (null when unscored). */
    public function monthlyScore(Employee $employee, CarbonInterface $month): ?float
    {
        $avg = AttendanceDailyScore::where('employee_id', $employee->id)
            ->whereBetween('date', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ])
            ->avg('score');

        return $avg === null ? null : round((float) $avg, 1);
    }

    /**
     * Rank an employee by month-to-date average score within a pool of
     * employee ids. Returns [rank, poolSizeWithScores] (1-based), or null when
     * the employee has no scores this month.
     *
     * @param  array<int, int>  $poolIds
     * @return array{0: int, 1: int}|null
     */
    public function rankAmong(Employee $employee, array $poolIds, CarbonInterface $month): ?array
    {
        $averages = AttendanceDailyScore::query()
            ->selectRaw('employee_id, AVG(score) as avg_score')
            ->whereIn('employee_id', $poolIds)
            ->whereBetween('date', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ])
            ->groupBy('employee_id')
            ->orderByDesc('avg_score')
            ->pluck('avg_score', 'employee_id');

        if (! $averages->has($employee->id)) {
            return null;
        }

        return [$averages->keys()->search($employee->id) + 1, $averages->count()];
    }

    /**
     * Company / scoped attendance-score health over a range, from the persisted
     * daily scores — powers the HR Admin dashboard and Command Center strips.
     * `at_risk` = employees whose average score is below 60 in the range.
     *
     * @param  array<int, int>  $employeeIds
     * @return array{avg_score: ?int, scored_employees: int, at_risk: int, bands: array{excellent: int, healthy: int, fair: int, at_risk: int}}
     */
    public function companyHealth(array $employeeIds, CarbonInterface $start, CarbonInterface $end): array
    {
        $averages = AttendanceDailyScore::query()
            ->selectRaw('employee_id, AVG(score) as avg_score')
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$this->normalize($start)->toDateString(), $this->normalize($end)->toDateString()])
            ->groupBy('employee_id')
            ->pluck('avg_score');

        $bands = ['excellent' => 0, 'healthy' => 0, 'fair' => 0, 'at_risk' => 0];
        foreach ($averages as $avg) {
            $band = match (true) {
                $avg >= 90 => 'excellent',
                $avg >= 75 => 'healthy',
                $avg >= 60 => 'fair',
                default => 'at_risk',
            };
            $bands[$band]++;
        }

        return [
            'avg_score' => $averages->isNotEmpty() ? (int) round($averages->avg()) : null,
            'scored_employees' => $averages->count(),
            'at_risk' => $bands['at_risk'],
            'bands' => $bands,
        ];
    }

    protected function persist(Employee $employee, Carbon $day, float $score, string $status, array $lines): AttendanceDailyScore
    {
        return AttendanceDailyScore::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $day->toDateString()],
            ['score' => $score, 'status' => $status, 'breakdown' => $lines],
        );
    }

    /** Any Carbon flavour (mutable/immutable) or string → a mutable start-of-day Carbon. */
    protected function normalize(CarbonInterface|string $date): Carbon
    {
        return Carbon::parse($date instanceof CarbonInterface ? $date->toDateString() : $date)->startOfDay();
    }

    protected function hm(int $minutes): string
    {
        return intdiv($minutes, 60).'h '.($minutes % 60).'m';
    }
}
