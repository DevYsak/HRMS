<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Services\Attendance\ShiftResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CheckLateArrivals extends Command
{
    protected $signature = 'hrms:check-late-arrivals';

    protected $description = 'Flag today\'s check-ins that arrived after their shift grace cutoff. Runs at 10:45 for IT shift and 13:15 for UK shift.';

    public function handle(ShiftResolver $shifts): int
    {
        $today = now()->toDateString();

        $records = Attendance::with(['employee.shift'])
            ->where('date', $today)
            ->whereNotNull('check_in')
            ->where('is_late', false)
            ->get();

        $flagged = 0;

        foreach ($records as $record) {
            if (! $record->employee) {
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

        $this->info("Flagged {$flagged} late arrival(s) for {$today}.");

        return self::SUCCESS;
    }
}
