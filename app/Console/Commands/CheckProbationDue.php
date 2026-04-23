<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\ProbationDueNotification;
use Illuminate\Console\Command;

class CheckProbationDue extends Command
{
    protected $signature   = 'hrms:check-probation-due';
    protected $description = 'Notify HR admins of employees whose probation review is due within 10 days.';

    public function handle(): int
    {
        // Probation period = 90 days from joining_date.
        // Flag employees whose (joining_date + 90 days) falls within the next 10 days.
        $windowStart = now()->toDateString();
        $windowEnd   = now()->addDays(10)->toDateString();

        $probationCutoffStart = now()->subDays(90)->toDateString();
        $probationCutoffEnd   = now()->subDays(80)->toDateString(); // 90-10=80

        $due = Employee::with('user')
            ->where('status', 'probation')
            ->whereBetween('joining_date', [$probationCutoffStart, $probationCutoffEnd])
            ->get();

        if ($due->isEmpty()) {
            $this->info('No probation reviews due within 10 days.');

            return self::SUCCESS;
        }

        $hrAdmins = User::whereIn('role', ['super_admin', 'hr_admin'])->get();

        foreach ($hrAdmins as $hr) {
            $hr->notify(new ProbationDueNotification($due));
        }

        $this->info("Notified HR about {$due->count()} probation review(s) due soon.");

        return self::SUCCESS;
    }
}
