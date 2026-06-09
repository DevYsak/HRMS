<?php

namespace App\Notifications;

use App\Models\OtRequest;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class OTApprovalRequiredNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly OtRequest $otRequest) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->otRequest->employee?->user?->name ?? 'Employee';
        $date = $this->otRequest->work_date->format('M d, Y');
        $hours = $this->otRequest->requested_hours;

        return [
            'type' => 'ot_approval_required',
            'title' => 'OT Approval Required',
            'body' => "{$employee} has {$hours}h of Nexflow OT on {$date} awaiting your approval.",
            'action' => 'Review',
            'url' => '/overtime/manage',
            'icon' => 'clock',
            'color' => 'amber',
        ];
    }
}
