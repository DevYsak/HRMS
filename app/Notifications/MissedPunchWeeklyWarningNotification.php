<?php

namespace App\Notifications;

use App\Models\Employee;
use App\Notifications\Concerns\FormatsForRecipient;
use App\Notifications\Concerns\NotifiesByRole;
use App\Notifications\Concerns\SendsMailChannel;
use App\Services\Attendance\MissedPunchTracker;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Warning: MissedPunchTracker::WEEKLY_WARNING_AT or more punches missed in
 * one Monday–Sunday week. Sent once per week to the employee and their
 * manager, each as their own role.
 */
class MissedPunchWeeklyWarningNotification extends Notification
{
    use FormatsForRecipient;
    use NotifiesByRole;
    use SendsMailChannel;

    public function __construct(public readonly Employee $employee, public readonly int $count, public readonly string $weekStart) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $week = Carbon::parse($this->weekStart)->format($this->recipientDateFormat($notifiable));
        $name = $this->employee->user?->name ?? 'An employee';

        return [
            'type' => 'missed_punch_warning',
            'title' => "Warning: {$this->count} missed punches this week",
            'body' => $this->role === 'manager'
                ? "{$name} has missed {$this->count} punches in the week of {$week} (warning from ".MissedPunchTracker::WEEKLY_WARNING_AT.').'
                : "You have missed {$this->count} punches in the week of {$week}. Missed punches lower your attendance score — punch Face IN and ID Card OUT every time you leave and return.",
            'action' => 'View attendance',
            'url' => $this->role === 'manager' ? '/attendance/team' : '/attendance/my',
            'icon' => 'exclamation-triangle',
            'color' => 'red',
            'week_count' => $this->count,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function templateVariables(object $notifiable): array
    {
        return [
            'employee_name' => $this->employee->user?->name ?? '',
            'employee_code' => (string) ($this->employee->employee_code ?? ''),
            'manager_name' => $this->employee->manager?->name ?? '',
            'department' => $this->employee->department?->name ?? '',
            'week_start' => Carbon::parse($this->weekStart)->format($this->recipientDateFormat($notifiable)),
            'missed_count' => (string) $this->count,
            'action_url' => url('/attendance/my'),
            'company_name' => (string) config('app.name'),
        ];
    }
}
