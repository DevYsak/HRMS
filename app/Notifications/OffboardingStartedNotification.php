<?php

namespace App\Notifications;

use App\Models\ExitRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OffboardingStartedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ExitRecord $exitRecord) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Offboarding Process Initiated')
            ->line('Your offboarding process has been initiated.')
            ->line('Your last working day is scheduled for: '.$this->exitRecord->last_working_day->format('M d, Y'))
            ->action('View Offboarding Checklist', url('/attendance/my')) // Or a dedicated page if exists
            ->line('Please ensure all offboarding tasks and clearances are completed.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'offboarding_started',
            'exit_record_id' => $this->exitRecord->id,
            'message' => 'Your offboarding process has started.',
        ];
    }
}
