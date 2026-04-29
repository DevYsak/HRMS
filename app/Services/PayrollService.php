<?php

namespace App\Services;

use App\Mail\PayslipMail;
use App\Models\Employee;
use App\Models\ExitRecord;
use App\Models\LeaveEncashment;
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
        protected LwpService $lwpService,
        protected OvertimeService $overtimeService,
        protected IncentiveService $incentiveService,
        protected ReimbursementService $reimbursementService,
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
                ->with('salaries.component')
                ->get();
            $activeEmployeeIds = $employees->pluck('id');

            $this->incentiveService->releaseIncludedForEmployeesAndMonth($payroll, $activeEmployeeIds, $monthLabel);
            $this->reimbursementService->releaseIncludedForEmployeesAndMonth($payroll, $activeEmployeeIds, $monthLabel);

            $totalPayrollPayout = 0;
            $otAmount = 0;
            $incentives = 0;
            $reimbursements = 0;
            $deductions = 0;

            foreach ($employees as $employee) {
                Payslip::where('payroll_id', $payroll->id)
                    ->where('employee_id', $employee->id)
                    ->delete();

                $gross = 0;
                $employeeDeductions = 0;
                $items = [];

                foreach ($employee->salaries as $salary) {
                    $component = $salary->component;
                    if (! $component->is_active) {
                        continue;
                    }

                    if ($component->type === 'earning') {
                        $gross += $salary->amount;
                    } else {
                        $employeeDeductions += $salary->amount;
                    }

                    $items[] = [
                        'name' => $component->name,
                        'amount' => $salary->amount,
                        'type' => $component->type,
                    ];
                }

                $incentiveInclusion = $this->incentiveService
                    ->includeApprovedForEmployeeMonth($employee, $monthLabel, $payroll);
                $incentiveTotal = (float) $incentiveInclusion['total'];
                if ($incentiveTotal > 0) {
                    $gross += $incentiveTotal;
                    $incentives += $incentiveTotal;
                    $items[] = $incentiveInclusion['item'];
                }

                $reimbursementInclusion = $this->reimbursementService
                    ->includeApprovedForEmployeeMonth($employee, $monthLabel, $payroll);
                $reimbursementTotal = (float) $reimbursementInclusion['total'];
                if ($reimbursementTotal > 0) {
                    $gross += $reimbursementTotal;
                    $reimbursements += $reimbursementTotal;
                    $items[] = $reimbursementInclusion['item'];
                }

                $exitRecord = ExitRecord::where('employee_id', $employee->id)
                    ->where('final_settlement_done', true)
                    ->whereNull('payroll_id')
                    ->first();
                if ($exitRecord && $exitRecord->final_settlement_amount > 0) {
                    $gross += $exitRecord->final_settlement_amount;
                    $items[] = [
                        'name' => 'Final Settlement',
                        'amount' => $exitRecord->final_settlement_amount,
                        'type' => 'earning',
                    ];
                    $exitRecord->update(['payroll_id' => $payroll->id]);
                }

                $otRecords = OvertimeRecord::where('employee_id', $employee->id)
                    ->unpaid()
                    ->whereNull('payslip_id')
                    ->whereBetween('work_date', [$cycleFrom->toDateString(), $cycleTo->toDateString()])
                    ->whereHas('otRequest', fn ($query) => $query->where('status', 'approved'))
                    ->get();
                $otTotal = (float) $otRecords->sum('ot_amount');
                if ($otTotal > 0) {
                    $otHours = round((float) $otRecords->sum('ot_hours'), 2);
                    $gross += $otTotal;
                    $otAmount += $otTotal;
                    $items[] = [
                        'name' => "OT ({$otHours}h)",
                        'amount' => $otTotal,
                        'type' => 'earning',
                    ];
                }

                $encashments = LeaveEncashment::where('employee_id', $employee->id)
                    ->where('payout_month', $monthLabel)
                    ->where('status', 'approved')
                    ->get();
                $encashmentTotal = $encashments->sum(function ($encashment) use ($gross) {
                    $dailyRate = $gross > 0 ? $gross / 26 : 0;

                    return round($encashment->requested_days * $dailyRate, 2);
                });
                if ($encashmentTotal > 0) {
                    $gross += $encashmentTotal;
                    $items[] = [
                        'name' => 'Leave Encashment ('.$encashments->sum('requested_days').'d)',
                        'amount' => $encashmentTotal,
                        'type' => 'earning',
                    ];
                    $encashments->each(fn ($encashment) => $encashment->update(['status' => 'processed']));
                }

                $lwp = $this->lwpService->calculate($employee, $cycleFrom, $cycleTo);
                if ($lwp['deduction'] > 0) {
                    $employeeDeductions += $lwp['deduction'];
                    $deductions += $lwp['deduction'];
                    foreach ($lwp['items'] as $item) {
                        $items[] = $item;
                    }
                }

                $net = $gross - $employeeDeductions;

                $payslip = Payslip::create([
                    'payroll_id' => $payroll->id,
                    'employee_id' => $employee->id,
                    'gross_salary' => $gross,
                    'total_deductions' => $employeeDeductions,
                    'net_salary' => $net,
                    'status' => 'draft',
                ]);

                foreach ($items as $item) {
                    $payslip->items()->create($item);
                }

                if ($otRecords->isNotEmpty()) {
                    $otRecords->each(fn ($record) => $record->update(['payslip_id' => $payslip->id]));
                }

                $totalPayrollPayout += $net;
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
