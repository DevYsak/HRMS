<?php

namespace App\Notifications;

use App\Models\Employee;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class WelcomeOnboardingNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly Employee $employee) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $joining = $this->employee->joining_date?->format('d M Y') ?? 'today';

        return [
            'type' => 'welcome_onboarding',
            'title' => 'Welcome to the Team!',
            'body' => "Welcome aboard! Your account is ready. Your joining date is {$joining}. Please complete your profile and review your onboarding tasks.",
            'action' => 'Get Started',
            'url' => route('dashboard', absolute: false),
            'icon' => 'hand-raised',
            'color' => 'green',
        ];
    }
}
