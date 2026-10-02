<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\EmployeeLeaveOverride;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Audit\AuditService;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Employee-specific entitlement exceptions (Phase 2B).
 *
 * "Policy Annual Leave = 20, this employee +5": recorded once, audited with
 * who, why and from when, and applied by re-running provisioning for the
 * affected year, which replaces the posted base entitlement through the
 * ledger (reversal + new version). No policy is cloned for one person.
 */
class EmployeeLeaveOverrideService
{
    public function __construct(
        private readonly EnsureEmployeeLeaveBalancesService $ensure,
        private readonly LeaveYearResolver $years,
    ) {}

    public function create(
        Employee $employee,
        LeaveType $type,
        string $mode,
        float $days,
        string $reason,
        ?User $actor,
        ?LeaveYear $year = null,
        ?CarbonInterface $effectiveFrom = null,
    ): EmployeeLeaveOverride {
        if (! in_array($mode, [EmployeeLeaveOverride::MODE_ADD, EmployeeLeaveOverride::MODE_SET], true)) {
            throw new DomainException("Override mode must be 'add' or 'set'.");
        }

        if ($mode === EmployeeLeaveOverride::MODE_SET && $days < 0) {
            throw new DomainException('An entitlement cannot be set below zero.');
        }

        if (trim($reason) === '') {
            throw new DomainException('An employee override needs a reason.');
        }

        if ($year?->isClosed()) {
            throw new DomainException("Leave year {$year->label} is closed.");
        }

        return DB::transaction(function () use ($employee, $type, $mode, $days, $reason, $actor, $year, $effectiveFrom) {
            $override = EmployeeLeaveOverride::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'leave_year_id' => $year?->id,
                'mode' => $mode,
                'days' => round($days, 2),
                'effective_from' => $effectiveFrom?->toDateString(),
                'reason' => $reason,
                'created_by' => $actor?->id,
            ]);

            app(AuditService::class)->event('LEAVE_OVERRIDE_CREATED', AuditService::LEAVE, $override,
                new: [
                    'leave_type' => $type->name, 'mode' => $mode, 'days' => round($days, 2),
                    'leave_year' => $year?->label ?? 'from '.($effectiveFrom?->toDateString() ?? 'now on'),
                ],
                reason: $reason, subjectEmployeeId: $employee->id);

            $this->ensure->ensureType($employee, $type, $year ?? $this->years->current(), $actor, recalculate: true, trigger: 'employee_override');

            return $override;
        });
    }

    public function revoke(EmployeeLeaveOverride $override, User $actor, string $reason): EmployeeLeaveOverride
    {
        if ($override->revoked_at !== null) {
            throw new DomainException('This override has already been revoked.');
        }

        if (trim($reason) === '') {
            throw new DomainException('Revoking an override needs a reason.');
        }

        return DB::transaction(function () use ($override, $actor, $reason) {
            $override->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revoke_reason' => $reason])->save();

            app(AuditService::class)->event('LEAVE_OVERRIDE_REVOKED', AuditService::LEAVE, $override,
                old: ['mode' => $override->mode, 'days' => (float) $override->days], new: ['revoked' => true],
                reason: $reason, subjectEmployeeId: $override->employee_id);

            $year = $override->leaveYear ?? $this->years->current();
            $this->ensure->ensureType($override->employee, $override->leaveType, $year, $actor, recalculate: true, trigger: 'override_revoked');

            return $override;
        });
    }
}
