<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class LeavePaymentStatusChangedNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(
        public readonly LeaveRequest $leaveRequest,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly User $changedBy,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $type = $this->leaveRequest->leaveType?->name ?? 'Leave';
        $days = $this->leaveRequest->days;
        $from = ucfirst($this->fromStatus);
        $to = ucfirst($this->toStatus);
        $changer = $this->changedBy->name;
        $url = route('time-off.my').'#request-'.$this->leaveRequest->id;

        return [
            'type' => 'leave_payment_changed',
            'title' => 'Leave Payment Status Updated',
            'body' => "Your {$type} ({$days} day(s)) was changed from {$from} to {$to} by {$changer}.",
            'action' => 'View',
            'url' => $url,
            'icon' => 'currency-rupee',
            'color' => $this->toStatus === 'paid' ? 'green' : 'orange',
            'hr_remark' => $this->leaveRequest->hr_remark,
        ];
    }
}
