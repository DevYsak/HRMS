<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class LeaveDynamicApproverNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(
        public readonly LeaveRequest $leaveRequest,
        public readonly int $level,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->leaveRequest->employee->user->name;
        $type = $this->leaveRequest->leaveType?->name ?? 'Leave';
        $days = $this->leaveRequest->days;
        // Straight to the request (the employees list never read selectedRequestId).
        $url = route('time-off.leave-requests.show', $this->leaveRequest);

        return [
            'type' => 'leave_approval_required',
            'title' => "Leave Approval Required (Level {$this->level})",
            'body' => "{$employee} has requested {$days} day(s) of {$type} and is awaiting your approval.",
            'action' => 'Review',
            'url' => $url,
            'icon' => 'inbox',
            'color' => 'blue',
        ];
    }
}
