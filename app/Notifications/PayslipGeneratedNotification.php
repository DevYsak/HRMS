<?php

namespace App\Notifications;

use App\Models\Payslip;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

/**
 * A lightweight "your payslip is ready" alert — the actual PayslipMail (with
 * the PDF attached) is sent separately by PayrollService, so this uses the
 * generic SendsMailChannel template rather than resending the same PDF mail
 * a second time.
 */
class PayslipGeneratedNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly Payslip $payslip) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $period = "{$this->payslip->payroll->month} {$this->payslip->payroll->year}";

        return [
            'type' => 'payslip',
            'title' => 'New Payslip Available',
            'body' => "Your payslip for {$period} has been generated and is ready for viewing.",
            'action' => 'View Payslip',
            'url' => '/payroll/my-payslips',
            'icon' => 'document-text',
            'color' => 'green',
        ];
    }
}
