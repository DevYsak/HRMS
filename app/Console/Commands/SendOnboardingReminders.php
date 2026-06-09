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
            ->whereIn('status', ['onboarding', 'active', 'probation', 'notice_period'])
            ->get();

        $onboardingCount = 0;
        $offboardingCount = 0;

        foreach ($employees as $employee) {
            if ($employee->onboardingTasks()->where('phase', 'onboarding')->exists() &&
                $employee->onboardingTasks()->where('phase', 'onboarding')->where('is_completed', false)->doesntExist()) {

                $notified = \DB::table('notifications')
                    ->where('type', 'App\\Notifications\\OnboardingCompletedNotification')
                    ->whereJsonContains('data->body', $employee->user?->name)
                    ->exists();

                if (! $notified) {
                    $service->checkAndNotifyCompletion($employee);
                    $onboardingCount++;
                }
            }

            if ($employee->onboardingTasks()->where('phase', 'offboarding')->exists() &&
                $employee->onboardingTasks()->where('phase', 'offboarding')->where('is_completed', false)->doesntExist()) {

                $notifiedOffboarding = \DB::table('notifications')
                    ->where('type', 'App\\Notifications\\OffboardingCompletedNotification')
                    ->whereJsonContains('data->body', $employee->user?->name)
                    ->exists();

                if (! $notifiedOffboarding) {
                    $service->checkAndNotifyOffboardingCompletion($employee);
                    $offboardingCount++;
                }
            }
        }

        $this->info("Sent {$onboardingCount} onboarding completion notification(s).");
        $this->info("Sent {$offboardingCount} offboarding completion notification(s).");
    }
}
