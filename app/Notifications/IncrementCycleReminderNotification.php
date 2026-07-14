<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** June-1 in-app nudge to HR: open the July increment cycle (v4 Phase E). */
class IncrementCycleReminderNotification extends Notification
{
    public function __construct(public readonly string $financialYear) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'increment_cycle_reminder',
            'title' => "Time to open the FY {$this->financialYear} increment cycle",
            'body' => 'Increments take effect on 1 July. Open the cycle in the Increment Center so calibration can start.',
            'action' => 'Open Increment Center',
            'url' => '/performance/increments',
            'icon' => 'banknotes',
            'color' => 'amber',
        ];
    }
}
