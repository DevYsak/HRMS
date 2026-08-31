<?php

namespace App\Notifications;

use App\Models\Attendance;
use App\Notifications\Concerns\NotifiesByRole;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

/**
 * FIX 4 — Spec §3.2 + §3.6
 * Sent to the employee and their manager when excess break is flagged, each
 * as their own role so Email/In-App/Auto-Send and the template can be
 * configured independently per recipient — see {@see NotifiesByRole}.
 * Notification event: "Excess break flag (>60 mins)" → Employee + Manager
 */
class ExcessBreakNotification extends Notification
{
    use NotifiesByRole;
    use SendsMailChannel;

    public function __construct(
        public readonly Attendance $attendance,
        public readonly int $totalBreakMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $excess = $this->totalBreakMinutes - 60;
        $employee = $this->attendance->employee;
        $name = $employee->user->name;
        $date = $this->attendance->date->format('d M Y');

        $body = $this->role === 'manager'
            ? "{$name}'s total break on {$date} was {$this->totalBreakMinutes} min (+{$excess} min over the 60-min limit)."
            : "Your total break time on {$date} was {$this->totalBreakMinutes} min, exceeding the configured limit of 60 min.";

        return [
            'type' => 'excess_break',
            'title' => 'Excess Break Flagged',
            'body' => $body,
            'action' => 'View Attendance',
            'url' => '/attendance/my',
            'icon' => 'clock',
            'color' => 'orange',
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
            'break_minutes' => (string) $this->totalBreakMinutes,
            'break_limit' => '60',
            'excess_minutes' => (string) ($this->totalBreakMinutes - 60),
            'action_url' => url('/attendance/my'),
            'company_name' => (string) config('app.name'),
        ];
    }
}
