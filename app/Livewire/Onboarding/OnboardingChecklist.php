<?php

namespace App\Livewire\Onboarding;

use App\Models\Employee;
use App\Models\OnboardingTask;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class OnboardingChecklist extends Component
{
    use WithPagination;

    public int $employeeId;
    public string $phase = 'onboarding'; // 'onboarding' | 'offboarding'

    // Add task form
    public bool $showAddModal = false;
    public string $newTitle       = '';
    public string $newCategory    = 'general';
    public string $newDescription = '';
    public string $newDueDate     = '';
    public string $newOwnerRole   = 'hr';

    public function mount(int $employeeId, string $phase = 'onboarding'): void
    {
        $this->employeeId = $employeeId;
        $this->phase      = $phase;
    }

    public function toggleComplete(int $taskId): void
    {
        $task = OnboardingTask::where('employee_id', $this->employeeId)->findOrFail($taskId);

        $task->update([
            'is_completed' => ! $task->is_completed,
            'completed_at' => $task->is_completed ? null : now(),
            'completed_by' => $task->is_completed ? null : Auth::id(),
        ]);
    }

    public function addTask(): void
    {
        $this->validate([
            'newTitle'    => 'required|max:255',
            'newCategory' => 'required|string',
            'newDueDate'  => 'nullable|date',
        ]);

        $maxOrder = OnboardingTask::where('employee_id', $this->employeeId)
            ->where('phase', $this->phase)
            ->max('sort_order') ?? 0;

        OnboardingTask::create([
            'employee_id'  => $this->employeeId,
            'phase'        => $this->phase,
            'title'        => $this->newTitle,
            'category'     => $this->newCategory,
            'owner_role'   => $this->newOwnerRole,
            'description'  => $this->newDescription,
            'due_date'     => $this->newDueDate ?: null,
            'sort_order'   => $maxOrder + 1,
        ]);

        $this->reset(['newTitle', 'newCategory', 'newOwnerRole', 'newDescription', 'newDueDate']);
        $this->showAddModal = false;
        \Flux::toast('Task added.');
    }

    public function deleteTask(int $taskId): void
    {
        OnboardingTask::where('employee_id', $this->employeeId)->findOrFail($taskId)->delete();
        \Flux::toast('Task removed.');
    }

    public function render()
    {
        $employee = Employee::with('user')->findOrFail($this->employeeId);
        $tasks    = OnboardingTask::where('employee_id', $this->employeeId)
            ->where('phase', $this->phase)
            ->orderBy('sort_order')
            ->get();

        $total     = $tasks->count();
        $completed = $tasks->where('is_completed', true)->count();
        $progress  = $total > 0 ? round(($completed / $total) * 100) : 0;

        return view('livewire.onboarding.onboarding-checklist', compact('employee', 'tasks', 'total', 'completed', 'progress'))
            ->layout('layouts.app', ['title' => ucfirst($this->phase) . ' Checklist']);
    }
}
