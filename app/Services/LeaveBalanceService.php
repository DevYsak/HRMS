<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\LeaveAllocationPolicy;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeaveBalanceService
{
    /**
     * Initialize leave balances for a new employee from the central leave type master.
     * Safe to call multiple times — uses updateOrCreate so existing data is not overwritten.
     */
    public function initializeForEmployee(Employee $employee, int $year): void
    {
        $allocatableTypes = LeaveType::whereNotNull('annual_allocation_days')
            ->where('annual_allocation_days', '>', 0)
            ->whereNull('deleted_at')
            ->get();

        foreach ($allocatableTypes as $type) {
            // firstOrCreate: if the record exists, leave all existing balances untouched.
            // If it is new, seed it with the master allocation.
            LeaveBalance::firstOrCreate(
                [
                    'employee_id' => $employee->id,
                    'leave_type_id' => $type->id,
                    'year' => $year,
                ],
                [
                    'allocated_days' => $type->annual_allocation_days,
                    'used_days' => 0,
                    'carried_forward_days' => 0,
                    'encashed_days' => 0,
                    'comp_off_credits' => 0,
                ],
            );
        }
    }

    /**
     * Initialize leave balances using the conditional allocation policies, falling
     * back to each leave type's uniform annual_allocation_days when no policy
     * matches. Safe to call repeatedly (firstOrCreate).
     */
    public function initializeFromPolicy(Employee $employee, int $year): void
    {
        $types = LeaveType::whereNull('deleted_at')->get();
        $policiesByType = LeaveAllocationPolicy::where('is_active', true)->get()->groupBy('leave_type_id');

        foreach ($types as $type) {
            $days = $this->resolveAllocation($employee, $type, $policiesByType->get($type->id) ?? collect());

            if ($days === null || $days <= 0) {
                continue;
            }

            LeaveBalance::firstOrCreate(
                ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year],
                [
                    'allocated_days' => $days,
                    'used_days' => 0,
                    'carried_forward_days' => 0,
                    'encashed_days' => 0,
                    'comp_off_credits' => 0,
                ],
            );
        }
    }

    /**
     * Resolve the default allocation for one leave type for a given employee:
     * the most specific matching policy wins; otherwise the type's uniform
     * annual_allocation_days (or null if that too is unset).
     *
     * @param  Collection<int, LeaveAllocationPolicy>  $policies
     */
    public function resolveAllocation(Employee $employee, LeaveType $type, Collection $policies): ?float
    {
        $matching = $policies->filter(fn (LeaveAllocationPolicy $p) => $this->policyMatches($p, $employee));

        if ($matching->isNotEmpty()) {
            $best = $matching->sortByDesc(fn (LeaveAllocationPolicy $p) => $p->specificity())->first();

            return (float) $best->allocated_days;
        }

        return $type->annual_allocation_days !== null ? (float) $type->annual_allocation_days : null;
    }

    /** All non-null conditions on the policy must match the employee. */
    private function policyMatches(LeaveAllocationPolicy $policy, Employee $employee): bool
    {
        if ($policy->department_id && $policy->department_id !== $employee->department_id) {
            return false;
        }
        if ($policy->job_title_id && $policy->job_title_id !== $employee->job_title_id) {
            return false;
        }
        if ($policy->employment_type_id && $policy->employment_type_id !== $employee->employment_type_id) {
            return false;
        }
        if ($policy->gender && strtolower($policy->gender) !== strtolower((string) $employee->gender)) {
            return false;
        }
        if ($policy->role && $policy->role !== ($employee->user?->role?->value)) {
            return false;
        }
        if ($policy->min_service_months > 0) {
            $months = $employee->joining_date
                ? Carbon::parse($employee->joining_date)->diffInMonths(now())
                : 0;
            if ($months < $policy->min_service_months) {
                return false;
            }
        }
        if ($policy->requires_probation_complete) {
            $status = $employee->status instanceof EmployeeStatus
                ? $employee->status->value
                : $employee->status;
            if (\in_array($status, ['draft', 'onboarding', 'probation'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Manually credit or debit leave balance for an employee.
     * Writes an immutable audit log entry for every change.
     *
     * @throws \DomainException if the leave type is system-controlled or debit exceeds balance
     */
    public function adjust(
        Employee $employee,
        LeaveType $leaveType,
        string $action,
        float $days,
        string $reason,
        string $remarks,
        User $adjuster,
        ?int $year = null,
    ): LeaveBalanceAdjustment {
        if (! \in_array($action, ['credit', 'debit'], true)) {
            throw new \InvalidArgumentException("Action must be 'credit' or 'debit'.");
        }

        if ($days <= 0) {
            throw new \DomainException('Days must be greater than zero.');
        }

        if ($leaveType->is_system_controlled) {
            throw new \DomainException(
                "'{$leaveType->name}' is system-controlled and cannot be manually adjusted."
            );
        }

        $year ??= now()->year;

        return DB::transaction(function () use ($employee, $leaveType, $action, $days, $reason, $remarks, $adjuster, $year) {
            $balance = LeaveBalance::firstOrCreate(
                ['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => $year],
                [
                    'allocated_days' => 0,
                    'used_days' => 0,
                    'carried_forward_days' => 0,
                    'encashed_days' => 0,
                    'comp_off_credits' => 0,
                ],
            );

            $previousBalance = (float) $balance->allocated_days;

            if ($action === 'credit') {
                $newBalance = $previousBalance + $days;
                $balance->increment('allocated_days', $days);
            } else {
                if ($days > $balance->available()) {
                    throw new \DomainException(
                        "Cannot debit {$days} day(s): only {$balance->available()} day(s) available."
                    );
                }
                $newBalance = max(0, $previousBalance - $days);
                $balance->decrement('allocated_days', $days);
            }

            return LeaveBalanceAdjustment::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'action' => $action,
                'days' => $days,
                'previous_balance' => $previousBalance,
                'new_balance' => $newBalance,
                'reason' => $reason,
                'remarks' => $remarks ?: null,
                'adjusted_by' => $adjuster->id,
                'adjusted_at' => now(),
            ]);
        });
    }

    /**
     * Return an enriched balance summary collection for a given employee and year.
     * Each item includes: balance row, leave type, pending_days computed live.
     */
    public function getBalanceSummary(Employee $employee, int $year): Collection
    {
        // Get all active leave types
        $allTypes = LeaveType::whereNull('deleted_at')->orderBy('name')->get();

        // Load existing balances keyed by leave_type_id
        $balances = LeaveBalance::where('employee_id', $employee->id)
            ->where('year', $year)
            ->with('leaveType')
            ->get()
            ->keyBy('leave_type_id');

        // Load pending request days per leave type in one query
        $pendingDays = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'pending_hr'])
            ->whereYear('start_date', $year)
            ->selectRaw('leave_type_id, SUM(days) as total_pending')
            ->groupBy('leave_type_id')
            ->pluck('total_pending', 'leave_type_id');

        return $allTypes->map(function (LeaveType $type) use ($balances, $pendingDays, $year) {
            $balance = $balances->get($type->id);

            return (object) [
                'leave_type' => $type,
                'leave_type_id' => $type->id,
                'year' => $year,
                'allocated' => $balance ? (float) $balance->allocated_days : 0.0,
                'used' => $balance ? (float) $balance->used_days : 0.0,
                'carried_forward' => $balance ? (float) $balance->carried_forward_days : 0.0,
                'encashed' => $balance ? (float) $balance->encashed_days : 0.0,
                'available' => $balance ? $balance->available() : 0.0,
                'pending' => (float) ($pendingDays->get($type->id) ?? 0),
                'balance' => $balance,
            ];
        });
    }

    /**
     * Get audit history for a specific employee's leave balance adjustments.
     */
    public function getAdjustmentHistory(Employee $employee, ?int $leaveTypeId = null): Collection
    {
        return LeaveBalanceAdjustment::where('employee_id', $employee->id)
            ->when($leaveTypeId, fn ($q) => $q->where('leave_type_id', $leaveTypeId))
            ->with(['leaveType', 'adjustedByUser'])
            ->latest('adjusted_at')
            ->get();
    }
}
