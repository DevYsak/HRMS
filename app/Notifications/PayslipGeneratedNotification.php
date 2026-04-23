<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayslipGeneratedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly \App\Models\Payslip $payslip) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): \App\Mail\PayslipMail
    {
        return (new \App\Mail\PayslipMail($this->payslip))->to($notifiable->email);
    }

    public function toArray(object $notifiable): array
    {
        $period = "{$this->payslip->payroll->month} {$this->payslip->payroll->year}";

        return [
            'type'   => 'payslip',
            'title'  => 'New Payslip Available',
            'body'   => "Your payslip for {$period} has been generated and is ready for viewing.",
            'action' => 'View Payslip',
            'url'    => '/payroll/my-payslips',
            'icon'   => 'document-text',
            'color'  => 'green',
        ];
    }
}
