<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * LwpService — Leave Without Pay deduction calculator.
 *
 * Two scenarios constitute an LWP day:
 *   1. The leave_type has is_paid = false  (explicit unpaid leave type)
 *   2. The leave_type has is_paid = true, but the employee's balance
 *      was exhausted at the time of approval (balance-exceeded LWP).
 *      We detect this by checking if used_days > allocated_days on the balance
 *      record after the fact.
 */
class LwpService
{
    /**
     * Calculate the total LWP days for one employee within a payroll window.
     *
     * @param  Carbon  $from  Cycle start date (inclusive)
     * @param  Carbon  $to  Cycle end date (inclusive)
     * @param  int  $workingDaysInMonth  Configurable; defaults to 26
     * @return array{days: float, deduction: float, items: array}
     */
    public function calculate(
        Employee $employee,
        Carbon $from,
        Carbon $to,
        int $workingDaysInMonth = 26
    ): array {
        $lwpDays = 0.0;
        $requests = $this->fetchLwpRequests($employee->id, $from, $to);

        foreach ($requests as $request) {
            $lwpDays += $this->resolveEffectiveDays($request, $from, $to);
        }

        // Monthly basic salary = sum of all 'earning' salary components
        $monthlySalary = $employee->salaries
            ->filter(fn ($s) => $s->component && $s->component->type === 'earning' && $s->component->is_active)
            ->sum('amount');

        $perDaySalary = $workingDaysInMonth > 0 ? $monthlySalary / $workingDaysInMonth : 0;
        $deduction = round($perDaySalary * $lwpDays, 2);

        $items = [];
        if ($lwpDays > 0) {
            $items[] = [
                'name' => "LWP ({$lwpDays} day".($lwpDays != 1 ? 's' : '').')',
                'amount' => $deduction,
                'type' => 'deduction',
            ];
        }

        return [
            'days' => $lwpDays,
            'deduction' => $deduction,
            'items' => $items,
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Fetch all approved leave requests for an employee in the cycle window
     * that are either:
     *  - Leave type is_paid = false (inherently unpaid), OR
     *  - Leave type category = 'unpaid'
     *  - Balance was exceeded at time of approval (paid leave but no balance)
     */
    private function fetchLwpRequests(int $employeeId, Carbon $from, Carbon $to): Collection
    {
        // Pre-load unpaid leave type IDs
        $unpaidTypeIds = LeaveType::where('is_paid', false)
            ->orWhere('category', 'unpaid')
            ->pluck('id');

        // Detect balance-exceeded leave type IDs for this employee
        $exceededTypeIds = LeaveBalance::where('employee_id', $employeeId)
            ->whereColumn('used_days', '>', 'allocated_days')
            ->pluck('leave_type_id');

        $lwpTypeIds = $unpaidTypeIds->merge($exceededTypeIds)->unique();

        return LeaveRequest::with('leaveType')
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->whereIn('leave_type_id', $lwpTypeIds)
            // Overlap: leave overlaps with the payroll window
            ->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString())
            ->get();
    }

    /**
     * Clamp the leave request dates to the payroll window and return
     * the effective number of LWP days.
     *
     * Handles:
     *  - Leaves spanning multiple payroll cycles (only counts days within window)
     *  - Half-day leaves (days stored as 0.5 in leave_requests.days; we use
     *    the stored value proportionally for the clamped range)
     */
    private function resolveEffectiveDays(LeaveRequest $request, Carbon $from, Carbon $to): float
    {
        $leaveStart = $request->start_date->max($from);
        $leaveEnd = $request->end_date->min($to);

        if ($leaveEnd->lt($leaveStart)) {
            return 0.0;
        }

        $totalLeaveDays = max((float) $request->days, 0.5);
        $rawSpan = $request->start_date->diffInDays($request->end_date) + 1;
        $effectiveSpan = $leaveStart->diffInDays($leaveEnd) + 1;

        // Proportion of the leave that falls within the payroll window
        $ratio = $rawSpan > 0 ? $effectiveSpan / $rawSpan : 1;

        return round($totalLeaveDays * $ratio, 2);
    }
}
