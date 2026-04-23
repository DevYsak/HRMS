<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use Illuminate\Console\Command;

class CheckLateArrivals extends Command
{
    protected $signature   = 'hrms:check-late-arrivals';
    protected $description = 'Flag attendance records from yesterday where check-in exceeded the shift grace period.';

    public function handle(): int
    {
        $yesterday = now()->subDay()->toDateString();

        $records = Attendance::with(['employee.office', 'employee.shift'])
            ->where('date', $yesterday)
            ->whereNotNull('check_in')
            ->where('is_late', false)
            ->get();

        $flagged = 0;

        foreach ($records as $record) {
            $shift = $record->employee->shift;
            $startTimeStr = $shift ? $shift->start_time : '09:00:00';
            
            $startTime = \Carbon\Carbon::parse($startTimeStr);
            // Add 15 min grace
            $graceTime = $startTime->addMinutes(15)->format('H:i');

            ['is_late' => $isLate, 'late_minutes' => $lateMinutes] = $record->computeLate($graceTime);

            if ($isLate) {
                $record->update(['is_late' => true, 'late_minutes' => $lateMinutes]);
                $flagged++;
            }
        }

        $this->info("Flagged {$flagged} late arrival(s) for {$yesterday}.");

        return self::SUCCESS;
    }
}
