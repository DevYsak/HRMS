<?php

namespace App\Notifications;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OffboardingCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Employee $employee) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Offboarding Completed')
            ->line('The offboarding process for '.($this->employee->user->name ?? 'the employee').' has been completed.')
            ->action('View Details', url('/employees/'.$this->employee->id))
            ->line('All tasks have been marked as complete.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'offboarding_completed',
            'employee_id' => $this->employee->id,
            'message' => 'Offboarding completed for '.($this->employee->user->name ?? 'employee'),
        ];
    }
}
