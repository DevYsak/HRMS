<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class ProbationDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  \Illuminate\Support\Collection<int, \App\Models\Employee>  $employees */
    public function __construct(public readonly Collection $employees) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $count = $this->employees->count();
        $names = $this->employees->map(fn ($e) => $e->user->name)->implode(', ');

        return [
            'type'   => 'probation_due',
            'title'  => 'Probation Reviews Due Soon',
            'body'   => "{$count} employee(s) have probation reviews due within 10 days: {$names}.",
            'action' => 'View Employees',
            'url'    => '/employees',
            'icon'   => 'calendar',
            'color'  => 'amber',
        ];
    }
}
