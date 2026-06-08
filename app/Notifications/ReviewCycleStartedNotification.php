<?php

namespace App\Notifications;

use App\Models\PerformanceCycle;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class ReviewCycleStartedNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly PerformanceCycle $cycle) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'review_cycle_started',
            'title' => 'Performance Review Cycle Started',
            'body' => "The performance review cycle \"{$this->cycle->name}\" has started. Please complete your self-assessment by {$this->cycle->end_date->format('d M Y')}.",
            'action' => 'Start Review',
            'url' => '/performance/my',
            'icon' => 'star',
            'color' => 'blue',
        ];
    }
}
