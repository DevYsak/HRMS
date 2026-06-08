<?php

namespace App\Notifications;

use App\Models\PipRecord;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class PipCreatedNotification extends Notification
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

        return [
            'type' => 'pip_created',
            'title' => 'Performance Improvement Plan Drafted',
            'body' => "A performance improvement plan has been drafted for {$employee} ({$this->pip->start_date->format('d M Y')} – {$this->pip->end_date->format('d M Y')}).",
            'action' => 'Review',
            'url' => '/performance/pip/manage',
            'icon' => 'document-text',
            'color' => 'amber',
        ];
    }
}
