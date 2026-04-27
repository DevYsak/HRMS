<?php

namespace App\Notifications;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ProbationExtendedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Employee $employee) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $name = $this->employee->user->name;
        $newDate = $this->employee->probation_end_date?->format('M d, Y') ?? 'a new date';

        return [
            'type' => 'probation_extended',
            'title' => 'Probation Period Extended',
            'body' => "The probation period for {$name} has been extended until {$newDate}.",
            'action' => 'View Employee',
            'url' => "/employees/{$this->employee->id}/edit",
            'icon' => 'calendar',
            'color' => 'amber',
        ];
    }
}
