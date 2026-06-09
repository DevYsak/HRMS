<?php

namespace App\Notifications;

use App\Models\Employee;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class OnboardingCompletedNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly Employee $employee) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $name = $this->employee->user?->name ?? 'The employee';
        $url = route('employees.onboarding', ['employee' => $this->employee->id]);

        return [
            'type' => 'onboarding_completed',
            'title' => 'Onboarding Complete',
            'body' => "{$name} has completed all onboarding tasks.",
            'action' => 'View Checklist',
            'url' => $url,
            'icon' => 'check-badge',
            'color' => 'green',
        ];
    }
}
