<?php

namespace App\Services;

use App\Mail\PayslipMail;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\Payroll;
use App\Models\PayrollRunFailure;
use App\Models\Payslip;
use App\Models\User;
use App\Notifications\PayrollApprovalNotification;
use App\Notifications\PayslipGeneratedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PayrollService
{
    public function __construct(
        protected OvertimeService $overtimeService,
        protected IncentiveService $incentiveService,
        protected ReimbursementService $reimbursementService,
        protected SalaryCalculationService $salaryCalculationService,
    ) {}

    public function generateDraft(string $month, int $year, string $cycle, int $processedBy): Payroll
    {
        try {
            return $this->runGenerateDraft($month, $year, $cycle, $processedBy);
        } catch (\Throwable $e) {
            // A bad run used to just vanish as a toast — record it so it shows up
            // somewhere (the payroll dashboard's "Failed" widget reads this table).
            PayrollRunFailure::create([
                'month' => $month,
                'year' => $year,
                'cycle' => $cycle,
                'attempted_by' => $processedBy,
                'reason' => $e->getMessage(),
                'context' => ['exception' => get_class($e)],
            ]);

            throw $e;
        }
    }

    private function runGenerateDraft(string $month, int $year, string $cycle, int $processedBy): Payroll
    {
        [$cycleFrom, $cycleTo] = $this->resolveCycleDates($month, $year, $cycle);
        $monthLabel = Carbon::parse("1 {$month} {$year}")->format('Y-m');

        return DB::transaction(function () use ($month, $year, $cycle, $processedBy, $cycleFrom, $cycleTo, $monthLabel) {
            $payroll = Payroll::where('month', $month)
                ->where('year', $year)
                ->where('cycle', $cycle)
                ->first();

            if ($payroll && $payroll->isLocked()) {
                throw new \DomainException('This payroll is locked and can no longer be regenerated.');
            }

            if ($payroll && $payroll->status !== 'draft') {
                throw new \DomainException('Payroll is locked for this cycle until finance completes review.');
            }

            if (! $payroll) {
                $payroll = Payroll::create([
                    'month' => $month,
                    'year' => $year,
                    'cycle' => $cycle,
                    'status' => 'draft',
                    'processed_by' => $processedBy,
                ]);
            }

            $employees = Employee::where('status', 'active')
                ->where('salary_cycle', $cycle)
                ->with('payrollSettings')
                ->get();
            $activeEmployeeIds = $employees->pluck('id');

            $this->incentiveService->releaseIncludedForEmployeesAndMonth($payroll, $activeEmployeeIds, $monthLabel);
            $this->reimbursementService->releaseIncludedForEmployeesAndMonth($payroll, $activeEmployeeIds, $monthLabel);

            // Drop payslips belonging to employees who have since left the run — a
            // deactivation or cycle switch between runs would otherwise strand a
            // payslip here while the totals below are rebuilt from the current set
            // only, leaving total_payout disagreeing with SUM(payslips.net_salary).
            Payslip::where('payroll_id', $payroll->id)
                ->whereNotIn('employee_id', $activeEmployeeIds)
                ->delete();

            $totalPayrollPayout = 0.0;
            $otAmount = 0.0;
            $incentives = 0.0;
            $reimbursements = 0.0;
            $deductions = 0.0;

            foreach ($employees as $employee) {
                $existing = Payslip::where('payroll_id', $payroll->id)
                    ->where('employee_id', $employee->id)
                    ->first();

                // A payslip locked individually (Phase 2) survives a whole-batch
                // regenerate untouched — its existing totals still count toward
                // the payroll header below.
                if ($existing?->isLocked()) {
                    $otAmount += 0.0;
                    $deductions += (float) $existing->total_deductions;
                    $totalPayrollPayout += (float) $existing->net_salary;

                    continue;
                }

                $existing?->delete();

                $result = $this->salaryCalculationService->calculate(
                    $employee,
                    $cycleFrom,
                    $cycleTo,
                    $monthLabel,
                    $payroll,
                );

                $payslip = Payslip::create([
                    'payroll_id' => $payroll->id,
                    'employee_id' => $employee->id,
                    'gross_salary' => $result->gross,
                    'total_deductions' => $result->totalDeductions,
                    'net_salary' => $result->net,
                    'status' => 'draft',
                ]);

                $allItems = array_merge(
                    $result->earningItems,
                    $result->deductionItems,
                    $result->employerContributionItems,
                );
                foreach ($allItems as $item) {
                    $payslip->items()->create($item);
                }

                if ($result->otRecords->isNotEmpty()) {
                    $result->otRecords->each(fn ($record) => $record->update(['payslip_id' => $payslip->id]));
                }

                $otAmount += $result->otAmount;
                $incentives += $result->incentiveAmount;
                $reimbursements += $result->reimbursementAmount;
                $deductions += $result->totalDeductions;
                $totalPayrollPayout += $result->net;
            }

            $payroll->update([
                'processed_by' => $processedBy,
                'processed_at' => now(),
                'status' => 'draft',
                'total_payout' => $totalPayrollPayout,
                'ot_amount' => $otAmount,
                'incentives' => $incentives,
                'reimbursements' => $reimbursements,
                'deductions' => $deductions,
            ]);

            return $payroll->fresh(['payslips.employee.user']);
        });
    }

    public function submitForFinanceApproval(Payroll $payroll): Payroll
    {
        if ($payroll->status !== 'draft') {
            throw new \DomainException('Only draft payrolls can be submitted for finance approval.');
        }

        $payroll->update(['status' => 'pending_finance']);
        AuditLog::record($payroll, 'submitted_for_approval', null, ['status' => 'pending_finance']);

        foreach (User::whereIn('role', ['super_admin', 'finance', 'director'])->get() as $approver) {
            $approver->notify(new PayrollApprovalNotification($payroll->fresh(), 'finance_approved'));
        }

        return $payroll->fresh(['payslips.employee.user']);
    }

    public function approveFinance(Payroll $payroll, int $approverId): Payroll
    {
        if ($payroll->status !== 'pending_finance') {
            throw new \DomainException('Only pending payrolls can be approved by finance.');
        }

        // Maker-checker: whoever most recently generated/regenerated this draft
        // (processed_by) cannot also be the one who approves it into finalized.
        if ((int) $payroll->processed_by === $approverId) {
            throw new \DomainException('You processed this payroll — someone else must approve it (maker-checker separation).');
        }

        return DB::transaction(function () use ($payroll, $approverId) {
            $payroll->update([
                'status' => 'finalized',
                'finance_approved_by' => $approverId,
                'finance_approved_at' => now(),
            ]);

            AuditLog::record($payroll, 'approved', null, ['status' => 'finalized', 'finance_approved_by' => $approverId]);

            $payroll->loadMissing('payslips.employee.user');
            $payroll->payslips()->update(['status' => 'paid']);

            foreach ($payroll->payslips as $payslip) {
                $records = OvertimeRecord::where('payslip_id', $payslip->id)->where('is_paid', false)->get();
                if ($records->isNotEmpty()) {
                    $this->overtimeService->markAsPaid($records, $payslip->id);
                }
            }

            return $payroll->fresh(['payslips.employee.user']);
        });
    }

    public function rejectFinance(Payroll $payroll, ?string $note = null): Payroll
    {
        if ($payroll->status !== 'pending_finance') {
            throw new \DomainException('Only pending payrolls can be rejected by finance.');
        }

        $this->incentiveService->releaseIncludedForPayroll($payroll);
        $this->reimbursementService->releaseIncludedForPayroll($payroll);

        $payroll->update([
            'status' => 'draft',
            'finance_note' => $note,
            'finance_approved_by' => null,
            'finance_approved_at' => null,
        ]);
        AuditLog::record($payroll, 'rejected', null, ['status' => 'draft'], reason: $note);

        return $payroll->fresh(['payslips.employee.user']);
    }

    /** Lock a finalized payroll so it can no longer be regenerated or edited. */
    public function lock(Payroll $payroll, int $userId): Payroll
    {
        if ($payroll->status !== 'finalized') {
            throw new \DomainException('Only a finalized payroll can be locked.');
        }

        if ($payroll->isLocked()) {
            throw new \DomainException('This payroll is already locked.');
        }

        $payroll->update(['locked_at' => now(), 'locked_by' => $userId]);
        AuditLog::record($payroll, 'locked', null, ['locked_by' => $userId]);

        return $payroll->fresh();
    }

    /** Unlock a payroll, allowing it to be regenerated again if needed. */
    public function unlock(Payroll $payroll): Payroll
    {
        if (! $payroll->isLocked()) {
            throw new \DomainException('This payroll is not locked.');
        }

        $payroll->update(['locked_at' => null, 'locked_by' => null]);
        AuditLog::record($payroll, 'unlocked');

        return $payroll->fresh();
    }

    /**
     * Recompute a payroll's header totals from its current payslips — the
     * single source of truth used after any operation that touches one
     * payslip in isolation (regenerate/edit/delete a single employee), so the
     * header never drifts from SUM(payslips.*) the way PayrollRerunIntegrityTest
     * guards against for the whole-batch path.
     */
    private function recalculatePayrollTotals(Payroll $payroll): void
    {
        $payroll->update([
            'total_payout' => (float) $payroll->payslips()->sum('net_salary'),
            'deductions' => (float) $payroll->payslips()->sum('total_deductions'),
        ]);
    }

    /** Re-run the calculation engine for one employee inside an existing draft payroll, leaving every other payslip untouched. */
    public function regenerateSinglePayslip(Payroll $payroll, Employee $employee, int $userId): Payslip
    {
        if ($payroll->isLocked()) {
            throw new \DomainException('This payroll is locked and can no longer be regenerated.');
        }

        if ($payroll->status !== 'draft') {
            throw new \DomainException('Only draft payroll payslips can be regenerated.');
        }

        return DB::transaction(function () use ($payroll, $employee) {
            [$cycleFrom, $cycleTo] = $this->resolveCycleDates($payroll->month, $payroll->year, $payroll->cycle);
            $monthLabel = Carbon::parse("1 {$payroll->month} {$payroll->year}")->format('Y-m');

            $existing = Payslip::where('payroll_id', $payroll->id)->where('employee_id', $employee->id)->first();
            if ($existing?->isLocked()) {
                throw new \DomainException('This payslip is locked and can no longer be regenerated.');
            }
            $existing?->delete();

            $result = $this->salaryCalculationService->calculate($employee, $cycleFrom, $cycleTo, $monthLabel, $payroll);

            $payslip = Payslip::create([
                'payroll_id' => $payroll->id,
                'employee_id' => $employee->id,
                'gross_salary' => $result->gross,
                'total_deductions' => $result->totalDeductions,
                'net_salary' => $result->net,
                'status' => 'draft',
            ]);

            foreach (array_merge($result->earningItems, $result->deductionItems, $result->employerContributionItems) as $item) {
                $payslip->items()->create($item);
            }

            if ($result->otRecords->isNotEmpty()) {
                $result->otRecords->each(fn ($record) => $record->update(['payslip_id' => $payslip->id]));
            }

            $this->recalculatePayrollTotals($payroll);
            AuditLog::record($payslip, 'regenerated', null, null, subjectEmployeeId: $employee->id);

            return $payslip->fresh(['items', 'employee.user']);
        });
    }

    /**
     * Replace a draft payslip's earning/deduction/employer-contribution lines
     * with a manually adjusted set and recompute gross/deductions/net from them.
     *
     * @param  array<int, array{name: string, amount: float, type: string}>  $items
     */
    public function updatePayslipItems(Payslip $payslip, array $items, ?string $reason = null): Payslip
    {
        if ($payslip->status !== 'draft') {
            throw new \DomainException('Only draft payslips can be edited.');
        }

        if ($payslip->isLocked() || $payslip->payroll->isLocked()) {
            throw new \DomainException('This payslip is locked and can no longer be edited.');
        }

        return DB::transaction(function () use ($payslip, $items, $reason) {
            $old = $payslip->only(['gross_salary', 'total_deductions', 'net_salary']);

            $payslip->items()->delete();
            $gross = 0.0;
            $deductions = 0.0;
            foreach ($items as $item) {
                $payslip->items()->create($item);
                if ($item['type'] === 'earning') {
                    $gross += (float) $item['amount'];
                } elseif ($item['type'] === 'deduction') {
                    $deductions += (float) $item['amount'];
                }
            }
            $net = $gross - $deductions;

            $payslip->update(['gross_salary' => $gross, 'total_deductions' => $deductions, 'net_salary' => $net]);
            $this->recalculatePayrollTotals($payslip->payroll);

            AuditLog::record(
                $payslip,
                'edited',
                $old,
                ['gross_salary' => $gross, 'total_deductions' => $deductions, 'net_salary' => $net],
                reason: $reason,
                subjectEmployeeId: $payslip->employee_id,
            );

            return $payslip->fresh(['items', 'employee.user']);
        });
    }

    /** Delete a draft payslip and recompute the parent payroll's totals. */
    public function deletePayslip(Payslip $payslip): void
    {
        if ($payslip->status !== 'draft') {
            throw new \DomainException('Only draft payslips can be deleted.');
        }

        if ($payslip->isLocked() || $payslip->payroll->isLocked()) {
            throw new \DomainException('This payslip is locked and cannot be deleted.');
        }

        DB::transaction(function () use ($payslip) {
            $payroll = $payslip->payroll;
            $payslip->delete();
            $this->recalculatePayrollTotals($payroll);
        });
    }

    /** Lock an individual payslip so it survives a payroll-level regenerate untouched. */
    public function lockPayslip(Payslip $payslip, int $userId): Payslip
    {
        if ($payslip->isLocked()) {
            throw new \DomainException('This payslip is already locked.');
        }

        $payslip->update(['locked_at' => now(), 'locked_by' => $userId]);
        AuditLog::record($payslip, 'locked', null, ['locked_by' => $userId], subjectEmployeeId: $payslip->employee_id);

        return $payslip->fresh();
    }

    /** Unlock an individual payslip. */
    public function unlockPayslip(Payslip $payslip): Payslip
    {
        if (! $payslip->isLocked()) {
            throw new \DomainException('This payslip is not locked.');
        }

        $payslip->update(['locked_at' => null, 'locked_by' => null]);
        AuditLog::record($payslip, 'unlocked', null, null, subjectEmployeeId: $payslip->employee_id);

        return $payslip->fresh();
    }

    /** Admin-triggered single payslip email (any employee) — self-service email lives in MyPayslips::emailPayslip(). */
    public function emailPayslip(Payslip $payslip): void
    {
        $email = $payslip->employee->user?->email;

        if (! $email) {
            throw new \DomainException('This employee has no email address on file.');
        }

        Mail::to($email)->queue(new PayslipMail($payslip));
        AuditLog::record($payslip, 'emailed', null, ['to' => $email], subjectEmployeeId: $payslip->employee_id);
    }

    public function dispatchFinalizedPayrollNotifications(Payroll $payroll): void
    {
        $payroll->loadMissing('payslips.employee.user', 'payslips.payroll');

        foreach ($payroll->payslips as $payslip) {
            if (! $payslip->employee->user) {
                continue;
            }

            $payslip->employee->user->notify(new PayslipGeneratedNotification($payslip));
            Mail::to($payslip->employee->user->email)->send(new PayslipMail($payslip));
            AuditLog::record($payslip, 'emailed', null, ['to' => $payslip->employee->user->email], subjectEmployeeId: $payslip->employee_id);
        }
    }

    public function resolveCycleDates(string $month, int $year, string $cycle): array
    {
        $monthNum = Carbon::parse("1 {$month} {$year}")->month;

        if ($cycle === 'cycle_a') {
            $from = Carbon::create($year, $monthNum, 1)->startOfDay();
            $to = $from->copy()->endOfMonth();
        } else {
            $from = Carbon::create($year, $monthNum, 1)
                ->subMonth()
                ->setDay(21)
                ->startOfDay();
            $to = Carbon::create($year, $monthNum, 20)->endOfDay();
        }

        return [$from, $to];
    }
}
