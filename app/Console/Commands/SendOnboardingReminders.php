<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\OnboardingTask;
use App\Models\User;
use App\Notifications\OnboardingTaskOverdueNotification;
use App\Services\OnboardingService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SendOnboardingReminders extends Command
{
    protected $signature = 'hrms:send-onboarding-reminders';

    protected $description = 'Mark overdue onboarding tasks and notify owners. Send completion notifications.';

    public function handle(OnboardingService $service): int
    {
        $overdue = $service->markOverdue();
        $this->info("Marked {$overdue} task(s) as overdue.");

        $this->notifyOverdueTasks();
        $this->notifyCompletedEmployees($service);

        return self::SUCCESS;
    }

    private function notifyOverdueTasks(): void
    {
        $tasks = OnboardingTask::with(['employee.user', 'employee.department'])
            ->where('phase', 'onboarding')
            ->where('status', 'overdue')
            ->where('is_completed', false)
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('No overdue tasks to notify.');

            return;
        }

        $notified = 0;

        foreach ($tasks as $task) {
            $employee = $task->employee;

            if (! $employee) {
                continue;
            }

            $recipients = $this->resolveRecipients($task->owner_role, $employee);

            $notification = new OnboardingTaskOverdueNotification($task, $employee);

            foreach ($recipients as $user) {
                $user->notify($notification);
                $notified++;
            }
        }

        $this->info("Sent {$notified} overdue reminder(s).");
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveRecipients(?string $ownerRole, Employee $employee): Collection
    {
        return match ($ownerRole) {
            'hr', 'hr_admin' => User::whereIn('role', ['hr_admin', 'super_admin'])->get(),
            'manager' => User::where('id', $employee->manager_id)->get(),
            'it' => User::whereIn('role', ['hr_admin', 'super_admin'])->get(),
            'finance' => User::whereIn('role', ['hr_admin', 'super_admin'])->get(),
            'employee' => $employee->user ? collect([$employee->user]) : collect(),
            default => User::whereIn('role', ['hr_admin', 'super_admin'])->get(),
        };
    }

    private function notifyCompletedEmployees(OnboardingService $service): void
    {
        $employees = Employee::with('user')
            ->whereIn('status', ['onboarding', 'active', 'probation'])
            ->get()
            ->filter(function (Employee $employee): bool {
                return $employee->onboardingTasks()
                    ->where('phase', 'onboarding')
                    ->where('is_completed', false)
                    ->doesntExist()
                    && $employee->onboardingTasks()
                        ->where('phase', 'onboarding')
                        ->exists();
            })
            ->filter(function (Employee $employee): bool {
                // Skip if HR already received a completion notification for this employee.
                return ! \DB::table('notifications')
                    ->where('type', 'App\\Notifications\\OnboardingCompletedNotification')
                    ->whereJsonContains('data->body', $employee->user?->name)
                    ->exists();
            });

        foreach ($employees as $employee) {
            $service->checkAndNotifyCompletion($employee);
        }

        $this->info("Sent {$employees->count()} onboarding completion notification(s).");
    }
}
