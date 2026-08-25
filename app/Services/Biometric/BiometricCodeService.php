<?php

namespace App\Services\Biometric;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ownership of the Biometric Device ID (employees.employee_code).
 *
 * The code is the device PIN: it is what the reader sends with every punch and
 * what the engine matches on. Exactly one *active* employee may hold a given
 * code, or punches map to the wrong person. Two rules follow from that:
 *
 *   - A departed employee must not keep holding a code. Their enrolment is
 *     removed from the device on release, so the physical card is dead; leaving
 *     the number reserved in HRMS only blocks their replacement.
 *   - Moving a code between two active employees is a deliberate act, never a
 *     silent overwrite, because it re-points historical punch matching.
 *
 * Uniqueness is enforced against live employees only. Soft-deleted rows are
 * excluded on purpose: Laravel's unique rule queries the raw table, so without
 * this a deleted employee reserves their device ID permanently and HR sees
 * "already taken" pointing at somebody who is not in the directory.
 */
class BiometricCodeService
{
    /**
     * The active employee currently holding a code, if any.
     *
     * @param  int|null  $exceptEmployeeId  the employee being edited, who may keep their own code
     */
    public function holderOf(int|string|null $code, ?int $exceptEmployeeId = null): ?Employee
    {
        if ($code === null || $code === '') {
            return null;
        }

        return Employee::with('user')
            ->where('employee_code', (int) $code)
            ->when($exceptEmployeeId, fn ($q) => $q->whereKeyNot($exceptEmployeeId))
            ->first();
    }

    /** A soft-deleted employee holding a code — the case that silently blocks reuse. */
    public function trashedHolderOf(int|string|null $code): ?Employee
    {
        if ($code === null || $code === '') {
            return null;
        }

        return Employee::onlyTrashed()->with('user')
            ->where('employee_code', (int) $code)
            ->first();
    }

    /**
     * Explain who holds a code, for an error message HR can act on.
     *
     * "Already taken" is useless when the holder is invisible in the directory;
     * naming them — and saying they are deleted — is the difference between a
     * dead end and a next step.
     */
    public function conflictMessage(int|string|null $code, ?int $exceptEmployeeId = null): ?string
    {
        if ($holder = $this->holderOf($code, $exceptEmployeeId)) {
            return sprintf(
                'Biometric Device ID %s is already assigned to %s (%s). Use Reassign to move it.',
                $code,
                $holder->user?->name ?? 'another employee',
                $holder->employee_id ?? 'no employee ID',
            );
        }

        if ($trashed = $this->trashedHolderOf($code)) {
            return sprintf(
                'Biometric Device ID %s is still held by %s, a deleted employee. Use Reassign to free it.',
                $code,
                $trashed->user?->name ?? 'a deleted employee',
            );
        }

        return null;
    }

    /**
     * Move a code onto an employee, clearing it from whoever holds it.
     *
     * This is the override HR needs when a card is handed to a new person or a
     * deleted record is still squatting on the number. Both sides are audited:
     * losing a device ID must never be silent, since it stops the previous
     * holder's punches from matching.
     */
    public function reassign(Employee $to, int|string $code, User $actor): void
    {
        $code = (int) $code;

        DB::transaction(function () use ($to, $code, $actor) {
            $previous = Employee::withTrashed()
                ->where('employee_code', $code)
                ->whereKeyNot($to->id)
                ->get();

            foreach ($previous as $holder) {
                $before = $holder->only(['employee_code', 'biometric_id', 'sync_status']);

                $holder->forceFill([
                    'employee_code' => null,
                    'biometric_id' => null,
                    'sync_status' => 'removed',
                ])->save();

                AuditLog::record(
                    $holder,
                    'biometric_code_released',
                    $before,
                    ['employee_code' => null, 'reassigned_to' => $to->id],
                    reason: sprintf('Device ID %d reassigned to %s by %s', $code, $to->user?->name ?? "employee {$to->id}", $actor->name),
                    subjectEmployeeId: $holder->id,
                );
            }

            $before = $to->only(['employee_code', 'biometric_id', 'sync_status']);

            $to->forceFill([
                'employee_code' => $code,
                'biometric_id' => (string) $code,
                'sync_status' => 'pending',
            ])->save();

            AuditLog::record(
                $to,
                'biometric_code_assigned',
                $before,
                ['employee_code' => $code, 'taken_from' => $previous->pluck('id')->all()],
                reason: sprintf('Device ID %d assigned by %s', $code, $actor->name),
                subjectEmployeeId: $to->id,
            );
        });
    }

    /**
     * Free a departing employee's code so their replacement can use it.
     *
     * Deliberately does NOT touch the device — callers that can reach the
     * reader should use BiometricSyncService::releaseEmployee() instead. This
     * is the HRMS-side release for paths where no device round-trip applies,
     * such as deleting an employee from the directory.
     */
    public function release(Employee $employee, ?User $actor = null): void
    {
        if (! $employee->employee_code) {
            return;
        }

        $before = $employee->only(['employee_code', 'biometric_id', 'sync_status']);

        $employee->forceFill([
            'employee_code' => null,
            'biometric_id' => null,
            'sync_status' => 'removed',
        ])->save();

        AuditLog::record(
            $employee,
            'biometric_code_released',
            $before,
            ['employee_code' => null],
            reason: $actor ? 'Released by '.$actor->name : 'Released automatically',
            subjectEmployeeId: $employee->id,
        );
    }
}
