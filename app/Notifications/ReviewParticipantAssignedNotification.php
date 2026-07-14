<?php

namespace App\Notifications;

use App\Models\ReviewParticipant;
use Illuminate\Notifications\Notification;

/** In-app only — a reviewer was added to an employee's performance review (v4 Phase D). */
class ReviewParticipantAssignedNotification extends Notification
{
    public function __construct(public readonly ReviewParticipant $participant) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->participant->review?->employee?->user?->name ?? 'an employee';
        $cycle = $this->participant->review?->performanceCycle?->name ?? 'the current cycle';

        return [
            'type' => 'review_participant_assigned',
            'title' => 'Performance review assigned to you',
            'body' => "You are the {$this->participant->roleLabel()} reviewer for {$employee} in {$cycle} (weight {$this->participant->weight_percent}%).",
            'action' => 'Open Review Tasks',
            'url' => '/performance/review-tasks',
            'icon' => 'clipboard-document-check',
            'color' => 'purple',
        ];
    }
}
