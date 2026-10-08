<?php

namespace App\Services;

use App\Exceptions\ApprovalNotPermitted;
use App\Models\Employee;
use App\Models\Incentive;
use App\Models\Payroll;
use App\Notifications\IncentiveNotification;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Payroll\PayableArrears;
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
        $this->assertDecidable($incentive, $approverId, 'approved');

        $incentive->update([
            'status' => 'approved',
            'approved_by' => $approverId,
            'approval_note' => $note,
            'approved_at' => Carbon::now(),
        ]);

        $fresh = $incentive->fresh(['employee.user']);
        $fresh->employee->user->notify(new IncentiveNotification($fresh));

        return $fresh;
    }

    public function reject(Incentive $incentive, int $approverId, ?string $note = null): Incentive
    {
        $this->assertDecidable($incentive, $approverId, 'rejected');

        $incentive->update([
            'status' => 'rejected',
            'approved_by' => $approverId,
            'approval_note' => $note,
            'approved_at' => Carbon::now(),
            'payroll_id' => null,
        ]);

        $fresh = $incentive->fresh(['employee.user']);
        $fresh->employee->user->notify(new IncentiveNotification($fresh));

        return $fresh;
    }

    /**
     * An incentive is decided once, while pending — never after it was paid
     * through payroll ('included'), which would strand or double the money —
     * and never by the person it pays or the person who raised it
     * (maker-checker).
     */
    private function assertDecidable(Incentive $incentive, int $approverId, string $outcome): void
    {
        if ($incentive->status !== 'pending') {
            throw new \DomainException("Only a pending incentive can be {$outcome}.");
        }

        app(ApprovalGuard::class)->assertNotSelf($approverId, $incentive->employee);

        if ((int) $incentive->requested_by === $approverId) {
            throw new ApprovalNotPermitted('You raised this incentive — someone else must decide it (maker-checker separation).');
        }
    }

    /**
     * Approved items for this run: its own month and any earlier month whose
     * run had already closed when they were approved (D8 — carried forward,
     * never dropped). Approved and not yet in any run — or already in THIS
     * run, so a draft re-run keeps what its previous pass included. The
     * payroll_id link pays each item exactly once.
     *
     * @return array{total: float, rows: Collection, item: ?array, items: array<int, array{name: string, amount: float, type: string}>}
     */
    public function includeApprovedForEmployeeMonth(Employee $employee, string $monthLabel, Payroll $payroll): array
    {
        $rows = Incentive::where('employee_id', $employee->id)
            ->where('month', '<=', $monthLabel)
            ->where(fn ($q) => $q->where(fn ($open) => $open->where('status', 'approved')->whereNull('payroll_id'))
                ->orWhere(fn ($mine) => $mine->where('status', 'included')->where('payroll_id', $payroll->id)))
            ->get();

        $total = (float) $rows->sum('amount');

        if ($rows->isNotEmpty()) {
            Incentive::whereKey($rows->pluck('id'))->update([
                'status' => 'included',
                'payroll_id' => $payroll->id,
            ]);
        }

        $items = PayableArrears::lines('Incentives', $rows, $monthLabel);

        return [
            'total' => $total,
            'rows' => $rows,
            'item' => $items[0] ?? null,
            'items' => $items,
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
        // Every item this run took for them, carried arrears included.
        Incentive::where('payroll_id', $payroll->id)
            ->whereNotIn('employee_id', $employeeIds->all())
            ->where('status', 'included')
            ->update([
                'status' => 'approved',
                'payroll_id' => null,
            ]);
    }
}
