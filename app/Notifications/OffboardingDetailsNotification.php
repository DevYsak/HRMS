<?php

namespace App\Notifications;

use App\Notifications\Concerns\FormatsForRecipient;
use App\Models\ExitRecord;
use App\Notifications\Concerns\LinksForRecipient;
use App\Notifications\Concerns\SendsMailChannel;
use Carbon\Carbon;
use Illuminate\Notifications\Notification;

class OffboardingDetailsNotification extends Notification
{
    use FormatsForRecipient;
    use LinksForRecipient;
    use SendsMailChannel;

    public function __construct(public readonly ExitRecord $exitRecord) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $lastDay = Carbon::parse($this->exitRecord->last_working_day)->format($this->recipientDateFormat($notifiable));
        $exitType = ucfirst(str_replace('_', ' ', $this->exitRecord->exit_type ?? 'resignation'));

        return [
            'type' => 'offboarding',
            'title' => 'Offboarding Details Recorded',
            'body' => "Your offboarding has been initiated. Exit type: {$exitType}. Last working day: {$lastDay}.",
            'action' => 'View',
            'url' => $this->linkFor($notifiable, [['employees.offboarding', ['employee' => $this->exitRecord->employee_id]]]),
            'icon' => 'arrow-right-start-on-rectangle',
            'color' => 'amber',
        ];
    }
}
