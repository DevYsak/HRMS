<?php

namespace App\Services\Biometric;

use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Services\Attendance\PunchClassifier;
use App\Support\PunchMethodResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Pulls pre-calculated daily attendance from the external Python attendance
 * engine (GET /api/dashboard?date=) and upserts it into HRMS.
 *
 * Shared by the scheduled command (attendance:sync-engine) and the on-demand
 * "Quick Scan" button on the Biometric Summary page. Rows are matched by
 * employee_code. The engine pairs real device IN/OUT direction, so its
 * working_min / break_min / inside figures are stored as-is; HRMS only
 * re-derives them from the raw punch stream when the engine sends no totals
 * (its own naive time-based dedup would otherwise corrupt the engine's math).
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

            // The engine reports whether the employee is currently inside (its
            // last punch is an IN with no matching OUT). When inside, the last
            // punch is NOT a clock-out, so leave check_out open.
            $inside = ! empty($row['inside']);
            $checkOut = $inside ? null : $lastPunch;

            // The engine pairs real device IN/OUT direction, so its break_min /
            // working_min are authoritative. Only fall back to deriving them from
            // HRMS's punch stream when the engine sent no totals at all.
            $engineProvidedTotals = array_key_exists('working_min', $row) || array_key_exists('break_min', $row);

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
                        'check_out' => $checkOut,
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

            // Every individual punch (Attendance Journey) — when the engine sends
            // them, tagged with the engine's real IN/OUT direction (from events).
            $this->syncPunches($employeeId, $code, $date, $row['punches'] ?? [], $row['events'] ?? [], $row['device_serial'] ?? null);

            // Only derive break/working from HRMS's own punch stream when the
            // engine sent no totals. The engine pairs real device direction, so
            // when it provides break_min/working_min they are authoritative —
            // re-deriving here (naive time-based dedup + alternation) corrupts
            // them, e.g. an 18m break becoming 114m.
            if (! $engineProvidedTotals) {
                $this->reconcileFromPunches($employeeId, $date, $firstPunch, $lastPunch);
            }

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
     * source?, device?, location?, lat?, lng?} and its `events` array of
     * {time, type} — the engine's authoritative IN/OUT direction, matched to a
     * punch by its time. Idempotent on (employee, time).
     *
     * @param  array<int, array<string, mixed>>  $punches
     * @param  array<int, array<string, mixed>>  $events
     */
    private function syncPunches(int $employeeId, ?int $code, string $date, array $punches, array $events, ?string $deviceSerial): void
    {
        // Map "HH:MM:SS" → in|out from the engine's directional events.
        $directionByTime = [];
        foreach ($events as $e) {
            if (! is_array($e)) {
                continue;
            }
            $t = Carbon::parse(trim((string) ($e['time'] ?? '')))->format('H:i:s');
            $type = strtolower((string) ($e['type'] ?? ''));
            if ($type === 'in' || $type === 'out') {
                $directionByTime[$t] = $type;
            }
        }

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
            $direction = $directionByTime[Carbon::parse($punchedAt)->format('H:i:s')] ?? null;

            AttendancePunch::updateOrCreate(
                ['employee_id' => $employeeId, 'punched_at' => $punchedAt],
                [
                    'employee_code' => $code,
                    'punch_date' => $date,
                    'method' => PunchMethodResolver::value($rawVerify),
                    'direction' => $direction,
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

    /**
     * Recompute break minutes and working hours from the day's actual punch
     * stream (via the shared PunchClassifier) and overwrite the engine-supplied
     * figures. Only runs when there are enough punches for breaks to matter
     * (in + at least one break pair); otherwise the engine values stand.
     */
    private function reconcileFromPunches(int $employeeId, string $date, ?string $firstPunch, ?string $lastPunch): void
    {
        if ($firstPunch === null) {
            return;
        }

        $punches = AttendancePunch::where('employee_id', $employeeId)
            ->whereDate('punch_date', $date)
            ->orderBy('punched_at')
            ->get();

        if ($punches->count() < 3) {
            return;
        }

        $breakMinutes = app(PunchClassifier::class)->breakMinutes($punches);
        $grossMinutes = $lastPunch !== null
            ? (int) Carbon::parse($firstPunch)->diffInMinutes(Carbon::parse($lastPunch))
            : 0;
        $workingHours = round(max(0, $grossMinutes - $breakMinutes) / 60, 2);

        Attendance::where('employee_id', $employeeId)->whereDate('date', $date)
            ->update(['break_minutes' => $breakMinutes, 'total_hours' => $workingHours]);

        AttendanceDailySummary::where('employee_id', $employeeId)->whereDate('date', $date)
            ->update(['break_minutes' => $breakMinutes, 'working_hours' => $workingHours]);
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
