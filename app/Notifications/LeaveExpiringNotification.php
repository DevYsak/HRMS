<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * "3 carry-forward days expire on 30 Sep 2026" — sent to the employee 30
 * and 7 days before a credit lot expires. Carries only employee-visible
 * facts (type, days, date); never HR notes.
 */
class LeaveExpiringNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(
        public readonly string $leaveType,
        public readonly string $bucket,
        public readonly float $days,
        public readonly string $expiresOn,
        // For de-duplication: one notice per lot per window.
        public readonly ?int $lotId = null,
        public readonly ?int $window = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $date = Carbon::parse($this->expiresOn)->format('d M Y');
        $days = rtrim(rtrim(number_format($this->days, 2), '0'), '.');

        return [
            'type' => 'leave_expiring',
            'title' => 'Leave expiring soon',
            'body' => "{$days} {$this->bucket} day(s) of {$this->leaveType} expire on {$date}. Book them before then or they lapse.",
            'action' => 'View My Time Off',
            'url' => route('time-off.my'),
            'icon' => 'clock',
            'color' => 'amber',
            'lot_id' => $this->lotId,
            'window' => $this->window,
        ];
    }
}
