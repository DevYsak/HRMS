<?php

namespace App\Notifications;

use App\Notifications\Concerns\NotifiesByRole;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

/**
 * Attendance exceptions raised by the Coordinator workflow, each recipient
 * as their own role ({@see NotifiesByRole}):
 *
 *   coordinator  a digest of new exceptions among the people they monitor
 *   employee     a reminder about their own exception
 *   manager / hr_admin  an escalation of an unresolved exception
 */
class AttendanceExceptionNotification extends Notification
{
    use NotifiesByRole;
    use SendsMailChannel;

    /**
     * @param  array<int, array{name: string, type: string, date: string}>  $items
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $items,
        public readonly ?string $from = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $first = $this->items[0] ?? ['name' => '', 'type' => '', 'date' => ''];
        $label = fn (string $type) => match ($type) {
            'absent' => 'absent',
            'late' => 'late',
            'missing_checkout' => 'missing a clock-out',
            'regularisation' => 'waiting on a regularisation',
            default => $type,
        };

        [$title, $body, $url] = match ($this->kind) {
            'digest' => [
                'Attendance exceptions to follow up',
                count($this->items).' new: '.collect($this->items)->take(5)->map(fn ($i) => "{$i['name']} ({$label($i['type'])})")->implode(', ').(count($this->items) > 5 ? ' …' : ''),
                '/attendance/exceptions',
            ],
            'reminder' => [
                'Attendance reminder',
                "Your attendance on {$first['date']} shows you as {$label($first['type'])}".($this->from ? " (reminder from {$this->from})" : '').'. Please clock in/out or raise a regularisation.',
                '/attendance/my',
            ],
            default => [
                'Attendance exception escalated',
                "{$first['name']} was {$label($first['type'])} on {$first['date']} and it is still unresolved".($this->from ? " (escalated by {$this->from})" : '').'.',
                '/attendance/employees',
            ],
        };

        return [
            'type' => 'attendance_exception',
            'title' => $title,
            'body' => $body,
            'action' => 'Open',
            'url' => $url,
            'icon' => 'exclamation-triangle',
            'color' => 'amber',
        ];
    }

    /** @return array<string, string> */
    public function templateVariables(object $notifiable): array
    {
        $first = $this->items[0] ?? ['name' => '', 'type' => '', 'date' => ''];

        return [
            'employee_name' => $first['name'],
            'date' => $first['date'],
            'exception' => str_replace('_', ' ', $first['type']),
            'count' => (string) count($this->items),
            'sent_by' => (string) $this->from,
            'company_name' => (string) config('app.name'),
        ];
    }
}
