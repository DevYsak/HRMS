<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Reimbursement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReimbursementService
{
    public function submit(Employee $employee, array $data): Reimbursement
    {
        return Reimbursement::create([
            'employee_id' => $employee->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'month' => $data['month'],
            'category' => $data['category'],
            'receipt_path' => $data['receipt_path'] ?? null,
            'status' => 'pending',
        ]);
    }

    public function approve(Reimbursement $reimbursement, int $approverId, ?string $note = null): Reimbursement
    {
        $reimbursement->update([
            'status' => 'approved',
            'approved_by' => $approverId,
            'approval_note' => $note,
            'approved_at' => Carbon::now(),
        ]);

        return $reimbursement->fresh();
    }

    public function reject(Reimbursement $reimbursement, int $approverId, ?string $note = null): Reimbursement
    {
        $reimbursement->update([
            'status' => 'rejected',
            'approved_by' => $approverId,
            'approval_note' => $note,
            'approved_at' => Carbon::now(),
            'payroll_id' => null,
        ]);

        return $reimbursement->fresh();
    }

    public function includeApprovedForEmployeeMonth(Employee $employee, string $monthLabel, Payroll $payroll): array
    {
        $rows = Reimbursement::where('employee_id', $employee->id)
            ->where('month', $monthLabel)
            ->where('status', 'approved')
            ->whereNull('payroll_id')
            ->get();

        $total = (float) $rows->sum('amount');

        if ($rows->isNotEmpty()) {
            Reimbursement::whereKey($rows->pluck('id'))->update([
                'status' => 'included',
                'payroll_id' => $payroll->id,
            ]);
        }

        return [
            'total' => $total,
            'rows' => $rows,
            'item' => $total > 0 ? ['name' => 'Reimbursements', 'amount' => $total, 'type' => 'earning'] : null,
        ];
    }

    public function releaseIncludedForPayroll(Payroll $payroll): void
    {
        Reimbursement::where('payroll_id', $payroll->id)
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
        Reimbursement::where('payroll_id', $payroll->id)
            ->whereNotIn('employee_id', $employeeIds->all())
            ->where('month', $monthLabel)
            ->where('status', 'included')
            ->update([
                'status' => 'approved',
                'payroll_id' => null,
            ]);
    }
}
