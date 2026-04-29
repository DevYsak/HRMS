<?php

namespace App\Livewire\Payroll;

use App\Models\Payroll;
use App\Services\PayrollService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class FinanceApproval extends Component
{
    public $pendingPayrolls;

    public function mount()
    {
        $this->loadPending();
    }

    public function loadPending()
    {
        $this->pendingPayrolls = Payroll::where('status', 'pending_finance')
            ->with(['payslips.employee.user', 'processedBy'])
            ->latest()
            ->get();
    }

    public function approve($payrollId, PayrollService $payrollService)
    {
        abort_unless(Auth::user()->canApproveFinance(), 403);

        $payroll = Payroll::findOrFail($payrollId);

        if ($payroll->status !== 'pending_finance') {
            return;
        }

        $payroll = $payrollService->approveFinance($payroll, Auth::id());
        $payrollService->dispatchFinalizedPayrollNotifications($payroll);

        $this->loadPending();
        \Flux::toast('Payroll approved and finalized!');
    }

    public function reject($payrollId)
    {
        abort_unless(Auth::user()->canApproveFinance(), 403);

        $payroll = Payroll::findOrFail($payrollId);
        if ($payroll->status !== 'pending_finance') {
            \Flux::toast('Only pending payrolls can be rejected.', variant: 'danger');

            return;
        }

        app(PayrollService::class)->rejectFinance($payroll);

        $this->loadPending();
        \Flux::toast('Payroll sent back for corrections.');
    }

    public function render()
    {
        abort_unless(Auth::user()->canApproveFinance(), 403);

        return view('livewire.payroll.finance-approval')
            ->layout('layouts.app', ['title' => 'Finance Approval']);
    }
}
