<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use Illuminate\Console\Command;

class FlagMissingCheckouts extends Command
{
    protected $signature = 'hrms:flag-missing-checkouts';

    protected $description = 'Flag today\'s attendance records with no check-out. Runs at 21:00 IST (shift end + 1 hr).';

    public function handle(): int
    {
        $today = now()->toDateString();

        $flagged = Attendance::where('date', $today)
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where('missing_checkout', false)
            ->update(['missing_checkout' => true]);

        $this->info("Flagged {$flagged} attendance record(s) as missing check-out for {$today}.");

        return self::SUCCESS;
    }
}
