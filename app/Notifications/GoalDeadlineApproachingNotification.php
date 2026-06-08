<?php

namespace App\Notifications;

use App\Models\ReviewGoal;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class GoalDeadlineApproachingNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly ReviewGoal $goal) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $due = $this->goal->due_date?->format('d M Y') ?? 'soon';

        return [
            'type' => 'goal_deadline_approaching',
            'title' => 'Goal Deadline Approaching',
            'body' => "Your goal \"{$this->goal->title}\" is due on {$due}. Make sure to record your progress before the deadline.",
            'action' => 'View Review',
            'url' => '/performance/my',
            'icon' => 'clock',
            'color' => 'amber',
        ];
    }
}
