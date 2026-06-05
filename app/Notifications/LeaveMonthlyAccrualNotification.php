<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsMailChannel;
use Carbon\Carbon;
use Illuminate\Notifications\Notification;

class LeaveMonthlyAccrualNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(
        public readonly int $count,
        public readonly int $year,
        public readonly int $month,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $monthName = Carbon::createFromDate($this->year, $this->month, 1)->format('F Y');

        return [
            'type' => 'leave_accrual_summary',
            'title' => 'Monthly Leave Accrual Completed',
            'body' => "Leave accrual for {$monthName} completed — {$this->count} credit(s) processed.",
            'action' => 'View Settings',
            'url' => route('time-off.settings'),
            'icon' => 'chart-bar',
            'color' => 'green',
        ];
    }
}
