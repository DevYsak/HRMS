<?php

namespace App\Notifications;

use App\Models\Reimbursement;
use App\Notifications\Concerns\LinksForRecipient;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class ReimbursementNotification extends Notification
{
    use LinksForRecipient;
    use SendsMailChannel;

    public function __construct(public readonly Reimbursement $reimbursement) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $title = $this->reimbursement->title;
        $url = $this->linkFor($notifiable, [['payroll.reimbursements'], ['payroll.payslips']]);
        $amount = number_format((float) $this->reimbursement->amount, 2);
        $status = $this->reimbursement->status;

        return match ($status) {
            'approved' => [
                'type' => 'reimbursement',
                'title' => 'Reimbursement Approved',
                'body' => "Your reimbursement \"{$title}\" of \u{20B9}{$amount} has been approved and will be included in your next payroll.",
                'action' => 'View',
                'url' => $url,
                'icon' => 'check-circle',
                'color' => 'green',
            ],
            'rejected' => [
                'type' => 'reimbursement',
                'title' => 'Reimbursement Rejected',
                'body' => "Your reimbursement \"{$title}\" of \u{20B9}{$amount} was rejected.",
                'action' => 'View',
                'url' => $url,
                'icon' => 'x-circle',
                'color' => 'red',
            ],
            default => [
                'type' => 'reimbursement',
                'title' => 'New Reimbursement Pending',
                'body' => "Reimbursement \"{$title}\" of \u{20B9}{$amount} is awaiting approval.",
                'action' => 'Review',
                'url' => $url,
                'icon' => 'banknotes',
                'color' => 'blue',
            ],
        };
    }
}
