<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\Payslip;
use App\Models\SalaryCycle;
use App\Observers\EmployeeObserver;
use App\Services\Audit\AuditService;
use App\Services\PayrollService;
use Illuminate\Support\Carbon;

/**
 * Moving an employee between salary cycles (spec §3.5: "the change takes
 * effect from the next cycle start").
 *
 * Each payroll run pays one monthly salary per employee, so the invariant is
 * one payslip per employee per payroll month. An employee who has already
 * been paid is therefore not moved at once: the move waits until the month
 * after the last month they were paid, and the old cycle's last paid day is
 * kept so the first new-cycle run counts absences (LWP) from the next day —
 * none twice (A→B), none skipped (B→A). Someone never paid moves at once.
 */
class SalaryCycleTransitionService
{
    /** salary_cycles.slug → the payroll run key on employees.salary_cycle. */
    public static function keyFor(?int $cycleId): ?string
    {
        if ($cycleId === null) {
            return null;
        }

        return match (SalaryCycle::withTrashed()->whereKey($cycleId)->value('slug')) {
            'cycle-a' => 'cycle_a',
            'cycle-b' => 'cycle_b',
            default => null,
        };
    }

    /**
     * The latest payroll month this employee has a payslip in (any status),
     * and the last day that run's period covered.
     *
     * @return array{month: string, through: Carbon}|null
     */
    public function lastPaid(Employee $employee): ?array
    {
        $latest = Payslip::where('employee_id', $employee->id)
            ->with('payroll')
            ->get()
            ->map(fn (Payslip $payslip) => $payslip->payroll)
            ->filter()
            ->map(fn ($payroll) => [
                'payroll' => $payroll,
                'month' => Carbon::parse("1 {$payroll->month} {$payroll->year}"),
            ])
            ->sortByDesc(fn (array $row) => $row['month']->timestamp)
            ->first();

        if ($latest === null) {
            return null;
        }

        [, $through] = app(PayrollService::class)->resolveCycleDates($latest['payroll']->month, (int) $latest['payroll']->year, $latest['payroll']->cycle);

        return ['month' => $latest['month']->format('Y-m'), 'through' => Carbon::parse($through)->startOfDay()];
    }

    /**
     * Called while an existing employee's salary_cycle_id is being changed.
     * Holds the move as pending when they have already been paid; returns
     * whether it was deferred (the caller then keeps the current cycle).
     */
    public function defer(Employee $employee): bool
    {
        $newId = $employee->salary_cycle_id ? (int) $employee->salary_cycle_id : null;
        $newKey = self::keyFor($newId);
        $currentKey = $employee->getOriginal('salary_cycle');

        // Back to the cycle they are already in: drop any pending move.
        if ($newKey === null || $newKey === $currentKey) {
            $employee->pending_salary_cycle_id = null;

            return false;
        }

        $last = $this->lastPaid($employee);
        if ($last === null) {
            return false;   // never paid: nothing to overlap, move now
        }

        $effective = Carbon::parse($last['month'].'-01')->addMonth()->format('Y-m');

        $employee->salary_cycle_id = $employee->getOriginal('salary_cycle_id');
        $employee->pending_salary_cycle_id = $newId;
        $employee->salary_cycle_effective_month = $effective;
        $employee->salary_cycle_paid_through = $last['through']->toDateString();

        app(AuditService::class)->event('SALARY_CYCLE_CHANGE_SCHEDULED', AuditService::PAYROLL, $employee,
            old: ['salary_cycle' => $currentKey],
            new: ['salary_cycle' => $newKey, 'effective_month' => $effective, 'old_cycle_paid_through' => $last['through']->toDateString()],
            subjectEmployeeId: $employee->id, module: AuditService::EMPLOYEE);

        return true;
    }

    /** Withdraw a pending move (HR kept the employee in their current cycle). */
    public function cancelPending(Employee $employee): void
    {
        if ($employee->pending_salary_cycle_id === null) {
            return;
        }

        $was = self::keyFor((int) $employee->pending_salary_cycle_id);

        EmployeeObserver::$applyingCycleTransition = true;
        try {
            $employee->forceFill([
                'pending_salary_cycle_id' => null,
                'salary_cycle_effective_month' => null,
                'salary_cycle_paid_through' => null,
            ])->save();
        } finally {
            EmployeeObserver::$applyingCycleTransition = false;
        }

        app(AuditService::class)->event('SALARY_CYCLE_CHANGE_CANCELLED', AuditService::PAYROLL, $employee,
            old: ['pending_salary_cycle' => $was], new: ['salary_cycle' => $employee->salary_cycle],
            subjectEmployeeId: $employee->id, module: AuditService::EMPLOYEE);
    }

    /**
     * Apply every pending move due by this payroll month, before a run picks
     * its employees. Returns how many moved.
     */
    public function activateDue(string $monthLabel): int
    {
        $due = Employee::whereNotNull('pending_salary_cycle_id')
            ->where('salary_cycle_effective_month', '<=', $monthLabel)
            ->get();

        foreach ($due as $employee) {
            $from = $employee->salary_cycle;
            $to = self::keyFor((int) $employee->pending_salary_cycle_id);

            EmployeeObserver::$applyingCycleTransition = true;
            try {
                $employee->forceFill([
                    'salary_cycle_id' => $employee->pending_salary_cycle_id,
                    'salary_cycle' => $to ?? $from,
                    'pending_salary_cycle_id' => null,
                ])->save();
            } finally {
                EmployeeObserver::$applyingCycleTransition = false;
            }

            app(AuditService::class)->event('SALARY_CYCLE_CHANGE_APPLIED', AuditService::PAYROLL, $employee,
                old: ['salary_cycle' => $from],
                new: ['salary_cycle' => $to, 'effective_month' => $employee->salary_cycle_effective_month],
                subjectEmployeeId: $employee->id, module: AuditService::EMPLOYEE);
        }

        return $due->count();
    }

    /**
     * The first day absences count from in this run: normally the cycle
     * start; in the employee's first new-cycle month, the day after the old
     * cycle's last paid day.
     */
    public static function absenceWindowStart(Employee $employee, Carbon $cycleStart, string $monthLabel): Carbon
    {
        if ($employee->salary_cycle_effective_month === $monthLabel
            && $employee->pending_salary_cycle_id === null
            && $employee->salary_cycle_paid_through !== null) {
            return Carbon::parse($employee->salary_cycle_paid_through)->addDay()->startOfDay();
        }

        return $cycleStart;
    }
}
