<?php

namespace App\Console\Commands;

use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Notifications\ReviewCycleStartedNotification;
use Illuminate\Console\Command;

class CheckReviewCycleReminders extends Command
{
    protected $signature = 'hrms:check-review-cycle-reminders';

    protected $description = 'Remind employees in active performance cycles whose self-review is still pending within 7 days of deadline.';

    public function handle(): int
    {
        $cycles = PerformanceCycle::where('status', 'active')
            ->whereNotNull('self_review_deadline')
            ->whereBetween('self_review_deadline', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->get();

        if ($cycles->isEmpty()) {
            $this->info('No performance cycles with approaching self-review deadlines.');

            return self::SUCCESS;
        }

        $notified = 0;

        foreach ($cycles as $cycle) {
            $pending = PerformanceReview::with(['employee.user'])
                ->where('performance_cycle_id', $cycle->id)
                ->whereIn('status', ['draft', 'in_progress'])
                ->get();

            foreach ($pending as $review) {
                $user = $review->employee->user ?? null;

                if (! $user) {
                    continue;
                }

                $user->notify(new ReviewCycleStartedNotification($cycle));
                $notified++;
            }
        }

        $this->info("Sent {$notified} review cycle reminder(s).");

        return self::SUCCESS;
    }
}
