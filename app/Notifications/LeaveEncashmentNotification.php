<?php

namespace App\Notifications;

use App\Models\LeaveEncashment;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class LeaveEncashmentNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly LeaveEncashment $encashment, public readonly string $action = 'submitted') {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
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
                'url' => '/time-off/encashments',
                'icon' => 'banknotes',
                'color' => 'blue',
            ],
            'pending_finance' => [
                'type' => 'leave_encashment',
                'title' => 'Encashment Awaiting Finance Approval',
                'body' => "{$employee}'s request to encash {$days} days of {$type} has been approved by HR and requires your finance approval.",
                'action' => 'Review',
                'url' => '/time-off/encashments',
                'icon' => 'banknotes',
                'color' => 'blue',
            ],
            'pending_finance_employee' => [
                'type' => 'leave_encashment',
                'title' => 'Encashment Forwarded to Finance',
                'body' => "Your request to encash {$days} days of {$type} has been approved by HR and is now awaiting finance approval.",
                'action' => 'View',
                'url' => '/time-off/my',
                'icon' => 'clock',
                'color' => 'amber',
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
            'rejected' => [
                'type' => 'leave_encashment',
                'title' => 'Encashment Rejected',
                'body' => "Your request to encash {$days} days of {$type} has been rejected.",
                'action' => 'View',
                'url' => '/time-off/my',
                'icon' => 'x-circle',
                'color' => 'red',
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
