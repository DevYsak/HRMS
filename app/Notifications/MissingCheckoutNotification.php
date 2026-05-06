<?php

namespace App\Notifications;

use App\Models\Attendance;
use Illuminate\Notifications\Notification;

/**
 * FIX 4 — Spec §3.2 + §3.6
 * Sent to the employee (and their manager) when a missing clock-out is flagged.
 * Notification event: "Missing clock-out reminder" → Employee + Manager
 */
class MissingCheckoutNotification extends Notification
{
    public function __construct(public readonly Attendance $attendance) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $date = $this->attendance->date->format('d M Y');
        $name = $this->attendance->employee->user->name;
        $checkin = $this->attendance->check_in?->format('H:i') ?? '--';

        return [
            'type' => 'missing_checkout',
            'title' => 'Missing Clock-Out',
            'body' => "{$name} clocked in at {$checkin} on {$date} but has no clock-out recorded. Please regularise.",
            'action' => 'Regularise',
            'url' => '/attendance/my',
            'icon' => 'exclamation-triangle',
            'color' => 'red',
        ];
    }
}
