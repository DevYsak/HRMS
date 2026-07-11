<?php

namespace App\Console\Commands;

use App\Models\ReviewParticipant;
use App\Notifications\ReviewParticipantAssignedNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hrms:remind-review-participants')]
#[Description('Nudge reviewers with pending review-participant submissions in active performance cycles (v4 Phase D, weekly).')]
class RemindReviewParticipants extends Command
{
    public function handle(): int
    {
        $pending = ReviewParticipant::query()
            ->where('status', 'pending')
            ->whereHas('review', fn ($q) => $q->where('status', '!=', 'locked')
                ->whereHas('performanceCycle', fn ($c) => $c->whereIn('status', ['active', 'self_review', 'manager_review', 'hr_review'])))
            ->with('reviewer')
            ->get();

        // One nudge per reviewer, re-using the assignment notification for
        // their oldest pending task.
        $pending->groupBy('reviewer_id')->each(function ($tasks) {
            $tasks->first()->reviewer?->notify(new ReviewParticipantAssignedNotification($tasks->first()));
        });

        $this->info("Reminded {$pending->groupBy('reviewer_id')->count()} reviewer(s) about {$pending->count()} pending submission(s).");

        return self::SUCCESS;
    }
}
