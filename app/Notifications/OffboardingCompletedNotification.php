<?php

namespace App\Notifications;

use App\Models\Employee;
use App\Notifications\Concerns\NotifiesByRole;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OffboardingCompletedNotification extends Notification implements ShouldQueue
{
    use NotifiesByRole;
    use Queueable;
    use SendsMailChannel;

    public function __construct(public Employee $employee) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        $name = $this->employee->user->name ?? 'the employee';

        return [
            // title/body/action/url feed SendsMailChannel, which stamps the
            // notification key and applies any per-role template. This used
            // to build its own MailMessage, so it reached the transport with
            // no key and silently ignored the templates the settings screen
            // offered for it.
            'title' => 'Offboarding Completed',
            'body' => $this->role === 'employee'
                ? 'Your offboarding process has been completed. All tasks have been marked as complete.'
                : "The offboarding process for {$name} has been completed. All tasks have been marked as complete.",
            'action' => 'View Details',
            'url' => '/employees/'.$this->employee->id,
            'type' => 'offboarding_completed',
            'employee_id' => $this->employee->id,
            'message' => 'Offboarding completed for '.($this->employee->user->name ?? 'employee'),
        ];
    }
}
