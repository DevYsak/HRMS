<?php

namespace App\Livewire\Onboarding;

use App\Models\Employee;
use App\Models\OnboardingTask;
use App\Services\Performance\TimelineService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class OnboardingChecklist extends Component
{
    use WithPagination;

    public int $employeeId;

    public string $phase = 'onboarding';

    public string $statusFilter = 'all';

    public bool $showTimeline = false;

    // Add task form
    public bool $showAddModal = false;

    public string $newTitle = '';

    public string $newCategory = 'general';

    public string $newDescription = '';

    public string $newDueDate = '';

    public string $newOwnerRole = 'hr';

    // Block task form
    public bool $showBlockModal = false;

    public ?int $blockingTaskId = null;

    public string $blockReason = '';

    public function mount(int $employee, string $phase = 'onboarding'): void
    {
        $this->employeeId = $employee;
        $this->phase = $phase;
    }

    public function toggleComplete(int $taskId, TimelineService $timelineService): void
    {
        $task = OnboardingTask::where('employee_id', $this->employeeId)->findOrFail($taskId);

        $nowCompleted = ! $task->is_completed;

        $task->update([
            'is_completed' => $nowCompleted,
            'completed_at' => $nowCompleted ? now() : null,
            'completed_by' => $nowCompleted ? Auth::id() : null,
            'status' => $nowCompleted ? 'completed' : 'pending',
        ]);

        $employee = Employee::findOrFail($this->employeeId);
        $timelineService->record(
            $employee,
            'task_update',
            'Task '.($nowCompleted ? 'Completed' : 'Reopened'),
            "Task '{$task->title}' was marked as ".($nowCompleted ? 'completed' : 'pending').'.',
            $task,
            Auth::user()
        );
    }

    public function updateStatus(int $taskId, string $status, TimelineService $timelineService): void
    {
        if (! in_array($status, ['pending', 'in_progress', 'completed', 'overdue', 'blocked'])) {
            return;
        }

        if ($status === 'blocked') {
            $this->blockingTaskId = $taskId;
            $this->blockReason = '';
            $this->showBlockModal = true;

            return;
        }

        $task = OnboardingTask::where('employee_id', $this->employeeId)->findOrFail($taskId);

        $isCompleted = $status === 'completed';

        $task->update([
            'status' => $status,
            'is_completed' => $isCompleted,
            'completed_at' => $isCompleted ? now() : null,
            'completed_by' => $isCompleted ? Auth::id() : null,
            'blocked_reason' => null,
        ]);

        $employee = Employee::findOrFail($this->employeeId);
        $timelineService->record(
            $employee,
            'task_update',
            'Task Status Updated',
            "Task '{$task->title}' status changed to {$status}.",
            $task,
            Auth::user()
        );
    }

    public function setBlocked(TimelineService $timelineService): void
    {
        $this->validate(['blockReason' => ['required', 'string', 'max:500']]);

        $task = OnboardingTask::where('employee_id', $this->employeeId)
            ->findOrFail($this->blockingTaskId);

        $task->update([
            'status' => 'blocked',
            'blocked_reason' => $this->blockReason,
        ]);

        $employee = Employee::findOrFail($this->employeeId);
        $timelineService->record(
            $employee,
            'task_update',
            'Task Blocked',
            "Task '{$task->title}' was blocked: {$this->blockReason}",
            $task,
            Auth::user()
        );

        $this->showBlockModal = false;
        $this->blockingTaskId = null;
        $this->blockReason = '';

        \Flux::toast('Task marked as blocked.');
    }

    public function addTask(): void
    {
        $this->validate([
            'newTitle' => 'required|max:255',
            'newCategory' => 'required|string',
            'newDueDate' => 'nullable|date',
        ]);

        $maxOrder = OnboardingTask::where('employee_id', $this->employeeId)
            ->where('phase', $this->phase)
            ->max('sort_order') ?? 0;

        OnboardingTask::create([
            'employee_id' => $this->employeeId,
            'phase' => $this->phase,
            'title' => $this->newTitle,
            'category' => $this->newCategory,
            'owner_role' => $this->newOwnerRole,
            'description' => $this->newDescription,
            'due_date' => $this->newDueDate ?: null,
            'sort_order' => $maxOrder + 1,
            'status' => 'pending',
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

        $query = OnboardingTask::where('employee_id', $this->employeeId)
            ->where('phase', $this->phase)
            ->orderBy('sort_order');

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        $tasks = $query->get();

        $allTasks = OnboardingTask::where('employee_id', $this->employeeId)
            ->where('phase', $this->phase)
            ->get();

        $total = $allTasks->count();
        $completed = $allTasks->where('is_completed', true)->count();
        $progress = $total > 0 ? round(($completed / $total) * 100) : 0;

        $timeline = OnboardingTask::where('employee_id', $this->employeeId)
            ->where('phase', $this->phase)
            ->where('is_completed', true)
            ->with('completedBy')
            ->orderBy('completed_at')
            ->get();

        return view('livewire.onboarding.onboarding-checklist', compact(
            'employee', 'tasks', 'total', 'completed', 'progress', 'allTasks', 'timeline'
        ))->layout('layouts.app', ['title' => ucfirst($this->phase).' Checklist']);
    }
}
