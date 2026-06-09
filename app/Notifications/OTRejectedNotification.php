<?php

namespace App\Notifications;

use App\Models\OtRequest;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class OTRejectedNotification extends Notification
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
        $reason = $this->otRequest->reviewer_comment;

        return [
            'type' => 'ot_rejected',
            'title' => 'OT Request Rejected',
            'body' => "Your OT request of {$hours}h on {$date} was rejected."
                .($reason ? " Reason: {$reason}" : ''),
            'action' => 'View',
            'url' => '/overtime/my',
            'icon' => 'x-circle',
            'color' => 'red',
        ];
    }
}
