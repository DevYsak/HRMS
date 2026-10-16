<?php

namespace App\Notifications;

use App\Models\Employee;
use App\Models\OnboardingTask;
use App\Notifications\Concerns\LinksForRecipient;
use App\Notifications\Concerns\NotifiesByRole;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class OnboardingTaskOverdueNotification extends Notification
{
    use LinksForRecipient;
    use NotifiesByRole;
    use SendsMailChannel;

    public function __construct(
        public readonly OnboardingTask $task,
        public readonly Employee $employee,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        // The checklist for HR; the employee's own task list for them; any
        // other owner (manager, finance) lands where their access allows.
        $url = $this->isAbout($notifiable, $this->employee->user_id)
            ? $this->linkFor($notifiable, [['onboarding.my']])
            : $this->linkFor($notifiable, [['employees.onboarding', ['employee' => $this->employee->id]]]);

        return [
            'type' => 'onboarding_task_overdue',
            'title' => 'Onboarding Task Overdue',
            'body' => "The task \"{$this->task->title}\" for {$this->employee->user?->name} is overdue. Please action it.",
            'action' => 'View Checklist',
            'url' => $url,
            'icon' => 'exclamation-triangle',
            'color' => 'red',
        ];
    }
}
