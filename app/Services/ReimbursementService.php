<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Reimbursement;
use App\Notifications\ReimbursementNotification;
use App\Services\Approvals\ApprovalGuard;
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

    public function approve(Reimbursement $reimbursement, int $approverId, ?string $note = null, bool $notify = true): Reimbursement
    {
        $this->assertDecidable($reimbursement, $approverId, 'approved');

        $reimbursement->update([
            'status' => 'approved',
            'approved_by' => $approverId,
            'approval_note' => $note,
            'approved_at' => Carbon::now(),
        ]);

        $fresh = $reimbursement->fresh(['employee.user']);

        if ($notify) {
            $fresh->employee->user->notify(new ReimbursementNotification($fresh));
        }

        return $fresh;
    }

    public function reject(Reimbursement $reimbursement, int $approverId, ?string $note = null, bool $notify = true): Reimbursement
    {
        $this->assertDecidable($reimbursement, $approverId, 'rejected');

        $reimbursement->update([
            'status' => 'rejected',
            'approved_by' => $approverId,
            'approval_note' => $note,
            'approved_at' => Carbon::now(),
            'payroll_id' => null,
        ]);

        $fresh = $reimbursement->fresh(['employee.user']);

        if ($notify) {
            $fresh->employee->user->notify(new ReimbursementNotification($fresh));
        }

        return $fresh;
    }

    /**
     * A claim is decided once, while pending (never after payroll paid it),
     * and never by the claimant.
     */
    private function assertDecidable(Reimbursement $reimbursement, int $approverId, string $outcome): void
    {
        if ($reimbursement->status !== 'pending') {
            throw new \DomainException("Only a pending reimbursement can be {$outcome}.");
        }

        app(ApprovalGuard::class)->assertNotSelf($approverId, $reimbursement->employee);
    }

    public function includeApprovedForEmployeeMonth(Employee $employee, string $monthLabel, Payroll $payroll): array
    {
        // Approved and not yet in any run — or already in THIS run, so a draft
        // re-run keeps what its previous pass included instead of dropping it.
        $rows = Reimbursement::where('employee_id', $employee->id)
            ->where('month', $monthLabel)
            ->where(fn ($q) => $q->where(fn ($open) => $open->where('status', 'approved')->whereNull('payroll_id'))
                ->orWhere(fn ($mine) => $mine->where('status', 'included')->where('payroll_id', $payroll->id)))
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
