<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CheckLateArrivals extends Command
{
    protected $signature = 'hrms:check-late-arrivals';

    protected $description = 'Flag today\'s check-ins that exceeded the shift grace period (5 min). Runs at 10:45 for IT shift and 13:15 for UK shift.';

    public function handle(): int
    {
        $today = now()->toDateString();

        $records = Attendance::with(['employee.shift'])
            ->where('date', $today)
            ->whereNotNull('check_in')
            ->where('is_late', false)
            ->get();

        $flagged = 0;

        foreach ($records as $record) {
            $shift = $record->employee->shift;

            if (! $shift) {
                continue;
            }

            $graceMinutes = (int) ($shift->grace_minutes ?? 5);
            $shiftStart = Carbon::parse($shift->start_time);
            $cutoff = $shiftStart->copy()->addMinutes($graceMinutes);
            $checkIn = Carbon::parse($record->check_in);

            if ($checkIn->gt($cutoff)) {
                $lateMinutes = (int) $cutoff->diffInMinutes($checkIn);
                $record->update(['is_late' => true, 'late_minutes' => $lateMinutes, 'status' => 'late']);
                $flagged++;
            }
        }

        $this->info("Flagged {$flagged} late arrival(s) for {$today}.");

        return self::SUCCESS;
    }
}
