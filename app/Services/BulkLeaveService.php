<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bulk leave-balance operations across a filtered set of employees.
 *
 * Backward-compatibility rule (see LeaveService): only `allocated_days` is ever
 * mutated. `used_days`/`encashed_days` are owned by the approval workflow and
 * are never touched here — decreases are floored at used+encashed so the
 * derived available balance (allocated − used − encashed) can never go negative.
 * Every change writes an immutable LeaveBalanceAdjustment audit row.
 */
class BulkLeaveService
{
    public const ACTIONS = ['assign', 'increase', 'decrease', 'reset'];

    /**
     * Resolve the employees matching the given filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Employee>
     */
    public function affectedEmployees(array $filters): Collection
    {
        return Employee::query()
            ->with('user')
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($filters['job_title_id'] ?? null, fn ($q, $v) => $q->where('job_title_id', $v))
            ->when($filters['shift_id'] ?? null, fn ($q, $v) => $q->where('shift_id', $v))
            ->when($filters['office_id'] ?? null, fn ($q, $v) => $q->where('office_id', $v))
            ->when($filters['employment_type_id'] ?? null, fn ($q, $v) => $q->where('employment_type_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['joined_from'] ?? null, fn ($q, $v) => $q->whereDate('joining_date', '>=', $v))
            ->when($filters['joined_to'] ?? null, fn ($q, $v) => $q->whereDate('joining_date', '<=', $v))
            ->orderBy('id')
            ->get();
    }

    /**
     * Dry-run: compute the resulting allocation per employee without persisting.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array{employee:Employee, current:float, new:float, delta:float}>
     */
    public function preview(Collection $employees, LeaveType $leaveType, string $action, float $days, int $year): array
    {
        $balances = LeaveBalance::whereIn('employee_id', $employees->pluck('id'))
            ->where('leave_type_id', $leaveType->id)
            ->where('year', $year)
            ->get()
            ->keyBy('employee_id');

        $default = (float) ($leaveType->annual_allocation_days ?? 0);

        return $employees->map(function (Employee $employee) use ($balances, $action, $days, $default) {
            $balance = $balances->get($employee->id);
            $current = $balance ? (float) $balance->allocated_days : 0.0;
            $floor = $balance ? (float) $balance->used_days + (float) ($balance->encashed_days ?? 0) : 0.0;
            $new = $this->computeNew($current, $action, $days, $floor, $default);

            return ['employee' => $employee, 'current' => $current, 'new' => $new, 'delta' => round($new - $current, 2)];
        })->all();
    }

    /**
     * Apply the bulk operation. Returns the number of employees actually changed.
     *
     * @param  Collection<int, Employee>  $employees
     *
     * @throws \DomainException|\InvalidArgumentException
     */
    public function apply(Collection $employees, LeaveType $leaveType, string $action, float $days, string $reason, User $actor, int $year): int
    {
        if (! \in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Invalid bulk action.');
        }

        if ($leaveType->is_system_controlled) {
            throw new \DomainException("'{$leaveType->name}' is system-controlled and cannot be bulk-assigned.");
        }

        if (\in_array($action, ['assign', 'increase', 'decrease'], true) && $days <= 0) {
            throw new \DomainException('Days must be greater than zero.');
        }

        $default = (float) ($leaveType->annual_allocation_days ?? 0);
        $updated = 0;

        DB::transaction(function () use ($employees, $leaveType, $action, $days, $reason, $actor, $year, $default, &$updated) {
            foreach ($employees as $employee) {
                $balance = LeaveBalance::firstOrCreate(
                    ['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => $year],
                    ['allocated_days' => 0, 'used_days' => 0, 'carried_forward_days' => 0, 'encashed_days' => 0, 'comp_off_credits' => 0],
                );

                $current = (float) $balance->allocated_days;
                $floor = (float) $balance->used_days + (float) ($balance->encashed_days ?? 0);
                $new = $this->computeNew($current, $action, $days, $floor, $default);

                if (abs($new - $current) < 0.001) {
                    continue; // no-op
                }

                $balance->update(['allocated_days' => $new]);

                LeaveBalanceAdjustment::create([
                    'employee_id' => $employee->id,
                    'leave_type_id' => $leaveType->id,
                    'action' => $new > $current ? 'credit' : 'debit',
                    'days' => round(abs($new - $current), 2),
                    'previous_balance' => $current,
                    'new_balance' => $new,
                    'reason' => $reason ?: 'Bulk '.$action,
                    'remarks' => 'Bulk '.$action,
                    'adjusted_by' => $actor->id,
                    'adjusted_at' => now(),
                ]);

                $updated++;
            }
        });

        return $updated;
    }

    /**
     * Resulting allocated_days for one balance under the chosen action.
     * `$floor` = used + encashed (never allocate below what is already consumed).
     */
    private function computeNew(float $current, string $action, float $days, float $floor, float $default): float
    {
        return round(match ($action) {
            'assign' => max($floor, $days),
            'increase' => $current + $days,
            'decrease' => max($floor, $current - $days),
            'reset' => max($floor, $default),
            default => $current,
        }, 2);
    }
}
