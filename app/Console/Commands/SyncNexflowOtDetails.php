<?php

namespace App\Console\Commands;

use App\Services\OvertimeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Pull overtime from the Nexflow ot-details endpoint for all active employees:
 * import approved OT into payroll and record rejected OT for visibility.
 * Scheduled every 10 minutes; also triggered manually from Manage OT Requests.
 */
#[Signature('hrms:sync-nexflow-ot-details {--from= : Start date YYYY-MM-DD} {--to= : End date YYYY-MM-DD} {--eligible-only : Only nexflow/hybrid employees}')]
#[Description('Sync approved/rejected overtime from Nexflow ot-details into HRMS OT requests')]
class SyncNexflowOtDetails extends Command
{
    public function handle(OvertimeService $overtime): int
    {
        $result = $overtime->syncNexflowOtDetails(
            $this->option('from'),
            $this->option('to'),
            (bool) $this->option('eligible-only'),
        );

        $this->info(sprintf(
            'Nexflow OT sync: %d approved imported, %d rejected recorded, %d already synced across %d employees.',
            $result['imported'],
            $result['rejected'],
            $result['skipped'],
            $result['employees'],
        ));

        return self::SUCCESS;
    }
}
