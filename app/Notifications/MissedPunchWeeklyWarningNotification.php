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
 * Early warning: the calendar month (its Mon–Fri days) reached the warning
 * number of missed punches set on Attendance Settings, still within the
 * allowance. Sent once per month to the employee and the HR covering them.
 * The class keeps its first name ("Weekly") because it is the key of its row
 * on Notifications & Email; the event is shown as "Missed Punch Warning".
 */
class MissedPunchWeeklyWarningNotification extends Notification
{
    use FormatsForRecipient;
    use NotifiesByRole;
    use SendsMailChannel;

    public function __construct(public readonly Employee $employee, public readonly int $count, public readonly string $monthStart) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $month = MissedPunchTracker::monthLabel(Carbon::parse($this->monthStart));
        $name = $this->employee->user?->name ?? 'An employee';
        $allowance = MissedPunchTracker::allowance();
        $employeeCopy = $this->role === null || $this->role === 'employee';

        return [
            'type' => 'missed_punch_warning',
            'title' => "Warning: {$this->count} of {$allowance} missed punches used this month",
            'body' => $employeeCopy
                ? "You have missed {$this->count} punches in {$month}; {$allowance} are allowed in a month. Punch Face IN and ID Card OUT every time you leave and come back — one more than {$allowance} and HR reviews your attendance."
                : "{$name} has missed {$this->count} of {$allowance} allowed punches in {$month}.",
            'action' => 'View attendance',
            'url' => $employeeCopy ? '/attendance/my' : '/attendance/missed-punches',
            'icon' => 'exclamation-triangle',
            'color' => 'amber',
            'month_count' => $this->count,
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
            'month' => MissedPunchTracker::monthLabel(Carbon::parse($this->monthStart)),
            'missed_count' => (string) $this->count,
            'monthly_allowance' => (string) MissedPunchTracker::allowance(),
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
