<?php

namespace App\Notifications;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class EmployeeStatusChangedNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(
        public readonly Employee $employee,
        public readonly EmployeeStatus $fromStatus,
        public readonly EmployeeStatus $toStatus,
        public readonly User $actor,
        public readonly ?string $note = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $name = $this->employee->user->name;
        $from = $this->fromStatus->label();
        $to = $this->toStatus->label();

        return [
            'type' => 'employee_status_changed',
            'title' => "Status Changed: {$name}",
            'body' => "{$name}'s status changed from {$from} to {$to}".($this->note ? " — {$this->note}" : '.'),
            'action' => 'View Employee',
            'url' => "/employees/{$this->employee->id}/edit",
            'icon' => 'arrow-path',
            'color' => $this->toStatus->color(),
        ];
    }
}
