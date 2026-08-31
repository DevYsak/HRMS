<?php

namespace App\Notifications;

use App\Models\PipRecord;
use App\Notifications\Concerns\NotifiesByRole;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class PipWeeklyReviewDueNotification extends Notification
{
    use NotifiesByRole;
    use SendsMailChannel;

    public function __construct(public readonly PipRecord $pip) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->pip->employee->user->name;

        return [
            'type' => 'pip_weekly_review_due',
            'title' => 'Weekly PIP Review Due',
            'body' => "A weekly progress review is due for {$employee}'s performance improvement plan (ends {$this->pip->end_date->format('d M Y')}).",
            'action' => 'Review Progress',
            'url' => '/performance/pip/manage',
            'icon' => 'calendar-days',
            'color' => 'amber',
        ];
    }
}
