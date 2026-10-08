<?php

namespace App\Observers;

use App\Enums\EmployeeStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\Leave\EnsureEmployeeLeaveBalancesService;
use App\Services\Leave\LeaveProvisioningService;
use App\Services\Leave\LeaveRuleResolver;
use App\Services\OnboardingService;
use App\Services\Payroll\SalaryCycleTransitionService;

class EmployeeObserver
{
    /** Set while provisioning assigns a policy itself, so it is not provisioned twice. */
    public static bool $provisioningInProgress = false;

    /** Set while a due salary-cycle move is applied, so it is not deferred again. */
    public static bool $applyingCycleTransition = false;

    /**
     * Keep the payroll run key (salary_cycle: cycle_a / cycle_b) in step with
     * the cycle HR picks on the employee form (salary_cycle_id). Payroll reads
     * the key; the form writes the id — without this, moving someone to Cycle
     * B on screen left them in the Cycle A run.
     */
    public function saving(Employee $employee): void
    {
        if (! $employee->isDirty('salary_cycle_id') || ! $employee->salary_cycle_id) {
            return;
        }

        // An already-paid employee does not change cycle mid-stream: the move
        // waits for the next payroll month (SalaryCycleTransitionService).
        if (! self::$applyingCycleTransition && $employee->exists && app(SalaryCycleTransitionService::class)->defer($employee)) {
            return;
        }

        $key = SalaryCycleTransitionService::keyFor((int) $employee->salary_cycle_id);

        if ($key !== null) {
            $employee->salary_cycle = $key;
        }
    }

    public function created(Employee $employee): void
    {
        AuditLog::record($employee, 'created', null, $employee->toArray());

        app(OnboardingService::class)->assignTemplate($employee);

        // Every new hire — form, import, API or seeder — starts with the annual
        // leave their policy and working pattern produce.
        //
        // This used to seed a flat allocation per leave type keyed on
        // now()->year: a calendar year, in a company whose leave year runs
        // 1 July to 30 June, with no leave policy assigned at all. Where the
        // policy or the pattern is missing, provisioning reports the gap rather
        // than defaulting past it — an entitlement resting on an assumed
        // pattern is a guess with a number in front of it.
        //
        // No previous-year balance and no carry-forward: a new employee has no
        // history, and carry forward is a decision HR makes about a year that
        // actually happened.
        app(LeaveProvisioningService::class)->provision($employee);
    }

    public function updated(Employee $employee): void
    {
        AuditLog::record(
            $employee,
            'updated',
            $employee->getOriginal(),
            $employee->getDirty(),
        );

        // Re-enrolment needed when biometric identity fields change.
        // Guard against infinite loop: skip if sync_status itself is what changed.
        if (
            ! $employee->wasChanged('sync_status')
            && $employee->wasChanged(['employee_code', 'biometric_device_id'])
            && $employee->employee_code
        ) {
            Employee::withoutEvents(fn () => $employee->update(['sync_status' => 'pending']));
        }

        $this->ensureLeave($employee);

        // Auto-complete biometric enrollment task when sync succeeds.
        if ($employee->wasChanged('sync_status') && $employee->sync_status === 'synced') {
            app(OnboardingService::class)->autoComplete($employee, 'biometric_sync', 0);
        }
    }

    /**
     * A new leave policy, or a move into a status that holds leave, changes
     * what the employee is entitled to now. Idempotent, so a save made while
     * the create path is still provisioning cannot grant anything twice.
     */
    private function ensureLeave(Employee $employee): void
    {
        if (static::$provisioningInProgress) {
            return;
        }

        $policyChanged = $employee->wasChanged('leave_policy_id') && $employee->leave_policy_id !== null;
        $status = fn ($value) => $value instanceof EmployeeStatus ? $value->value : (string) $value;
        $becameEligible = $employee->wasChanged('status')
            && in_array($status($employee->status), LeaveRuleResolver::ELIGIBLE_STATUSES, true)
            && ! in_array($status($employee->getOriginal('status')), LeaveRuleResolver::ELIGIBLE_STATUSES, true);

        if (! $policyChanged && ! $becameEligible) {
            return;
        }

        app(EnsureEmployeeLeaveBalancesService::class)->ensure(
            $employee->fresh(),
            actor: auth()->user(),
            recalculate: $policyChanged,
            trigger: $policyChanged ? 'policy_assigned' : 'status_change',
        );
    }

    public function deleted(Employee $employee): void
    {
        AuditLog::record($employee, 'deleted', $employee->toArray(), null);
    }
}
