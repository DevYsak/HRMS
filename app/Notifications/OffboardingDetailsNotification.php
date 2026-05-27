<?php

namespace App\Notifications;

use App\Models\ExitRecord;
use Carbon\Carbon;
use Illuminate\Notifications\Notification;

class OffboardingDetailsNotification extends Notification
{
    public function __construct(public readonly ExitRecord $exitRecord) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $lastDay = Carbon::parse($this->exitRecord->last_working_day)->format('d M Y');
        $exitType = ucfirst(str_replace('_', ' ', $this->exitRecord->exit_type ?? 'resignation'));

        return [
            'type' => 'offboarding',
            'title' => 'Offboarding Details Recorded',
            'body' => "Your offboarding has been initiated. Exit type: {$exitType}. Last working day: {$lastDay}.",
            'action' => 'View',
            'url' => route('employees.offboarding', $this->exitRecord->employee_id),
            'icon' => 'arrow-right-start-on-rectangle',
            'color' => 'amber',
        ];
    }
}
