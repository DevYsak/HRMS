<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Services\Audit\AuditService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Nightly auto checkout: a day with a valid IN and no final OUT is closed,
 * after 11:00 PM IST, at the employee's assigned shift end (as ShiftResolver
 * resolves it — the assigned shift, else HR's company default; a night shift
 * ends the next morning and is closed by the following night's run).
 *
 * Writes the attendance row only — never a device / card OUT punch. The row
 * is marked is_auto_checkout with Attendance::AUTO_CHECKOUT_REASON and the
 * change is audited. Everything else comes from the canonical services:
 * AttendanceCalculator treats the stored shift end as the final OUT (no
 * overtime, never payable), and AttendanceDayRebuilder writes the day and
 * lets a later genuine OUT supersede the auto checkout.
 *
 * Left alone: no IN, an existing OUT, no resolvable shift, a shift not yet
 * ended, an IN after the shift end, and days the rebuilder protects
 * (regularised, HR-corrected, settled payroll). Running again changes nothing.
 */
class AutoCheckoutService
{
    /** Local time (app timezone, IST) after which a still-open day is closed. */
    public const CLOSE_AFTER = '23:00';

    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceDayRebuilder $rebuilder,
        private readonly ShiftResolver $shifts,
    ) {}

    /**
     * Close the open days of one work date.
     *
     * @return array{date: string, checked: int, closed: int, skipped: array<string, int>}
     */
    public function close(CarbonInterface $date, ?CarbonInterface $now = null, bool $dryRun = false): array
    {
        $now = $now ? Carbon::parse($now) : Carbon::now();
        $day = Carbon::parse($date->toDateString())->startOfDay();
        $result = ['date' => $day->toDateString(), 'checked' => 0, 'closed' => 0, 'skipped' => []];

        if ($now->lessThan($day->copy()->setTimeFromTimeString(self::CLOSE_AFTER))) {
            $result['skipped']['before 11 PM'] = 1;

            return $result;
        }

        $open = Attendance::with('employee')
            ->whereDate('date', $day->toDateString())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->get();

        foreach ($open as $attendance) {
            $result['checked']++;

            try {
                $reason = $this->closeOne($attendance, $day, $now, $dryRun);
            } catch (\Throwable $e) {
                report($e);   // one bad record never stops the run
                $reason = 'error';
            }

            if ($reason === null) {
                $result['closed']++;
            } else {
                $result['skipped'][$reason] = ($result['skipped'][$reason] ?? 0) + 1;
            }
        }

        return $result;
    }

    /**
     * Close one open day at its shift end.
     *
     * @return string|null null when closed (or, in a dry run, closable); otherwise why it was left
     */
    public function closeOne(Attendance $attendance, CarbonInterface $day, CarbonInterface $now, bool $dryRun = false): ?string
    {
        $employee = $attendance->employee;
        if ($employee === null) {
            return 'no employee';
        }

        $shift = ShiftResolver::hasResolvableShift($employee) ? $this->shifts->resolve($employee, Carbon::parse($day->toDateString())) : null;
        if ($shift === null) {
            return 'no shift assigned';
        }
        if (Carbon::parse($now)->lessThan($shift->end)) {
            return 'shift not ended';
        }

        $row = $this->rebuilder->examine($employee, $day, now: $now);
        // Regularised, HR-corrected or settled-payroll days are never touched.
        // A web-punch day has no device timeline ('no valid Face IN'); the row
        // itself carries its IN, so it is still closed.
        if ($row['skip'] !== null && $row['skip'] !== 'no valid Face IN') {
            return 'protected: '.$row['skip'];
        }

        $calc = $row['calc'];
        if ($calc->firstIn === null) {
            return 'no IN';
        }
        if ($calc->lastOut !== null) {
            return 'has OUT';
        }
        if ($shift->end->lessThanOrEqualTo(Carbon::parse($calc->firstIn))) {
            return 'IN after shift end';
        }

        if ($dryRun) {
            return null;
        }

        return DB::transaction(function () use ($attendance, $employee, $day, $now, $shift): ?string {
            $locked = Attendance::whereKey($attendance->id)->lockForUpdate()->first();
            if ($locked === null || $locked->check_out !== null) {
                return 'has OUT';   // closed meanwhile — nothing to do (idempotent)
            }

            $before = $locked->only(['check_in', 'check_out', 'total_hours', 'status', 'missing_checkout', 'is_auto_checkout']);

            $locked->update([
                'check_out' => $shift->end->copy(),
                'is_auto_checkout' => true,
                'auto_checkout_reason' => Attendance::AUTO_CHECKOUT_REASON,
                'missing_checkout' => false,
            ]);

            // Hours, status and the daily summary from the canonical rebuild; a
            // web-punch day (no device timeline) takes its hours from the calculator.
            $examined = $this->rebuilder->examine($employee, $day, now: $now);
            if ($examined['skip'] === null) {
                $this->rebuilder->apply($employee, $examined);
            } else {
                $calc = $this->calculator->forAttendance($locked->fresh(), now: $now);
                $locked->update([
                    'total_hours' => $this->calculator->storedHours($calc->firstIn, $calc->lastOut),
                    'missing_checkout' => false,
                ]);
            }

            $after = $locked->fresh();
            app(AuditService::class)->event('ATTENDANCE_AUTO_CHECKOUT', AuditService::ATTENDANCE, $after,
                old: $this->scalar($before),
                new: $this->scalar($after->only(['check_in', 'check_out', 'total_hours', 'status', 'missing_checkout', 'is_auto_checkout'])),
                reason: Attendance::AUTO_CHECKOUT_EXPLANATION.' (Shift end '.$shift->end->format('d M Y H:i').'.)',
                subjectEmployeeId: $employee->id,
                actor: null);

            return null;
        });
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
