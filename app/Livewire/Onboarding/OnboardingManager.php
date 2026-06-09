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

    public string $activeTab = 'employees'; // employees | analytics

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

        $employeeIds = $employees->pluck('id');
        $taskStats = OnboardingTask::whereIn('employee_id', $employeeIds)
            ->where('phase', 'onboarding')
            ->selectRaw('employee_id, count(*) as total, sum(is_completed) as completed')
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        $analytics = null;
        $ownerBreakdown = null;

        if ($this->activeTab === 'analytics') {
            $analytics = OnboardingTask::where('phase', 'onboarding')
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(is_completed) as completed,
                    SUM(CASE WHEN is_completed = 0 AND (due_date IS NULL OR due_date >= CURDATE()) AND status != "blocked" THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN is_completed = 0 AND due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    SUM(CASE WHEN status = "blocked" THEN 1 ELSE 0 END) as blocked,
                    SUM(CASE WHEN status = "in_progress" THEN 1 ELSE 0 END) as in_progress
                ')
                ->first();

            $ownerBreakdown = OnboardingTask::where('phase', 'onboarding')
                ->selectRaw('owner_role, COUNT(*) as total, SUM(is_completed) as completed')
                ->groupBy('owner_role')
                ->orderBy('owner_role')
                ->get();
        }

        return view('livewire.onboarding.onboarding-manager', [
            'employees' => $employees,
            'taskStats' => $taskStats,
            'analytics' => $analytics,
            'ownerBreakdown' => $ownerBreakdown,
        ])->layout('layouts.app', ['title' => 'Onboarding']);
    }
}
