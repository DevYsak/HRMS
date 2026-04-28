<?php

namespace App\Livewire\Payroll;

use App\Models\Payslip;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class MyPayslips extends Component
{
    public $selectedSlip = null;

    public $showModal = false;

    // Payslips sidebar / preview
    public $selectedYear;

    public $selectedPayslipId = null;

    // Salary breakup modal
    public $showSalaryBreakup = false;

    public function viewDetails($id)
    {
        $this->selectedSlip = Payslip::with(['payroll', 'items', 'employee.user', 'employee.jobTitle', 'employee.department'])
            ->where('employee_id', Auth::user()->employee->id)
            ->findOrFail($id);

        $this->showModal = true;
    }

    public function selectPayslip($id)
    {
        $this->selectedPayslipId = $id;
        $this->selectedSlip = Payslip::with(['payroll', 'items', 'employee.user', 'employee.jobTitle', 'employee.department'])
            ->where('employee_id', Auth::user()->employee->id)
            ->find($id);
    }

    public function selectYear($year)
    {
        $this->selectedYear = $year;
        // reset selected payslip when changing year
        $this->selectedPayslipId = null;
        $this->selectedSlip = null;
    }

    public function openSalaryBreakup()
    {
        $this->showSalaryBreakup = true;
    }

    public function render()
    {
        $employee = Auth::user()->employee;

        if (! $employee) {
            return view('livewire.payroll.my-payslips', [
                'payslips' => collect(),
                'payslipsByYear' => collect(),
                'years' => collect(),
                'selectedYear' => now()->year,
                'salaryComponents' => collect(),
                'monthlyGross' => 0,
                'ctc' => 0,
                'payCycle' => null,
                'timeline' => collect(),
            ])->layout('layouts.app', ['title' => 'My Payslips']);
        }

        $payslips = Payslip::where('employee_id', $employee->id)
            ->whereIn('status', ['paid', 'draft'])
            ->with(['payroll', 'items'])
            ->orderByDesc('id')
            ->get();

        // group payslips by payroll year
        $payslipsByYear = $payslips->groupBy(function ($p) {
            return $p->payroll->year ?? now()->year;
        })->map(fn ($g) => $g->sortByDesc(fn ($p) => $p->payroll->month ?? 0));

        $years = $payslipsByYear->keys()->sortDesc()->values();

        // salary components (monthly)
        $salaryComponents = $employee->salaries()->with('component')->get();
        $monthlyGross = $salaryComponents->sum('amount');
        $ctc = round($monthlyGross * 12, 2);

        // build a simple salary timeline from salary component created_at groups (best-effort)
        $timeline = $salaryComponents->groupBy(fn ($s) => $s->created_at->toDateString())
            ->map(function ($group, $date) {
                $total = $group->sum('amount');
                $basic = $group->filter(fn ($c) => str_contains(strtolower($c->component->name ?? ''), 'basic'))->sum('amount');
                if ($basic <= 0) {
                    $basic = $group->first()?->amount ?? 0;
                }

                return [
                    'effective_from' => $date,
                    'components' => $group->map(fn ($c) => [
                        'name' => $c->component->name ?? 'Component',
                        'amount' => $c->amount,
                        'type' => $c->component->type ?? 'earning',
                    ])->values(),
                    'regular_salary' => round($basic, 2),
                    'allowances' => round($total - $basic, 2),
                    'total' => round($total, 2),
                ];
            })->sortByDesc('effective_from')->values();

        // default selected year
        if (! $this->selectedYear) {
            $this->selectedYear = $years->first() ?? now()->year;
        }

        return view('livewire.payroll.my-payslips', [
            'payslips' => $payslips,
            'payslipsByYear' => $payslipsByYear,
            'years' => $years,
            'selectedYear' => $this->selectedYear,
            'salaryComponents' => $salaryComponents,
            'monthlyGross' => $monthlyGross,
            'ctc' => $ctc,
            'payCycle' => $employee->salary_cycle ?? null,
            'timeline' => $timeline,
        ])->layout('layouts.app', ['title' => 'My Payslips']);
    }
}
