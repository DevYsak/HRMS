<?php

namespace App\Services\Biometric;

use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\PunchTimeline;
use App\Services\Attendance\ShiftResolver;
use App\Services\Attendance\WorkingDayResolver;
use App\Support\PunchMethodResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Pulls pre-calculated daily attendance from the external Python attendance
 * engine (GET /api/dashboard?date=) and upserts it into HRMS.
 *
 * Shared by the scheduled command (attendance:sync-engine) and the on-demand
 * "Quick Scan" button on the Biometric Summary page. Rows are matched by
 * employee_code. Whenever the day's punches are in HRMS, the attendance row
 * is built from the canonical PunchTimeline (Face = IN, ID Card = OUT, latest
 * of a 60-second burst, stray cards ignored) — the same numbers every screen
 * shows. The engine's own first/last punch and totals are only the fallback
 * for a day it sent no punch stream for, and stay on the raw daily summary.
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
            $breakMinutes = (int) ($row['break_min'] ?? 0);
            $lateMinutes = (int) ($row['delay_min'] ?? 0);
            $isLate = ! empty($row['late']);

            // Weekly off / holiday: punches are stored as they are (Worked on
            // Weekly Off) but never late; no punches is a weekly off, not absent.
            $dayState = $this->dayState($employeeId, $date);
            if ($dayState !== WorkingDayResolver::WORKING_DAY) {
                $isLate = false;
                $lateMinutes = 0;
            }

            // The engine reports whether the employee is currently inside (its
            // last punch is an IN with no matching OUT). When inside, the last
            // punch is NOT a clock-out, so leave check_out open.
            $inside = ! empty($row['inside']);
            $checkOut = $inside ? null : $lastPunch;

            // Every individual punch first (Attendance Journey) — the raw
            // stream the canonical timeline is built from.
            $this->syncPunches($employeeId, $code, $date, $row['punches'] ?? [], $row['events'] ?? [], $row['device_serial'] ?? null);

            // Rule 10 — with the punches in HRMS, the timeline decides first
            // IN, final OUT and break. A day of stray card taps only has no
            // valid IN and gets no attendance row (no phantom hours).
            $rowIn = $firstPunch;
            $rowOut = $checkOut;
            $rowInMethod = $firstMethod;
            $rowOutMethod = $lastMethod;
            if ($processed = $this->processedDay($employeeId, $date)) {
                $rowIn = $processed['in'];
                $rowOut = $processed['out'];
                $rowInMethod = $processed['in_method'] ?? $firstMethod;
                $rowOutMethod = $processed['out_method'];
                $breakMinutes = $processed['break'];
            }

            // Pulse v3.1: worked = final clock-out − first clock-in; breaks are
            // information only and never deducted.
            $workingHours = app(AttendanceCalculator::class)->storedHours(
                $rowIn ? Carbon::parse($rowIn) : null,
                $rowOut ? Carbon::parse($rowOut) : null,
            );

            // Late is judged against the employee's HRMS shift (start + grace),
            // not the engine's own shift table.
            if ($rowIn !== null && $dayState === WorkingDayResolver::WORKING_DAY
                && ($shiftEmployee = Employee::with('shift')->find($employeeId))
                && ($shift = app(ShiftResolver::class)->resolve($shiftEmployee, Carbon::parse($date)))) {
                $isLate = $shift->isLate(Carbon::parse($rowIn));
                $lateMinutes = $shift->lateMinutes(Carbon::parse($rowIn));
            }

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
                    // The same worked figure as the attendance row.
                    'working_hours' => $workingHours,
                    'late_minutes' => $lateMinutes,
                    'early_leave_minutes' => 0,
                    'overtime_minutes' => (int) ($row['overtime_min'] ?? 0),
                    'status' => $this->mapStatus($row, $dayState),
                    'device_serial' => null,
                    'raw_punch_count' => (int) ($row['punch_count'] ?? 0),
                    'synced_at' => $now,
                ]
            );

            // Core attendance row so the standard pages + reports + payroll reflect it.
            // Never over an approved regularisation: the correction (with its
            // original-value snapshot) is the record of truth for that day, and
            // the 10-minute sync / nightly re-sync would otherwise silently put
            // the raw device punches back.
            $existing = Attendance::where('employee_id', $employeeId)->where('date', $date)->first();

            if ($rowIn !== null && $existing?->is_regularized && ! $existing->hasCorrectedPunches()) {
                // A half-day (status-only) regularisation: record the real
                // punches, keep the approved status and late flags.
                $existing->update([
                    'check_in' => $rowIn,
                    'check_out' => $rowOut,
                    'check_in_method' => $rowInMethod,
                    'check_out_method' => $rowOutMethod,
                    'total_hours' => $workingHours,
                    'break_minutes' => $breakMinutes,
                ]);
            } elseif ($rowIn !== null && ! $existing?->is_regularized) {
                Attendance::updateOrCreate(
                    ['employee_id' => $employeeId, 'date' => $date],
                    [
                        'check_in' => $rowIn,
                        'check_out' => $rowOut,
                        'check_in_method' => $rowInMethod,
                        'check_out_method' => $rowOutMethod,
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
     * The day as the canonical timeline reads the punches HRMS holds — first
     * valid IN, final valid OUT (null while open), break from valid OUT → next
     * IN — or null when there is no punch stream for the day.
     *
     * @return array{in: ?string, out: ?string, in_method: ?string, out_method: ?string, break: int}|null
     */
    private function processedDay(int $employeeId, string $date): ?array
    {
        $punches = AttendancePunch::where('employee_id', $employeeId)
            ->whereDate('punch_date', $date)
            ->orderBy('punched_at')
            ->get();

        if ($punches->isEmpty()) {
            return null;
        }

        $t = app(PunchTimeline::class)->process($punches, Carbon::parse($date));
        $method = fn (?Carbon $at) => $at ? $punches->first(fn (AttendancePunch $p) => $p->punched_at->equalTo($at))?->method : null;

        return [
            'in' => $t['first_in_at']?->format('Y-m-d H:i:s'),
            'out' => $t['last_out_at']?->format('Y-m-d H:i:s'),
            'in_method' => $method($t['first_in_at']),
            'out_method' => $method($t['last_out_at']),
            'break' => (int) $t['break_minutes'],
        ];
    }

    /** Normalise the engine's status into HRMS's vocabulary. */
    private function mapStatus(array $row, string $dayState = WorkingDayResolver::WORKING_DAY): string
    {
        if ((int) ($row['punch_count'] ?? 0) < 1) {
            return $dayState === WorkingDayResolver::WEEKLY_OFF ? 'weekly_off' : 'absent';
        }

        return ! empty($row['late']) && $dayState === WorkingDayResolver::WORKING_DAY ? 'late' : 'present';
    }

    /** The shared working-day decision for one employee and date. */
    private function dayState(int $employeeId, string $date): string
    {
        $employee = Employee::with('exitRecord')->find($employeeId);

        return $employee
            ? app(WorkingDayResolver::class)->classify($employee, Carbon::parse($date), withLeave: false)
            : WorkingDayResolver::WORKING_DAY;
    }
}
