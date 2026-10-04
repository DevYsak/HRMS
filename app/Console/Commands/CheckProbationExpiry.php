<?php

namespace App\Console\Commands;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\ProbationDueNotification;
use App\Services\Notifications\NotificationRecipients;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hrms:check-probation-expiry')]
#[Description('Notify HR of employees whose probation period is overdue and not yet confirmed')]
class CheckProbationExpiry extends Command
{
    public function handle(): int
    {
        // Overdue only — "due within 10 days" is hrms:check-probation-due's job
        // (manager + HR, once per end date); doing it here too sent it twice.
        $overdue = Employee::with('user')
            ->where('status', EmployeeStatus::Probation)
            ->whereNotNull('probation_end_date')
            ->whereDate('probation_end_date', '<', now())
            ->whereNull('probation_confirmed_at')
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('No overdue probation reviews.');

            return self::SUCCESS;
        }

        // The shared HR queue, deliberately: a probation falling due is HR's as a
        // function, not one named person's. One digest each — the notification
        // takes a collection (passing a single Employee threw a TypeError).
        app(NotificationRecipients::class)->hrQueue()
            ->each(fn (User $hr) => $hr->notify(new ProbationDueNotification($overdue, 'overdue')));

        $this->info("Notified HR: {$overdue->count()} overdue probation review(s).");

        return self::SUCCESS;
    }
}
