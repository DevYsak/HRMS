<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\EmployeeStatusChangedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EmployeeLifecycleService
{
    /** Transition an employee to a new status, setting lifecycle date fields automatically. */
    public function transition(Employee $employee, EmployeeStatus $newStatus, User $actor, ?string $note = null): void
    {
        $current = $employee->status;

        if (! $current->canTransitionTo($newStatus)) {
            throw new \DomainException(
                "Cannot transition employee from [{$current->label()}] to [{$newStatus->label()}]."
            );
        }

        DB::transaction(function () use ($employee, $newStatus, $actor, $note) {
            $updates = ['status' => $newStatus];

            // Stamp the relevant lifecycle date field on transition
            $updates = array_merge($updates, match ($newStatus) {
                EmployeeStatus::NoticePeriod => ['resignation_date' => now()->toDateString()],
                EmployeeStatus::Resigned => ['resignation_date' => $employee->resignation_date ?? now()->toDateString()],
                EmployeeStatus::Terminated => ['termination_date' => now()->toDateString()],
                EmployeeStatus::Absconded => ['absconded_at' => now()],
                EmployeeStatus::Confirmed => ['confirmed_at' => now()],
                EmployeeStatus::Archived => ['archived_at' => now()],
                default => [],
            });

            $employee->update($updates);

            $employee->user->notify(
                new EmployeeStatusChangedNotification($employee->fresh(), $current, $newStatus, $actor, $note)
            );
        });
    }

    /** Place an employee on notice period, setting the end date from their employment type. */
    public function initiateNoticePeriod(Employee $employee, User $actor, ?string $resignationDate = null): void
    {
        $days = $employee->employmentType?->notice_period_days ?? 30;
        $startDate = $resignationDate ? Carbon::parse($resignationDate) : now();
        $endDate = $startDate->copy()->addDays($days);

        DB::transaction(function () use ($employee, $actor, $startDate, $endDate) {
            $employee->update([
                'status' => EmployeeStatus::NoticePeriod,
                'resignation_date' => $startDate->toDateString(),
                'notice_period_end_date' => $endDate->toDateString(),
            ]);

            $employee->user->notify(
                new EmployeeStatusChangedNotification(
                    $employee->fresh(),
                    EmployeeStatus::Active,
                    EmployeeStatus::NoticePeriod,
                    $actor
                )
            );
        });
    }

    /** Archive an employee — final terminal state. */
    public function archive(Employee $employee, User $actor): void
    {
        if (! $employee->status->isExited() && $employee->status !== EmployeeStatus::Inactive) {
            throw new \DomainException('Only exited or inactive employees can be archived.');
        }

        $employee->update([
            'status' => EmployeeStatus::Archived,
            'archived_at' => now(),
        ]);
    }

    /** Automatically process lifecycle transitions that are date-driven (called by scheduler). */
    public function processDueTransitions(): int
    {
        $count = 0;

        // Probation → Active: probation_end_date passed, no confirmation pending
        Employee::where('status', EmployeeStatus::Probation)
            ->whereNotNull('probation_end_date')
            ->whereDate('probation_end_date', '<', now())
            ->whereNull('probation_confirmed_at')
            ->each(function (Employee $employee) use (&$count) {
                // Do not auto-confirm — just flag as overdue via notification
                // The ProbationEngine handles the actual confirmation flow
                $count++;
            });

        // Notice Period → Resigned: notice_period_end_date passed
        Employee::where('status', EmployeeStatus::NoticePeriod)
            ->whereNotNull('notice_period_end_date')
            ->whereDate('notice_period_end_date', '<=', now())
            ->each(function (Employee $employee) use (&$count) {
                $employee->update([
                    'status' => EmployeeStatus::Resigned,
                    'resignation_date' => $employee->resignation_date ?? now()->toDateString(),
                ]);
                $count++;
            });

        return $count;
    }
}
