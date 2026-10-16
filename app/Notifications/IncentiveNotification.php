<?php

namespace App\Notifications;

use App\Models\Incentive;
use App\Notifications\Concerns\LinksForRecipient;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class IncentiveNotification extends Notification
{
    use LinksForRecipient;
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
        $url = $this->linkFor($notifiable, [['payroll.incentives'], ['payroll.payslips']]);

        return match ($status) {
            'approved' => [
                'type' => 'incentive',
                'title' => 'Incentive Approved',
                'body' => "Your incentive \"{$title}\" of \u{20B9}{$amount} has been approved.",
                'action' => 'View',
                'url' => $url,
                'icon' => 'currency-rupee',
                'color' => 'green',
            ],
            'rejected' => [
                'type' => 'incentive',
                'title' => 'Incentive Rejected',
                'body' => "Your incentive \"{$title}\" of \u{20B9}{$amount} was rejected.",
                'action' => 'View',
                'url' => $url,
                'icon' => 'x-circle',
                'color' => 'red',
            ],
            default => [
                'type' => 'incentive',
                'title' => 'New Incentive Pending',
                'body' => "Incentive \"{$title}\" of \u{20B9}{$amount} is awaiting approval.",
                'action' => 'Review',
                'url' => $url,
                'icon' => 'currency-rupee',
                'color' => 'blue',
            ],
        };
    }
}
