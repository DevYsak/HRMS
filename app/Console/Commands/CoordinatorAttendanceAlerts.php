<?php

namespace App\Console\Commands;

use App\Services\Attendance\CoordinatorService;
use Illuminate\Console\Command;

/**
 * Tells each coordinator about attendance exceptions among the people they
 * monitor. Safe to run often: an exception is reported once, and repeated
 * only when the configured reminder interval rolls over while it is still
 * unresolved.
 */
class CoordinatorAttendanceAlerts extends Command
{
    protected $signature = 'hrms:coordinator-attendance-alerts';

    protected $description = 'Notify coordinators of new attendance exceptions (deduplicated, interval-based reminders)';

    public function handle(CoordinatorService $coordinators): int
    {
        $count = $coordinators->alertCoordinators();
        $this->info("Notified {$count} coordinator(s).");

        return self::SUCCESS;
    }
}
