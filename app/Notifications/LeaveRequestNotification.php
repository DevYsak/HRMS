<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Notifications\Notification;

class LeaveRequestNotification extends Notification
{
    public function __construct(public readonly LeaveRequest $leaveRequest) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->leaveRequest->employee->user->name;
        $status = $this->leaveRequest->status;
        $type = $this->leaveRequest->leaveType->name;
        $days = $this->leaveRequest->days;

        return match ($status) {
            'pending' => [
                'type' => 'leave_request',
                'title' => 'New Leave Request',
                'body' => "{$employee} requested {$days} day(s) of {$type}.",
                'action' => 'Review',
                'url' => '/time-off/team',
                'icon' => 'calendar',
                'color' => 'blue',
            ],
            'approved' => [
                'type' => 'leave_request',
                'title' => 'Leave Approved ✓',
                'body' => "Your {$type} request for {$days} day(s) has been approved.",
                'action' => 'View',
                'url' => '/time-off/my',
                'icon' => 'check-circle',
                'color' => 'green',
            ],
            'cancelled' => [
                'type' => 'leave_request',
                'title' => 'Leave Cancelled',
                'body' => "Your {$type} request for {$days} day(s) has been cancelled.",
                'action' => 'View',
                'url' => '/time-off/my',
                'icon' => 'x-circle',
                'color' => 'orange',
            ],
            default => [
                'type' => 'leave_request',
                'title' => 'Leave Rejected',
                'body' => "Your {$type} request for {$days} day(s) was rejected.",
                'action' => 'View',
                'url' => '/time-off/my',
                'icon' => 'x-circle',
                'color' => 'red',
            ],
        };
    }
}
