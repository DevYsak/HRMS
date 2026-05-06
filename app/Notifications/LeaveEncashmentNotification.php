<?php

namespace App\Notifications;

use App\Models\LeaveEncashment;
use Illuminate\Notifications\Notification;

class LeaveEncashmentNotification extends Notification
{
    public function __construct(public readonly LeaveEncashment $encashment, public readonly string $action = 'submitted') {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->encashment->employee->user->name;
        $days = $this->encashment->requested_days;
        $type = $this->encashment->leaveType->name;

        return match ($this->action) {
            'submitted' => [
                'type' => 'leave_encashment',
                'title' => 'New Encashment Request',
                'body' => "{$employee} has requested to encash {$days} days of {$type}.",
                'action' => 'Review',
                'url' => '/payroll/finance-approve',
                'icon' => 'banknotes',
                'color' => 'blue',
            ],
            'approved' => [
                'type' => 'leave_encashment',
                'title' => 'Encashment Approved',
                'body' => "Your request to encash {$days} days of {$type} has been approved and will be included in the next payroll.",
                'action' => 'View',
                'url' => '/time-off/my',
                'icon' => 'check-circle',
                'color' => 'green',
            ],
            default => [
                'type' => 'leave_encashment',
                'title' => 'Encashment Updated',
                'body' => "Your encashment request for {$type} has been updated.",
                'action' => 'View',
                'url' => '/time-off/my',
                'icon' => 'information-circle',
                'color' => 'zinc',
            ],
        };
    }
}
