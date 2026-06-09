<?php

namespace App\Notifications;

use App\Models\Employee;
use App\Models\OtRequest;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class NexflowOvertimeDetectedNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(
        public readonly OtRequest $otRequest,
        public readonly Employee $employee
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $date = $this->otRequest->work_date->format('M d, Y');
        $hours = $this->otRequest->requested_hours;
        $isAutoApproved = $this->otRequest->status === 'approved';

        return [
            'type' => 'nexflow_ot_detected',
            'title' => $isAutoApproved ? 'Nexflow OT Auto-Approved' : 'Nexflow OT Detected',
            'body' => $isAutoApproved
                ? "Your Nexflow overtime of {$hours}h on {$date} was automatically approved."
                : "Nexflow detected {$hours}h overtime on {$date}. Pending HR approval.",
            'action' => 'View',
            'url' => '/overtime/my',
            'icon' => 'clock',
            'color' => $isAutoApproved ? 'green' : 'indigo',
        ];
    }
}
