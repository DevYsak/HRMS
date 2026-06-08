<?php

namespace App\Livewire\Performance;

use App\Models\EmployeeKpi;
use App\Models\EmployeeScorecard;
use App\Models\PerformanceCycle;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class MyKpis extends Component
{
    public ?int $selectedCycleId = null;

    public ?int $updatingKpiId = null;

    public float $progressInput = 0;

    public string $notesInput = '';

    public bool $showUpdateModal = false;

    public function mount(): void
    {
        $employee = Auth::user()->employee;

        if (! $employee) {
            return;
        }

        $latest = PerformanceCycle::whereHas('employeeKpis', fn ($q) => $q->where('employee_id', $employee->id))
            ->whereIn('status', ['active', 'self_review', 'manager_review', 'hr_review', 'completed', 'locked'])
            ->latest('start_date')
            ->first();

        $this->selectedCycleId = $latest?->id;
    }

    public function openUpdate(int $kpiId): void
    {
        $employeeId = Auth::user()->employee?->id;
        $kpi = EmployeeKpi::where('id', $kpiId)->where('employee_id', $employeeId)->firstOrFail();

        $this->updatingKpiId = $kpiId;
        $this->progressInput = $kpi->progress_percent ?? 0;
        $this->notesInput = $kpi->notes ?? '';
        $this->showUpdateModal = true;
    }

    public function saveProgress(): void
    {
        $this->validate([
            'progressInput' => 'required|numeric|min:0|max:100',
            'notesInput' => 'nullable|string|max:500',
        ]);

        $employeeId = Auth::user()->employee?->id;
        $kpi = EmployeeKpi::where('id', $this->updatingKpiId)->where('employee_id', $employeeId)->firstOrFail();

        $status = match (true) {
            $this->progressInput >= 100 => 'achieved',
            $this->progressInput > 0 => 'in_progress',
            default => 'not_started',
        };

        $kpi->update([
            'progress_percent' => $this->progressInput,
            'notes' => $this->notesInput ?: null,
            'status' => $status,
        ]);

        $this->showUpdateModal = false;
        $this->reset(['updatingKpiId', 'progressInput', 'notesInput']);

        \Flux::toast('KPI progress updated.', variant: 'success');
    }

    public function render()
    {
        $user = Auth::user();
        $employee = $user->employee;

        abort_unless($employee, 403);

        $cycles = PerformanceCycle::whereHas('employeeKpis', fn ($q) => $q->where('employee_id', $employee->id))
            ->whereIn('status', ['active', 'self_review', 'manager_review', 'hr_review', 'completed', 'locked'])
            ->latest('start_date')
            ->get();

        $kpis = collect();
        $scorecard = null;

        if ($this->selectedCycleId) {
            $kpis = EmployeeKpi::with(['component.category'])
                ->where('employee_id', $employee->id)
                ->where('performance_cycle_id', $this->selectedCycleId)
                ->orderBy('due_date')
                ->get();

            $scorecard = EmployeeScorecard::where('employee_id', $employee->id)
                ->where('performance_cycle_id', $this->selectedCycleId)
                ->first();
        }

        $overallProgress = $kpis->avg('progress_percent') ?? 0;

        return view('livewire.performance.my-kpis', [
            'cycles' => $cycles,
            'kpis' => $kpis,
            'scorecard' => $scorecard,
            'overallProgress' => round($overallProgress, 1),
        ])->layout('layouts.app', ['title' => 'My KPIs']);
    }
}
