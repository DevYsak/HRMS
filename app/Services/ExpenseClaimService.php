<?php

namespace App\Services;

use App\Enums\ExpenseStatus;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use Illuminate\Support\Facades\DB;

class ExpenseClaimService
{
    public function submit(Employee $employee, array $data): ExpenseClaim
    {
        return ExpenseClaim::create([
            'employee_id' => $employee->id,
            'title' => $data['title'],
            'category' => $data['category'],
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'receipt_path' => $data['receipt_path'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => ExpenseStatus::Pending,
        ]);
    }

    public function approve(ExpenseClaim $claim, int $approverId, ReimbursementService $reimbursementService): ExpenseClaim
    {
        if ($claim->status !== ExpenseStatus::Pending) {
            throw new \DomainException('Only pending claims can be approved.');
        }

        return DB::transaction(function () use ($claim, $approverId, $reimbursementService) {
            $reimbursement = $reimbursementService->submit($claim->employee, [
                'title' => $claim->title,
                'description' => $claim->notes,
                'amount' => $claim->amount,
                'expense_date' => $claim->expense_date->toDateString(),
                'month' => $claim->expense_date->format('Y-m'),
                'category' => $claim->category,
                'receipt_path' => $claim->receipt_path,
            ]);

            $reimbursementService->approve($reimbursement, $approverId);

            $claim->update([
                'status' => ExpenseStatus::Approved,
                'approved_by' => $approverId,
                'rejection_reason' => null,
            ]);

            return $claim->fresh(['employee.user', 'approver']);
        });
    }

    public function reject(ExpenseClaim $claim, int $approverId, string $reason): ExpenseClaim
    {
        if ($claim->status !== ExpenseStatus::Pending) {
            throw new \DomainException('Only pending claims can be rejected.');
        }

        $claim->update([
            'status' => ExpenseStatus::Rejected,
            'approved_by' => $approverId,
            'rejection_reason' => $reason,
        ]);

        return $claim->fresh(['employee.user', 'approver']);
    }
}
