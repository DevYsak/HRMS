<?php

namespace App\Notifications;

use App\Models\WarningLetter;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class WarningAcknowledgementReminderNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly WarningLetter $warning) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'warning_acknowledgement_reminder',
            'title' => 'Acknowledgement Pending',
            'body' => "Please acknowledge your {$this->warning->warningTypeLabel()} issued on {$this->warning->issue_date->format('d M Y')}.",
            'action' => 'Acknowledge',
            'url' => '/performance/my-warnings',
            'icon' => 'bell-alert',
            'color' => 'amber',
        ];
    }
}
