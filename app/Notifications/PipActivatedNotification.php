<?php

namespace App\Notifications;

use App\Notifications\Concerns\FormatsForRecipient;
use App\Models\PipRecord;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class PipActivatedNotification extends Notification
{
    use FormatsForRecipient;
    use SendsMailChannel;

    public function __construct(public readonly PipRecord $pip) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'pip_activated',
            'title' => 'Performance Improvement Plan Activated',
            'body' => "Your performance improvement plan is now active and runs until {$this->pip->end_date->format($this->recipientDateFormat($notifiable))}. Please review your goals and action plan.",
            'action' => 'View Plan',
            'url' => '/performance/pip/my',
            'icon' => 'play-circle',
            'color' => 'blue',
        ];
    }
}
