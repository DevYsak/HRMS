<?php

namespace App\Services;

use App\Mail\PayslipMail;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\Payroll;
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
        [$cycleFrom, $cycleTo] = $this->resolveCycleDates($month, $year, $cycle);
        $monthLabel = Carbon::parse("1 {$month} {$year}")->format('Y-m');

        return DB::transaction(function () use ($month, $year, $cycle, $processedBy, $cycleFrom, $cycleTo, $monthLabel) {
            $payroll = Payroll::where('month', $month)
                ->where('year', $year)
                ->where('cycle', $cycle)
                ->first();

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

            $totalPayrollPayout = 0.0;
            $otAmount = 0.0;
            $incentives = 0.0;
            $reimbursements = 0.0;
            $deductions = 0.0;

            foreach ($employees as $employee) {
                Payslip::where('payroll_id', $payroll->id)
                    ->where('employee_id', $employee->id)
                    ->delete();

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

        return DB::transaction(function () use ($payroll, $approverId) {
            $payroll->update([
                'status' => 'finalized',
                'finance_approved_by' => $approverId,
                'finance_approved_at' => now(),
            ]);

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

        return $payroll->fresh(['payslips.employee.user']);
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
