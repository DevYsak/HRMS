<?php

namespace App\Services\Leave;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBulkRun;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveRolloverRecord;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The year-end rollover (Phase 2C): 30 June closes, 1 July opens.
 *
 * For every balance of the closing year it works out
 *
 *     closing  = approved available at year end (never floored)
 *     carry    = min(closing, cap days, cap %, room under the max balance)
 *     expire   = closing − carry
 *
 * and classifies the row:
 *
 *   SAFE              everything is known and the type carries forward
 *                     automatically (or not at all) — processed without HR;
 *   NEEDS_HR_REVIEW   pending requests in the closing year, an overdrawn or
 *                     unmigrated/ambiguous balance, unknown usage, or a type
 *                     whose carry forward is an HR decision (hr_approval);
 *   BLOCKED           the row cannot be read (missing employee/type).
 *
 * Processing a SAFE row: carry forward through the existing carry-forward
 * workflow (a CARRY_FORWARD lot in the new year, with its expiry), post the
 * unused remainder of the closing year as EXPIRY, and provision the new
 * year's BASE entitlement separately — carry forward never stands in for it.
 * Each (employee, type, closing year) has one LeaveRolloverRecord; once
 * processed it is never processed again, so the rollover is safe to retry.
 */
class LeaveRolloverService
{
    public const SAFE = 'SAFE';

    public const NEEDS_HR_REVIEW = 'NEEDS_HR_REVIEW';

    public const BLOCKED = 'BLOCKED';

    public const PROCESSED = 'PROCESSED';

    private const EPSILON = 0.005;

    public function __construct(
        private readonly LeaveRuleResolver $rules,
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeaveLedgerService $ledger,
        private readonly LeaveLedgerBackfillService $backfill,
        private readonly EnsureEmployeeLeaveBalancesService $ensure,
        private readonly LeaveCarryForwardService $carryForward,
        private readonly LeaveYearResolver $years,
    ) {}

    /**
     * Every closing-year balance with what the rollover would do to it.
     *
     * @param  array{employee_id?: int|null, leave_type_id?: int|null, department_id?: int|null}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function preview(LeaveYear $from, ?LeaveYear $to = null, array $filters = []): Collection
    {
        $to ??= $this->years->next($from);

        $records = LeaveRolloverRecord::where('from_leave_year_id', $from->id)->get()
            ->keyBy(fn (LeaveRolloverRecord $r) => $r->employee_id.':'.$r->leave_type_id);

        return LeaveBalance::with(['employee.user', 'employee.department', 'employee.leavePolicy', 'leaveType'])
            ->where(fn ($q) => $q->where('leave_year_id', $from->id)->orWhere(fn ($w) => $w->whereNull('leave_year_id')->where('year', $from->legacyYear())))
            ->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($filters['leave_type_id'] ?? null, fn ($q, $id) => $q->where('leave_type_id', $id))
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $id)))
            ->orderBy('employee_id')->orderBy('leave_type_id')
            ->get()
            ->map(fn (LeaveBalance $b) => $this->row($b, $from, $to, $records->get($b->employee_id.':'.$b->leave_type_id)))
            ->values();
    }

    /**
     * Process the SAFE rows (and, when given, only the selected ones).
     * Ambiguous rows are recorded as NEEDS_HR_REVIEW, never guessed at.
     *
     * @param  array<int, int>|null  $balanceIds
     * @return array{run: LeaveBulkRun, processed: int, skipped: int, needs_review: int, failed: int, already: int, provisioned: int, errors: array<int, string>}
     */
    public function process(LeaveYear $from, ?LeaveYear $to, ?User $actor, ?array $balanceIds = null, string $trigger = 'manual'): array
    {
        $to ??= $this->years->next($from);

        $run = LeaveBulkRun::create([
            'kind' => LeaveBulkRun::KIND_ROLLOVER, 'leave_year_id' => $from->id, 'target_leave_year_id' => $to->id,
            'dry_run' => false, 'status' => 'running', 'created_by' => $actor?->id,
            'parameters' => ['trigger' => $trigger, 'selected' => $balanceIds],
        ]);

        $result = ['processed' => 0, 'skipped' => 0, 'needs_review' => 0, 'failed' => 0, 'already' => 0, 'provisioned' => 0, 'errors' => []];

        foreach ($this->preview($from, $to) as $row) {
            if ($balanceIds !== null && ! in_array($row['balance_id'], $balanceIds, true)) {
                continue;
            }

            if ($row['status'] === self::PROCESSED) {
                $result['already']++;

                continue;
            }

            if ($row['status'] !== self::SAFE) {
                $result[$row['status'] === self::BLOCKED ? 'failed' : 'needs_review']++;
                $this->record($row, $from, $to, $run, $row['status'] === self::BLOCKED ? LeaveRolloverRecord::FAILED : LeaveRolloverRecord::NEEDS_HR_REVIEW, $row['reason'], $actor);

                continue;
            }

            try {
                $this->processRow($row, $from, $to, $run, $actor);
                $result['processed']++;
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = "{$row['employee']} / {$row['leave_type']}: ".$e->getMessage();
                $this->record($row, $from, $to, $run, LeaveRolloverRecord::FAILED, $e->getMessage(), $actor);
            }
        }

        // The new year's entitlement for everyone eligible — including those
        // with no closing-year balance (joiners, types without carry forward).
        if ($balanceIds === null) {
            $provision = $this->ensure->bulk(
                Employee::whereIn('status', LeaveRuleResolver::ELIGIBLE_STATUSES)->with('user')->get(),
                $to, $actor, dryRun: false, trigger: 'rollover',
            );
            $result['provisioned'] = $provision['summary']['valid'];
        }

        $summary = collect($result)->except('errors')->all() + ['errors' => array_slice($result['errors'], 0, 50)];
        $run->update([
            'status' => $result['failed'] > 0 || $result['needs_review'] > 0 ? 'completed_with_issues' : 'completed',
            'summary' => $summary,
            'completed_at' => now(),
        ]);

        app(AuditService::class)->event('LEAVE_ROLLOVER_RUN', AuditService::LEAVE, $run,
            new: ['from' => $from->label, 'to' => $to->label] + collect($result)->except('errors')->all(),
            reason: 'Leave-year rollover ('.$trigger.')');

        return ['run' => $run->fresh()] + $result;
    }

    /**
     * HR resolves a NEEDS_HR_REVIEW row by stating the carry amount. The row
     * is then processed exactly like a SAFE one (carry, expire the rest,
     * provision the new base). Refused while the year still has undecided
     * requests or the balance is not on the ledger.
     */
    public function resolveReview(LeaveBalance $balance, float $carry, User $actor, string $reason): LeaveRolloverRecord
    {
        if (trim($reason) === '') {
            throw new \DomainException('Resolving a rollover row needs a reason.');
        }

        $from = $this->ledger->yearOf($balance);
        $to = $this->years->next($from);
        $row = $this->preview($from, $to, ['employee_id' => $balance->employee_id, 'leave_type_id' => $balance->leave_type_id])
            ->firstWhere('balance_id', $balance->id);

        if ($row === null || $row['status'] === self::PROCESSED) {
            throw new \DomainException('This balance has already been rolled over.');
        }

        if (str_contains($row['reason'], 'await a decision')) {
            throw new \DomainException('Decide the pending requests in '.$from->label.' first.');
        }

        if (! $balance->isLedgerBacked() && ! $this->backfill->migrateIfSafe($balance, $actor)) {
            throw new \DomainException('Resolve this balance in the ledger backfill first; its history is ambiguous.');
        }

        $closing = max(0.0, (float) $this->calculator->summary($balance->fresh())['approved_available']);
        $carry = round($carry, 2);

        if ($carry < 0 || $carry > $closing + self::EPSILON) {
            throw new \DomainException("Carry forward must be between 0 and the closing balance ({$closing}).");
        }

        $row = ['closing' => $closing, 'carry' => $carry, 'expire' => round($closing - $carry, 2)] + $row;
        $run = LeaveBulkRun::create([
            'kind' => LeaveBulkRun::KIND_ROLLOVER, 'leave_year_id' => $from->id, 'target_leave_year_id' => $to->id,
            'dry_run' => false, 'status' => 'completed', 'created_by' => $actor->id,
            'parameters' => ['trigger' => 'hr_review', 'balance_id' => $balance->id, 'carry' => $carry, 'reason' => $reason],
            'summary' => ['processed' => 1], 'completed_at' => now(),
        ]);

        $this->processRow($row, $from, $to, $run, $actor);

        return LeaveRolloverRecord::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('from_leave_year_id', $from->id)
            ->firstOrFail();
    }

    /** Whether every closing-year balance has been rolled over cleanly. */
    public function isComplete(LeaveYear $from): bool
    {
        return $this->preview($from)->every(fn (array $r) => $r['status'] === self::PROCESSED);
    }

    /** @return array<string, mixed> */
    private function row(LeaveBalance $balance, LeaveYear $from, LeaveYear $to, ?LeaveRolloverRecord $record): array
    {
        $employee = $balance->employee;
        $type = $balance->leaveType;

        $row = [
            'balance_id' => $balance->id,
            'employee_id' => $balance->employee_id,
            'employee' => $employee?->user?->name ?? 'Employee #'.$balance->employee_id,
            'employee_code' => $employee?->employee_id,
            'department' => $employee?->department?->name,
            'leave_type_id' => $balance->leave_type_id,
            'leave_type' => $type?->name,
            'closing' => null,
            'carry' => 0.0,
            'expire' => 0.0,
            'new_base' => null,
            'new_opening' => null,
            'expires_on' => null,
            'record_id' => $record?->id,
        ];

        if ($record?->status === LeaveRolloverRecord::PROCESSED) {
            return [
                'status' => self::PROCESSED,
                'closing' => (float) $record->closing_days,
                'carry' => (float) $record->carry_days,
                'expire' => (float) $record->expired_days,
                'new_base' => $record->new_base_days !== null ? (float) $record->new_base_days : null,
                'new_opening' => round((float) $record->new_base_days + (float) $record->carry_days, 2),
                'reason' => 'Rolled over '.$record->processed_at?->format('d M Y H:i').'.',
            ] + $row;
        }

        if ($employee === null || $type === null) {
            return ['status' => self::BLOCKED, 'reason' => 'Employee or leave type no longer exists.'] + $row;
        }

        $settings = $this->rules->settings($employee, $type);
        $reasons = [];

        if (! $balance->isLedgerBacked()) {
            $class = $this->backfill->classify($balance)['classification'];
            if ($class !== LeaveLedgerBackfillService::SAFE) {
                $reasons[] = "Balance is not on the leave ledger ({$class} for migration).";
            }
        } elseif ($balance->ledger_status === LeaveBalance::LEDGER_NEEDS_HR_REVIEW) {
            $reasons[] = 'Balance holds an opening figure still awaiting HR review.';
        }

        if ($balance->used_days_unknown || $balance->encashed_days_unknown) {
            $reasons[] = 'Usage for the closing year is unknown.';
        }

        $pending = LeaveRequest::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->whereIn('status', LeaveBalanceCalculator::RESERVING_STATUSES)
            ->whereDate('start_date', '>=', $from->starts_on->toDateString())
            ->whereDate('start_date', '<=', $from->ends_on->toDateString())
            ->count();
        if ($pending > 0) {
            $reasons[] = "{$pending} request(s) in {$from->label} still await a decision.";
        }

        $closing = $this->calculator->summary($balance)['approved_available'];
        $row['closing'] = $closing;

        if ($closing < -self::EPSILON) {
            $reasons[] = "The {$from->label} balance is overdrawn ({$closing}); decide how to recover it.";
        }

        [$carry, $carryNote] = $this->carryFor($employee, $type, $settings, max(0.0, $closing), $to);
        $row['carry'] = $carry;
        $row['expire'] = round(max(0.0, $closing) - $carry, 2);
        $row['expires_on'] = $carry > 0 ? $this->carryExpiry($settings, $to) : null;

        if ($carry > 0 && $type->carry_forward_mode !== LeaveType::CARRY_AUTOMATIC && $settings['carry_forward_enabled'] && ! $this->ruleSaysAutomatic($settings)) {
            $reasons[] = 'Carry forward for '.$type->name.' is an HR decision (mode: '.($type->carry_forward_mode ?? 'unset').').';
        }

        $plan = $this->ensure->ensureType($employee, $type, $to, null, dryRun: true);
        $row['new_base'] = $plan['expected'] ?? null;
        $row['new_opening'] = round(($row['new_base'] ?? 0) + $carry, 2);

        return [
            'status' => $reasons === [] ? self::SAFE : self::NEEDS_HR_REVIEW,
            'reason' => $reasons === [] ? ($carryNote ?: 'Ready to roll over.') : implode(' ', $reasons),
        ] + $row;
    }

    /** A policy rule that enables carry forward states the decision itself. */
    private function ruleSaysAutomatic(array $settings): bool
    {
        return $settings['carry_forward_by_rule'];
    }

    /** @return array{0: float, 1: string} */
    private function carryFor(Employee $employee, LeaveType $type, array $settings, float $closing, LeaveYear $to): array
    {
        if (! $settings['carry_forward_enabled'] || (! $type->permitsCarryForward() && $settings['rule_id'] === null)) {
            return [0.0, 'No carry forward for this type; the unused balance expires.'];
        }

        $status = $employee->status instanceof EmployeeStatus ? $employee->status->value : (string) $employee->status;
        if ($settings['carry_forward_eligible_statuses'] && ! in_array($status, $settings['carry_forward_eligible_statuses'], true)) {
            return [0.0, "Status '{$status}' is not eligible to carry forward."];
        }

        $carry = $closing;
        $notes = [];

        if ($settings['carry_forward_max_days'] !== null && $carry > $settings['carry_forward_max_days']) {
            $carry = $settings['carry_forward_max_days'];
            $notes[] = "capped at {$carry} day(s)";
        }

        if ($settings['carry_forward_percent'] !== null) {
            $byPercent = round($closing * $settings['carry_forward_percent'] / 100, 2);
            if ($carry > $byPercent) {
                $carry = $byPercent;
                $notes[] = "{$settings['carry_forward_percent']}% of the closing balance";
            }
        }

        if ($settings['max_balance'] !== null) {
            $newBase = (float) ($this->rules->entitlement($employee, $type, $to)['days'] ?? 0);
            $room = max(0.0, $settings['max_balance'] - $newBase);
            if ($carry > $room) {
                $carry = $room;
                $notes[] = "limited by the maximum balance of {$settings['max_balance']}";
            }
        }

        return [round(max(0.0, $carry), 2), $notes ? 'Carry '.implode(', ', $notes).'.' : ''];
    }

    /** The expiry date a carried lot gets in the new year, if any. */
    public function carryExpiry(array $settings, LeaveYear $to): ?string
    {
        return $this->rules->carryForwardExpiry($settings, $to);
    }

    /** @param  array<string, mixed>  $row */
    private function processRow(array $row, LeaveYear $from, LeaveYear $to, LeaveBulkRun $run, ?User $actor): void
    {
        DB::transaction(function () use ($row, $from, $to, $run, $actor) {
            // The anchor: a second run (or a concurrent one) stops here.
            $record = LeaveRolloverRecord::where('employee_id', $row['employee_id'])
                ->where('leave_type_id', $row['leave_type_id'])
                ->where('from_leave_year_id', $from->id)
                ->lockForUpdate()
                ->first();

            if ($record?->status === LeaveRolloverRecord::PROCESSED) {
                return;
            }

            $balance = LeaveBalance::findOrFail($row['balance_id']);
            $employee = Employee::findOrFail($row['employee_id']);
            $type = LeaveType::withTrashed()->findOrFail($row['leave_type_id']);

            if (! $balance->isLedgerBacked() && ! $this->backfill->migrateIfSafe($balance, $actor)) {
                throw new \RuntimeException('Balance could not be moved onto the ledger.');
            }

            $txId = null;
            if ($row['carry'] > 0) {
                $tx = $this->carryForward->apply($employee, $type, $from, $to, $actor ?? $this->systemActor(), $row['carry'], 'Automatic year-end rollover');
                $txId = $tx->id;
            }

            if ($row['expire'] > self::EPSILON) {
                $this->ledger->debit($balance->fresh(), LeaveLedgerEntry::TYPE_EXPIRY, $row['expire'], $from->ends_on->copy(),
                    "rollover:expiry:{$balance->id}:{$to->id}", [
                        'source_type' => 'leave_rollover', 'source_id' => $run->id,
                        'reason' => "Year-end lapse: {$row['expire']} day(s) of {$from->label} not carried forward",
                        'actor' => $actor, 'allow_closed_year' => true,
                        'meta' => ['bulk_run_id' => $run->id, 'closing' => $row['closing'], 'carried' => $row['carry']],
                    ]);
                $this->ledger->rebuild($balance->fresh());
            }

            $base = $this->ensure->ensureType($employee, $type, $to, $actor, trigger: 'rollover');

            $record = LeaveRolloverRecord::updateOrCreate(
                ['employee_id' => $row['employee_id'], 'leave_type_id' => $row['leave_type_id'], 'from_leave_year_id' => $from->id],
                [
                    'to_leave_year_id' => $to->id, 'bulk_run_id' => $run->id, 'status' => LeaveRolloverRecord::PROCESSED,
                    'closing_days' => $row['closing'], 'carry_days' => $row['carry'], 'expired_days' => $row['expire'],
                    'new_base_days' => $base['current_base'] ?? $base['expected'],
                    'carry_forward_transaction_id' => $txId,
                    'message' => 'New-year base: '.$base['status'].'. '.$base['message'],
                    'processed_by' => $actor?->id, 'processed_at' => now(),
                ],
            );

            app(AuditService::class)->event('LEAVE_ROLLOVER_PROCESSED', AuditService::LEAVE, $record,
                old: ['closing' => $row['closing']],
                new: [
                    'leave_type' => $row['leave_type'], 'from' => $from->label, 'to' => $to->label,
                    'carried_forward' => $row['carry'], 'expired' => $row['expire'],
                    'new_base' => $record->new_base_days !== null ? (float) $record->new_base_days : null,
                    'bulk_run_id' => $run->id,
                ],
                reason: 'Leave-year rollover', subjectEmployeeId: $row['employee_id']);
        });
    }

    /** @param  array<string, mixed>  $row */
    private function record(array $row, LeaveYear $from, LeaveYear $to, LeaveBulkRun $run, string $status, string $message, ?User $actor): void
    {
        LeaveRolloverRecord::updateOrCreate(
            ['employee_id' => $row['employee_id'], 'leave_type_id' => $row['leave_type_id'], 'from_leave_year_id' => $from->id],
            [
                'to_leave_year_id' => $to->id, 'bulk_run_id' => $run->id, 'status' => $status,
                'closing_days' => $row['closing'], 'carry_days' => $row['carry'], 'expired_days' => $row['expire'],
                'new_base_days' => $row['new_base'], 'message' => $message, 'processed_by' => $actor?->id,
            ],
        );
    }

    /** Carry-forward transactions need a named actor; a scheduled run uses the first Super Admin. */
    private function systemActor(): User
    {
        return User::where('role', 'super_admin')->orderBy('id')->firstOrFail();
    }
}
