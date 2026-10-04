<?php

namespace App\Notifications;

use App\Models\Payslip;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

/**
 * A lightweight in-app "your payslip is ready" alert. The email is the
 * PayslipMail (with the PDF attached) that PayrollService queues separately —
 * spec §2.2: "Payslip issued (payslip PDF attached)" is ONE email. This used
 * to add a mail channel of its own, so every employee got two emails, the
 * second sent synchronously: one SMTP error then aborted delivery for
 * everyone after them.
 */
class PayslipGeneratedNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly Payslip $payslip) {}

    public function via(object $notifiable): array
    {
        return ['database'];
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
