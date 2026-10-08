<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\BiometricLog;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Services\Audit\AuditService;
use App\Services\HolidayWorkService;
use App\Services\OvertimeService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * THE way a day's processed attendance is produced from raw punches.
 *
 * Every path that turns biometric punches into an attendance row — the legacy
 * device sync, the engine sync and `attendance:rebuild-punch-timelines` — goes
 * through here, so they cannot disagree: first IN, final OUT, worked hours,
 * break, late and missing-checkout all come from {@see PunchTimeline} +
 * {@see AttendanceCalculator} (Face = IN, ID Card = OUT, latest of a 60-second
 * burst, stray cards ignored, shift-window aware for night shifts).
 *
 * Raw punches are never modified. A regularised day is rebuilt like any other:
 * the correction's IN / OUT punches stand in for the boundary they corrected
 * and later genuine punches still count. A day is left untouched, and the
 * reason returned, when it is a leave / half-day regularisation, a correction
 * that exists only as row times, inside a settled payroll or has no valid
 * Face IN — never guessed.
 */
class AttendanceDayRebuilder
{
    /** Payroll statuses whose attendance must no longer change (with finance, or final). */
    private const SETTLED_PAYROLL = ['pending_finance', 'finalized'];

    /** @var array<string, bool> */
    private array $settledCache = [];

    public function __construct(
        private readonly PunchTimeline $timeline,
        private readonly AttendanceCalculator $calculator,
        private readonly ShiftResolver $shifts,
    ) {}

    /**
     * The work date a punch belongs to. A night-shift employee's punch after
     * midnight, up to the missing-checkout cutoff (shift end + 1h), belongs to
     * the shift that started the day before.
     */
    public function workDateFor(Employee $employee, CarbonInterface $punchedAt): Carbon
    {
        $moment = Carbon::parse($punchedAt);
        $yesterday = $moment->copy()->startOfDay()->subDay();
        $shift = $this->shifts->resolve($employee, $yesterday->toDateString());

        if ($shift?->crossesMidnight()
            && $moment->lessThanOrEqualTo($shift->end->copy()->addMinutes(AttendanceCalculator::MISSING_CHECKOUT_AFTER_MINUTES))) {
            return $yesterday;
        }

        return $moment->copy()->startOfDay();
    }

    /**
     * What the canonical timeline says about one employee-day, against what
     * the attendance row holds now.
     *
     * @return array<string, mixed>
     */
    public function examine(Employee $employee, CarbonInterface $day, bool $allowCreate = false, ?CarbonInterface $now = null, bool $allowClear = false, bool $hrDecision = false): array
    {
        $day = Carbon::parse($day->toDateString())->startOfDay();

        $punches = AttendancePunch::where('employee_id', $employee->id)
            ->whereDate('punch_date', $day->toDateString())
            ->orderBy('punched_at')
            ->get();
        $attendance = Attendance::where('employee_id', $employee->id)->whereDate('date', $day->toDateString())->first();
        $summary = AttendanceDailySummary::where('employee_id', $employee->id)->whereDate('date', $day->toDateString())->first();

        $shift = $this->shifts->resolve($employee, $day->toDateString());
        $processed = $this->timeline->process($punches, $day, null, $shift);
        $flags = $processed['flags'];
        $calc = $this->calculator->forDay($employee, $day, $attendance, $punches, $now);
        $in = $calc->firstIn ? Carbon::parse($calc->firstIn) : null;
        $out = $calc->lastOut ? Carbon::parse($calc->lastOut) : null;
        $worked = $out !== null ? $this->calculator->storedHours($in, $out) : null;

        $row = [
            'day' => $day,
            'punches' => $punches,
            'attendance' => $attendance,
            'summary' => $summary,
            'calc' => $calc,
            'old_in' => $attendance?->check_in,
            'old_out' => $attendance?->check_out,
            'old_worked' => $attendance?->check_out ? (float) $attendance->total_hours : null,
            'old_break' => $attendance ? (int) ($attendance->break_minutes ?? 0) : null,
            'new_in' => $in,
            'new_out' => $out,
            'new_worked' => $worked,
            'new_break' => $this->breakMinutes($calc->breakMinutes, $attendance),
            'duplicates' => $flags['duplicate'] + $flags['retry'],
            'ignored' => $flags['stray'] + $flags['other_day'],
            'directions' => $flags['direction_corrected'],
            'raw_count' => $punches->count(),
            'changes' => [],
            'create' => false,
            'clear' => false,
            'synthetic' => null,
            'unsupported' => false,
            'skip' => null,
        ];

        // A row with nothing real behind it: no genuine punch, no web / device
        // evidence. On a holiday or weekly off that is a synthetic row (the old
        // holiday-work approval stamped shift times); elsewhere it is only
        // reported — it may be a manual or imported day.
        $row['unsupported'] = $attendance !== null && ! $this->hasSupport($attendance, $punches, $summary);
        $row['synthetic'] = $row['unsupported'] ? $this->syntheticKind($employee, $day, $attendance) : null;

        // A correction written as punches is part of the timeline, so the day
        // is rebuilt around it like any other. Left alone: a leave or half-day
        // regularisation (it sets the day's status, not its punches) and a
        // correction approved before corrections were written as punches —
        // its row times are the only record of what HR approved. An HR
        // decision (approve / correct / revert) is the change itself, so the
        // approved-correction and settled-payroll guards — there to stop an
        // automatic sync — do not hold it back.
        $correctionPunches = $flags['regularised'];
        $protected = match (true) {
            $attendance !== null && $attendance->is_regularized && in_array($attendance->status, ['leave', 'half_day'], true) => 'regularised '.str_replace('_', '-', $attendance->status).' — status kept',
            $attendance !== null && $attendance->is_regularized && ! $correctionPunches => 'regularised — corrected times kept',
            ! $hrDecision && ! $correctionPunches && $this->hasApprovedCorrection($employee, $day) => 'HR-corrected — approved correction kept',
            ! $hrDecision && $this->inSettledPayroll($employee, $day) => 'inside approved payroll',
            default => null,
        };

        $row['skip'] = match (true) {
            $attendance === null && ! $allowCreate => 'no attendance row',
            $protected !== null => $protected,
            $row['synthetic'] !== null && ! $allowClear => 'synthetic '.$row['synthetic'].' row — left for the rebuild command',
            $row['synthetic'] !== null => null,
            $processed['first_in_at'] === null || $in === null => 'no valid Face IN',
            $flags['impossible_duration'] => 'impossible duration — check manually',
            default => null,
        };

        if ($row['skip'] === null && $row['synthetic'] !== null) {
            // Rebuilt only from genuine punches: there are none, so the made-up
            // attendance goes (audited), never the raw logs.
            $row['clear'] = true;
            $row['changes'] = ['clear' => true];
            $row['new_in'] = $row['new_out'] = $row['new_worked'] = null;
            $row['new_break'] = 0;

            return $row;
        }

        if ($row['skip'] !== null) {
            return $row;
        }

        $target = [
            'check_in' => $in,
            'check_out' => $out,
            'total_hours' => $worked ?? 0.0,   // still open: nothing counted until the OUT
            'break_minutes' => $row['new_break'],
            'is_late' => $calc->isLate,
            'late_minutes' => $calc->lateMinutes,
            'missing_checkout' => $calc->missingCheckout,
        ];
        if ($attendance === null || in_array($attendance->status, ['on_time', 'late'], true)) {
            $target['status'] = $calc->isLate ? 'late' : 'on_time';
        }

        $methods = $this->methods($punches, $in, $out);

        if ($attendance === null) {
            $row['create'] = true;
            $row['changes'] = $target;
            $row['target'] = $target + $methods + ['employee_id' => $employee->id, 'date' => $day->toDateString(), 'work_mode' => 'office'];

            return $row;
        }

        $row['changes'] = $this->diff($attendance, $target);
        $row['target'] = $target + $methods;

        return $row;
    }

    /**
     * Write an examined day: the attendance row and the daily summary. Does
     * nothing for a skipped or unchanged day.
     *
     * @param  array<string, mixed>  $row  from {@see examine()}
     * @param  'update'|'upsert'  $summaryMode  update only an existing summary, or create it too
     */
    public function apply(Employee $employee, array $row, string $summaryMode = 'update', bool $audit = false, ?string $reason = null, bool $score = false): ?Attendance
    {
        if ($row['skip'] !== null) {
            return $row['attendance'];
        }

        /** @var Attendance|null $attendance */
        $attendance = $row['attendance'];
        $before = $attendance?->only(['check_in', 'check_out', 'total_hours', 'break_minutes', 'status', 'is_late', 'missing_checkout']);

        if ($row['clear']) {
            return $this->clearSynthetic($employee, $row, $before, $reason);
        }

        if ($row['create']) {
            $attendance = Attendance::create($row['target']);
        } elseif ($row['changes'] !== []) {
            $attendance->update($row['target']);
        }

        $this->writeSummary($employee, $row, $summaryMode);

        // A real final OUT turns waiting approved overtime into payable overtime.
        if (($row['create'] || $row['changes'] !== []) && $row['new_out'] !== null) {
            app(OvertimeService::class)->settleApprovedForDay($employee, $row['day']);
            app(HolidayWorkService::class)->settleForDay($employee, $row['day']);
        }

        if ($audit && ($row['create'] || $row['changes'] !== [])) {
            app(AuditService::class)->event('ATTENDANCE_TIMELINE_REBUILT', AuditService::ATTENDANCE, $attendance,
                old: $before === null ? null : $this->scalar($before),
                new: $this->scalar($attendance->fresh()->only(['check_in', 'check_out', 'total_hours', 'break_minutes', 'status', 'is_late', 'missing_checkout'])),
                reason: $reason, subjectEmployeeId: $employee->id, actor: null);
        }

        if ($score && ! $row['day']->isToday()) {
            app(AttendanceScoreEngine::class)->scoreDay($employee, $row['day']->toDateString());
        }

        return $attendance?->fresh();
    }

    /** Examine and apply one day. @return array<string, mixed> the examined row, with `attendance` refreshed */
    public function rebuild(Employee $employee, CarbonInterface $day, string $summaryMode = 'upsert', bool $allowCreate = true, ?CarbonInterface $now = null): array
    {
        $row = $this->examine($employee, $day, $allowCreate, $now);
        $row['attendance'] = $this->apply($employee, $row, $summaryMode);

        return $row;
    }

    /**
     * Rebuild a day after HR approved, corrected or reverted a regularisation:
     * the whole work-date timeline — genuine punches and correction punches —
     * through the same examine/apply as every sync. HR's decision is explicit,
     * so neither the approved-correction guard nor a settled payroll holds it
     * back (approval never checked payroll either).
     *
     * @return array<string, mixed> the examined row, with `attendance` refreshed
     */
    public function rebuildCorrectedDay(Employee $employee, CarbonInterface $day): array
    {
        $row = $this->examine($employee, $day, allowCreate: false, hrDecision: true);
        $row['attendance'] = $this->apply($employee, $row, 'update');

        return $row;
    }

    /**
     * Remove an attendance row nothing real supports, and the made-up summary
     * figures with it. Raw punches and logs are never touched. Overtime that
     * was derived from the invented hours is recalculated (to zero) unless it
     * has been paid.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $before
     */
    private function clearSynthetic(Employee $employee, array $row, ?array $before, ?string $reason): ?Attendance
    {
        /** @var Attendance $attendance */
        $attendance = $row['attendance'];

        app(AuditService::class)->event('ATTENDANCE_SYNTHETIC_ROW_CLEARED', AuditService::ATTENDANCE, $attendance,
            old: $before === null ? null : $this->scalar($before) + ['kind' => $row['synthetic']],
            new: null,
            reason: $reason ?? 'No genuine punches support this '.$row['synthetic'].' attendance row.',
            subjectEmployeeId: $employee->id, actor: null);

        $row['summary']?->update(['first_punch' => null, 'last_punch' => null, 'first_punch_method' => null, 'last_punch_method' => null,
            'working_hours' => 0, 'break_minutes' => 0, 'raw_punch_count' => 0]);

        $attendance->delete();
        app(OvertimeService::class)->settleApprovedForDay($employee, $row['day']);

        return null;
    }

    /**
     * Break time for the day: the gaps between punches, or the breaks the
     * employee logged on the web (break_logs), whichever is larger. Logged
     * breaks are real data the punch stream cannot see, so a rebuild must not
     * erase them. Informational only — never deducted.
     */
    private function breakMinutes(int $fromPunches, ?Attendance $attendance): int
    {
        if ($attendance === null) {
            return $fromPunches;
        }

        $logged = (int) $attendance->breakLogs()->whereNotNull('break_end')->sum('duration_minutes');

        return max($fromPunches, $logged);
    }

    /** Anything real behind the row: a genuine punch, a web punch trace, breaks, device logs or an engine count. */
    private function hasSupport(Attendance $attendance, $punches, ?AttendanceDailySummary $summary): bool
    {
        if ($punches->contains(fn (AttendancePunch $p) => ! in_array($p->source, ['system_auto', 'regularisation'], true))) {
            return true;
        }

        if ($attendance->check_in_method || $attendance->check_out_method || $attendance->check_in_ip || $attendance->check_in_user_agent
            || $attendance->check_in_photo || $attendance->check_in_lat !== null || $attendance->check_out_ip || $attendance->is_regularized
            || $attendance->status === 'leave') {
            return true;
        }

        return $attendance->breakLogs()->exists()
            || BiometricLog::where('attendance_id', $attendance->id)->exists()
            || ($summary !== null && (int) $summary->raw_punch_count > 0);
    }

    /** holiday | weekly_off | mdl for a day nobody was scheduled to work; null on a working day. */
    private function syntheticKind(Employee $employee, CarbonInterface $day, Attendance $attendance): ?string
    {
        return match (app(WorkingDayResolver::class)->classify($employee, Carbon::parse($day->toDateString()), withLeave: false)) {
            WorkingDayResolver::PUBLIC_HOLIDAY => 'holiday',
            WorkingDayResolver::WEEKLY_OFF => 'weekly-off',
            WorkingDayResolver::MDL_SHUTDOWN => 'MDL shutdown',
            default => null,
        };
    }

    /**
     * Whether the day falls in a payroll that is with finance, finalised or
     * locked and holds a payslip for the employee.
     */
    public function inSettledPayroll(Employee $employee, CarbonInterface $day): bool
    {
        $key = $employee->id.'|'.$day->toDateString();
        if (isset($this->settledCache[$key])) {
            return $this->settledCache[$key];
        }

        $payrollIds = Payslip::where('employee_id', $employee->id)->pluck('payroll_id');
        $settled = Payroll::whereIn('id', $payrollIds)
            ->where(fn ($q) => $q->whereIn('status', self::SETTLED_PAYROLL)->orWhereNotNull('locked_at'))
            ->get()
            ->contains(function (Payroll $payroll) use ($day) {
                try {
                    $month = Carbon::parse('1 '.$payroll->month.' '.$payroll->year);
                } catch (\Throwable) {
                    return true;   // unreadable period: protect it
                }
                [$start, $end] = $payroll->cycle === 'cycle_b'
                    ? [$month->copy()->subMonth()->setDay(21)->startOfDay(), $month->copy()->setDay(20)->endOfDay()]
                    : [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];

                return Carbon::parse($day->toDateString())->betweenIncluded($start, $end);
            });

        return $this->settledCache[$key] = $settled;
    }

    /**
     * Settled payroll, or an approved HR correction that exists only as row
     * times: the stored day must not be rewritten. A correction written as
     * punches is rebuilt with the rest of the timeline instead.
     */
    public function isLocked(Employee $employee, CarbonInterface $day): bool
    {
        return ($this->hasApprovedCorrection($employee, $day) && ! $this->hasCorrectionPunches($employee, $day))
            || $this->inSettledPayroll($employee, $day);
    }

    /** Whether an approved correction's IN / OUT punches are in the day's timeline. */
    private function hasCorrectionPunches(Employee $employee, CarbonInterface $day): bool
    {
        return AttendancePunch::where('employee_id', $employee->id)
            ->whereDate('punch_date', $day->toDateString())
            ->where('source', 'regularisation')
            ->exists();
    }

    /** An approved (non-leave) regularisation HR decided for the day — the correction is the record. */
    private function hasApprovedCorrection(Employee $employee, CarbonInterface $day): bool
    {
        return AttendanceRegularisation::where('employee_id', $employee->id)
            ->whereDate('work_date', $day->toDateString())
            ->where('status', 'approved')
            ->where(fn ($q) => $q->whereNull('category')->orWhere('category', '!=', 'leave'))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function writeSummary(Employee $employee, array $row, string $mode): void
    {
        $calc = $row['calc'];
        $values = [
            'employee_code' => $employee->employee_code,
            'first_punch' => $row['new_in'],
            'last_punch' => $row['new_out'],
            'first_punch_method' => $row['target']['check_in_method'] ?? $row['summary']?->first_punch_method,
            'last_punch_method' => $row['target']['check_out_method'] ?? $row['summary']?->last_punch_method,
            'break_minutes' => $row['new_break'],
            'working_hours' => $row['new_worked'] ?? 0,
            'late_minutes' => $calc->lateMinutes,
            'status' => $calc->isLate ? 'late' : 'present',
            'raw_punch_count' => $row['raw_count'],
            'synced_at' => now(),
        ];

        if ($row['summary']) {
            $row['summary']->update($values);
        } elseif ($mode === 'upsert' && $employee->employee_code !== null) {   // the summary is keyed on the device code
            AttendanceDailySummary::create($values + ['employee_id' => $employee->id, 'date' => $row['day']->toDateString()]);
        }
    }

    /**
     * Columns whose value would change.
     *
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function diff(Attendance $attendance, array $target): array
    {
        $changes = [];
        foreach ($target as $column => $value) {
            $current = $attendance->{$column};
            $same = match (true) {
                $value instanceof \DateTimeInterface || $current instanceof \DateTimeInterface => ($value ? Carbon::parse($value)->format('Y-m-d H:i:s') : null) === ($current ? Carbon::parse($current)->format('Y-m-d H:i:s') : null),
                $column === 'total_hours' => ($value === null && $current === null) || ($value !== null && $current !== null && abs((float) $value - (float) $current) < 0.01),
                is_bool($value) => $value === (bool) $current,
                default => (string) $value === (string) $current,
            };
            if (! $same) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    /**
     * The verify method of the punches the day now starts and ends on.
     *
     * @param  Collection<int, AttendancePunch>  $punches
     * @return array<string, ?string>
     */
    private function methods($punches, ?Carbon $in, ?Carbon $out): array
    {
        $at = fn (?Carbon $t) => $t ? $punches->first(fn (AttendancePunch $p) => $p->punched_at->equalTo($t))?->method : null;

        return array_filter(['check_in_method' => $at($in), 'check_out_method' => $at($out)]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function scalar(array $values): array
    {
        return array_map(fn ($v) => $v instanceof \DateTimeInterface ? Carbon::parse($v)->format('Y-m-d H:i:s') : $v, $values);
    }
}
