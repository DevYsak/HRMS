<?php

namespace App\Notifications;

use App\Models\Payroll;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class PayrollApprovalNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly Payroll $payroll, public readonly string $event) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $period = "{$this->payroll->month}/{$this->payroll->year}";

        return match ($this->event) {
            'submitted' => [
                'type' => 'payroll',
                'title' => 'Payroll Pending Finance Approval',
                'body' => "Payroll for {$period} has been submitted for finance approval.",
                'action' => 'Review',
                'url' => '/payroll/overview',
                'icon' => 'banknotes',
                'color' => 'amber',
            ],
            'finance_approved' => [
                'type' => 'payroll',
                'title' => 'Payroll Finance Approved',
                'body' => "Payroll for {$period} has been approved by Finance.",
                'action' => 'View',
                'url' => '/payroll/overview',
                'icon' => 'check-circle',
                'color' => 'green',
            ],
            'rejected' => [
                'type' => 'payroll',
                'title' => 'Payroll Rejected by Finance',
                'body' => $this->payroll->finance_note
                    ? "Payroll for {$period} was rejected by Finance: {$this->payroll->finance_note}"
                    : "Payroll for {$period} was rejected by Finance and returned to draft.",
                'action' => 'Review',
                'url' => '/payroll/process',
                'icon' => 'x-circle',
                'color' => 'red',
            ],
            default => [
                'type' => 'payroll',
                'title' => 'Payroll Processed',
                'body' => "Payroll for {$period} has been processed. Payslips are now available.",
                'action' => 'View Payslip',
                'url' => '/payroll/my-payslips',
                'icon' => 'document-text',
                'color' => 'green',
            ],
        };
    }
}
