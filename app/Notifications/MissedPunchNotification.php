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
 * A punch was missed in the middle of the day — two INs in a row (missed
 * OUT) or two OUTs in a row (missed IN), see MissedPunchTracker. Sent once
 * per missed punch to the employee and the HR covering them, each as their
 * own role (never the manager by default). The employee's copy is also the
 * popup they see on their next page. (A missed final check-out has its own
 * MissingCheckoutNotification.)
 */
class MissedPunchNotification extends Notification
{
    use FormatsForRecipient;
    use NotifiesByRole;
    use SendsMailChannel;

    /**
     * @param  array{key: string, date: string, kind: string, label: string}  $missed
     */
    public function __construct(public readonly Employee $employee, public readonly array $missed, public readonly int $weekCount) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $date = Carbon::parse($this->missed['date'])->format($this->recipientDateFormat($notifiable));
        $what = $this->missed['label'];
        $name = $this->employee->user?->name ?? 'An employee';
        $week = $this->weekCount.' of '.MissedPunchTracker::allowance().' allowed this week';
        $employeeCopy = $this->role === null || $this->role === 'employee';

        return [
            'type' => 'missed_punch',
            'title' => $this->missed['kind'] === 'in' ? 'Missed IN punch' : 'Missed OUT punch',
            'body' => $employeeCopy
                ? "{$what} on {$date}. Request a regularisation so your hours are right. {$week}."
                : "{$name}: {$what} on {$date}. {$week}.",
            'action' => $employeeCopy ? 'Regularise' : 'Review',
            'url' => $employeeCopy ? '/attendance/my?regularise='.$this->missed['date'] : '/attendance/missed-punches',
            'icon' => 'exclamation-triangle',
            'color' => 'red',
            'missed_key' => $this->missed['key'],
            'date' => $this->missed['date'],
            'week_count' => $this->weekCount,
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
            'date' => Carbon::parse($this->missed['date'])->format($this->recipientDateFormat($notifiable)),
            'missed_punch' => $this->missed['label'],
            'week_count' => (string) $this->weekCount,
            'weekly_allowance' => (string) MissedPunchTracker::allowance(),
            'action_url' => url('/attendance/my?regularise='.$this->missed['date']),
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
