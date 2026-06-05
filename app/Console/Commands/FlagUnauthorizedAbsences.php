<?php

namespace App\Console\Commands;

use App\Services\LeaveService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FlagUnauthorizedAbsences extends Command
{
    protected $signature = 'hrms:flag-unauthorized-absences {--date= : Date to check (YYYY-MM-DD), defaults to yesterday}';

    protected $description = 'Auto-flag absences with no approved leave or regularisation as Unauthorized Leave';

    public function handle(LeaveService $leaveService): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : now()->subDay();

        if ($date->isWeekend()) {
            $this->info("Skipping {$date->toDateString()} — weekend.");

            return self::SUCCESS;
        }

        $flagged = $leaveService->autoFlagUnauthorizedAbsences($date);

        $this->info("Flagged {$flagged} unauthorized absence(s) for {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
