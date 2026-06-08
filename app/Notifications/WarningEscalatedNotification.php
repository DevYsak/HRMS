<?php

namespace App\Notifications;

use App\Models\WarningLetter;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class WarningEscalatedNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly WarningLetter $warning) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->warning->employee->user->name;

        return [
            'type' => 'warning_escalated',
            'title' => 'Warning Escalated to '.$this->warning->warningTypeLabel(),
            'body' => "The warning for {$employee} has been escalated to {$this->warning->warningTypeLabel()} level: {$this->warning->reason}.",
            'action' => 'Review',
            'url' => '/performance/warnings/manage',
            'icon' => 'arrow-trending-up',
            'color' => 'red',
        ];
    }
}
