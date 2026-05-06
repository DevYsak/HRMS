<?php

namespace App\Notifications;

use App\Models\Reimbursement;
use Illuminate\Notifications\Notification;

class ReimbursementNotification extends Notification
{
    public function __construct(public readonly Reimbursement $reimbursement) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $title = $this->reimbursement->title;
        $amount = number_format((float) $this->reimbursement->amount, 2);
        $status = $this->reimbursement->status;

        return match ($status) {
            'approved' => [
                'type' => 'reimbursement',
                'title' => 'Reimbursement Approved',
                'body' => "Your reimbursement \"{$title}\" of \u{20B9}{$amount} has been approved and will be included in your next payroll.",
                'action' => 'View',
                'url' => '/payroll/reimbursements',
                'icon' => 'check-circle',
                'color' => 'green',
            ],
            'rejected' => [
                'type' => 'reimbursement',
                'title' => 'Reimbursement Rejected',
                'body' => "Your reimbursement \"{$title}\" of \u{20B9}{$amount} was rejected.",
                'action' => 'View',
                'url' => '/payroll/reimbursements',
                'icon' => 'x-circle',
                'color' => 'red',
            ],
            default => [
                'type' => 'reimbursement',
                'title' => 'New Reimbursement Pending',
                'body' => "Reimbursement \"{$title}\" of \u{20B9}{$amount} is awaiting approval.",
                'action' => 'Review',
                'url' => '/payroll/reimbursements',
                'icon' => 'banknotes',
                'color' => 'blue',
            ],
        };
    }
}
