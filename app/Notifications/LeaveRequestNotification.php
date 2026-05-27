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

        // For manager/HR (pending) we open the Team page and pass a query param
        // that the Livewire component will use to open the review modal.
        $teamUrl = route('time-off.team') . '?selectedRequestId=' . $this->leaveRequest->id;
        // For employees we anchor to the specific row in My Time Off.
        $myUrl = route('time-off.my') . '#request-' . $this->leaveRequest->id;

        return match ($status) {
            'pending' => [
                'type' => 'leave_request',
                'title' => 'New Leave Request',
                'body' => "{$employee} requested {$days} day(s) of {$type}.",
                'action' => 'Review',
                'url' => $teamUrl,
                'icon' => 'calendar',
                'color' => 'blue',
            ],
            'approved' => [
                'type' => 'leave_request',
                'title' => 'Leave Approved ✓',
                'body' => "Your {$type} request for {$days} day(s) has been approved.",
                'action' => 'View',
                'url' => $myUrl,
                'icon' => 'check-circle',
                'color' => 'green',
            ],
            'cancelled' => [
                'type' => 'leave_request',
                'title' => 'Leave Cancelled',
                'body' => "Your {$type} request for {$days} day(s) has been cancelled.",
                'action' => 'View',
                'url' => $myUrl,
                'icon' => 'x-circle',
                'color' => 'orange',
            ],
            default => [
                'type' => 'leave_request',
                'title' => 'Leave Rejected',
                'body' => "Your {$type} request for {$days} day(s) was rejected.",
                'action' => 'View',
                'url' => $myUrl,
                'icon' => 'x-circle',
                'color' => 'red',
            ],
        };
    }
}
