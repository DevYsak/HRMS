<?php

namespace App\Notifications;

use App\Models\SalaryRevision;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class SalaryStructureAssignedNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly SalaryRevision $revision) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $effective = $this->revision->effective_date?->format('d M Y') ?? 'as notified';

        return [
            'type' => 'salary_revision',
            'title' => 'Salary Structure Updated',
            'body' => "Your salary structure has been updated, effective {$effective}. Please contact HR for details.",
            'action' => 'View Payslip',
            'url' => '/payroll/my-payslips',
            'icon' => 'currency-rupee',
            'color' => 'blue',
        ];
    }
}
