<?php

namespace App\Notifications;

use App\Models\OtRequest;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class OTApprovedNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly OtRequest $otRequest) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $date = $this->otRequest->work_date->format('M d, Y');
        $hours = $this->otRequest->requested_hours;

        return [
            'type' => 'ot_approved',
            'title' => 'OT Request Approved',
            'body' => "Your OT request of {$hours}h on {$date} has been approved.",
            'action' => 'View',
            'url' => '/overtime/my',
            'icon' => 'check-circle',
            'color' => 'green',
        ];
    }
}
