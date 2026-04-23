<?php

namespace App\Notifications;

use App\Models\PerformanceReview;
use App\Models\ReviewCycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ReviewReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ReviewCycle $cycle,
        public readonly PerformanceReview $review,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'   => 'review_reminder',
            'title'  => 'Performance Review Pending',
            'body'   => "Your self-assessment for the \"{$this->cycle->name}\" review cycle is still pending. Please complete it before the deadline.",
            'action' => 'Complete Review',
            'url'    => '/performance/my',
            'icon'   => 'clipboard-document-check',
            'color'  => 'purple',
        ];
    }
}
