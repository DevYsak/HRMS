<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\ReviewCycle;
use App\Notifications\ReviewReminderNotification;
use Illuminate\Console\Command;

class SendReviewReminders extends Command
{
    protected $signature   = 'hrms:send-review-reminders';
    protected $description = 'Send weekly reminders to employees with pending performance reviews in active cycles.';

    public function handle(): int
    {
        $activeCycles = ReviewCycle::where('status', 'active')->get();

        if ($activeCycles->isEmpty()) {
            $this->info('No active review cycles found.');

            return self::SUCCESS;
        }

        $reminded = 0;

        foreach ($activeCycles as $cycle) {
            $pendingReviews = PerformanceReview::with(['employee.user'])
                ->where('review_cycle_id', $cycle->id)
                ->whereIn('status', ['pending', 'in_progress'])
                ->get();

            foreach ($pendingReviews as $review) {
                $user = $review->employee->user ?? null;

                if (! $user) {
                    continue;
                }

                $user->notify(new ReviewReminderNotification($cycle, $review));
                $reminded++;
            }
        }

        $this->info("Sent {$reminded} review reminder(s).");

        return self::SUCCESS;
    }
}
