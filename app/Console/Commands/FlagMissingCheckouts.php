<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Notifications\MissingCheckoutNotification;
use Illuminate\Console\Command;

/**
 * FIX 4 — Spec §3.2 + §3.6
 * Flags missing clock-outs and notifies employee + their manager in-app.
 */
class FlagMissingCheckouts extends Command
{
    protected $signature = 'hrms:flag-missing-checkouts';

    protected $description = 'Flag today\'s attendance records with no check-out. Runs at 21:00 IST (shift end + 1 hr).';

    public function handle(): int
    {
        $today = now()->toDateString();

        $records = Attendance::with(['employee.user', 'employee.manager'])
            ->where('date', $today)
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where('missing_checkout', false)
            ->get();

        foreach ($records as $record) {
            $record->update(['missing_checkout' => true]);

            $employee = $record->employee;
            $notification = new MissingCheckoutNotification($record);

            $employee->user?->notify($notification);

            if ($employee->manager_id) {
                $employee->manager?->notify($notification);
            }
        }

        $this->info("Flagged {$records->count()} attendance record(s) as missing check-out for {$today}.");

        return self::SUCCESS;
    }
}
