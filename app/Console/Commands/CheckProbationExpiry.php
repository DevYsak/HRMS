<?php

namespace App\Console\Commands;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\ProbationDueNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hrms:check-probation-expiry {--days=10 : Warn when probation ends within this many days}')]
#[Description('Notify HR of employees whose probation period is ending soon or is overdue')]
class CheckProbationExpiry extends Command
{
    public function handle(): int
    {
        $warnDays = (int) $this->option('days');

        // Overdue — probation_end_date has passed, not yet confirmed
        $overdue = Employee::where('status', EmployeeStatus::Probation)
            ->whereNotNull('probation_end_date')
            ->whereDate('probation_end_date', '<', now())
            ->whereNull('probation_confirmed_at')
            ->get();

        // Due soon
        $dueSoon = Employee::where('status', EmployeeStatus::Probation)
            ->whereNotNull('probation_end_date')
            ->whereDate('probation_end_date', '>=', now())
            ->whereDate('probation_end_date', '<=', now()->addDays($warnDays))
            ->whereNull('probation_confirmed_at')
            ->get();

        $total = $overdue->count() + $dueSoon->count();

        if ($total === 0) {
            $this->info('No probation reviews require attention.');

            return self::SUCCESS;
        }

        // Notify all HR admins and super admins
        $recipients = User::whereIn('role', ['hr_admin', 'super_admin'])->get();

        foreach ($overdue->merge($dueSoon) as $employee) {
            $recipients->each(fn (User $hr) => $hr->notify(new ProbationDueNotification($employee)));
        }

        $this->info("Notified HR: {$overdue->count()} overdue, {$dueSoon->count()} due within {$warnDays} days.");

        return self::SUCCESS;
    }
}
