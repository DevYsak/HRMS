<?php

namespace App\Notifications;

use App\Models\Attendance;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Spec §7 check-late-arrivals → the manager, in-app only (§2.2: late flags
 * are not an email trigger). One digest per manager per shift run, listing
 * the team members who arrived after their grace cutoff today.
 */
class LateArrivalNotification extends Notification
{
    /** @param  Collection<int, Attendance>  $attendances */
    public function __construct(public readonly Collection $attendances) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $names = $this->attendances
            ->map(fn (Attendance $a) => ($a->employee?->user?->name ?? 'An employee').' ('.(int) $a->late_minutes.' min)')
            ->implode(', ');

        return [
            'type' => 'late_arrival',
            'title' => 'Late Arrivals Today',
            'body' => $this->attendances->count().' team member(s) arrived after the grace period today: '.$names.'.',
            'action' => 'View Team Attendance',
            'url' => '/attendance/team',
            'icon' => 'clock',
            'color' => 'amber',
            'attendance_ids' => $this->attendances->pluck('id')->values()->all(),
        ];
    }
}
