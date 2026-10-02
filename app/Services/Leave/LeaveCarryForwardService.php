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
        $source = $this->engine->preview($from, $to, $employee->id)
            ->first(fn (array $r) => $r['employee_id'] === $employee->id && $r['leave_type_id'] === $type->id);

        if ($source === null) {
            throw new RuntimeException('This employee has nothing to carry forward for '.$type->name.' from '.$from->label.'.');
        }

        $figuresKnown = $source['figures_known'] ?? true;

        // With no usage figure for the closed year there is nothing to derive
        // an eligible amount from, so HR states the amount and the ceiling is
        // the recorded closing balance rather than a calculation.
        if (! $figuresKnown) {
            if ($days === null) {
                throw new RuntimeException(
                    'Historical used days are not available for '.$type->name.' in '.$from->label
                    .'. Enter the carry-forward days HR has approved — the system cannot derive them.'
                );
            }

            $applied = round((float) $days, 2);
            $ceiling = (float) $source['closing_balance'];

            if ($applied < 0) {
                throw new RuntimeException('Carry forward days cannot be negative.');
            }

            if ($applied > $ceiling) {
                throw new RuntimeException("Cannot carry forward {$applied} days — the recorded {$from->label} balance is {$ceiling}.");
            }

            // Nothing was calculated, so eligible_days stays null: a number
            // there would claim an entitlement the inputs never supported.
            // The ceiling is recorded in its own field, as what HR was told
            // the year closed at.
            $eligible = null;
            $closingBalance = $ceiling;
        } else {
            $closingBalance = $source['closing_balance'] ?? null;
            $eligible = (float) $source['carry'];
            $applied = $days === null ? $eligible : round((float) $days, 2);

            if ($applied < 0) {
                throw new RuntimeException('Carry forward days cannot be negative.');
            }

            if ($applied > $eligible) {
                throw new RuntimeException("Cannot carry forward {$applied} days — only {$eligible} are eligible.");
            }
        }

        return DB::transaction(function () use ($employee, $type, $from, $to, $actor, $source, $eligible, $applied, $reason, $figuresKnown, $closingBalance) {
            $tx = Transaction::firstOrNew([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'previous_leave_year_id' => $from->id,
                'current_leave_year_id' => $to->id,
            ]);

            $before = $this->currentCarried($employee, $type, $to);

            $tx->fill([
                'previous_allocated_days' => $source['allocated'],
                // An unrecorded figure is stored as unknown, never as zero:
                // zero would say the employee took no leave that year.
                'previous_used_days' => $figuresKnown ? $source['used'] : 0,
                'previous_encashed_days' => $figuresKnown ? $source['encashed'] : 0,
                'used_status' => $figuresKnown ? Transaction::FIGURE_KNOWN : Transaction::FIGURE_UNKNOWN,
                'encashed_status' => $figuresKnown ? Transaction::FIGURE_KNOWN : Transaction::FIGURE_UNKNOWN,
                'historical_closing_balance' => $closingBalance,
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

            $after = $this->writeBalance($employee, $type, $to, $applied, $tx->id, $actor);

            $this->audit($tx, 'leave.carry_forward_applied', $before, $after, $actor, [
                'eligible_days' => $eligible,
                'applied_days' => $applied,
                'partial' => $applied < $eligible,
                // The audit must never imply the system calculated an
                // entitlement it could not calculate.
                'historical_figures_known' => $figuresKnown,
                'carry_forward_decided_by' => $figuresKnown ? 'calculated' : 'hr_approved',
                'reason' => $reason,
            ]);

            return $tx;
        });
    }

    /**
     * What HR may carry forward for one employee and leave type, for the
     * Carry Forward action on the employee's leave screen.
     *
     *  - source_found: the previous year has a balance in this system;
     *  - figures_known: its usage is recorded, so an eligible amount (closing
     *    balance capped by the policy) can be calculated;
     *  - max_allowed: the ceiling HR may enter — the calculated eligible
     *    amount, the recorded closing balance when usage is unknown, or the
     *    policy cap when the previous year was never kept here (null =
     *    no ceiling: HR states the figure and owns it, with a reason).
     *
     * @return array{carryable: bool, message: ?string, source_found: bool, figures_known: bool, closing_balance: ?float,
     *     allocated: ?float, used: ?float, encashed: ?float, eligible: ?float, cap: ?float, max_allowed: ?float,
     *     already_applied: float, transaction: ?Transaction, to_year_closed: bool}
     */
    public function eligibilityFor(Employee $employee, LeaveType $type, LeaveYear $from, LeaveYear $to): array
    {
        $settings = app(LeaveRuleResolver::class)->settings($employee, $type);
        $carryable = $settings['carry_forward_by_rule'] || ($type->allow_carry_forward && $type->permitsCarryForward());

        $tx = Transaction::where('employee_id', $employee->id)->where('leave_type_id', $type->id)
            ->where('previous_leave_year_id', $from->id)->where('current_leave_year_id', $to->id)->first();

        $source = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)
            ->where(fn ($q) => $q->where('leave_year_id', $from->id)
                ->orWhere(fn ($w) => $w->whereNull('leave_year_id')->where('year', $from->legacyYear())))
            ->first();

        $row = $source ? $this->engine->preview($from, $to, $employee->id)->firstWhere('leave_type_id', $type->id) : null;
        $figuresKnown = $source ? (! $source->used_days_unknown && ! $source->encashed_days_unknown) : false;
        $closing = $source ? round((float) $source->allocated_days - (float) $source->used_days - (float) ($source->encashed_days ?? 0), 2) : null;
        $eligible = $source && $figuresKnown ? (float) ($row['carry'] ?? 0.0) : null;

        $maxAllowed = match (true) {
            ! $source => $settings['carry_forward_max_days'],
            $figuresKnown => $eligible,
            default => max(0.0, (float) $source->allocated_days),
        };

        $message = match (true) {
            ! $carryable => "Carry forward is switched off for {$type->name}.",
            $to->isClosed() => "{$to->label} is closed.",
            ! $source => "No {$from->label} balance is held in this system. Enter the carry forward HR has approved; the reason is required.",
            ! $figuresKnown => "{$from->label} usage was never recorded. Enter the approved amount, up to the recorded balance.",
            default => null,
        };

        return [
            'carryable' => $carryable,
            'message' => $message,
            'source_found' => $source !== null,
            'figures_known' => $figuresKnown,
            'closing_balance' => $closing,
            'allocated' => $source ? (float) $source->allocated_days : null,
            'used' => $source && ! $source->used_days_unknown ? (float) $source->used_days : null,
            'encashed' => $source && ! $source->encashed_days_unknown ? (float) ($source->encashed_days ?? 0) : null,
            'eligible' => $eligible,
            'cap' => $settings['carry_forward_max_days'],
            'max_allowed' => $maxAllowed !== null ? round((float) $maxAllowed, 2) : null,
            'already_applied' => $tx ? $tx->netApplied() : 0.0,
            'transaction' => $tx,
            'to_year_closed' => $to->isClosed(),
        ];
    }

    /**
     * HR enters an employee's carry forward directly (employee leave screen).
     *
     * Not a manual balance adjustment: it is recorded as a carry-forward
     * transaction (from year, to year, eligible, applied, reason, who),
     * posted to the new year as its own CARRY_FORWARD lot with the policy
     * expiry, and audited. Re-entering replaces the earlier figure rather
     * than adding to it. Works even when the previous year was never kept in
     * this system — then HR's stated figure is recorded as such.
     */
    public function applyForEmployee(Employee $employee, LeaveType $type, LeaveYear $from, LeaveYear $to, float $days, string $reason, User $actor): Transaction
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A carry forward entered by HR needs a reason.');
        }

        if ($from->starts_on->gte($to->starts_on)) {
            throw new RuntimeException('The "from" leave year must be before the "to" leave year.');
        }

        $days = round($days, 2);
        $info = $this->eligibilityFor($employee, $type, $from, $to);

        if (! $info['carryable']) {
            throw new RuntimeException($info['message']);
        }

        if ($info['to_year_closed']) {
            throw new RuntimeException("{$to->label} is closed; its balances can only change through an authorised historical correction.");
        }

        if ($days < 0) {
            throw new RuntimeException('Carry forward days cannot be negative.');
        }

        if ($info['max_allowed'] !== null && $days > $info['max_allowed'] + 0.001) {
            throw new RuntimeException("Cannot carry forward {$days} day(s): the most allowed is {$info['max_allowed']}.");
        }

        if ($info['source_found']) {
            return $this->apply($employee, $type, $from, $to, $actor, $days, $reason);
        }

        // No previous-year record in this system: HR states the figure.
        return DB::transaction(function () use ($employee, $type, $from, $to, $actor, $days, $reason) {
            $tx = Transaction::firstOrNew([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'previous_leave_year_id' => $from->id,
                'current_leave_year_id' => $to->id,
            ]);

            $before = $this->currentCarried($employee, $type, $to);

            $tx->fill([
                'previous_allocated_days' => 0,
                'previous_used_days' => 0,
                'previous_encashed_days' => 0,
                // Nothing about that year is known here; never recorded as zero.
                'used_status' => Transaction::FIGURE_UNKNOWN,
                'encashed_status' => Transaction::FIGURE_UNKNOWN,
                'historical_closing_balance' => null,
                'eligible_days' => null,
                'applied_days' => $days,
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

            $after = $this->writeBalance($employee, $type, $to, $days, $tx->id, $actor);

            $this->audit($tx, 'leave.carry_forward_applied', $before, $after, $actor, [
                'eligible_days' => null,
                'applied_days' => $days,
                'historical_figures_known' => false,
                'carry_forward_decided_by' => 'hr_stated_no_previous_year_record',
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

            $after = $this->writeBalance($employee, $type, $year, 0.0, $tx->id, $actor);

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

            // No usage figure means no derivable amount. Bulk apply must not
            // guess one — these rows need HR to decide, one at a time.
            if (($row['figures_known'] ?? true) === false) {
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
     * Apply a set of decisions HR has made, each with its own amount.
     *
     * This is the path for the years we are migrating: their usage was never
     * recorded, so no amount can be derived and applyAll() rightly refuses
     * them. Here HR supplies the figure per employee — including zero, which
     * is a decision to carry nothing and is recorded as such rather than
     * skipped.
     *
     * One decision failing does not discard the rest: each is attempted on its
     * own and its error reported against its row, because a single bad figure
     * in a hundred should not cost HR the other ninety-nine.
     *
     * @param  array<int, array{employee_id:int, leave_type_id:int, days:float}>  $decisions
     * @return array{applied:int, failed:int, days:float, errors:array<int, string>}
     */
    public function applyDecisions(
        LeaveYear $from,
        LeaveYear $to,
        array $decisions,
        User $actor,
        ?string $reason = null,
    ): array {
        $applied = 0;
        $failed = 0;
        $days = 0.0;
        $errors = [];

        foreach ($decisions as $decision) {
            $employee = Employee::find($decision['employee_id'] ?? null);
            $type = LeaveType::find($decision['leave_type_id'] ?? null);

            if (! $employee || ! $type) {
                $failed++;
                $errors[] = 'Unknown employee or leave type in the selection.';

                continue;
            }

            $label = ($employee->user?->name ?? 'Employee #'.$employee->id).' / '.$type->name;

            try {
                $tx = $this->apply($employee, $type, $from, $to, $actor, (float) ($decision['days'] ?? 0), $reason);
                $applied++;
                $days += $tx->netApplied();
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = $label.': '.$e->getMessage();
            }
        }

        return ['applied' => $applied, 'failed' => $failed, 'days' => round($days, 2), 'errors' => $errors];
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
    private function writeBalance(Employee $employee, LeaveType $type, LeaveYear $year, float $carried, int $transactionId, ?User $actor = null): float
    {
        $balance = LeaveBalance::firstOrCreate(
            ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year->legacyYear()],
            [
                'leave_year_id' => $year->id,
                'allocated_days' => 0, 'used_days' => 0, 'carried_forward_days' => 0,
                'encashed_days' => 0, 'comp_off_credits' => 0,
            ],
        );

        // A ledger-backed target gets a carry-forward credit lot (traceable to
        // this transaction, with its policy expiry); a legacy row keeps the
        // non-incremental column arithmetic.
        $balance = app(LeaveMovementService::class)->setCarriedForward($balance, $carried, $transactionId, $actor);

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
