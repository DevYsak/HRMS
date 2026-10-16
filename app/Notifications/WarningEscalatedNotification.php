<?php

namespace App\Notifications;

use App\Models\WarningLetter;
use App\Notifications\Concerns\LinksForRecipient;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class WarningEscalatedNotification extends Notification
{
    use LinksForRecipient;
    use SendsMailChannel;

    public function __construct(public readonly WarningLetter $warning) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->warning->employee->user->name;
        $own = $this->isAbout($notifiable, $this->warning->employee->user_id);

        return [
            'type' => 'warning_escalated',
            'title' => 'Warning Escalated to '.$this->warning->warningTypeLabel(),
            'body' => $own
                ? "Your warning has been escalated to {$this->warning->warningTypeLabel()} level: {$this->warning->reason}."
                : "The warning for {$employee} has been escalated to {$this->warning->warningTypeLabel()} level: {$this->warning->reason}.",
            'action' => $own ? 'View' : 'Review',
            'url' => $own ? $this->linkFor($notifiable, [['performance.my-warnings']]) : $this->linkFor($notifiable, [['performance.warnings.manage'], ['performance.my-warnings']]),
            'icon' => 'arrow-trending-up',
            'color' => 'red',
        ];
    }
}
