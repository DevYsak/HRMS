<?php

namespace App\Livewire\Payroll;

use App\Models\Payroll;
use App\Models\User;
use App\Notifications\PayrollApprovalNotification;
use App\Services\OvertimeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    public function approve($payrollId, OvertimeService $overtimeService)
    {
        $payroll = Payroll::findOrFail($payrollId);

        if ($payroll->status !== 'pending_finance') {
            return;
        }

        DB::transaction(function () use ($payroll, $overtimeService) {
            $payroll->update([
                'status'              => 'finalized',
                'finance_approved_by' => Auth::id(),
                'finance_approved_at' => Carbon::now(),
            ]);

            $payroll->payslips()->update(['status' => 'paid']);

            // Mark unpaid OT records as paid
            foreach ($payroll->payslips as $payslip) {
                $unpaidRecords = $overtimeService->getUnpaidRecords($payslip->employee);
                if ($unpaidRecords->isNotEmpty()) {
                    $overtimeService->markAsPaid($unpaidRecords, $payslip->id);
                }
            }
        });

        // Notify employees and send email
        foreach ($payroll->payslips as $payslip) {
            if ($payslip->employee->user) {
                $payslip->employee->user->notify(
                    new PayrollApprovalNotification($payroll, 'processed')
                );
                
                \Illuminate\Support\Facades\Mail::to($payslip->employee->user->email)->send(
                    new \App\Mail\PayslipMail($payslip)
                );
            }
        }

        $this->loadPending();
        \Flux::toast('Payroll approved and finalized!');
    }

    public function reject($payrollId)
    {
        $payroll = Payroll::findOrFail($payrollId);
        $payroll->update(['status' => 'draft']); // Send back to draft for HR to fix
        
        $this->loadPending();
        \Flux::toast('Payroll sent back for corrections.');
    }

    public function render()
    {
        return view('livewire.payroll.finance-approval')
            ->layout('layouts.app', ['title' => 'Finance Approval']);
    }
}
