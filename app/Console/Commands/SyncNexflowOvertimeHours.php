<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\NexflowOtSyncLog;
use App\Models\OtRequest;
use App\Models\OtWindow;
use App\Models\ShiftSetting;
use App\Notifications\NexflowOvertimeDetectedNotification;
use App\Services\NexflowApiService;
use App\Services\OvertimeService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SyncNexflowOvertimeHours extends Command
{
    protected $signature = 'hrms:sync-nexflow-ot
                            {--date= : Date to sync (YYYY-MM-DD), defaults to yesterday}
                            {--dry-run : Show what would be created without writing to DB}';

    protected $description = 'Fetch Nexflow clock summaries and auto-create pending OT requests when hours exceed shift threshold';

    public function handle(NexflowApiService $nexflow, OvertimeService $otService): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : now()->subDay();

        $isDryRun = (bool) $this->option('dry-run');

        if ($date->isWeekend()) {
            $this->info("Skipping {$date->toDateString()} — weekend.");

            return self::SUCCESS;
        }

        // Only employees with nexflow or hybrid OT tracking source
        $employees = Employee::where('status', 'active')
            ->whereIn('ot_tracking_source', ['nexflow', 'hybrid'])
            ->with(['user', 'shift'])
            ->get();

        $created = 0;
        $skipped = 0;
        $autoApproved = 0;

        foreach ($employees as $employee) {
            if (! $employee->user?->email) {
                continue;
            }

            $threshold = (float) ($employee->shift?->ot_threshold_hours ?? ShiftSetting::DEFAULT_OT_THRESHOLD ?? 9.0);

            // Per-employee OtWindow check (skip, don't abort globally)
            if (! OtWindow::isOpenFor($date)) {
                $skipped++;

                if (! $isDryRun) {
                    NexflowOtSyncLog::create([
                        'employee_id' => $employee->id,
                        'sync_date' => $date->toDateString(),
                        'net_hours' => 0,
                        'shift_threshold' => $threshold,
                        'ot_hours_detected' => 0,
                        'action' => 'skipped',
                        'skip_reason' => 'No active OT window',
                    ]);
                }

                continue;
            }

            $summary = $nexflow->getClockSummary(
                $employee->user->email,
                $date->toDateString(),
                $date->toDateString()
            );

            if (! $summary || empty($summary['days'])) {
                $skipped++;

                continue;
            }

            $dayData = collect($summary['days'])->firstWhere('date', $date->toDateString());

            if (! $dayData) {
                $skipped++;

                continue;
            }

            $totalHours = (float) ($dayData['total_hours'] ?? 0);

            if ($totalHours <= $threshold) {
                $skipped++;

                continue;
            }

            $otHours = round($totalHours - $threshold, 2);
            $nexflowRef = $dayData['ref'] ?? null;

            // Duplicate check
            $exists = OtRequest::where('employee_id', $employee->id)
                ->where('work_date', $date->toDateString())
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            if ($exists) {
                $skipped++;

                if (! $isDryRun) {
                    NexflowOtSyncLog::create([
                        'employee_id' => $employee->id,
                        'sync_date' => $date->toDateString(),
                        'net_hours' => $totalHours,
                        'shift_threshold' => $threshold,
                        'ot_hours_detected' => $otHours,
                        'action' => 'duplicate',
                        'skip_reason' => 'OT request already exists for this date',
                    ]);
                }

                continue;
            }

            if ($isDryRun) {
                $this->line("  [dry-run] Would create OT request for {$employee->user->name} on {$date->toDateString()} — {$otHours}h excess");
                $created++;

                continue;
            }

            // Derive a synthetic OT window: threshold marks end-of-standard-day, OT starts there.
            $shiftStartHour = (int) ($employee->shift?->start_time ? date('H', strtotime($employee->shift->start_time)) : 9);
            $otStartTime = sprintf('%02d:00', ($shiftStartHour + (int) $threshold) % 24);
            $otEndMinutes = (int) round($otHours * 60);
            $otEndTime = date('H:i', strtotime($otStartTime) + ($otEndMinutes * 60));

            $otRequest = OtRequest::create([
                'employee_id' => $employee->id,
                'work_date' => $date->toDateString(),
                'start_time' => $otStartTime,
                'end_time' => $otEndTime,
                'requested_hours' => $otHours,
                'reason' => "Auto-detected via Nexflow: worked {$totalHours}h (threshold {$threshold}h).",
                'status' => 'pending',
                'source' => 'nexflow',
                'nexflow_ref' => $nexflowRef,
            ]);

            Log::info('[NexflowOT] Created OT request from Nexflow sync.', [
                'employee_id' => $employee->id,
                'date' => $date->toDateString(),
                'ot_hours' => $otHours,
                'nexflow_ref' => $nexflowRef,
            ]);

            // Auto-approve for pure nexflow employees
            $action = 'created';
            if ($employee->ot_tracking_source === 'nexflow') {
                $otService->autoApprove($otRequest);
                $action = 'auto_approved';
                $autoApproved++;
            } else {
                $created++;
            }

            NexflowOtSyncLog::create([
                'employee_id' => $employee->id,
                'sync_date' => $date->toDateString(),
                'net_hours' => $totalHours,
                'shift_threshold' => $threshold,
                'ot_hours_detected' => $otHours,
                'ot_request_id' => $otRequest->id,
                'action' => $action,
            ]);

            // Notify the employee
            if ($employee->user) {
                $employee->user->notify(new NexflowOvertimeDetectedNotification($otRequest, $employee));
            }
        }

        $this->info("Nexflow OT sync for {$date->toDateString()}: {$created} created, {$autoApproved} auto-approved, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
