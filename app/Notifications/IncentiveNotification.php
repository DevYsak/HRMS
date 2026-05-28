<?php

namespace App\Notifications;

use App\Models\Incentive;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class IncentiveNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly Incentive $incentive) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $title = $this->incentive->title;
        $amount = number_format((float) $this->incentive->amount, 2);
        $status = $this->incentive->status;

        return match ($status) {
            'approved' => [
                'type' => 'incentive',
                'title' => 'Incentive Approved',
                'body' => "Your incentive \"{$title}\" of \u{20B9}{$amount} has been approved.",
                'action' => 'View',
                'url' => '/payroll/incentives',
                'icon' => 'currency-rupee',
                'color' => 'green',
            ],
            'rejected' => [
                'type' => 'incentive',
                'title' => 'Incentive Rejected',
                'body' => "Your incentive \"{$title}\" of \u{20B9}{$amount} was rejected.",
                'action' => 'View',
                'url' => '/payroll/incentives',
                'icon' => 'x-circle',
                'color' => 'red',
            ],
            default => [
                'type' => 'incentive',
                'title' => 'New Incentive Pending',
                'body' => "Incentive \"{$title}\" of \u{20B9}{$amount} is awaiting approval.",
                'action' => 'Review',
                'url' => '/payroll/incentives',
                'icon' => 'currency-rupee',
                'color' => 'blue',
            ],
        };
    }
}
