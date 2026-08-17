<?php

namespace App\Livewire\Onboarding;

use App\Models\OnboardingTask;
use App\Services\OnboardingService;
use App\Services\Performance\TimelineService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * An employee's own onboarding checklist.
 *
 * Tasks were created for every new hire — including tasks owned by the
 * employee themselves — but the only screens that showed them sat behind
 * `role:manage-employees`. New joiners were assigned work they had no way to
 * see, let alone complete.
 *
 * This is a read-and-tick view over the same OnboardingService the HR screens
 * use; it is not a second onboarding system. Employees see only their own
 * tasks, and can only complete the ones they own — an IT or Finance task stays
 * visible for context but is not theirs to tick.
 */
#[Title('My Onboarding')]
class MyOnboarding extends Component
{
    /** Roles whose tasks the employee may complete themselves. */
    private const EMPLOYEE_OWNED = ['employee'];

    public function toggleComplete(int $taskId, TimelineService $timelineService): void
    {
        $employee = Auth::user()?->employee;

        if (! $employee) {
            return;
        }

        $task = OnboardingTask::where('employee_id', $employee->id)->find($taskId);

        // Someone else's task, or somebody else's checklist entirely.
        if (! $task || ! in_array($task->owner_role, self::EMPLOYEE_OWNED, true)) {
            \Flux::toast('That task is handled by another team.', variant: 'danger');

            return;
        }

        $nowCompleted = ! $task->is_completed;

        $task->update([
            'is_completed' => $nowCompleted,
            'status' => $nowCompleted ? 'completed' : 'pending',
            'completed_at' => $nowCompleted ? now() : null,
            'completed_by' => $nowCompleted ? Auth::id() : null,
        ]);

        // Same timeline entry shape the HR checklist writes, so both routes to
        // completing a task read identically in the employee's history.
        $timelineService->record(
            $employee,
            'task_update',
            'Task '.($nowCompleted ? 'Completed' : 'Reopened'),
            "Task '{$task->title}' was marked as ".($nowCompleted ? 'completed' : 'pending').'.',
            $task,
            Auth::user(),
        );

        app(OnboardingService::class)->checkAndNotifyCompletion($employee);
    }

    /** @return Collection<int, OnboardingTask> */
    private function tasks(): Collection
    {
        $employee = Auth::user()?->employee;

        if (! $employee) {
            return collect();
        }

        return OnboardingTask::where('employee_id', $employee->id)
            ->onboarding()
            ->orderBy('sort_order')
            ->orderBy('due_date')
            ->get();
    }

    public function render()
    {
        $tasks = $this->tasks();

        return view('livewire.onboarding.my-onboarding', [
            'employee' => Auth::user()?->employee,
            'mine' => $tasks->filter(fn (OnboardingTask $t) => in_array($t->owner_role, self::EMPLOYEE_OWNED, true))->values(),
            'others' => $tasks->reject(fn (OnboardingTask $t) => in_array($t->owner_role, self::EMPLOYEE_OWNED, true))->values(),
            'completed' => $tasks->where('is_completed', true)->count(),
            'total' => $tasks->count(),
        ]);
    }
}
