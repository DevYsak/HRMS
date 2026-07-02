<?php

namespace App\Services\Biometric;

use App\Models\Attendance;
use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Models\Employee;
use App\Services\AttendanceService;
use App\Support\PunchMethodResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * BiometricSyncService — orchestrates pulling punch data from a ZKTeco device
 * and writing it into the HRMS attendance records.
 *
 * Usage:
 *   $service = new BiometricSyncService(new AttendanceService());
 *   $result  = $service->syncDevice($device);
 */
class BiometricSyncService
{
    public bool $debug = false;

    public function __construct(
        private readonly AttendanceService $attendanceService,
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Ping every active device and update its last_ping_* columns.
     *
     * @return array<int, array{id: int, name: string, status: string, error: string|null}>
     */
    public function pingAllDevices(): array
    {
        $results = [];

        foreach (BiometricDevice::where('is_active', true)->get() as $device) {
            $results[] = $this->pingDevice($device);
        }

        return $results;
    }

    /**
     * Ping one device and persist the result.
     *
     * @return array{id: int, name: string, status: string, error: string|null}
     */
    public function pingDevice(BiometricDevice $device): array
    {
        $zk = $this->makeZKService($device);
        $error = null;

        try {
            $online = $zk->ping();
            $status = $online ? 'online' : 'offline';
        } catch (Throwable $e) {
            $status = 'error';
            $error = $e->getMessage();
        }

        $device->update([
            'last_ping_at' => now(),
            'last_ping_status' => $status,
            'last_ping_error' => $error,
        ]);

        return ['id' => $device->id, 'name' => $device->name, 'status' => $status, 'error' => $error];
    }

    /**
     * Full sync cycle for one device:
     *   1. Fetch raw punch logs from device
     *   2. Upsert into biometric_logs (dedup by device+user+timestamp)
     *   3. Apply unprocessed logs to the attendances table
     *
     * @return array{fetched: int, inserted: int, applied: int, errors: int}
     *
     * @throws RuntimeException when the device is unreachable.
     */
    public function syncDevice(BiometricDevice $device): array
    {
        $zk = $this->makeZKService($device);
        $zk->connect();

        $fetched = 0;
        $inserted = 0;

        try {
            $rawLogs = $zk->getAttendance();
            $fetched = count($rawLogs);

            $inserted = $this->upsertRawLogs($device, $rawLogs);
        } finally {
            $zk->disconnect();
        }

        $applied = $this->applyUnprocessedLogs($device);
        $errors = BiometricLog::where('device_id', $device->id)
            ->where('is_processed', false)
            ->whereNotNull('process_error')
            ->count();

        $device->update([
            'last_synced_at' => now(),
            'last_sync_count' => $fetched,
            'last_ping_status' => 'online',
            'last_ping_at' => now(),
            'last_ping_error' => null,
        ]);

        return compact('fetched', 'inserted', 'applied', 'errors');
    }

    /**
     * Sync all active devices in sequence.
     *
     * @return array<int, array{device: string, result: array|null, error: string|null}>
     */
    public function syncAllDevices(): array
    {
        $summary = [];

        foreach (BiometricDevice::where('is_active', true)->get() as $device) {
            try {
                $result = $this->syncDevice($device);
                $summary[] = ['device' => $device->name, 'result' => $result, 'error' => null];
            } catch (Throwable $e) {
                Log::error("Biometric sync failed for {$device->name}: {$e->getMessage()}");

                $device->update([
                    'last_ping_at' => now(),
                    'last_ping_status' => 'error',
                    'last_ping_error' => $e->getMessage(),
                ]);

                $summary[] = ['device' => $device->name, 'result' => null, 'error' => $e->getMessage()];
            }
        }

        return $summary;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Employee push (HRMS → device)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Enrol or update one employee on the given biometric device.
     *
     * Marks sync_status = 'synced' on success, 'failed' on error.
     * Uses withoutEvents() when writing sync fields to avoid re-triggering the observer.
     *
     * @throws RuntimeException when the employee has no employee_code.
     */
    public function pushEmployee(Employee $employee, BiometricDevice $device): bool
    {
        if (! $employee->employee_code) {
            throw new RuntimeException("Employee#{$employee->id} has no employee_code — cannot push to device.");
        }

        $zk = $this->makeZKService($device);
        $zk->connect();

        try {
            $success = $zk->setUser(
                userId: $employee->employee_code,
                name: $employee->user->name ?? 'Unknown',
            );
        } finally {
            $zk->disconnect();
        }

        Employee::withoutEvents(function () use ($employee, $device, $success) {
            $employee->update([
                'sync_status' => $success ? 'synced' : 'failed',
                'last_biometric_sync_at' => now(),
                'biometric_device_id' => $device->id,
            ]);
        });

        return $success;
    }

    /**
     * Push all employees whose sync_status is 'pending' or 'failed' to their assigned device.
     *
     * Employees without an employee_code or biometric_device_id are skipped.
     *
     * @return array{pushed: int, failed: int, skipped: int, errors: list<string>}
     */
    public function pushAllPendingEmployees(): array
    {
        $employees = Employee::with(['user', 'biometricDevice'])
            ->whereNotNull('employee_code')
            ->whereNotNull('biometric_device_id')
            ->whereIn('sync_status', ['pending', 'failed'])
            ->get();

        $pushed = 0;
        $failed = 0;
        $errors = [];

        foreach ($employees as $employee) {
            try {
                $this->pushEmployee($employee, $employee->biometricDevice);
                $pushed++;
            } catch (Throwable $e) {
                Log::error("BiometricPush: employee#{$employee->id} failed — {$e->getMessage()}");

                Employee::withoutEvents(function () use ($employee) {
                    $employee->update([
                        'sync_status' => 'failed',
                        'last_biometric_sync_at' => now(),
                    ]);
                });

                $failed++;
                $errors[] = "Employee#{$employee->id} ({$employee->user?->name}): {$e->getMessage()}";
            }
        }

        return compact('pushed', 'failed', 'errors') + ['skipped' => 0];
    }

    /**
     * Remove an employee from a biometric device by deleting their enrolled user_id.
     *
     * Does not update sync_status — callers decide how to track removals.
     */
    public function removeEmployeeFromDevice(Employee $employee, BiometricDevice $device): bool
    {
        if (! $employee->employee_code) {
            return false;
        }

        $zk = $this->makeZKService($device);
        $zk->connect();

        try {
            return $zk->deleteUser($employee->employee_code);
        } finally {
            $zk->disconnect();
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Raw log storage
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Map raw device punch records to the biometric_logs table.
     * Uses upsert so re-running the sync is idempotent.
     *
     * Attendance MUST map only by employee_code (the numeric code enrolled on the device).
     * Name and email are NEVER used for matching — only employee_code is authoritative.
     *
     * @param  array<int, array{device_user_id: string, punched_at: string, state: int, verify: int}>  $rawLogs
     */
    private function upsertRawLogs(BiometricDevice $device, array $rawLogs): int
    {
        // Primary lookup: employee_code is the sole authoritative key for attendance mapping.
        // device_user_id from the punch log is cast to int for comparison (devices send "17", "003", etc.).
        $employeeMap = Employee::whereNotNull('employee_code')
            ->pluck('id', 'employee_code')
            ->mapWithKeys(fn ($id, $code) => [(string) $code => $id]);

        $inserted = 0;
        $punchTypes = config('biometric.punch_types', [0 => 'check_in', 1 => 'check_out']);
        $batchSize = config('biometric.sync.batch_size', 500);
        $chunks = array_chunk($rawLogs, $batchSize);

        foreach ($chunks as $chunk) {
            $rows = [];

            foreach ($chunk as $raw) {
                $punchType = $punchTypes[$raw['state']] ?? 'unknown';
                // Normalise device_user_id: devices may send "017", "17", or "  17" — cast to int string.
                $normalised = (string) (int) $raw['device_user_id'];
                $employeeId = $employeeMap[$normalised] ?? null;

                $rows[] = [
                    'device_id' => $device->id,
                    'device_user_id' => $normalised,   // always stored as normalised integer string
                    'employee_id' => $employeeId,
                    'punched_at' => $raw['punched_at'],
                    'punch_type' => $punchType,
                    'verify_type' => $raw['verify'],
                    'is_processed' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Ignore duplicates via unique(device_id, device_user_id, punched_at).
            $count = DB::table('biometric_logs')->insertOrIgnore($rows);
            $inserted += $count;
        }

        return $inserted;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Attendance application
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Process unprocessed biometric_logs that have a matched employee_id.
     * Creates or updates attendance records in the attendances table.
     *
     * @return int Number of logs successfully applied.
     */
    /** Public alias used by AdmsController after a push upload. */
    public function applyPendingLogs(BiometricDevice $device): int
    {
        return $this->applyUnprocessedLogs($device);
    }

    private function applyUnprocessedLogs(BiometricDevice $device): int
    {
        $applied = 0;

        $logs = BiometricLog::with('employee.shift')
            ->where('device_id', $device->id)
            ->where('is_processed', false)
            ->whereNotNull('employee_id')
            ->whereIn('punch_type', ['check_in', 'check_out'])
            ->orderBy('punched_at')
            ->cursor();  // memory-efficient for large result sets

        foreach ($logs as $log) {
            try {
                DB::transaction(function () use ($log, &$applied) {
                    $this->applyLog($log);
                    $applied++;
                });
            } catch (Throwable $e) {
                Log::warning("BiometricLog#{$log->id} apply failed: {$e->getMessage()}");

                $log->update([
                    'is_processed' => false,
                    'process_error' => substr($e->getMessage(), 0, 500),
                ]);
            }
        }

        return $applied;
    }

    /**
     * Apply a single biometric log entry to the attendance table.
     */
    private function applyLog(BiometricLog $log): void
    {
        $employee = $log->employee;
        $punchedAt = Carbon::parse($log->punched_at);
        $date = $punchedAt->toDateString();
        $method = $this->resolvePunchMethod($log->verify_type);

        if ($log->punch_type === 'check_in') {
            $attendance = $this->resolveCheckIn($employee, $punchedAt, $date, $method);
        } else {
            // check_out
            $attendance = $this->resolveCheckOut($employee, $punchedAt, $date, $method);
        }

        if ($attendance) {
            $log->update([
                'is_processed' => true,
                'attendance_id' => $attendance->id,
                'process_error' => null,
            ]);
        }
    }

    /**
     * Map a device verify code (ZKTeco) to a tracked punch method value.
     * Device codes vary, so a config map (biometric.verify_methods) takes
     * precedence; otherwise the standard aliases in PunchMethod are used.
     */
    private function resolvePunchMethod(int|string|null $verifyType): ?string
    {
        return PunchMethodResolver::value($verifyType);
    }

    /**
     * Resolve check-in: create an attendance record if one doesn't exist for the day.
     * If one already exists (e.g. from manual entry), skip and mark processed.
     */
    private function resolveCheckIn(Employee $employee, Carbon $punchedAt, string $date, ?string $method = null): ?Attendance
    {
        $existing = Attendance::where('employee_id', $employee->id)
            ->where('date', $date)
            ->first();

        if ($existing) {
            // Already clocked in (possibly via web); preserve existing record but
            // backfill the punch method if the device now tells us how.
            if ($method && ! $existing->check_in_method) {
                $existing->update(['check_in_method' => $method]);
            }

            return $existing;
        }

        $shift = $employee->shift;

        $isLate = false;
        $lateMinutes = 0;

        if ($shift) {
            $shiftStart = Carbon::parse($shift->start_time)->setDateFrom($punchedAt);
            $cutoff = $shiftStart->copy()->addMinutes((int) $shift->grace_minutes);
            $isLate = $punchedAt->gt($cutoff);
            $lateMinutes = $isLate ? (int) $cutoff->diffInMinutes($punchedAt) : 0;
        }

        return Attendance::create([
            'employee_id' => $employee->id,
            'date' => $date,
            'check_in' => $punchedAt,
            'check_in_method' => $method,
            'status' => $isLate ? 'late' : 'on_time',
            'is_late' => $isLate,
            'late_minutes' => $lateMinutes,
            'work_mode' => 'office',
        ]);
    }

    /**
     * Resolve check-out: update the existing attendance record for the day.
     * If no check-in record exists (e.g. device only captured exit), create a minimal record.
     */
    private function resolveCheckOut(Employee $employee, Carbon $punchedAt, string $date, ?string $method = null): ?Attendance
    {
        $attendance = Attendance::where('employee_id', $employee->id)
            ->where('date', $date)
            ->first();

        if (! $attendance) {
            // Orphan check-out — no check-in on file. Create a stub so the day isn't lost.
            $attendance = Attendance::create([
                'employee_id' => $employee->id,
                'date' => $date,
                'check_in' => $punchedAt,  // use checkout time as proxy check-in
                'check_in_method' => $method,
                'status' => 'on_time',
                'work_mode' => 'office',
            ]);
        }

        // Never overwrite a checkout with an earlier time (re-runs / device clock drift).
        if ($attendance->check_out && $attendance->check_out->gte($punchedAt)) {
            return $attendance;
        }

        $totalHours = round($attendance->check_in->diffInMinutes($punchedAt) / 60, 2);

        $attendance->update([
            'check_out' => $punchedAt,
            'check_out_method' => $method,
            'total_hours' => $totalHours,
        ]);

        return $attendance->fresh();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Factory
    // ──────────────────────────────────────────────────────────────────────

    protected function makeZKService(BiometricDevice $device): ZKTecoService
    {
        $zk = new ZKTecoService(
            ip: $device->ip_address,
            port: $device->port,
            timeout: $device->timeout_seconds,
        );

        $zk->debug = $this->debug;

        return $zk;
    }
}
