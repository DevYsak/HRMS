<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

/**
 * Tells an employee HR changed their leave balance (add, deduct, correct).
 * The public reason only — HR's internal note is never included.
 */
class LeaveBalanceChangedNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(
        public readonly string $leaveType,
        public readonly string $action,
        public readonly float $days,
        public readonly float $newAvailable,
        public readonly string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $verb = match ($this->action) {
            'add' => 'added '.$this->days.' day(s) to',
            'deduct' => 'deducted '.$this->days.' day(s) from',
            default => 'corrected',
        };

        return [
            'type' => 'leave_balance_changed',
            'title' => 'Your leave balance was updated',
            'body' => "HR {$verb} your {$this->leaveType} balance. Available now: {$this->newAvailable} day(s). Reason: {$this->reason}",
            'action' => 'View My Time Off',
            'url' => route('time-off.my'),
            'icon' => 'adjustments-horizontal',
            'color' => 'blue',
        ];
    }
}
