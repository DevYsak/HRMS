<?php

namespace App\Services\Biometric;

use App\Models\AttendancePunch;
use App\Models\AuditLog;
use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Models\Employee;
use App\Services\Attendance\AttendanceDayRebuilder;
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

    /**
     * Release a departed employee's biometric identity on offboarding: delete
     * their enrolment from the device (so their card/PIN stops working) and
     * clear the biometric code from HRMS so the same card can be reassigned to
     * a new hire. Past attendance is keyed on employee_id, so history is kept.
     * A device that is offline never blocks the release — it is logged and the
     * HRMS-side clearing still happens.
     *
     * @return bool whether the device enrolment was actually removed
     */
    public function releaseEmployee(Employee $employee): bool
    {
        $removed = false;
        $device = $employee->biometricDevice;

        if ($device && $employee->employee_code) {
            try {
                $removed = $this->removeEmployeeFromDevice($employee, $device);
            } catch (Throwable $e) {
                Log::warning('Biometric release: device removal failed, clearing HRMS-side anyway', [
                    'employee_id' => $employee->id,
                    'device_id' => $device->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $before = $employee->only(['employee_code', 'biometric_user_id', 'biometric_device_id', 'sync_status']);

        $employee->update([
            'employee_code' => null,
            'biometric_user_id' => null,
            'biometric_device_id' => null,
            'sync_status' => 'removed',
        ]);

        AuditLog::record($employee, 'biometric_released', $before, [
            'device_removed' => $removed,
        ]);

        return $removed;
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

    /**
     * Turn unprocessed device logs into attendance — through the canonical
     * timeline, never by reading the raw IN/OUT or first/last punch.
     *
     * Each log is first kept as a raw attendance punch exactly as received
     * (device state stored as the raw direction, for audit only). Then every
     * affected employee-day is rebuilt ONCE by {@see AttendanceDayRebuilder}
     * — Face = IN, ID Card = OUT, latest of a 60-second burst, stray cards
     * ignored, night shifts attached to their own work date — and the same
     * numbers go to the attendance row and the daily summary. Regularised,
     * HR-corrected and payroll-settled days are left alone. Running it again
     * changes nothing: logs are already processed, punches are keyed on
     * (employee, time) and the rebuild is deterministic.
     *
     * @return int Number of logs successfully applied.
     */
    private function applyUnprocessedLogs(BiometricDevice $device): int
    {
        $rebuilder = app(AttendanceDayRebuilder::class);
        $applied = 0;

        $logs = BiometricLog::with('employee.shift')
            ->where('device_id', $device->id)
            ->where('is_processed', false)
            ->whereNotNull('employee_id')
            ->whereIn('punch_type', ['check_in', 'check_out'])
            ->orderBy('punched_at')
            ->cursor();  // memory-efficient for large result sets

        /** @var array<string, array{employee: Employee, day: Carbon, logs: array<int, BiometricLog>}> $days */
        $days = [];

        foreach ($logs as $log) {
            try {
                $employee = $log->employee;
                $punchedAt = Carbon::parse($log->punched_at);
                $workDate = $rebuilder->workDateFor($employee, $punchedAt);

                $this->storeRawPunch($log, $employee, $punchedAt, $workDate, $device);

                $key = $employee->id.'|'.$workDate->toDateString();
                $days[$key] ??= ['employee' => $employee, 'day' => $workDate, 'logs' => []];
                $days[$key]['logs'][] = $log;
            } catch (Throwable $e) {
                $this->markFailed($log, $e);
            }
        }

        foreach ($days as $entry) {
            try {
                DB::transaction(function () use ($entry, $rebuilder, &$applied) {
                    $row = $rebuilder->rebuild($entry['employee'], $entry['day']);

                    foreach ($entry['logs'] as $log) {
                        $log->update([
                            'is_processed' => true,
                            'attendance_id' => $row['attendance']?->id,
                            'process_error' => null,
                        ]);
                        $applied++;
                    }
                });
            } catch (Throwable $e) {
                foreach ($entry['logs'] as $log) {
                    $this->markFailed($log, $e);
                }
            }
        }

        return $applied;
    }

    /**
     * Keep the device read as a raw attendance punch — as received, never
     * rewritten. Keyed on (employee, time), so a retry adds nothing, and a
     * punch another path already stored (the engine sync) is left as it is.
     */
    private function storeRawPunch(BiometricLog $log, Employee $employee, Carbon $punchedAt, Carbon $workDate, BiometricDevice $device): void
    {
        AttendancePunch::firstOrCreate(
            ['employee_id' => $employee->id, 'punched_at' => $punchedAt],
            [
                'employee_code' => $employee->employee_code,
                'punch_date' => $workDate->toDateString(),
                'method' => $this->resolvePunchMethod($log->verify_type),
                // The device's own state byte, for audit only — the timeline
                // decides the effective direction from the method.
                'direction' => $log->punch_type === 'check_out' ? 'out' : 'in',
                'verify_raw' => $log->verify_type !== null ? (string) $log->verify_type : null,
                'source' => 'biometric',
                'device_serial' => $device->name,
            ],
        );
    }

    private function markFailed(BiometricLog $log, Throwable $e): void
    {
        Log::warning("BiometricLog#{$log->id} apply failed: {$e->getMessage()}");

        $log->update([
            'is_processed' => false,
            'process_error' => substr($e->getMessage(), 0, 500),
        ]);
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
