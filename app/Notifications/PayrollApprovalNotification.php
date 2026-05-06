<?php

namespace App\Notifications;

use App\Models\Payroll;
use Illuminate\Notifications\Notification;

class PayrollApprovalNotification extends Notification
{
    public function __construct(public readonly Payroll $payroll, public readonly string $event) {}

    public function via(object $notifiable): array
    {
        return ['database'];
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
