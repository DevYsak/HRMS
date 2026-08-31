<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Notifications\NewHireCheckInNotification;
use App\Services\Notifications\NotificationRecipients;
use Illuminate\Console\Command;

class CheckNewHireCheckIn extends Command
{
    protected $signature = 'hrms:check-newhire-checkin';

    protected $description = 'Notify HR when a new hire reaches their 30-day milestone.';

    public function handle(): int
    {
        // Employees who joined exactly 30 days ago
        $thirtyDaysAgo = now()->subDays(30)->toDateString();

        $newHires = Employee::with('user')
            ->where('joining_date', $thirtyDaysAgo)
            ->where('status', 'onboarding')
            ->get();

        if ($newHires->isEmpty()) {
            $this->info('No 30-day new hire milestones today.');

            return self::SUCCESS;
        }

        $hrAdmins = app(NotificationRecipients::class)->hrQueue();

        foreach ($hrAdmins as $hr) {
            $hr->notify(new NewHireCheckInNotification($newHires));
        }

        $this->info("Notified HR about {$newHires->count()} new hire 30-day milestone(s).");

        return self::SUCCESS;
    }
}
