<?php

namespace App\Livewire\Payroll;

use App\Models\Employee;
use App\Models\Incentive;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Incentives extends Component
{
    // Form fields
    public int    $employeeId  = 0;
    public string $title       = '';
    public string $description = '';
    public float  $amount      = 0;
    public string $month       = '';

    // Filters
    public string $filterStatus = '';
    public string $filterMonth  = '';

    public function mount(): void
    {
        $this->month       = Carbon::now()->format('Y-m');
        $this->filterMonth = Carbon::now()->format('Y-m');
    }

    public function submit(): void
    {
        $this->validate([
            'employeeId'  => ['required', 'exists:employees,id'],
            'title'       => ['required', 'string', 'max:191'],
            'amount'      => ['required', 'numeric', 'min:1'],
            'month'       => ['required', 'date_format:Y-m'],
        ]);

        Incentive::create([
            'employee_id'  => $this->employeeId,
            'title'        => $this->title,
            'description'  => $this->description,
            'amount'       => $this->amount,
            'month'        => $this->month,
            'status'       => 'pending',
            'requested_by' => Auth::id(),
        ]);

        $this->reset(['employeeId', 'title', 'description', 'amount']);
        $this->dispatch('flux:modal:close', name: 'incentive-modal');
        \Flux::toast('Incentive request submitted for approval.');
    }

    public function approve(int $id): void
    {
        $incentive = Incentive::findOrFail($id);
        $incentive->update([
            'status'      => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => Carbon::now(),
        ]);
        \Flux::toast('Incentive approved.');
    }

    public function reject(int $id): void
    {
        $incentive = Incentive::findOrFail($id);
        $incentive->update([
            'status'      => 'rejected',
            'approved_by' => Auth::id(),
            'approved_at' => Carbon::now(),
        ]);
        \Flux::toast('Incentive rejected.', variant: 'danger');
    }

    public function render()
    {
        $incentives = Incentive::with(['employee.user', 'requestedBy', 'approvedBy'])
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterMonth,  fn ($q) => $q->where('month', $this->filterMonth))
            ->latest()
            ->paginate(20);

        $employees = Employee::with('user')->where('status', 'active')->get();

        return view('livewire.payroll.incentives', compact('incentives', 'employees'))
            ->layout('layouts.app', ['title' => 'Incentives']);
    }
}
