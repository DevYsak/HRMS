<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use Illuminate\Console\Command;

class FlagMissingCheckouts extends Command
{
    protected $signature   = 'hrms:flag-missing-checkouts';
    protected $description = 'Flag attendance records from yesterday with no check-out.';

    public function handle(): int
    {
        $yesterday = now()->subDay()->toDateString();

        $flagged = Attendance::where('date', $yesterday)
            ->whereNull('check_out')
            ->where('missing_checkout', false)
            ->update(['missing_checkout' => true]);

        $this->info("Flagged {$flagged} attendance record(s) as missing check-out.");

        return self::SUCCESS;
    }
}
