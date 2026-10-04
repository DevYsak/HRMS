<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\ProbationDueNotification;
use App\Services\Notifications\NotificationRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Spec §7 — notify the manager + HR Admin 10 days before probation ends.
 *
 * The end date is the employee's recorded probation_end_date (which already
 * reflects any extension), falling back to joining + 90 days (spec §3.8 —
 * 90-day probation review) only when none is recorded. Each employee is
 * announced once per end date (probation_reminder_sent_for), so the daily
 * run does not repeat itself for 10 days, and an extension re-announces.
 */
class CheckProbationDue extends Command
{
    /** Spec §7: 10 days before probation end. */
    private const WINDOW_DAYS = 10;

    private const DEFAULT_PROBATION_DAYS = 90;

    protected $signature = 'hrms:check-probation-due';

    protected $description = 'Notify the manager and HR admins once when an employee\'s probation ends within 10 days.';

    public function handle(): int
    {
        $today = now()->startOfDay();
        $windowEnd = $today->copy()->addDays(self::WINDOW_DAYS);

        $due = Employee::with(['user', 'manager'])
            ->where('status', 'probation')
            ->whereNull('probation_confirmed_at')
            ->get()
            ->map(fn (Employee $e) => [$e, $this->endDate($e)])
            ->filter(fn (array $pair) => $pair[1] !== null
                && $pair[1]->betweenIncluded($today, $windowEnd)
                && ! $pair[0]->probation_reminder_sent_for?->isSameDay($pair[1]));

        if ($due->isEmpty()) {
            $this->info('No probation reviews due within '.self::WINDOW_DAYS.' days.');

            return self::SUCCESS;
        }

        $hrQueue = app(NotificationRecipients::class)->hrQueue();

        // HR gets one digest of everyone due; each manager hears about their own people.
        $hrQueue->each(fn (User $hr) => $hr->notify(new ProbationDueNotification($due->map(fn ($pair) => $pair[0])->values())));

        $due->groupBy(fn ($pair) => $pair[0]->manager_id)
            ->each(function (Collection $pairs, $managerId) use ($hrQueue) {
                $manager = $pairs->first()[0]->manager;

                if ($manager && ! $hrQueue->contains('id', $manager->id)) {
                    $manager->notify(new ProbationDueNotification($pairs->map(fn ($pair) => $pair[0])->values()));
                }
            });

        foreach ($due as [$employee, $endDate]) {
            Employee::withoutEvents(fn () => $employee->forceFill(['probation_reminder_sent_for' => $endDate->toDateString()])->save());
        }

        $this->info("Notified manager + HR about {$due->count()} probation review(s) due soon.");

        return self::SUCCESS;
    }

    private function endDate(Employee $employee): ?Carbon
    {
        if ($employee->probation_end_date) {
            return Carbon::parse($employee->probation_end_date)->startOfDay();
        }

        return $employee->joining_date
            ? Carbon::parse($employee->joining_date)->addDays(self::DEFAULT_PROBATION_DAYS)->startOfDay()
            : null;
    }
}
