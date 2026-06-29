<?php

namespace App\Console\Commands;

use App\Services\Biometric\EngineAttendanceSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pulls pre-calculated daily attendance from the external Python attendance
 * engine into HRMS (attendances + attendance_daily_summaries) via
 * EngineAttendanceSyncService. HRMS never recalculates; matched by employee_code.
 */
class SyncEngineAttendance extends Command
{
    protected $signature = 'attendance:sync-engine
                            {--date= : End date to sync (Y-m-d); defaults to today}
                            {--days=1 : Number of days back to sync, ending at --date}';

    protected $description = 'Pull computed daily attendance from the Python biometric engine into HRMS.';

    public function handle(EngineAttendanceSyncService $service): int
    {
        $endDate = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        $days = max(1, (int) $this->option('days'));

        $totalSynced = 0;
        $totalSkipped = 0;

        for ($i = 0; $i < $days; $i++) {
            $date = $endDate->copy()->subDays($i)->toDateString();
            $result = $service->syncDate($date);

            if ($result['error'] !== null) {
                $this->error("  {$date}: {$result['error']}");

                return self::FAILURE;
            }

            $totalSynced += $result['synced'];
            $totalSkipped += $result['skipped'];
            $this->line("  {$date}: synced <info>{$result['synced']}</info>, skipped {$result['skipped']}");
        }

        $this->info("Done. Synced {$totalSynced}, skipped {$totalSkipped} (unmatched PINs).");

        return self::SUCCESS;
    }
}
