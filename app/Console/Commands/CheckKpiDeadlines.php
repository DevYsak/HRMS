<?php

namespace App\Console\Commands;

use App\Models\ReviewGoal;
use App\Notifications\GoalDeadlineApproachingNotification;
use Illuminate\Console\Command;

class CheckKpiDeadlines extends Command
{
    protected $signature = 'hrms:check-kpi-deadlines';

    protected $description = 'Notify employees of review goals with deadlines within 7 days that are not yet completed.';

    public function handle(): int
    {
        $goals = ReviewGoal::with(['employee.user'])
            ->where('is_completed', false)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->get();

        if ($goals->isEmpty()) {
            $this->info('No goal deadlines approaching.');

            return self::SUCCESS;
        }

        $notified = 0;

        foreach ($goals as $goal) {
            $user = $goal->employee->user ?? null;

            if (! $user) {
                continue;
            }

            $user->notify(new GoalDeadlineApproachingNotification($goal));
            $notified++;
        }

        $this->info("Notified {$notified} employee(s) of approaching goal deadlines.");

        return self::SUCCESS;
    }
}
