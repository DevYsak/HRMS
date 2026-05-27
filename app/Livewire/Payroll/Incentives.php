<?php

namespace App\Livewire\Payroll;

use App\Models\Employee;
use App\Models\Incentive;
use App\Services\IncentiveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Incentives extends Component
{
    // Modal
    public bool $showModal = false;

    // Form fields
    public int $employeeId = 0;

    public string $title = '';

    public string $description = '';

    public float $amount = 0;

    public string $month = '';

    // Filters
    public string $filterStatus = '';

    public string $filterMonth = '';

    public function mount(): void
    {
        $this->month = Carbon::now()->format('Y-m');
        $this->filterMonth = Carbon::now()->format('Y-m');
    }

    public function openModal(): void
    {
        $this->reset(['employeeId', 'title', 'description', 'amount']);
        $this->resetErrorBag();
        $this->month = Carbon::now()->format('Y-m');
        $this->showModal = true;
    }

    public function submit(IncentiveService $incentiveService): void
    {
        $this->validate([
            'employeeId' => ['required', 'exists:employees,id'],
            'title' => ['required', 'string', 'max:191'],
            'amount' => ['required', 'numeric', 'min:1'],
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $employee = Employee::findOrFail($this->employeeId);
        $incentiveService->submit($employee, [
            'title' => $this->title,
            'description' => $this->description,
            'amount' => $this->amount,
            'month' => $this->month,
        ], Auth::id());

        $this->showModal = false;
        $this->reset(['employeeId', 'title', 'description', 'amount']);
        \Flux::toast('Incentive submitted for approval.');
    }

    public function approve(int $id, IncentiveService $incentiveService): void
    {
        $incentive = Incentive::findOrFail($id);
        $incentiveService->approve($incentive, Auth::id());
        \Flux::toast('Incentive approved.');
    }

    public function reject(int $id, IncentiveService $incentiveService): void
    {
        $incentive = Incentive::findOrFail($id);
        $incentiveService->reject($incentive, Auth::id());
        \Flux::toast('Incentive rejected.', variant: 'danger');
    }

    public function render()
    {
        $incentives = Incentive::with(['employee.user', 'requestedBy', 'approvedBy'])
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterMonth, fn ($q) => $q->where('month', $this->filterMonth))
            ->latest()
            ->paginate(20);

        $employees = Employee::with('user')->where('status', 'active')->get();

        return view('livewire.payroll.incentives', compact('incentives', 'employees'))
            ->layout('layouts.app', ['title' => 'Incentives']);
    }
}
