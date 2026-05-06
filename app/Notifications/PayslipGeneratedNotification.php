<?php

namespace App\Notifications;

use App\Mail\PayslipMail;
use App\Models\Payslip;
use Illuminate\Notifications\Notification;

class PayslipGeneratedNotification extends Notification
{
    public function __construct(public readonly Payslip $payslip) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): PayslipMail
    {
        return (new PayslipMail($this->payslip))->to($notifiable->email);
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
