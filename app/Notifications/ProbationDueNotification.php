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
    /**
     * @param  Collection<int, Employee>  $employees
     * @param  string  $mode  'due' (ending within 10 days) or 'overdue' (ended, not confirmed)
     */
    public function __construct(public readonly Collection $employees, public readonly string $mode = 'due') {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $count = $this->employees->count();
        $names = $this->employees->map(fn ($e) => $e->user->name)->implode(', ');

        $overdue = $this->mode === 'overdue';

        return [
            'type' => $overdue ? 'probation_overdue' : 'probation_due',
            'title' => $overdue ? 'Probation Reviews Overdue' : 'Probation Reviews Due Soon',
            'body' => $overdue
                ? "{$count} employee(s) are past their probation end date and not yet confirmed: {$names}."
                : "{$count} employee(s) have probation reviews due within 10 days: {$names}.",
            'action' => 'View Employees',
            'url' => '/employees',
            'icon' => 'calendar',
            'color' => 'amber',
            'priority' => 'high',
        ];
    }
}
