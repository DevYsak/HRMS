<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Incentive;
use App\Models\Payroll;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class IncentiveService
{
    public function submit(Employee $employee, array $data, int $requestedBy): Incentive
    {
        return Incentive::create([
            'employee_id' => $employee->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'amount' => $data['amount'],
            'month' => $data['month'],
            'status' => 'pending',
            'requested_by' => $requestedBy,
        ]);
    }

    public function approve(Incentive $incentive, int $approverId, ?string $note = null): Incentive
    {
        $incentive->update([
            'status' => 'approved',
            'approved_by' => $approverId,
            'approval_note' => $note,
            'approved_at' => Carbon::now(),
        ]);

        return $incentive->fresh();
    }

    public function reject(Incentive $incentive, int $approverId, ?string $note = null): Incentive
    {
        $incentive->update([
            'status' => 'rejected',
            'approved_by' => $approverId,
            'approval_note' => $note,
            'approved_at' => Carbon::now(),
            'payroll_id' => null,
        ]);

        return $incentive->fresh();
    }

    public function includeApprovedForEmployeeMonth(Employee $employee, string $monthLabel, Payroll $payroll): array
    {
        $rows = Incentive::where('employee_id', $employee->id)
            ->where('month', $monthLabel)
            ->where('status', 'approved')
            ->whereNull('payroll_id')
            ->get();

        $total = (float) $rows->sum('amount');

        if ($rows->isNotEmpty()) {
            Incentive::whereKey($rows->pluck('id'))->update([
                'status' => 'included',
                'payroll_id' => $payroll->id,
            ]);
        }

        return [
            'total' => $total,
            'rows' => $rows,
            'item' => $total > 0 ? ['name' => 'Incentives', 'amount' => $total, 'type' => 'earning'] : null,
        ];
    }

    public function releaseIncludedForPayroll(Payroll $payroll): void
    {
        Incentive::where('payroll_id', $payroll->id)
            ->where('status', 'included')
            ->update([
                'status' => 'approved',
                'payroll_id' => null,
            ]);
    }

    /**
     * @param  Collection<int, int>  $employeeIds
     */
    public function releaseIncludedForEmployeesAndMonth(Payroll $payroll, Collection $employeeIds, string $monthLabel): void
    {
        Incentive::where('payroll_id', $payroll->id)
            ->whereNotIn('employee_id', $employeeIds->all())
            ->where('month', $monthLabel)
            ->where('status', 'included')
            ->update([
                'status' => 'approved',
                'payroll_id' => null,
            ]);
    }
}
