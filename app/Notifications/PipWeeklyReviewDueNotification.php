<?php

namespace App\Notifications;

use App\Notifications\Concerns\FormatsForRecipient;
use App\Models\PipRecord;
use App\Notifications\Concerns\LinksForRecipient;
use App\Notifications\Concerns\NotifiesByRole;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class PipWeeklyReviewDueNotification extends Notification
{
    use FormatsForRecipient;
    use LinksForRecipient;
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
        $own = $this->isAbout($notifiable, $this->pip->employee->user_id);

        return [
            'type' => 'pip_weekly_review_due',
            'title' => 'Weekly PIP Review Due',
            'body' => $own
                ? "A weekly progress review is due on your performance improvement plan (ends {$this->pip->end_date->format($this->recipientDateFormat($notifiable))})."
                : "A weekly progress review is due for {$employee}'s performance improvement plan (ends {$this->pip->end_date->format($this->recipientDateFormat($notifiable))}).",
            'action' => $own ? 'View My PIP' : 'Review Progress',
            'url' => $own ? $this->linkFor($notifiable, [['performance.pip.my']]) : $this->linkFor($notifiable, [['performance.pip.manage']]),
            'icon' => 'calendar-days',
            'color' => 'amber',
        ];
    }
}
