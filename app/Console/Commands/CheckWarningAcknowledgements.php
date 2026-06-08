<?php

namespace App\Console\Commands;

use App\Models\WarningLetter;
use App\Notifications\WarningAcknowledgementReminderNotification;
use Illuminate\Console\Command;

class CheckWarningAcknowledgements extends Command
{
    protected $signature = 'hrms:check-warning-acknowledgements';

    protected $description = 'Send acknowledgement reminders for issued warnings older than 48 hours that have not been acknowledged.';

    public function handle(): int
    {
        $warnings = WarningLetter::with(['employee.user'])
            ->where('status', 'issued')
            ->where('issue_date', '<=', now()->subHours(48)->toDateString())
            ->whereDoesntHave('acknowledgements')
            ->get();

        if ($warnings->isEmpty()) {
            $this->info('No unacknowledged warnings requiring reminders.');

            return self::SUCCESS;
        }

        $notified = 0;

        foreach ($warnings as $warning) {
            $user = $warning->employee->user ?? null;

            if (! $user) {
                continue;
            }

            $user->notify(new WarningAcknowledgementReminderNotification($warning));
            $notified++;
        }

        $this->info("Sent {$notified} acknowledgement reminder(s).");

        return self::SUCCESS;
    }
}
