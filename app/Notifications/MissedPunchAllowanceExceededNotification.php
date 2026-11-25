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
 * The calendar month went over the missed-punch allowance: the employee is
 * flagged. HR is asked to review their
 * attendance; the employee is told the allowance is used up and to contact
 * HR. Sent once per month.
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
        public readonly string $monthStart,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $month = MissedPunchTracker::monthLabel(Carbon::parse($this->monthStart));
        $name = $this->employee->user?->name ?? 'An employee';
        $department = $this->employee->department?->name;
        $employeeCopy = $this->role === null || $this->role === 'employee';

        return [
            'type' => 'missed_punch_allowance',
            'title' => $employeeCopy ? 'Missed-punch allowance used up — contact HR' : "Review attendance: {$name}",
            'body' => $employeeCopy
                ? "You have missed {$this->count} punches in {$month}, over the {$this->allowance} allowed in a month. Please contact HR about your attendance; you can still request regularisations."
                : "{$name}".($department ? " ({$department})" : '')." has missed {$this->count} punches in {$month}, over the {$this->allowance} allowed in a month, and is flagged for your review.",
            'action' => $employeeCopy ? 'View attendance' : 'Review',
            'url' => $employeeCopy ? '/attendance/my' : '/attendance/missed-punches',
            'icon' => 'exclamation-triangle',
            'color' => 'red',
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
            'monthly_allowance' => (string) $this->allowance,
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
