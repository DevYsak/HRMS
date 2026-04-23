<?php

namespace App\Livewire\Payroll;

use App\Models\Payslip;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class MyPayslips extends Component
{
    public $selectedSlip = null;
    public $showModal = false;

    public function viewDetails($id)
    {
        $this->selectedSlip = Payslip::with(['payroll', 'items', 'employee.user', 'employee.jobTitle', 'employee.department'])
            ->where('employee_id', Auth::user()->employee->id)
            ->findOrFail($id);
            
        $this->showModal = true;
    }

    public function render()
    {
        $payslips = Payslip::where('employee_id', Auth::user()->employee->id)
            ->where('status', 'paid')
            ->with('payroll')
            ->latest()
            ->get();

        return view('livewire.payroll.my-payslips', [
            'payslips' => $payslips,
        ])->layout('layouts.app', ['title' => 'My Payslips']);
    }
}
