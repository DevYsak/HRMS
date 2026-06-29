<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Pulls pre-calculated daily attendance from the external Python attendance
 * engine (GET /api/dashboard?date=) and upserts it into attendance_daily_summaries.
 *
 * HRMS never recalculates — it stores exactly what the engine computed. Rows are
 * matched to employees by employee_code (the device PIN); unmatched PINs are skipped.
 */
class SyncEngineAttendance extends Command
{
    protected $signature = 'attendance:sync-engine
                            {--date= : End date to sync (Y-m-d); defaults to today}
                            {--days=1 : Number of days back to sync, ending at --date}';

    protected $description = 'Pull computed daily attendance from the Python biometric engine into attendance_daily_summaries.';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('services.biometric_app.url'), '/');

        if ($baseUrl === '') {
            $this->error('BIOMETRIC_APP_URL is not configured — cannot reach the attendance engine.');

            return self::FAILURE;
        }

        $endDate = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        $days = max(1, (int) $this->option('days'));

        $employeeMap = Employee::whereNotNull('employee_code')->pluck('id', 'employee_code');

        $totalSynced = 0;
        $totalSkipped = 0;

        for ($i = 0; $i < $days; $i++) {
            $date = $endDate->copy()->subDays($i)->toDateString();
            [$synced, $skipped] = $this->syncDate($baseUrl, $date, $employeeMap);

            if ($synced === null) {
                return self::FAILURE;
            }

            $totalSynced += $synced;
            $totalSkipped += $skipped;
            $this->line("  {$date}: synced <info>{$synced}</info>, skipped {$skipped}");
        }

        $this->info("Done. Synced {$totalSynced}, skipped {$totalSkipped} (unmatched PINs).");

        return self::SUCCESS;
    }

    /**
     * Sync one date. Returns [synced, skipped], or [null, 0] when the engine is unreachable.
     *
     * @param  Collection<int, int>  $employeeMap  employee_code => employee_id
     * @return array{0:int|null,1:int}
     */
    private function syncDate(string $baseUrl, string $date, $employeeMap): array
    {
        try {
            $response = Http::timeout((int) config('services.biometric_app.timeout', 10))
                ->when(! config('services.biometric_app.verify_ssl', true), fn ($r) => $r->withoutVerifying())
                ->acceptJson()
                ->get("{$baseUrl}/api/dashboard", ['date' => $date]);
        } catch (\Throwable $e) {
            $this->error("  {$date}: cannot reach the engine — {$e->getMessage()}");

            return [null, 0];
        }

        if (! $response->successful()) {
            $this->error("  {$date}: engine returned HTTP {$response->status()}");

            return [null, 0];
        }

        $rows = $response->json('table') ?? [];
        $synced = 0;
        $skipped = 0;
        $now = now();

        foreach ($rows as $row) {
            $code = isset($row['emp_id']) && is_numeric($row['emp_id']) ? (int) $row['emp_id'] : null;
            $employeeId = $code !== null ? ($employeeMap[$code] ?? null) : null;

            if ($employeeId === null) {
                $skipped++;

                continue;
            }

            $firstPunch = $this->punchDateTime($date, $row['first_punch'] ?? null);
            $lastPunch = $this->punchDateTime($date, $row['last_punch'] ?? null);
            $workingHours = round(((int) ($row['working_min'] ?? 0)) / 60, 2);
            $breakMinutes = (int) ($row['break_min'] ?? 0);
            $lateMinutes = (int) ($row['delay_min'] ?? 0);
            $isLate = ! empty($row['late']);

            // Rich biometric figures — backs the read-only Biometric Summary page.
            AttendanceDailySummary::updateOrCreate(
                ['employee_id' => $employeeId, 'date' => $date],
                [
                    'employee_code' => $code,
                    'first_punch' => $firstPunch,
                    'last_punch' => $lastPunch,
                    'break_minutes' => $breakMinutes,
                    'working_hours' => $workingHours,
                    'late_minutes' => $lateMinutes,
                    'early_leave_minutes' => 0,
                    'overtime_minutes' => (int) ($row['overtime_min'] ?? 0),
                    'status' => $this->mapStatus($row),
                    'device_serial' => null,
                    'raw_punch_count' => (int) ($row['punch_count'] ?? 0),
                    'synced_at' => $now,
                ]
            );

            // Core attendance row so the standard pages (My/Team/All Attendance,
            // profile tab, reports, payroll) reflect the engine data. Requires a
            // punch-in since attendances.check_in is NOT NULL.
            if ($firstPunch !== null) {
                Attendance::updateOrCreate(
                    ['employee_id' => $employeeId, 'date' => $date],
                    [
                        'check_in' => $firstPunch,
                        'check_out' => $lastPunch,
                        'total_hours' => $workingHours,
                        'break_minutes' => $breakMinutes,
                        'status' => $isLate ? 'late' : 'on_time',
                        'is_late' => $isLate,
                        'late_minutes' => $lateMinutes,
                        'work_mode' => 'office',
                    ]
                );
            }

            $synced++;
        }

        return [$synced, $skipped];
    }

    /** Combine the engine's "HH:MM:SS" time with the attendance date, or null. */
    private function punchDateTime(string $date, ?string $time): ?string
    {
        return $time ? "{$date} {$time}" : null;
    }

    /** Normalise the engine's status into HRMS's vocabulary. */
    private function mapStatus(array $row): string
    {
        if ((int) ($row['punch_count'] ?? 0) < 1) {
            return 'absent';
        }

        return ! empty($row['late']) ? 'late' : 'present';
    }
}
