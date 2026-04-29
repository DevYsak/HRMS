<?php

namespace App\Notifications;

use App\Models\Attendance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * FIX 4 — Spec §3.2 + §3.6
 * Sent to the employee (and their manager) when excess break is flagged.
 * Notification event: "Excess break flag (>60 mins)" → Employee + Manager
 */
class ExcessBreakNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Attendance $attendance,
        public readonly int $totalBreakMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $excess = $this->totalBreakMinutes - 60;
        $date = $this->attendance->date->format('d M Y');
        $name = $this->attendance->employee->user->name;

        return [
            'type' => 'excess_break',
            'title' => 'Excess Break Flagged',
            'body' => "{$name}'s total break on {$date} was {$this->totalBreakMinutes} min (+{$excess} min over the 60-min limit).",
            'action' => 'View Attendance',
            'url' => '/attendance/my',
            'icon' => 'clock',
            'color' => 'orange',
        ];
    }
}
