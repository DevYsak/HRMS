<?php

namespace App\Livewire\Onboarding;

use App\Models\Employee;
use App\Models\OnboardingTask;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class OnboardingManager extends Component
{
    use WithPagination;

    public string $search = '';

    public string $filter = 'active'; // active | all

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $employees = Employee::with(['user', 'department', 'onboardingTasks'])
            ->when($this->filter === 'active', fn ($q) => $q->where('status', 'active')
                ->where('joining_date', '>=', now()->subDays(90)))
            ->when($this->search, function ($q) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$this->search}%"));
            })
            ->orderByDesc('joining_date')
            ->paginate(15);

        // Attach task stats
        $employeeIds = $employees->pluck('id');
        $taskStats = OnboardingTask::whereIn('employee_id', $employeeIds)
            ->where('phase', 'onboarding')
            ->selectRaw('employee_id, count(*) as total, sum(is_completed) as completed')
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        return view('livewire.onboarding.onboarding-manager', [
            'employees' => $employees,
            'taskStats' => $taskStats,
        ])->layout('layouts.app', ['title' => 'Onboarding']);
    }
}
