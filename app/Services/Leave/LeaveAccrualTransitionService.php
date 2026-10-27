<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveAccrualTransition;
use App\Models\User;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Audit\AuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Monthly Accrual Starts From": when automatic Earned Leave accrual takes
 * over for an employee who already holds an Earned Leave balance.
 *
 * Setting it changes no balance and no ledger entry: the balance held before
 * stays as it is, and the accrual engine credits only completed months from
 * the 1st of that month on. Only HR who may correct leave balances, in
 * scope, never for themselves — and every change is audited.
 */
class LeaveAccrualTransitionService
{
    public const PERMISSION = 'correct_leave_balance';

    public function startsOn(Employee $employee): ?Carbon
    {
        $date = LeaveAccrualTransition::where('employee_id', $employee->id)->value('starts_on');

        return $date ? Carbon::parse($date)->startOfDay() : null;
    }

    public function mayChange(User $user, Employee $employee): bool
    {
        try {
            $this->authorise($user, $employee);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /** Set (or move) the date. It must be the 1st of a month. */
    public function set(Employee $employee, Carbon|string $startsOn, string $reason, User $actor): LeaveAccrualTransition
    {
        $this->authorise($actor, $employee);
        $startsOn = Carbon::parse($startsOn)->startOfDay();
        if ($startsOn->day !== 1) {
            throw new \DomainException('Monthly accrual starts on the 1st of a month.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new \DomainException('Give a reason of at least 5 characters.');
        }

        return DB::transaction(function () use ($employee, $startsOn, $reason, $actor) {
            $transition = LeaveAccrualTransition::lockForUpdate()->firstOrNew(['employee_id' => $employee->id]);
            $before = $transition->exists ? $transition->starts_on->toDateString() : null;
            $transition->fill(['starts_on' => $startsOn->toDateString(), 'reason' => $reason, 'set_by' => $actor->id])->save();

            app(AuditService::class)->event('LEAVE_ACCRUAL_START_SET', AuditService::LEAVE, $transition,
                old: ['monthly_accrual_starts_from' => $before],
                new: ['monthly_accrual_starts_from' => $startsOn->toDateString()],
                reason: $reason, subjectEmployeeId: $employee->id, actor: $actor);

            return $transition;
        });
    }

    /** Remove the date: the employee goes back to NEEDS REVIEW if they hold an earlier balance. */
    public function clear(Employee $employee, string $reason, User $actor): void
    {
        $this->authorise($actor, $employee);

        DB::transaction(function () use ($employee, $reason, $actor) {
            $transition = LeaveAccrualTransition::lockForUpdate()->where('employee_id', $employee->id)->first();
            if ($transition === null) {
                return;
            }

            app(AuditService::class)->event('LEAVE_ACCRUAL_START_CLEARED', AuditService::LEAVE, $transition,
                old: ['monthly_accrual_starts_from' => $transition->starts_on->toDateString()], new: ['monthly_accrual_starts_from' => null],
                reason: trim($reason) ?: null, subjectEmployeeId: $employee->id, actor: $actor);
            $transition->delete();
        });
    }

    /** @throws AuthorizationException */
    private function authorise(User $user, Employee $employee): void
    {
        if (! $user->hasPermission(self::PERMISSION)) {
            throw new AuthorizationException('You do not have permission to change when monthly accrual starts.');
        }

        // Never one's own; only employees in reach.
        app(ApprovalGuard::class)->assertCanDecide($user, $employee);
    }
}
