<?php

namespace App\Notifications;

use App\Models\ExpenseClaim;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class ExpenseClaimNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly ExpenseClaim $claim) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->claim->employee->user->name;
        $status = $this->claim->status->value;
        $title = $this->claim->title;
        $amount = number_format((float) $this->claim->amount, 2);

        return match ($status) {
            'pending' => [
                'type' => 'expense_claim',
                'title' => 'New Expense Claim',
                'body' => "{$employee} submitted an expense claim \"{$title}\" for \u{20B9}{$amount}.",
                'action' => 'Review',
                'url' => '/operations/expenses',
                'icon' => 'receipt-refund',
                'color' => 'blue',
            ],
            'approved' => [
                'type' => 'expense_claim',
                'title' => 'Expense Claim Approved',
                'body' => "Your expense claim \"{$title}\" for \u{20B9}{$amount} has been approved and converted to reimbursement.",
                'action' => 'View',
                'url' => '/operations/expenses',
                'icon' => 'check-circle',
                'color' => 'green',
            ],
            default => [
                'type' => 'expense_claim',
                'title' => 'Expense Claim Rejected',
                'body' => "Your expense claim \"{$title}\" for \u{20B9}{$amount} was rejected.",
                'action' => 'View',
                'url' => '/operations/expenses',
                'icon' => 'x-circle',
                'color' => 'red',
            ],
        };
    }
}
