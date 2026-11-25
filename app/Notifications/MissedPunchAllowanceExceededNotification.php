<?php

namespace App\Notifications;

use App\Models\Employee;
use App\Notifications\Concerns\FormatsForRecipient;
use App\Notifications\Concerns\NotifiesByRole;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * The working week (Mon–Fri) went over the missed-punch allowance: the
 * employee is flagged. HR is asked to review their attendance; the employee
 * is told the allowance is used up and to contact HR. Sent once per week.
 */
class MissedPunchAllowanceExceededNotification extends Notification
{
    use FormatsForRecipient;
    use NotifiesByRole;
    use SendsMailChannel;

    public function __construct(
        public readonly Employee $employee,
        public readonly int $count,
        public readonly int $allowance,
        public readonly string $weekStart,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $week = Carbon::parse($this->weekStart)->format($this->recipientDateFormat($notifiable));
        $name = $this->employee->user?->name ?? 'An employee';
        $department = $this->employee->department?->name;
        $employeeCopy = $this->role === null || $this->role === 'employee';

        return [
            'type' => 'missed_punch_allowance',
            'title' => $employeeCopy ? 'Missed-punch allowance used up — contact HR' : "Review attendance: {$name}",
            'body' => $employeeCopy
                ? "You have missed {$this->count} punches in the week of {$week}, over the {$this->allowance} allowed. Please contact HR about your attendance; you can still request regularisations."
                : "{$name}".($department ? " ({$department})" : '')." has missed {$this->count} punches in the week of {$week}, over the {$this->allowance} allowed, and is flagged for your review.",
            'action' => $employeeCopy ? 'View attendance' : 'Review',
            'url' => $employeeCopy ? '/attendance/my' : '/attendance/missed-punches',
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
            'employee_code' => (string) ($this->employee->employee_id ?? ''),
            'department' => $this->employee->department?->name ?? '',
            'week_start' => Carbon::parse($this->weekStart)->format($this->recipientDateFormat($notifiable)),
            'missed_count' => (string) $this->count,
            'weekly_allowance' => (string) $this->allowance,
            'action_url' => url('/attendance/missed-punches'),
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
