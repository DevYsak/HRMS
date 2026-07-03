<?php

namespace App\Services\Biometric;

use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Support\PunchMethodResolver;
use Illuminate\Support\Facades\Http;

/**
 * Pulls pre-calculated daily attendance from the external Python attendance
 * engine (GET /api/dashboard?date=) and upserts it into HRMS.
 *
 * Shared by the scheduled command (attendance:sync-engine) and the on-demand
 * "Quick Scan" button on the Biometric Summary page. HRMS never recalculates —
 * it stores exactly what the engine computed; rows are matched by employee_code.
 */
class EngineAttendanceSyncService
{
    /**
     * Sync one date from the engine.
     *
     * @return array{synced:int, skipped:int, error:?string}
     */
    public function syncDate(string $date): array
    {
        $baseUrl = rtrim((string) config('services.biometric_app.url'), '/');

        if ($baseUrl === '') {
            return ['synced' => 0, 'skipped' => 0, 'error' => 'Attendance engine URL (BIOMETRIC_APP_URL) is not configured.'];
        }

        try {
            $response = Http::timeout((int) config('services.biometric_app.timeout', 10))
                ->when(! config('services.biometric_app.verify_ssl', true), fn ($r) => $r->withoutVerifying())
                ->acceptJson()
                ->get("{$baseUrl}/api/dashboard", ['date' => $date]);
        } catch (\Throwable $e) {
            return ['synced' => 0, 'skipped' => 0, 'error' => 'Could not reach the attendance engine.'];
        }

        if (! $response->successful()) {
            return ['synced' => 0, 'skipped' => 0, 'error' => "Engine returned HTTP {$response->status()}."];
        }

        $rows = $response->json('table') ?? [];
        $employeeMap = Employee::whereNotNull('employee_code')->pluck('id', 'employee_code');

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
            $firstMethod = PunchMethodResolver::value($row['first_punch_method'] ?? $row['first_punch_verify'] ?? null);
            $lastMethod = PunchMethodResolver::value($row['last_punch_method'] ?? $row['last_punch_verify'] ?? null);
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
                    'first_punch_method' => $firstMethod,
                    'last_punch_method' => $lastMethod,
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

            // Core attendance row so the standard pages + reports + payroll reflect it.
            if ($firstPunch !== null) {
                Attendance::updateOrCreate(
                    ['employee_id' => $employeeId, 'date' => $date],
                    [
                        'check_in' => $firstPunch,
                        'check_out' => $lastPunch,
                        'check_in_method' => $firstMethod,
                        'check_out_method' => $lastMethod,
                        'total_hours' => $workingHours,
                        'break_minutes' => $breakMinutes,
                        'status' => $isLate ? 'late' : 'on_time',
                        'is_late' => $isLate,
                        'late_minutes' => $lateMinutes,
                        'work_mode' => 'office',
                    ]
                );
            }

            // Every individual punch (Attendance Journey) — when the engine sends them.
            $this->syncPunches($employeeId, $code, $date, $row['punches'] ?? [], $row['device_serial'] ?? null);

            $synced++;
        }

        return ['synced' => $synced, 'skipped' => $skipped, 'error' => null];
    }

    /** Combine the engine's "HH:MM:SS" time with the attendance date, or null. */
    private function punchDateTime(string $date, ?string $time): ?string
    {
        return $time ? "{$date} {$time}" : null;
    }

    /**
     * Upsert every individual punch of the day for the Attendance Journey.
     * Accepts the engine's `punches` array of {time|punch_dt, verify|method,
     * source?, device?, location?, lat?, lng?}. Idempotent on (employee, time).
     *
     * @param  array<int, array<string, mixed>>  $punches
     */
    private function syncPunches(int $employeeId, ?int $code, string $date, array $punches, ?string $deviceSerial): void
    {
        foreach ($punches as $p) {
            if (! is_array($p)) {
                continue;
            }

            $raw = trim((string) ($p['time'] ?? $p['punch_dt'] ?? ''));
            if ($raw === '') {
                continue;
            }

            // Time-only ("09:02:00") → anchor to the date; full datetime → as-is.
            $punchedAt = strlen($raw) <= 8 ? "{$date} {$raw}" : $raw;
            $rawVerify = $p['verify'] ?? $p['method'] ?? $p['verify_type'] ?? null;

            AttendancePunch::updateOrCreate(
                ['employee_id' => $employeeId, 'punched_at' => $punchedAt],
                [
                    'employee_code' => $code,
                    'punch_date' => $date,
                    'method' => PunchMethodResolver::value($rawVerify),
                    'verify_raw' => $rawVerify !== null && $rawVerify !== '' ? (string) $rawVerify : null,
                    'source' => $p['source'] ?? 'biometric',
                    'device_serial' => $p['device'] ?? $deviceSerial,
                    'location' => $p['location'] ?? null,
                    'lat' => $p['lat'] ?? null,
                    'lng' => $p['lng'] ?? null,
                ]
            );
        }
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
