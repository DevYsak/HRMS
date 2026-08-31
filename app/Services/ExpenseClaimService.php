<?php

namespace App\Services;

use App\Enums\ExpenseStatus;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\User;
use App\Notifications\ExpenseClaimNotification;
use App\Services\Notifications\NotificationRecipients;
use Illuminate\Support\Facades\DB;

class ExpenseClaimService
{
    public function submit(Employee $employee, array $data): ExpenseClaim
    {
        $claim = ExpenseClaim::create([
            'employee_id' => $employee->id,
            'title' => $data['title'],
            'category' => $data['category'],
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'receipt_path' => $data['receipt_path'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => ExpenseStatus::Pending,
        ]);

        $claim->load(['employee.user']);

        // Notify manager; fall back to HR/super admins if no manager assigned
        $manager = $employee->manager;
        if ($manager) {
            $manager->notify((new ExpenseClaimNotification($claim))->forRole('manager'));
        } else {
            // No manager assigned, so the claim falls to HR as a queue.
            app(NotificationRecipients::class)->hrQueue()->each(
                fn (User $hr) => $hr->notify((new ExpenseClaimNotification($claim))->forRole('hr_admin'))
            );
        }

        return $claim;
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

            $reimbursementService->approve($reimbursement, $approverId, notify: false);

            $claim->update([
                'status' => ExpenseStatus::Approved,
                'approved_by' => $approverId,
                'rejection_reason' => null,
            ]);

            $fresh = $claim->fresh(['employee.user', 'approver']);

            $fresh->employee->user->notify((new ExpenseClaimNotification($fresh))->forRole('employee'));

            return $fresh;
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

        $fresh = $claim->fresh(['employee.user', 'approver']);

        $fresh->employee->user->notify((new ExpenseClaimNotification($fresh))->forRole('employee'));

        return $fresh;
    }
}
