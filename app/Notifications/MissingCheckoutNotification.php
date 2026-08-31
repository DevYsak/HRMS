<?php

namespace App\Notifications;

use App\Models\Attendance;
use App\Notifications\Concerns\NotifiesByRole;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

/**
 * FIX 4 — Spec §3.2 + §3.6
 * Sent to the employee and their manager when a missing clock-out is
 * flagged, each as their own role — see {@see NotifiesByRole}.
 * Notification event: "Missing clock-out reminder" → Employee + Manager
 */
class MissingCheckoutNotification extends Notification
{
    use NotifiesByRole;
    use SendsMailChannel;

    public function __construct(public readonly Attendance $attendance) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->attendance->employee;
        $name = $employee->user->name;
        $date = $this->attendance->date->format('d M Y');
        $checkin = $this->attendance->check_in?->format('H:i') ?? '--';

        $body = $this->role === 'manager'
            ? "{$name} clocked in at {$checkin} on {$date} but has no clock-out recorded. Please follow up."
            : "You clocked in at {$checkin} on {$date} but have no clock-out recorded. Please regularise.";

        return [
            'type' => 'missing_checkout',
            'title' => 'Missing Clock-Out',
            'body' => $body,
            'action' => 'Regularise',
            'url' => '/attendance/my',
            'icon' => 'exclamation-triangle',
            'color' => 'red',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function templateVariables(object $notifiable): array
    {
        $employee = $this->attendance->employee;

        return [
            'employee_name' => $employee->user->name,
            'employee_code' => (string) ($employee->employee_code ?? ''),
            'manager_name' => $employee->manager?->name ?? '',
            'department' => $employee->department?->name ?? '',
            'date' => $this->attendance->date->format('d M Y'),
            'check_in_time' => $this->attendance->check_in?->format('H:i') ?? '--',
            'action_url' => url('/attendance/my'),
            'company_name' => (string) config('app.name'),
        ];
    }
}
