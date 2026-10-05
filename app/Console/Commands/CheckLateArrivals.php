<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Notifications\LateArrivalNotification;
use App\Services\Attendance\ShiftResolver;
use App\Services\Attendance\WorkingDayResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Spec §7 — confirm late flags for each shift, then notify the manager in-app.
 * Runs at 10:45 (IT shift) and 13:15 (UK Sales shift). Each late day is
 * reported to the manager once (late_notified_at), whichever run sees it.
 */
class CheckLateArrivals extends Command
{
    protected $signature = 'hrms:check-late-arrivals';

    protected $description = 'Flag today\'s check-ins that arrived after their shift grace cutoff and notify managers. Runs at 10:45 for IT shift and 13:15 for UK shift.';

    public function handle(ShiftResolver $shifts): int
    {
        $today = now()->toDateString();
        $days = app(WorkingDayResolver::class);

        // Saturday / Sunday (or an MDL date): nobody is late, nobody is told.
        if (! $days->isCompanyWorkingDay(now())) {
            $this->info("Skipping {$today} — not a working day.");

            return self::SUCCESS;
        }

        $records = Attendance::with(['employee.shift', 'employee.exitRecord'])
            ->where('date', $today)
            ->whereNotNull('check_in')
            ->where('is_late', false)
            ->get();

        $flagged = 0;

        foreach ($records as $record) {
            // A holiday on the employee's calendar: no shift to be late for.
            if (! $record->employee || $days->classify($record->employee, now(), withLeave: false) !== WorkingDayResolver::WORKING_DAY) {
                continue;
            }

            // Cutoff = shift start + grace, both from the employee's assigned
            // shift on this day — never a hardcoded grace value.
            $shift = $shifts->resolve($record->employee, $today);
            if (! $shift) {
                continue;
            }

            $checkIn = Carbon::parse($record->check_in);

            if ($shift->isLate($checkIn)) {
                $record->update([
                    'is_late' => true,
                    'late_minutes' => $shift->lateMinutes($checkIn),
                    'status' => 'late',
                ]);
                $flagged++;
            }
        }

        $notified = $this->notifyManagers($today);

        $this->info("Flagged {$flagged} late arrival(s) for {$today}; told managers about {$notified}.");

        return self::SUCCESS;
    }

    /** One digest per manager of today's late arrivals not yet reported. */
    private function notifyManagers(string $today): int
    {
        $late = Attendance::with(['employee.user', 'employee.manager'])
            ->where('date', $today)
            ->where('is_late', true)
            ->whereNull('late_notified_at')
            ->get();

        $late->groupBy(fn (Attendance $a) => $a->employee?->manager_id)
            ->each(function (Collection $rows) {
                $manager = $rows->first()->employee?->manager;

                try {
                    $manager?->notify(new LateArrivalNotification($rows->values()));
                } catch (\Throwable $e) {
                    report($e);

                    return; // left unmarked, retried on the next run
                }

                // Marked even without a manager, so the day is not re-examined.
                Attendance::whereKey($rows->pluck('id'))->update(['late_notified_at' => now()]);
            });

        return $late->count();
    }
}
