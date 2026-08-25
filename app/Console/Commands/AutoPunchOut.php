<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\OtRequest;
use App\Notifications\MissingCheckoutNotification;
use App\Services\Attendance\AttendanceScoreEngine;
use App\Services\Attendance\ShiftResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Auto punch-out engine (spec Rules 5 & 6).
 *
 * Closes attendance days left open past the shift, WITHOUT ever overwriting a
 * real punch — the stamped OUT is system-generated and stays regularizable:
 *
 *  - No approved OT: once now ≥ shift-end + buffer, close the day AT shift-end
 *    (not shift-end + buffer). Reason: missing_punchout.
 *  - Approved OT for the day: do not close at shift-end — hold until the
 *    configured OT close time (end of the working day). If a real OUT arrives
 *    first the biometric sync records it; otherwise close at that time.
 *    Reason: ot_auto_close.
 *
 * Runs every few minutes; idempotent (skips days already closed or auto-closed).
 */
class AutoPunchOut extends Command
{
    protected $signature = 'hrms:auto-punch-out {--date= : Process a specific date (Y-m-d), defaults to today}';

    protected $description = 'Auto-close attendance days with no check-out at the shift-end (or OT close time). System-generated and regularizable.';

    public function handle(ShiftResolver $shifts): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();
        $now = Carbon::now();

        $open = Attendance::with('employee.shift')
            ->whereDate('date', $date->toDateString())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->get();

        $closed = 0;

        foreach ($open as $attendance) {
            $employee = $attendance->employee;
            if (! $employee) {
                continue;
            }

            $shift = $shifts->resolve($employee, $date);
            if (! $shift) {
                continue;   // no shift/settings configured — never invent a time
            }

            $hasApprovedOt = OtRequest::query()
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $date->toDateString())
                ->where('status', 'approved')
                ->exists();

            if ($hasApprovedOt) {
                // Rule 6 — hold an OT day open until the OT close boundary.
                if ($now->lt($shift->otAutoCloseAt)) {
                    continue;
                }
                $closeAt = $shift->otAutoCloseAt;
                $reason = 'ot_auto_close';
            } else {
                // Rule 5 — wait the buffer past shift-end, then close AT shift-end.
                if ($now->lt($shift->autoCheckoutTriggerAt())) {
                    continue;
                }
                $closeAt = $shift->end;
                $reason = 'missing_punchout';
            }

            // Never stamp an OUT before the IN (a very short or mis-timed shift).
            if ($closeAt->lessThanOrEqualTo($attendance->check_in)) {
                $closeAt = $attendance->check_in->copy()->addMinute();
            }

            $this->closeDay($attendance, $closeAt, $reason);
            $closed++;

            // Rescore the day the engine just closed (no-op while it's still
            // today — the nightly scorer covers it after midnight).
            app(AttendanceScoreEngine::class)->scoreDay($employee, $date);

            $notification = new MissingCheckoutNotification($attendance->fresh());
            $employee->user?->notify($notification);
            $employee->manager?->notify($notification);
        }

        $this->info("Auto punch-out closed {$closed} open day(s) for {$date->toDateString()}.");

        return self::SUCCESS;
    }

    /** Stamp the system OUT on the attendance row and mirror it into the punch journey. */
    protected function closeDay(Attendance $attendance, Carbon $closeAt, string $reason): void
    {
        $grossMinutes = (int) $attendance->check_in->diffInMinutes($closeAt);
        $netMinutes = max(0, $grossMinutes - (int) ($attendance->break_minutes ?? 0));

        $attendance->update([
            'check_out' => $closeAt,
            'check_out_method' => 'auto',
            'total_hours' => round($netMinutes / 60, 2),
            'is_auto_checkout' => true,
            'auto_checkout_reason' => $reason,
            'missing_checkout' => true,   // still flagged so the employee regularizes it
        ]);

        // Mirror into the punch journey so the timeline shows an "Auto OUT" node.
        AttendancePunch::updateOrCreate(
            ['employee_id' => $attendance->employee_id, 'punched_at' => $closeAt],
            [
                'employee_code' => $attendance->employee?->employee_code,
                'punch_date' => $attendance->date->toDateString(),
                'method' => 'auto',
                'direction' => 'out',
                'verify_raw' => 'auto',
                'source' => 'system_auto',
            ],
        );
    }
}
