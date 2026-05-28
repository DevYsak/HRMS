<?php

namespace App\Notifications;

use App\Models\Employee;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class ProbationDueNotification extends Notification
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
            'type' => 'probation_due',
            'title' => 'Probation Reviews Due Soon',
            'body' => "{$count} employee(s) have probation reviews due within 10 days: {$names}.",
            'action' => 'View Employees',
            'url' => '/employees',
            'icon' => 'calendar',
            'color' => 'amber',
        ];
    }
}
