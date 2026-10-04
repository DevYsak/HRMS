<?php

namespace App\Services\Leave;

use App\Models\AttendanceRegularisation;
use App\Models\LeaveAccrualLog;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveCarryForwardTransaction;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves existing leave_balances rows onto the ledger (Phase 2A).
 *
 * Old rows recorded one blended allocated_days, so their composition can
 * only sometimes be recovered. Every row is classified, and nothing is
 * invented:
 *
 *  SAFE             Every credit recorded against the year is accounted for
 *                   (carry forward, accruals, comp-off) and nothing else ever
 *                   touched it — no HR adjustment, no historical statement —
 *                   so the remainder can only be its allocation. Posted as
 *                   proper buckets; usage posted per leave request.
 *  NEEDS_HR_REVIEW  The total is real but the composition is not knowable
 *                   (HR adjustments without a year, a stated historical year,
 *                   usage that does not match the requests, unknown usage).
 *                   The known parts are posted; the rest is preserved as one
 *                   OPENING_BALANCE and the row is flagged. Migrated only
 *                   when explicitly requested.
 *  BLOCKED          The row cannot be represented honestly (credits exceed
 *                   the allocation, duplicates, missing employee/type).
 *                   Never migrated.
 *
 * A migration never changes a balance: after posting, the rebuilt totals
 * must equal the stored ones to the cent or the whole row is rolled back.
 */
class LeaveLedgerBackfillService
{
    public const SAFE = 'SAFE';

    public const NEEDS_HR_REVIEW = 'NEEDS_HR_REVIEW';

    public const BLOCKED = 'BLOCKED';

    private const EPSILON = 0.005;

    public function __construct(
        private readonly LeaveLedgerService $ledger,
        private readonly LeaveYearResolver $years,
    ) {}

    /**
     * Classify every unmigrated balance, without writing anything.
     *
     * @param  array{leave_year_id?: int|null, employee_id?: int|null}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function preview(array $filters = []): Collection
    {
        return $this->candidates($filters)->map(fn (LeaveBalance $b) => $this->classify($b));
    }

    /**
     * Migrate the SAFE rows (and NEEDS_HR_REVIEW rows only when asked).
     * BLOCKED rows are never touched.
     *
     * @param  array{leave_year_id?: int|null, employee_id?: int|null}  $filters
     * @return array{migrated: int, safe: int, needs_hr_review: int, blocked: int, skipped: int, failed: int, errors: array<int, string>}
     */
    public function apply(array $filters, User $actor, bool $includeReview = false): array
    {
        $result = ['migrated' => 0, 'safe' => 0, 'needs_hr_review' => 0, 'blocked' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        foreach ($this->candidates($filters) as $balance) {
            $plan = $this->classify($balance);

            if ($plan['classification'] === self::BLOCKED) {
                $result['blocked']++;

                continue;
            }

            if ($plan['classification'] === self::NEEDS_HR_REVIEW && ! $includeReview) {
                $result['skipped']++;

                continue;
            }

            try {
                $this->migrate($balance, $plan, $actor);
                $result['migrated']++;
                $result[$plan['classification'] === self::SAFE ? 'safe' : 'needs_hr_review']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = "Balance #{$balance->id}: ".$e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Migrate on first touch, but only when the row is SAFE. Ambiguous rows
     * stay on the legacy path until HR runs the backfill deliberately.
     */
    public function migrateIfSafe(LeaveBalance $balance, ?User $actor = null): bool
    {
        if ($balance->isLedgerBacked()) {
            return true;
        }

        $plan = $this->classify($balance);

        if ($plan['classification'] !== self::SAFE) {
            return false;
        }

        $this->migrate($balance, $plan, $actor);

        return true;
    }

    /**
     * Migrate exactly one row, including a NEEDS_HR_REVIEW one, for a caller
     * that is about to restate the row anyway (the HR register
     * reconciliation). apply() filters by employee and year, which would
     * also migrate the employee's other leave types as a side effect.
     *
     * @return string the classification the row was migrated under
     *
     * @throws \DomainException when the row is BLOCKED
     */
    public function migrateRow(LeaveBalance $balance, ?User $actor = null): string
    {
        if ($balance->isLedgerBacked()) {
            return self::SAFE;
        }

        $plan = $this->classify($balance);

        if ($plan['classification'] === self::BLOCKED) {
            throw new \DomainException('Balance #'.$balance->id.' cannot be moved onto the ledger: '.$plan['reason']);
        }

        $this->migrate($balance, $plan, $actor);

        return $plan['classification'];
    }

    /**
     * The full picture of one row, and the entries that would represent it.
     *
     * @return array<string, mixed>
     */
    public function classify(LeaveBalance $balance): array
    {
        $balance->loadMissing(['employee.user', 'leaveType']);
        $year = $this->ledger->yearOf($balance);

        $allocated = round((float) $balance->allocated_days, 2);
        $used = round((float) $balance->used_days, 2);
        $encashed = round((float) $balance->encashed_days, 2);
        $carried = round((float) $balance->carried_forward_days, 2);
        $compOff = round((float) $balance->comp_off_credits, 2);

        $reasons = [];
        $blocked = [];

        if (! $balance->employee || ! $balance->leaveType) {
            $blocked[] = 'Employee or leave type no longer exists.';
        }

        if ($balance->leave_year_id && (int) $balance->leave_year_id !== (int) $year->id) {
            $blocked[] = 'leave_year_id does not match the legacy year.';
        }

        $duplicates = LeaveBalance::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('id', '!=', $balance->id)
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear()))
            ->exists();
        if ($duplicates) {
            $blocked[] = 'Another balance row exists for the same employee, type and leave year.';
        }

        if ($allocated < 0 || $used < 0 || $encashed < 0 || $carried < 0) {
            $blocked[] = 'Negative stored figure.';
        }

        // Known carry forward: the column, cross-checked against the
        // carry-forward transactions when there are any.
        $txNet = LeaveCarryForwardTransaction::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('current_leave_year_id', $year->id)
            ->get()
            ->sum(fn (LeaveCarryForwardTransaction $t) => $t->netApplied());
        $hasTx = LeaveCarryForwardTransaction::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('current_leave_year_id', $year->id)->exists();
        if ($hasTx && abs($txNet - $carried) > self::EPSILON) {
            $reasons[] = "Carried forward column ({$carried}) differs from carry-forward transactions ({$txNet}).";
        }

        // Known accrual: one log per credited month inside the year.
        $accruals = LeaveAccrualLog::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->get()
            ->filter(fn (LeaveAccrualLog $log) => $year->contains(Carbon::create($log->year, $log->month, 1)));
        $accrued = round((float) $accruals->sum('days_credited'), 2);

        // HR movements. Adjustments never recorded their year, so any that
        // fall inside this year's window make the composition unknowable.
        $adjustments = LeaveBalanceAdjustment::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)
                ->orWhere(fn ($w) => $w->whereNull('leave_year_id')
                    ->whereBetween('adjusted_at', [$year->starts_on->copy()->startOfDay(), $year->ends_on->copy()->endOfDay()])))
            ->get();
        $manual = $adjustments->filter(fn ($a) => in_array($a->source, [null, 'manual'], true));
        // A historical statement belongs to the year it states, which only
        // leave_year_id records — its adjusted_at is when HR entered it.
        $historical = $adjustments->where('source', 'historical')->where('leave_year_id', $year->id);
        // One that could not be mapped to a year could be about any of this
        // employee's years of this type; none of them can be called SAFE.
        $unmappedHistorical = LeaveBalanceAdjustment::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('source', 'historical')
            ->whereNull('leave_year_id')
            ->exists();
        $knownAdjustments = round($manual->sum(fn ($a) => ($a->action === 'debit' ? -1 : 1) * (float) $a->days), 2);
        if ($manual->isNotEmpty()) {
            $reasons[] = 'HR adjustments in this year were recorded without a year or bucket; their effect on the allocation cannot be separated.';
        }
        if ($historical->isNotEmpty()) {
            $reasons[] = 'The year was stated as a historical closing balance; its components were never recorded.';
        }
        if ($unmappedHistorical) {
            $reasons[] = 'A historical balance statement for this leave type could not be matched to a leave year.';
        }

        // Known usage: approved paid requests and applied leave
        // regularisations whose leave falls in this year.
        $requests = LeaveRequest::with('leaveType')
            ->where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('status', 'approved')
            ->whereDate('start_date', '>=', $year->starts_on->toDateString())
            ->whereDate('start_date', '<=', $year->ends_on->toDateString())
            ->get()
            ->filter(fn (LeaveRequest $r) => ($r->approved_leave_status ?? $r->requested_leave_status ?? ($r->leaveType?->is_paid ? 'paid' : 'unpaid')) === 'paid');
        $regularisations = AttendanceRegularisation::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('category', 'leave')
            ->where('status', 'approved')
            ->get()
            ->filter(fn (AttendanceRegularisation $r) => $year->contains(Carbon::parse($r->from_date ?? $r->work_date)));
        $knownUsage = round((float) $requests->sum('days') + (float) $regularisations->sum(fn ($r) => (float) ($r->duration ?? 1)), 2);
        $usageMatches = ! $balance->used_days_unknown && abs($knownUsage - $used) <= self::EPSILON;
        if ($balance->used_days_unknown) {
            $reasons[] = 'Usage for this year was never recorded.';
        } elseif (! $usageMatches) {
            $reasons[] = "Stored usage ({$used}) does not match approved leave on record ({$knownUsage}).";
        }
        if ($balance->encashed_days_unknown) {
            $reasons[] = 'Encashment for this year was never recorded.';
        }

        $remainder = round($allocated - $carried - $accrued - $compOff, 2);
        if ($remainder < -self::EPSILON) {
            $blocked[] = "Recorded credits (carry forward {$carried} + accrual {$accrued} + comp-off {$compOff}) exceed the allocation ({$allocated}).";
        }

        $classification = match (true) {
            $blocked !== [] => self::BLOCKED,
            $reasons !== [] => self::NEEDS_HR_REVIEW,
            default => self::SAFE,
        };

        return [
            'balance_id' => $balance->id,
            'employee_id' => $balance->employee_id,
            'employee' => $balance->employee?->user?->name ?? 'Employee #'.$balance->employee_id,
            'employee_code' => $balance->employee?->employee_id,
            'leave_type_id' => $balance->leave_type_id,
            'leave_type' => $balance->leaveType?->name,
            'leave_year_id' => $year->id,
            'leave_year' => $year->label,
            'stored_allocated' => $allocated,
            'stored_used' => $used,
            'stored_encashed' => $encashed,
            'stored_available' => round($allocated - $used - $encashed, 2),
            'known_carry_forward' => $carried,
            'known_adjustments' => $knownAdjustments,
            'known_accrual' => $accrued,
            'known_comp_off' => $compOff,
            'known_usage' => $knownUsage,
            'migration_amount' => max(0.0, $remainder),
            'migration_bucket' => $classification === self::SAFE ? 'base' : 'opening',
            'classification' => $classification,
            'reason' => implode(' ', $blocked !== [] ? $blocked : $reasons) ?: 'All components accounted for.',
            // Not part of the report: what migrate() posts.
            '_accrual_logs' => $accruals->values(),
            '_requests' => $usageMatches ? $requests->values() : collect(),
            '_regularisations' => $usageMatches ? $regularisations->values() : collect(),
            '_usage_matches' => $usageMatches,
        ];
    }

    /**
     * The report rows without the internal posting plan.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function reportRows(Collection $rows): Collection
    {
        return $rows->map(fn (array $row) => array_filter($row, fn ($key) => ! str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY));
    }

    /**
     * Post a row's plan and prove the totals did not move.
     *
     * @param  array<string, mixed>  $plan
     */
    private function migrate(LeaveBalance $balance, array $plan, ?User $actor): void
    {
        DB::transaction(function () use ($balance, $plan, $actor) {
            $year = LeaveYear::findOrFail($plan['leave_year_id']);
            $before = ['allocated' => $plan['stored_allocated'], 'used' => $plan['stored_used'], 'encashed' => $plan['stored_encashed']];
            $start = $year->starts_on->copy();
            $migration = ['meta' => ['migrated' => true], 'actor' => $actor, 'allow_closed_year' => true];
            $id = $balance->id;

            // Credits first, so usage consumes them in the normal order.
            if ($plan['known_carry_forward'] > 0) {
                $this->ledger->credit($balance, LeaveLedgerEntry::TYPE_CARRY_FORWARD, $plan['known_carry_forward'], $start,
                    "migration:{$id}:carry_forward", $migration + ['source_type' => 'leave_balance', 'source_id' => $id, 'reason' => 'Carried forward (migrated)']);
            }

            foreach ($plan['_accrual_logs'] as $log) {
                $this->ledger->credit($balance, LeaveLedgerEntry::TYPE_ACCRUAL, (float) $log->days_credited, Carbon::create($log->year, $log->month, 1),
                    "accrual:log:{$log->id}", $migration + ['source_type' => 'leave_accrual_log', 'source_id' => $log->id, 'reason' => 'Monthly accrual (migrated)']);
            }

            if ($plan['known_comp_off'] > 0) {
                $this->ledger->credit($balance, LeaveLedgerEntry::TYPE_ADD_ON, $plan['known_comp_off'], $start,
                    "migration:{$id}:comp_off", ['meta' => ['migrated' => true, 'add_on_type' => 'comp_off_credit']] + $migration + ['source_type' => 'leave_balance', 'source_id' => $id, 'reason' => 'Comp-off credits (migrated)']);
            }

            if ($plan['migration_amount'] > 0) {
                $safe = $plan['classification'] === self::SAFE;
                $this->ledger->credit($balance, $safe ? LeaveLedgerEntry::TYPE_BASE : LeaveLedgerEntry::TYPE_OPENING, $plan['migration_amount'], $start,
                    "migration:{$id}:".($safe ? 'base' : 'opening'), $migration + [
                        'source_type' => 'leave_balance', 'source_id' => $id,
                        'reason' => $safe ? 'Base entitlement (migrated)' : 'Opening balance (migrated; composition unknown — needs HR review)',
                    ]);
            }

            // Usage: per request when the records account for it exactly,
            // otherwise one migrated figure.
            if ($plan['_usage_matches']) {
                foreach ($plan['_requests'] as $request) {
                    $this->ledger->debit($balance, LeaveLedgerEntry::TYPE_USAGE, (float) $request->days, $request->start_date,
                        "usage:leave_request:{$request->id}:v1", $migration + ['source_type' => 'leave_request', 'source_id' => $request->id, 'reason' => 'Approved leave (migrated)']);
                }
                foreach ($plan['_regularisations'] as $reg) {
                    $this->ledger->debit($balance, LeaveLedgerEntry::TYPE_USAGE, (float) ($reg->duration ?? 1), Carbon::parse($reg->from_date ?? $reg->work_date),
                        "usage:leave_regularisation:{$reg->id}:v1", $migration + ['source_type' => 'leave_regularisation', 'source_id' => $reg->id, 'reason' => 'Leave regularisation (migrated)']);
                }
            } elseif ($plan['stored_used'] > 0) {
                $this->ledger->debit($balance, LeaveLedgerEntry::TYPE_USAGE, $plan['stored_used'], $year->starts_on->copy(),
                    "migration:{$id}:usage", $migration + ['source_type' => 'leave_balance', 'source_id' => $id, 'reason' => 'Usage (migrated; does not match the leave on record)']);
            }

            if ($plan['stored_encashed'] > 0) {
                $this->ledger->debit($balance, LeaveLedgerEntry::TYPE_ENCASHMENT, $plan['stored_encashed'], $year->starts_on->copy(),
                    "migration:{$id}:encashment", $migration + ['source_type' => 'leave_balance', 'source_id' => $id, 'reason' => 'Encashment (migrated)']);
            }

            $status = $plan['classification'] === self::SAFE ? LeaveBalance::LEDGER_SAFE : LeaveBalance::LEDGER_NEEDS_HR_REVIEW;
            $balance->forceFill([
                'leave_year_id' => $year->id,
                'ledger_status' => $status,
                'ledger_review_reason' => $status === LeaveBalance::LEDGER_SAFE ? null : $plan['reason'],
                'ledger_migrated_at' => now(),
            ])->save();

            $after = $this->ledger->rebuild($balance);

            foreach (['allocated' => 'allocated_days', 'used' => 'used_days', 'encashed' => 'encashed_days'] as $key => $column) {
                if (abs((float) $after->{$column} - $before[$key]) > self::EPSILON) {
                    throw new RuntimeException("Migration would change {$column} from {$before[$key]} to {$after->{$column}}; rolled back.");
                }
            }

            app(AuditService::class)->event(
                'LEAVE_LEDGER_MIGRATED',
                AuditService::LEAVE,
                $after,
                old: ['allocated_days' => $before['allocated'], 'used_days' => $before['used'], 'encashed_days' => $before['encashed']],
                new: [
                    'classification' => $plan['classification'],
                    'leave_type' => $plan['leave_type'],
                    'leave_year' => $plan['leave_year'],
                    'base_or_opening' => $plan['migration_amount'],
                    'bucket' => $plan['migration_bucket'],
                    'carried_forward' => $plan['known_carry_forward'],
                    'accrued' => $plan['known_accrual'],
                ],
                reason: $plan['reason'],
                subjectEmployeeId: $balance->employee_id,
            );
        });
    }

    /** @return Collection<int, LeaveBalance> */
    private function candidates(array $filters): Collection
    {
        return LeaveBalance::query()
            ->whereNull('ledger_migrated_at')
            ->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($filters['leave_year_id'] ?? null, function ($q, $id) {
                $year = LeaveYear::find($id);
                $q->where(fn ($w) => $w->where('leave_year_id', $id)->orWhere('year', $year?->legacyYear()));
            })
            ->orderBy('employee_id')->orderBy('leave_type_id')->orderBy('year')
            ->get();
    }
}
