<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\OtWindow;
use App\Models\ShiftSetting;
use App\Services\NexflowApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SyncNexflowOvertimeHours extends Command
{
    protected $signature = 'hrms:sync-nexflow-ot
                            {--date= : Date to sync (YYYY-MM-DD), defaults to yesterday}
                            {--dry-run : Show what would be created without writing to DB}';

    protected $description = 'Fetch Nexflow clock summaries and auto-create pending OT requests when hours exceed shift threshold';

    public function handle(NexflowApiService $nexflow): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : now()->subDay();

        $isDryRun = (bool) $this->option('dry-run');

        if ($date->isWeekend()) {
            $this->info("Skipping {$date->toDateString()} — weekend.");

            return self::SUCCESS;
        }

        if (! OtWindow::isOpenFor($date)) {
            $this->info("No active OT window for {$date->toDateString()} — skipping sync.");

            return self::SUCCESS;
        }

        $employees = Employee::where('status', 'active')
            ->with(['user', 'shift'])
            ->get();

        $created = 0;
        $skipped = 0;

        foreach ($employees as $employee) {
            if (! $employee->user?->email) {
                continue;
            }

            $summary = $nexflow->getClockSummary(
                $employee->user->email,
                $date->toDateString(),
                $date->toDateString()
            );

            if (! $summary || empty($summary['days'])) {
                continue;
            }

            $dayData = collect($summary['days'])
                ->firstWhere('date', $date->toDateString());

            if (! $dayData) {
                continue;
            }

            $totalHours = (float) ($dayData['total_hours'] ?? 0);
            $threshold = (float) ($employee->shift?->ot_threshold_hours ?? ShiftSetting::DEFAULT_OT_THRESHOLD ?? 9.0);

            if ($totalHours <= $threshold) {
                $skipped++;

                continue;
            }

            $otHours = round($totalHours - $threshold, 2);

            // Skip if an OT request already exists for this employee on this date
            $exists = OtRequest::where('employee_id', $employee->id)
                ->where('work_date', $date->toDateString())
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            if ($isDryRun) {
                $this->line("  [dry-run] Would create OT request for {$employee->user->name} on {$date->toDateString()} — {$otHours}h excess");
                $created++;

                continue;
            }

            OtRequest::create([
                'employee_id' => $employee->id,
                'work_date' => $date->toDateString(),
                'requested_hours' => $otHours,
                'reason' => "Auto-detected via Nexflow: worked {$totalHours}h (threshold {$threshold}h).",
                'status' => 'pending',
            ]);

            Log::info('[NexflowOT] Created OT request from Nexflow sync.', [
                'employee_id' => $employee->id,
                'date' => $date->toDateString(),
                'ot_hours' => $otHours,
            ]);

            $created++;
        }

        $this->info("Nexflow OT sync for {$date->toDateString()}: {$created} request(s) created, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
