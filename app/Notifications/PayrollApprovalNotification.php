<?php

namespace App\Notifications;

use App\Models\Payroll;
use App\Models\PayrollApprovalStep;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class PayrollApprovalNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(
        public readonly Payroll $payroll,
        public readonly string $event,
        public readonly ?PayrollApprovalStep $step = null,
    ) {}

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
            'step_ready' => [
                'type' => 'payroll',
                'title' => "Payroll Approval Needed — {$this->step?->label}",
                'body' => "Payroll for {$period} needs your approval at the \"{$this->step?->label}\" step.",
                'action' => 'Review',
                'url' => '/payroll/finance-approve',
                'icon' => 'banknotes',
                'color' => 'amber',
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
