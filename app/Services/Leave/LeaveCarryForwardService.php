<?php

namespace App\Services\Leave;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCarryForwardTransaction as Transaction;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The HR workflow around carrying leave forward.
 *
 * The arithmetic is not here. LeaveCarryOverService already owns
 * "eligible = allocated - used - encashed, capped by policy" and this class
 * calls it rather than repeating it — two implementations of an entitlement
 * calculation is two answers to the same question.
 *
 * What this adds is everything around the number: a record of what was
 * calculated versus what HR approved, so a partial carry-forward keeps its
 * original eligibility; idempotency, so a second click cannot double someone's
 * leave; and reversal that leaves the history standing.
 */
class LeaveCarryForwardService
{
    public function __construct(private readonly LeaveCarryOverService $engine) {}

    /**
     * What carrying forward would do, joined to what has already been decided.
     *
     * The engine supplies the calculation; this adds the decision recorded
     * against each row, so HR sees "eligible 8, applied 5, 3 remaining"
     * rather than being offered the same 8 days again.
     *
     * @param  array{department_id?:int|null, employee_id?:int|null, leave_type_id?:int|null, status?:string|null}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function preview(LeaveYear $from, LeaveYear $to, array $filters = []): Collection
    {
        $existing = Transaction::where('previous_leave_year_id', $from->id)
            ->where('current_leave_year_id', $to->id)
            ->get()
            ->keyBy(fn (Transaction $t) => $t->employee_id.':'.$t->leave_type_id);

        $departmentId = $filters['department_id'] ?? null;
        $employeeIds = $departmentId
            ? Employee::where('department_id', $departmentId)->pluck('id')->flip()
            : null;

        return $this->engine->preview($from, $to)
            ->map(function (array $row) use ($existing) {
                $tx = $existing->get($row['employee_id'].':'.$row['leave_type_id']);

                $row['transaction_id'] = $tx?->id;
                $row['applied'] = $tx ? $tx->netApplied() : 0.0;
                $row['remaining_eligible'] = $tx ? $tx->remainingEligible() : $row['eligible'];
                $row['status'] = $tx?->status ?? Transaction::STATUS_ELIGIBLE;
                $row['applied_by'] = $tx?->appliedBy?->name;
                $row['applied_at'] = $tx?->applied_at;

                return $row;
            })
            ->when($employeeIds !== null, fn (Collection $rows) => $rows->filter(fn ($r) => $employeeIds->has($r['employee_id'])))
            ->when($filters['employee_id'] ?? null, fn (Collection $rows, $id) => $rows->filter(fn ($r) => $r['employee_id'] === (int) $id))
            ->when($filters['leave_type_id'] ?? null, fn (Collection $rows, $id) => $rows->filter(fn ($r) => $r['leave_type_id'] === (int) $id))
            ->when($filters['status'] ?? null, fn (Collection $rows, $s) => $rows->filter(fn ($r) => $r['status'] === $s))
            ->values();
    }

    /**
     * Carry days forward for one employee and leave type.
     *
     * Idempotent by construction: the transaction row is unique per
     * (employee, type, previous year, current year), and the balance is
     * rebuilt from fresh entitlement plus the approved figure rather than
     * incremented. Applying the same 8 days twice leaves 8, not 16.
     *
     * @param  float|null  $days  null carries the full eligible amount; a smaller
     *                            number is a partial carry-forward and keeps the
     *                            original eligibility on the record.
     *
     * @throws RuntimeException when the request exceeds what was calculated
     */
    public function apply(
        Employee $employee,
        LeaveType $type,
        LeaveYear $from,
        LeaveYear $to,
        User $actor,
        ?float $days = null,
        ?string $reason = null,
    ): Transaction {
        $source = $this->engine->preview($from, $to)
            ->first(fn (array $r) => $r['employee_id'] === $employee->id && $r['leave_type_id'] === $type->id);

        if ($source === null) {
            throw new RuntimeException('This employee has nothing to carry forward for '.$type->name.' from '.$from->label.'.');
        }

        $eligible = (float) $source['carry'];
        $applied = $days === null ? $eligible : round((float) $days, 2);

        if ($applied < 0) {
            throw new RuntimeException('Carry forward days cannot be negative.');
        }

        if ($applied > $eligible) {
            throw new RuntimeException("Cannot carry forward {$applied} days — only {$eligible} are eligible.");
        }

        return DB::transaction(function () use ($employee, $type, $from, $to, $actor, $source, $eligible, $applied, $reason) {
            $tx = Transaction::firstOrNew([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'previous_leave_year_id' => $from->id,
                'current_leave_year_id' => $to->id,
            ]);

            $before = $this->currentCarried($employee, $type, $to);

            $tx->fill([
                'previous_allocated_days' => $source['allocated'],
                'previous_used_days' => $source['used'],
                'previous_encashed_days' => $source['encashed'],
                'eligible_days' => $eligible,
                'applied_days' => $applied,
                // Re-applying supersedes any earlier reversal rather than
                // leaving a stale figure that no longer describes the balance.
                'reversed_days' => 0,
                'reversed_by' => null,
                'reversed_at' => null,
                'reversal_reason' => null,
                'reason' => $reason,
                'applied_by' => $actor->id,
                'applied_at' => now(),
            ]);
            $tx->status = $tx->deriveStatus();
            $tx->save();

            $after = $this->writeBalance($employee, $type, $to, $applied);

            $this->audit($tx, 'leave.carry_forward_applied', $before, $after, $actor, [
                'eligible_days' => $eligible,
                'applied_days' => $applied,
                'partial' => $applied < $eligible,
                'reason' => $reason,
            ]);

            return $tx;
        });
    }

    /**
     * Undo a carry-forward without erasing that it happened.
     *
     * The row stays, gains a reversal, and the balance drops back to its fresh
     * entitlement. Deleting the record instead would leave the employee's
     * balance changed with nothing to explain it.
     */
    public function reverse(Transaction $tx, User $actor, string $reason): Transaction
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A reversal needs a reason.');
        }

        if (! $tx->isApplied()) {
            throw new RuntimeException('Only an applied carry forward can be reversed.');
        }

        return DB::transaction(function () use ($tx, $actor, $reason) {
            $employee = $tx->employee;
            $type = $tx->leaveType;
            $year = $tx->currentLeaveYear;

            $before = $this->currentCarried($employee, $type, $year);

            $tx->reversed_days = $tx->applied_days;
            $tx->reversed_by = $actor->id;
            $tx->reversed_at = now();
            $tx->reversal_reason = $reason;
            $tx->status = $tx->deriveStatus();
            $tx->save();

            $after = $this->writeBalance($employee, $type, $year, 0.0);

            $this->audit($tx, 'leave.carry_forward_reversed', $before, $after, $actor, [
                'reversed_days' => (float) $tx->reversed_days,
                'reason' => $reason,
            ]);

            return $tx;
        });
    }

    /**
     * Apply every eligible row in one go, skipping anything already at its
     * approved figure. Returns what changed.
     *
     * @return array{applied:int, skipped:int, days:float}
     */
    public function applyAll(LeaveYear $from, LeaveYear $to, User $actor, array $filters = []): array
    {
        $applied = 0;
        $skipped = 0;
        $days = 0.0;

        foreach ($this->preview($from, $to, $filters) as $row) {
            // Already carried at the full eligible amount: nothing to do, and
            // re-applying would only rewrite applied_at.
            if ($row['status'] === Transaction::STATUS_APPLIED) {
                $skipped++;

                continue;
            }

            $employee = Employee::find($row['employee_id']);
            $type = LeaveType::find($row['leave_type_id']);

            if (! $employee || ! $type) {
                $skipped++;

                continue;
            }

            $tx = $this->apply($employee, $type, $from, $to, $actor);
            $applied++;
            $days += $tx->netApplied();
        }

        return ['applied' => $applied, 'skipped' => $skipped, 'days' => round($days, 2)];
    }

    /**
     * Where an employee's carried days came from, for the balance drill-down.
     *
     * @return Collection<int, Transaction>
     */
    public function historyFor(Employee $employee, ?LeaveYear $year = null): Collection
    {
        return Transaction::with(['leaveType', 'previousLeaveYear', 'currentLeaveYear', 'appliedBy', 'reversedBy'])
            ->where('employee_id', $employee->id)
            ->when($year, fn ($q) => $q->where('current_leave_year_id', $year->id))
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Rebuild the balance from fresh entitlement plus the approved figure.
     *
     * Never incremental. The whole reason carry-over went wrong before was
     * arithmetic that added to whatever was already there, so a second run
     * compounded. Recomputing from the entitlement converges instead.
     */
    private function writeBalance(Employee $employee, LeaveType $type, LeaveYear $year, float $carried): float
    {
        $balance = LeaveBalance::firstOrNew([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => $year->legacyYear(),
        ]);

        $fresh = (float) ($balance->allocated_days ?? 0) - (float) ($balance->carried_forward_days ?? 0);

        $balance->leave_year_id = $year->id;
        $balance->allocated_days = round($fresh + $carried, 2);
        $balance->carried_forward_days = round($carried, 2);
        $balance->used_days = (float) ($balance->used_days ?? 0);
        $balance->encashed_days = (float) ($balance->encashed_days ?? 0);
        $balance->comp_off_credits = (float) ($balance->comp_off_credits ?? 0);
        $balance->save();

        return (float) $balance->carried_forward_days;
    }

    private function currentCarried(Employee $employee, LeaveType $type, LeaveYear $year): float
    {
        return (float) (LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $year->legacyYear())
            ->value('carried_forward_days') ?? 0);
    }

    /**
     * Both sides of the change, not just where it ended up: a final balance
     * alone cannot answer "what did this action do".
     *
     * @param  array<string, mixed>  $extra
     */
    private function audit(Transaction $tx, string $action, float $before, float $after, User $actor, array $extra): void
    {
        AuditLog::record(
            $tx,
            $action,
            ['carried_forward_days' => $before],
            array_merge([
                'carried_forward_days' => $after,
                'employee_id' => $tx->employee_id,
                'leave_type_id' => $tx->leave_type_id,
                'previous_leave_year' => $tx->previousLeaveYear?->label,
                'current_leave_year' => $tx->currentLeaveYear?->label,
                'actor_id' => $actor->id,
                'status' => $tx->status,
            ], $extra),
            $extra['reason'] ?? null,
            $tx->employee_id,
        );
    }
}
