<?php

namespace App\Notifications;

use App\Models\Employee;
use App\Notifications\Concerns\NotifiesByRole;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class ProbationConfirmedNotification extends Notification
{
    use NotifiesByRole;
    use SendsMailChannel;

    /**
     * @param  string  $stage  'manager_confirmed' | 'hr_approved'
     */
    public function __construct(
        public readonly Employee $employee,
        public readonly string $stage,
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

        if ($this->stage === 'manager_confirmed') {
            return [
                'type' => 'probation_manager_confirmed',
                'title' => 'Probation Review Ready',
                'body' => "Manager has confirmed probation for {$name}. HR approval required.",
                'action' => 'Review Now',
                'url' => "/employees/{$this->employee->id}/probation",
                'icon' => 'check',
                'color' => 'amber',
            ];
        }

        return [
            'type' => 'probation_hr_approved',
            'title' => 'Probation Confirmed',
            'body' => "Probation for {$name} has been fully confirmed. Status updated to Confirmed.",
            'action' => 'View Profile',
            'url' => "/employees/{$this->employee->id}/edit",
            'icon' => 'check-badge',
            'color' => 'green',
        ];
    }
}
