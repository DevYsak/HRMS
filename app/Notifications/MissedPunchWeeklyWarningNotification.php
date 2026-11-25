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
 * Early warning: the working week (Mon–Fri) reached the warning number of
 * missed punches set on Attendance Settings, still within the allowance.
 * Sent once per week to the employee and the HR covering them.
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
        $allowance = MissedPunchTracker::allowance();
        $employeeCopy = $this->role === null || $this->role === 'employee';

        return [
            'type' => 'missed_punch_warning',
            'title' => "Warning: {$this->count} of {$allowance} missed punches used this week",
            'body' => $employeeCopy
                ? "You have missed {$this->count} punches in the week of {$week}; {$allowance} are allowed. Punch Face IN and ID Card OUT every time you leave and come back — one more than {$allowance} and HR reviews your attendance."
                : "{$name} has missed {$this->count} of {$allowance} allowed punches in the week of {$week}.",
            'action' => 'View attendance',
            'url' => $employeeCopy ? '/attendance/my' : '/attendance/missed-punches',
            'icon' => 'exclamation-triangle',
            'color' => 'amber',
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
            'employee_code' => (string) ($this->employee->employee_id ?? ''),
            'department' => $this->employee->department?->name ?? '',
            'week_start' => Carbon::parse($this->weekStart)->format($this->recipientDateFormat($notifiable)),
            'missed_count' => (string) $this->count,
            'weekly_allowance' => (string) MissedPunchTracker::allowance(),
            'action_url' => url('/attendance/my'),
            'company_name' => (string) config('app.name'),
        ];
    }

    /**
     * The employee it is about, for the email history.
     *
     * @return array{type: string, id: int}
     */
    public function mailContext(): array
    {
        return ['type' => 'employee', 'id' => (int) $this->employee->id];
    }
}
