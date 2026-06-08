<?php

namespace App\Notifications;

use App\Models\WarningLetter;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class WarningIssuedNotification extends Notification
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
            'type' => 'warning_issued',
            'title' => $this->warning->warningTypeLabel().' Issued',
            'body' => "You have received a {$this->warning->warningTypeLabel()} regarding: {$this->warning->reason}.",
            'action' => 'Review',
            'url' => '/performance/my-warnings',
            'icon' => 'exclamation-triangle',
            'color' => 'red',
        ];
    }
}
