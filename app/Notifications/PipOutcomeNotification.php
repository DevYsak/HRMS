<?php

namespace App\Notifications;

use App\Models\PipRecord;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class PipOutcomeNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly PipRecord $pip) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->pip->employee->user->name;
        $outcome = ucfirst((string) $this->pip->outcome);

        return match ($this->pip->outcome) {
            'successful' => [
                'type' => 'pip_outcome',
                'title' => 'Performance Improvement Plan Completed Successfully',
                'body' => "{$employee}'s performance improvement plan has concluded with a successful outcome.",
                'action' => 'View Outcome',
                'url' => '/performance/pip/my',
                'icon' => 'check-circle',
                'color' => 'green',
            ],
            'failed' => [
                'type' => 'pip_outcome',
                'title' => 'Performance Improvement Plan Outcome: Failed',
                'body' => "{$employee}'s performance improvement plan has concluded without the required improvement.",
                'action' => 'View Outcome',
                'url' => '/performance/pip/my',
                'icon' => 'x-circle',
                'color' => 'red',
            ],
            default => [
                'type' => 'pip_outcome',
                'title' => 'Performance Improvement Plan Outcome: '.$outcome,
                'body' => "{$employee}'s performance improvement plan outcome has been recorded as {$outcome}.",
                'action' => 'View Outcome',
                'url' => '/performance/pip/my',
                'icon' => 'flag',
                'color' => 'amber',
            ],
        };
    }
}
