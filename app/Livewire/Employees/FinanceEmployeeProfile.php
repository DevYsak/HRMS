<?php

namespace App\Livewire\Employees;

use App\Models\Employee;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class FinanceEmployeeProfile extends Component
{
    use WithPagination;

    public Employee $employee;

    public function mount(Employee $employee): void
    {
        abort_unless(Auth::user()->canViewFinanceProfile(), 403);
        $this->employee = $employee;
    }

    public function render(): View
    {
        abort_unless(Auth::user()->canViewFinanceProfile(), 403);

        $salaries = $this->employee->salaries()
            ->with('salaryComponent')
            ->latest()
            ->get();

        $payslips = $this->employee->payslips()
            ->with('payroll')
            ->latest()
            ->paginate(6);

        return view('livewire.employees.finance-employee-profile', [
            'salaries' => $salaries,
            'payslips' => $payslips,
        ]);
    }
}
