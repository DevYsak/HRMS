<?php

namespace App\Livewire;

use App\Models\Incentive;
use App\Models\LeaveEncashment;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\Payroll;
use App\Models\Reimbursement;
use Illuminate\Support\Carbon;
use Livewire\Component;

class FinanceDashboard extends Component
{
    public string $month = '';

    public int $year = 0;

    public function mount(): void
    {
        $this->month = Carbon::now()->format('Y-m');
        $this->year = Carbon::now()->year;
    }

    public function render()
    {
        [$year, $mon] = explode('-', $this->month);

        $cycleARun = Payroll::where('cycle', 'cycle_a')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $mon)
            ->with('payslips')
            ->first();

        $cycleBRun = Payroll::where('cycle', 'cycle_b')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $mon)
            ->with('payslips')
            ->first();

        $pendingIncentives = Incentive::where('status', 'pending')->count();
        $pendingReimbursements = Reimbursement::where('status', 'pending')->count();
        $pendingEncashments = LeaveEncashment::where('status', 'pending')->count();

        $approvedIncentivesTotal = Incentive::where('month', $this->month)->where('status', 'approved')->sum('amount');
        $approvedReimbursementsTotal = Reimbursement::where('month', $this->month)->where('status', 'approved')->sum('amount');

        $otPendingCount = OtRequest::where('status', 'pending')->count();
        $monthlyOtAmount = OtRequest::where('status', 'approved')
            ->whereYear('work_date', $year)
            ->whereMonth('work_date', $mon)
            ->sum('requested_hours') * 100; // ₹100/hr

        $otSummary = OvertimeRecord::query()
            ->whereHas('otRequest', fn ($q) => $q->where('status', 'approved'))
            ->whereYear('work_date', $year)
            ->whereMonth('work_date', $mon)
            ->selectRaw('employee_id, SUM(ot_hours) as total_hours, SUM(ot_amount) as total_amount')
            ->groupBy('employee_id')
            ->with('employee.user')
            ->get();

        return view('livewire.finance-dashboard', compact(
            'cycleARun',
            'cycleBRun',
            'pendingIncentives',
            'pendingReimbursements',
            'pendingEncashments',
            'approvedIncentivesTotal',
            'approvedReimbursementsTotal',
            'otPendingCount',
            'monthlyOtAmount',
            'otSummary',
        ))->layout('layouts.app', ['title' => 'Finance Dashboard']);
    }
}
