<?php

namespace App\Livewire\Payroll;

use App\Models\Payslip;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class MyPayslips extends Component
{
    use WithPagination;

    public $selectedSlip = null;

    public bool $showModal = false;

    public bool $showSalaryBreakup = false;

    public string $filterYear = '';

    public function viewDetails(int $id): void
    {
        $this->selectedSlip = Payslip::with(['payroll', 'items', 'employee.user', 'employee.jobTitle', 'employee.department'])
            ->where('employee_id', Auth::user()->employee->id)
            ->findOrFail($id);
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->selectedSlip = null;
    }

    public function openSalaryBreakup(): void
    {
        $this->showSalaryBreakup = true;
    }

    public function render()
    {
        $employee = Auth::user()->employee;

        $empty = [
            'payslips' => collect(),
            'latestPayslip' => null,
            'trendLabels' => [],
            'trendGross' => [],
            'trendNet' => [],
            'salaryRevisions' => [],
            'monthlyGross' => 0,
            'monthlyNet' => 0,
            'monthlyDeductions' => 0,
            'ctc' => 0,
            'payCycle' => null,
            'salaryComponents' => collect(),
        ];

        if (! $employee) {
            return view('livewire.payroll.my-payslips', $empty)->layout('layouts.app', ['title' => 'My Payslip']);
        }

        $payslips = Payslip::where('employee_id', $employee->id)
            ->whereIn('status', ['paid', 'draft'])
            ->with(['payroll', 'items'])
            ->when($this->filterYear, fn ($q) => $q->whereHas('payroll', fn ($q2) => $q2->where('year', $this->filterYear)))
            ->orderByDesc('id')
            ->paginate(5)->withPath(route('payroll.payslips'));

        $allPayslips = Payslip::where('employee_id', $employee->id)
            ->whereIn('status', ['paid', 'draft'])
            ->with(['payroll', 'items'])
            ->orderByDesc('id')
            ->get();

        $latestPayslip = $allPayslips->first();

        // Last 6 months trend (oldest → newest for chart)
        $trendSlips = $allPayslips->take(6)->reverse()->values();
        $trendLabels = $trendSlips->map(fn ($s) => substr($s->payroll->month ?? '', 0, 3).' '.($s->payroll->year ?? ''))->toArray();
        $trendGross = $trendSlips->map(fn ($s) => (float) $s->gross_salary)->toArray();
        $trendNet = $trendSlips->map(fn ($s) => (float) $s->net_salary)->toArray();

        // Salary components
        $salaryComponents = $employee->salaries()->with('component')->get();
        $monthlyGross = (float) ($latestPayslip?->gross_salary ?? $salaryComponents->filter(fn ($s) => ($s->component?->type ?? '') === 'earning')->sum('amount'));
        $monthlyDeductions = (float) ($latestPayslip?->total_deductions ?? $salaryComponents->filter(fn ($s) => ($s->component?->type ?? '') === 'deduction')->sum('amount'));
        $monthlyNet = (float) ($latestPayslip?->net_salary ?? $monthlyGross - $monthlyDeductions);
        $ctc = round($monthlyGross * 12, 2);

        // Salary revision history from payslip gross changes
        $salaryRevisions = [];
        $prev = null;
        foreach ($allPayslips->reverse() as $slip) {
            $gross = (float) $slip->gross_salary;
            if ($prev === null || abs($gross - $prev) > 100) {
                $pct = $prev ? round((($gross - $prev) / $prev) * 100, 1) : 0;
                $salaryRevisions[] = [
                    'month' => ($slip->payroll->month ?? '').' '.($slip->payroll->year ?? ''),
                    'gross' => $gross,
                    'net' => (float) $slip->net_salary,
                    'change' => $pct,
                    'label' => $pct > 0 ? "{$pct}% Increment" : ($pct < 0 ? "{$pct}% Reduction" : 'Joining'),
                    'color' => $pct > 0 ? 'emerald' : ($pct < 0 ? 'red' : 'blue'),
                ];
                $prev = $gross;
            }
        }
        $salaryRevisions = array_reverse(array_slice($salaryRevisions, -4));

        return view('livewire.payroll.my-payslips', [
            'payslips' => $payslips,
            'latestPayslip' => $latestPayslip,
            'trendLabels' => $trendLabels,
            'trendGross' => $trendGross,
            'trendNet' => $trendNet,
            'salaryRevisions' => $salaryRevisions,
            'monthlyGross' => $monthlyGross,
            'monthlyNet' => $monthlyNet,
            'monthlyDeductions' => $monthlyDeductions,
            'ctc' => $ctc,
            'payCycle' => $employee->salary_cycle ?? null,
            'salaryComponents' => $salaryComponents,
        ])->layout('layouts.app', ['title' => 'My Payslip']);
    }
}
