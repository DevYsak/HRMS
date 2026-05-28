<?php

namespace App\Notifications;

use App\Models\Employee;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class NewHireCheckInNotification extends Notification
{
    use SendsMailChannel;

    /** @param  Collection<int, Employee>  $employees */
    public function __construct(public readonly Collection $employees) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $count = $this->employees->count();
        $names = $this->employees->map(fn ($e) => $e->user->name)->implode(', ');

        return [
            'type' => 'newhire_checkin',
            'title' => '30-Day New Hire Milestone',
            'body' => "{$count} employee(s) have reached their 30-day milestone and are due for an HR check-in: {$names}.",
            'action' => 'View Employees',
            'url' => '/employees',
            'icon' => 'user-check',
            'color' => 'green',
        ];
    }
}
