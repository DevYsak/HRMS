<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\User;
use App\Notifications\MissingCheckoutNotification;
use App\Services\Attendance\ShiftResolver;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * FIX 4 — Spec §3.2 + §3.6
 * Flags missing clock-outs and notifies employee + their manager in-app.
 *
 * "Missing" means no clock-out by the employee's OWN shift end + 1 hour: the
 * IT shift (10:30–19:30) is due at 20:30, the UK Sales shift (13:00–22:00) at
 * 23:00. Scheduled at 21:00 and 23:05, each run flags only the days whose
 * deadline has passed — a UK employee still working at 21:00 is not flagged.
 * Idempotent: a day already flagged is never flagged or notified twice.
 */
class FlagMissingCheckouts extends Command
{
    /** Spec §3.2: shift end + 1 hour. */
    private const GRACE_MINUTES_AFTER_SHIFT = 60;

    protected $signature = 'hrms:flag-missing-checkouts';

    protected $description = 'Flag today\'s attendance records with no check-out once the employee\'s shift end + 1 hour has passed.';

    public function handle(ShiftResolver $shifts): int
    {
        $today = now()->toDateString();
        $days = app(WorkingDayResolver::class);

        // Saturday / Sunday (or an MDL date): no one was required to work, so
        // there is no check-out to miss and nobody is notified.
        if (! $days->isCompanyWorkingDay(now())) {
            $this->info("Skipping {$today} — not a working day.");

            return self::SUCCESS;
        }

        $records = Attendance::with(['employee.user', 'employee.manager', 'employee.shift', 'employee.exitRecord'])
            ->where('date', $today)
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where('missing_checkout', false)
            ->get();

        $flagged = 0;

        foreach ($records as $record) {
            $employee = $record->employee;

            if (! $employee || ! $this->deadlinePassed($shifts, $record)
                || $days->classify($employee, now(), withLeave: false) !== WorkingDayResolver::WORKING_DAY) {
                continue;
            }

            try {
                $record->update(['missing_checkout' => true]);

                // The employee and their manager, plus whoever the
                // Notifications & Email page adds (e.g. a Coordinator or HR),
                // minus excluded roles — each once for this day.
                $notification = new MissingCheckoutNotification($record);
                app(NotificationDispatcher::class)->sendToRecipients(
                    MissingCheckoutNotification::class,
                    collect([$employee->user, $employee->manager_id ? $employee->manager : null])->filter(),
                    fn (User $u) => $notification->forRole($u->id === $employee->user_id ? 'employee' : 'manager'),
                    'missing_checkout:'.$record->id,
                    $employee,
                );

                $flagged++;
            } catch (\Throwable $e) {
                // One bad record never stops the rest of the run.
                report($e);
            }
        }

        $this->info("Flagged {$flagged} attendance record(s) as missing check-out for {$today}.");

        return self::SUCCESS;
    }

    /**
     * Whether the employee's shift end + 1 hour has passed. With no resolvable
     * shift, the run time itself is the deadline (the original 21:00 rule).
     */
    private function deadlinePassed(ShiftResolver $shifts, Attendance $record): bool
    {
        $shift = $shifts->resolve($record->employee, Carbon::parse($record->date));

        if ($shift === null) {
            return true;
        }

        return now()->greaterThanOrEqualTo($shift->end->copy()->addMinutes(self::GRACE_MINUTES_AFTER_SHIFT));
    }
}
