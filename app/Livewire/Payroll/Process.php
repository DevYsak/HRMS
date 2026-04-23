<?php

namespace App\Livewire\Payroll;

use App\Models\Employee;
use App\Models\Incentive;
use App\Models\LeaveEncashment;
use App\Models\OvertimeRecord;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Reimbursement;
use App\Services\LwpService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Process extends Component
{
    public string $month;

    public int $year;

    /** cycle_a = 1st-31st | cycle_b = 21st-20th */
    public string $cycle = 'cycle_a';

    public ?Payroll $currentPayroll = null;

    public function mount(): void
    {
        $this->month = Carbon::now()->format('F');
        $this->year = Carbon::now()->year;
        $this->loadCurrentPayroll();
    }

    public function loadCurrentPayroll(): void
    {
        $this->currentPayroll = Payroll::where('month', $this->month)
            ->where('year', $this->year)
            ->where('cycle', $this->cycle)
            ->with(['payslips.employee.user'])
            ->first();
    }

    /** Run payroll for the selected cycle (A or B). */
    public function startProcessing(): void
    {
        if ($this->currentPayroll && $this->currentPayroll->status === 'finalized') {
            \Flux::toast('Payroll for this period and cycle is already finalized.', variant: 'danger');

            return;
        }

        $monthLabel = Carbon::now()->format('Y-m'); // e.g. 2026-04

        // Resolve payroll cycle date window
        [$cycleFrom, $cycleTo] = $this->resolveCycleDates();

        DB::transaction(function () use ($monthLabel, $cycleFrom, $cycleTo) {
            $payroll = Payroll::firstOrCreate(
                ['month' => $this->month, 'year' => $this->year, 'cycle' => $this->cycle],
                ['status' => 'draft', 'processed_by' => Auth::id()]
            );

            // Only process employees assigned to the selected salary cycle
            $employees = Employee::where('status', 'active')
                ->where('salary_cycle', $this->cycle)
                ->with('salaries.component')
                ->get();

            $lwpService = app(LwpService::class);

            $totalPayrollPayout = 0;

            foreach ($employees as $employee) {
                // Remove any existing draft payslip for this cycle run
                Payslip::where('payroll_id', $payroll->id)
                    ->where('employee_id', $employee->id)
                    ->delete();

                $gross = 0;
                $deductions = 0;
                $items = [];

                // --- Base salary components ---
                foreach ($employee->salaries as $salary) {
                    $comp = $salary->component;

                    if (! $comp->is_active) {
                        continue;
                    }

                    if ($comp->type === 'earning') {
                        $gross += $salary->amount;
                    } else {
                        $deductions += $salary->amount;
                    }

                    $items[] = [
                        'name' => $comp->name,
                        'amount' => $salary->amount,
                        'type' => $comp->type,
                    ];
                }

                // --- Approved Incentives for this month ---
                $incentivesTotal = Incentive::where('employee_id', $employee->id)
                    ->where('month', $monthLabel)
                    ->where('status', 'approved')
                    ->sum('amount');

                if ($incentivesTotal > 0) {
                    $gross += $incentivesTotal;
                    $items[] = ['name' => 'Incentives', 'amount' => $incentivesTotal, 'type' => 'earning'];
                    // Mark as included in payroll
                    Incentive::where('employee_id', $employee->id)
                        ->where('month', $monthLabel)
                        ->where('status', 'approved')
                        ->update(['status' => 'included', 'payroll_id' => $payroll->id]);
                }

                // --- Approved Reimbursements for this month ---
                $reimbTotal = Reimbursement::where('employee_id', $employee->id)
                    ->where('month', $monthLabel)
                    ->where('status', 'approved')
                    ->sum('amount');

                if ($reimbTotal > 0) {
                    $gross += $reimbTotal;
                    $items[] = ['name' => 'Reimbursements', 'amount' => $reimbTotal, 'type' => 'earning'];
                    Reimbursement::where('employee_id', $employee->id)
                        ->where('month', $monthLabel)
                        ->where('status', 'approved')
                        ->update(['status' => 'included', 'payroll_id' => $payroll->id]);
                }

                // --- Final Settlement (Offboarding) ---
                $exitRecord = \App\Models\ExitRecord::where('employee_id', $employee->id)
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

                // --- Approved OT Records within cycle window (auto-inclusion) ---
                $otRecords = OvertimeRecord::where('employee_id', $employee->id)
                    ->unpaid()
                    ->whereBetween('work_date', [$cycleFrom->toDateString(), $cycleTo->toDateString()])
                    ->whereHas('otRequest', fn ($q) => $q->where('status', 'approved'))
                    ->get();

                $otTotal = $otRecords->sum('ot_amount');

                if ($otTotal > 0) {
                    $otHours = $otRecords->sum('ot_hours');
                    $gross += $otTotal;
                    $items[] = [
                        'name' => "OT ({$otHours}h)",
                        'amount' => $otTotal,
                        'type' => 'earning',
                    ];
                    // Mark OT records as paid and link payslip (will be updated after payslip creation)
                    $otRecords->each(fn ($r) => $r->update(['is_paid' => true]));
                }

                // --- Approved Leave Encashments for this month ---
                // Capture gross AFTER all earnings are summed so the daily rate is accurate.
                $encashments = LeaveEncashment::where('employee_id', $employee->id)
                    ->where('payout_month', $monthLabel)
                    ->where('status', 'approved')
                    ->get();

                $encashmentTotal = $encashments->sum(function ($enc) use ($gross) {
                    // Daily rate = total earnings gross / 26 working days
                    $dailyRate = $gross > 0 ? $gross / 26 : 0;

                    return round($enc->requested_days * $dailyRate, 2);
                });

                if ($encashmentTotal > 0) {
                    $gross += $encashmentTotal;
                    $encashedDays = $encashments->sum('requested_days');
                    $items[] = [
                        'name' => "Leave Encashment ({$encashedDays}d)",
                        'amount' => $encashmentTotal,
                        'type' => 'earning',
                    ];
                    $encashments->each(fn ($e) => $e->update(['status' => 'processed']));
                }

                // --- LWP (Leave Without Pay) auto-deduction ---
                $lwp = $lwpService->calculate($employee, $cycleFrom, $cycleTo);
                if ($lwp['deduction'] > 0) {
                    $deductions += $lwp['deduction'];
                    foreach ($lwp['items'] as $lwpItem) {
                        $items[] = $lwpItem;
                    }
                }

                $net = $gross - $deductions;

                $payslip = Payslip::create([
                    'payroll_id' => $payroll->id,
                    'employee_id' => $employee->id,
                    'gross_salary' => $gross,
                    'total_deductions' => $deductions,
                    'net_salary' => $net,
                    'status' => 'draft',
                ]);

                foreach ($items as $itemData) {
                    $payslip->items()->create($itemData);
                }

                $totalPayrollPayout += $net;
            }

            $payroll->update([
                'total_payout' => $totalPayrollPayout,
                'status' => 'draft',
                'processed_at' => Carbon::now(),
            ]);
        });

        $this->loadCurrentPayroll();

        $cycleLabel = strtoupper(str_replace('_', ' ', $this->cycle));
        \Flux::toast("Payroll draft [{$cycleLabel}] generated for {$this->month} {$this->year}.");
    }

    /** Finalize and mark all payslips as paid for this cycle run. */
    public function finalize(): void
    {
        if (! $this->currentPayroll || $this->currentPayroll->status !== 'draft') {
            \Flux::toast('No draft payroll to finalize.', variant: 'danger');

            return;
        }

        $this->currentPayroll->payslips()->update(['status' => 'paid']);
        $this->currentPayroll->update([
            'status' => 'finalized',
            'processed_at' => Carbon::now(),
        ]);

        // Notify each employee
        foreach ($this->currentPayroll->payslips as $payslip) {
            $payslip->employee->user->notify(new \App\Notifications\PayslipGeneratedNotification($payslip));
        }

        $this->loadCurrentPayroll();

        \Flux::toast('Payroll finalized. Employees can now view their payslips.');
    }

    public function render()
    {
        return view('livewire.payroll.process')
            ->layout('layouts.app', ['title' => 'Run Payroll']);
    }

    /**
     * Returns [from, to] Carbon dates for the active payroll cycle.
     *
     *  Cycle A: 1st → last day of the selected month
     *  Cycle B: 21st of previous month → 20th of selected month
     */
    private function resolveCycleDates(): array
    {
        $monthNum = Carbon::parse("1 {$this->month} {$this->year}")->month;

        if ($this->cycle === 'cycle_a') {
            $from = Carbon::create($this->year, $monthNum, 1)->startOfDay();
            $to = $from->copy()->endOfMonth();
        } else {
            // Cycle B: 21st of previous month to 20th of this month
            $from = Carbon::create($this->year, $monthNum, 1)
                ->subMonth()
                ->setDay(21)
                ->startOfDay();
            $to = Carbon::create($this->year, $monthNum, 20)->endOfDay();
        }

        return [$from, $to];
    }
}
