<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCreditConsumption;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveV2Migration;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Audit\AuditService;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Where a Leave V2 migration opening came from.
 *
 * The migration moved each employee's CURRENT-year CSL available into one
 * Earned Leave opening. That figure is a mix: CSL carry forward from the
 * previous year, this year's CSL credits, HR movements — net of usage. The
 * CSL ledger still holds the evidence: every credit lot and exactly what
 * each debit consumed (carry forward first). The days still unconsumed in
 * each lot are what the opening is made of.
 *
 * planFor() reads that evidence and never writes. Only an opening made
 * entirely of carry forward and current-year CSL credits, reconciling to the
 * day with the migration and with today's CSL ledger, is classifiable;
 * anything else is AMBIGUOUS and left for HR.
 *
 * apply() reclassifies ONE employee on an explicit HR instruction: the
 * opening is reversed and the same days are credited as Carry Forward and
 * current-year credit (base) under deterministic keys. History is appended,
 * never edited, and the total available must be unchanged or nothing is kept.
 */
class EarnedOpeningSourceService
{
    public const CARRY_FORWARD = 'CARRY FORWARD';

    public const CURRENT_YEAR = 'CURRENT YEAR';

    public const SPLIT = 'SPLIT';

    public const DONE = 'RECLASSIFIED';

    public const NOTHING = 'NOTHING TO CLASSIFY';

    public const AMBIGUOUS = 'AMBIGUOUS';

    /** Statuses apply() acts on. */
    public const CLASSIFIABLE = [self::CARRY_FORWARD, self::CURRENT_YEAR, self::SPLIT];

    public const KEY_PREFIX = 'v2-opening-source';

    public const SOURCE_TYPE = 'leave_v2_opening_source';

    private const EPSILON = 0.005;

    public function __construct(
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeaveLedgerService $ledger,
        private readonly LeaveMovementService $movements,
        private readonly LeaveYearResolver $years,
    ) {}

    /**
     * @param  array<int, string>  $employeeCodes
     * @return Collection<int, array<string, mixed>>
     */
    public function plan(array $employeeCodes = []): Collection
    {
        return LeaveV2Migration::with(['employee.user', 'leaveYear'])
            ->when($employeeCodes !== [], fn ($q) => $q->whereHas('employee', fn ($e) => $e->whereIn('employee_id', $employeeCodes)))
            ->get()
            ->sortBy(fn (LeaveV2Migration $m) => [$m->employee?->employee_id, $m->leave_year_id])
            ->map(fn (LeaveV2Migration $m) => $this->planFor($m))
            ->values();
    }

    /** @return array<string, mixed> */
    public function planFor(LeaveV2Migration $migration): array
    {
        $employee = $migration->employee ?? Employee::find($migration->employee_id);
        $year = $migration->leaveYear ?? LeaveYear::find($migration->leave_year_id);

        $row = [
            'migration_id' => $migration->id,
            'employee_id' => $migration->employee_id,
            'employee' => $employee?->user?->name ?? 'Employee #'.$migration->employee_id,
            'code' => $employee?->employee_id,
            'year_id' => $migration->leave_year_id,
            'year' => $year?->label,
            'migrated' => round((float) $migration->migrated_available, 2),
            'opening_entry_id' => $migration->ledger_entry_id,
            'carry_forward' => 0.0, 'current_year' => 0.0, 'other' => 0.0,
            'lots' => [],
            'status' => self::AMBIGUOUS, 'reason' => '',
        ];
        // By reference: a stop reports the figures gathered so far.
        $stop = function (string $status, string $reason) use (&$row): array {
            return ['status' => $status, 'reason' => $reason] + $row;
        };

        if ($row['migrated'] <= self::EPSILON) {
            return $stop(self::NOTHING, 'Nothing was migrated (opening 0).');
        }

        $opening = $migration->ledger_entry_id ? LeaveLedgerEntry::find($migration->ledger_entry_id) : null;
        if ($opening === null) {
            return $stop(self::AMBIGUOUS, 'The migration has no opening ledger entry.');
        }
        if ($opening->reversedBy()->exists()) {
            $done = LeaveLedgerEntry::where('idempotency_key', 'like', self::KEY_PREFIX.':'.$migration->id.':%')->get();

            return $done->isNotEmpty()
                ? $stop(self::DONE, 'Reclassified as '.$this->describe(
                    (float) $done->where('entry_type', LeaveLedgerEntry::TYPE_CARRY_FORWARD)->sum('days'),
                    (float) $done->where('entry_type', LeaveLedgerEntry::TYPE_BASE)->sum('days')).'.')
                : $stop(self::AMBIGUOUS, 'The opening entry was reversed by hand — resolve it manually.');
        }
        if (abs((float) $opening->days - $row['migrated']) > self::EPSILON) {
            return $stop(self::AMBIGUOUS, "The opening entry ({$opening->days}) does not match the migrated amount ({$row['migrated']}).");
        }
        if ($migration->override_reason !== null) {
            return $stop(self::AMBIGUOUS, 'Set by HR override ("'.$migration->override_reason.'") — there is no source ledger to read; HR decides.');
        }

        $source = $migration->source_balance_id ? LeaveBalance::find($migration->source_balance_id) : null;
        if ($source === null || $migration->source_leave_type_id === null) {
            return $stop(self::AMBIGUOUS, 'The source CSL balance is not recorded.');
        }

        $entries = LeaveLedgerEntry::where('employee_id', $migration->employee_id)
            ->where('leave_type_id', $migration->source_leave_type_id)
            ->where('leave_year_id', $migration->leave_year_id)
            ->orderBy('id')->get();
        $lots = $entries->filter(fn (LeaveLedgerEntry $e) => $e->isCredit() && (float) $e->days > 0)
            ->map(fn (LeaveLedgerEntry $e) => ['id' => $e->id, 'type' => $e->entry_type, 'days' => (float) $e->days,
                'remaining' => $this->ledger->remaining($e), 'expires_on' => $e->expires_on?->toDateString()])
            ->filter(fn (array $lot) => $lot['remaining'] > self::EPSILON)
            ->values();
        $row['lots'] = $lots->all();

        $byType = fn (array $types) => round((float) $lots->whereIn('type', $types)->sum('remaining'), 2);
        $row['carry_forward'] = $byType([LeaveLedgerEntry::TYPE_CARRY_FORWARD]);
        $row['current_year'] = $byType([LeaveLedgerEntry::TYPE_BASE, LeaveLedgerEntry::TYPE_ACCRUAL]);
        $row['other'] = round((float) $lots->sum('remaining') - $row['carry_forward'] - $row['current_year'], 2);

        $unbacked = round((float) LeaveCreditConsumption::whereIn('debit_entry_id', $entries->pluck('id'))->whereNull('credit_entry_id')->sum('days'), 2);
        $now = (float) $this->calculator->summary($source)['approved_available'];
        $remaining = round((float) $lots->sum('remaining'), 2);

        if ($unbacked > self::EPSILON) {
            return $stop(self::AMBIGUOUS, "CSL was overdrawn by {$unbacked} day(s); the lots cannot account for the opening.");
        }
        if (abs($now - (float) $migration->source_available) > self::EPSILON) {
            return $stop(self::AMBIGUOUS, 'CSL has changed since the migration ('.((float) $migration->source_available + 0).' → '.($now + 0).').');
        }
        if (abs($remaining - $row['migrated']) > self::EPSILON) {
            return $stop(self::AMBIGUOUS, "The unconsumed CSL lots ({$remaining}) do not add up to the opening ({$row['migrated']}).");
        }
        if ($row['other'] > self::EPSILON) {
            $kinds = $lots->whereNotIn('type', [LeaveLedgerEntry::TYPE_CARRY_FORWARD, LeaveLedgerEntry::TYPE_BASE, LeaveLedgerEntry::TYPE_ACCRUAL])
                ->pluck('type')->unique()->map(fn ($t) => str_replace('_', ' ', $t))->implode(', ');

            return $stop(self::AMBIGUOUS, "{$row['other']} day(s) of the opening come from {$kinds} — origin cannot be classified; HR decides.");
        }
        if ($lots->where('type', LeaveLedgerEntry::TYPE_CARRY_FORWARD)->contains(fn (array $lot) => $lot['expires_on'] !== null)) {
            return $stop(self::AMBIGUOUS, 'The CSL carry forward had an expiry date — Earned Leave carry forward does not lapse; HR decides.');
        }

        $status = match (true) {
            $row['current_year'] <= self::EPSILON => self::CARRY_FORWARD,
            $row['carry_forward'] <= self::EPSILON => self::CURRENT_YEAR,
            default => self::SPLIT,
        };

        return $stop($status, 'The opening is '.$this->describe($row['carry_forward'], $row['current_year'])
            .' (unconsumed CSL lots #'.$lots->pluck('id')->implode(', #').').');
    }

    /**
     * Reclassify one employee's opening as the evidence shows — on an
     * explicit HR instruction, re-judged under a lock. The total available
     * is unchanged; the opening is reversed, never edited.
     *
     * @param  array<string, mixed>  $planned  a row from planFor()
     * @return array<string, mixed>
     */
    public function apply(array $planned, User $actor, string $reason): array
    {
        if (trim($reason) === '') {
            throw new DomainException('A reason is required to reclassify an opening balance.');
        }

        return DB::transaction(function () use ($planned, $actor, $reason) {
            $migration = LeaveV2Migration::with(['employee', 'leaveYear'])->findOrFail($planned['migration_id']);
            if ((int) $actor->employee?->id === (int) $migration->employee_id) {
                throw new DomainException('You cannot reclassify your own leave balance.');
            }

            $year = $migration->leaveYear;
            $balance = $this->movements->balanceFor($migration->employee_id, $migration->earned_leave_type_id, Carbon::parse($year->starts_on));
            LeaveBalance::whereKey($balance->id)->lockForUpdate()->first();

            $row = $this->planFor($migration->fresh());
            if (! in_array($row['status'], self::CLASSIFIABLE, true)) {
                return $row;
            }

            $before = (float) $this->calculator->summary($balance->fresh())['approved_available'];
            $opening = LeaveLedgerEntry::findOrFail($migration->ledger_entry_id);
            $why = 'Opening reclassified from CSL evidence: '.$this->describe($row['carry_forward'], $row['current_year']).'. '.$reason;
            $meta = ['migration_id' => $migration->id, 'source_lots' => array_column($row['lots'], 'id'), 'actor' => 'user:'.$actor->id];

            $reversal = $this->ledger->reverse($opening, $why, $actor, ['meta' => $meta]);
            $posted = [];
            foreach ([LeaveLedgerEntry::TYPE_CARRY_FORWARD => [$row['carry_forward'], 'carry_forward', 'Carry Forward from '.$this->years->previous($year)->label.' (from CSL)'],
                LeaveLedgerEntry::TYPE_BASE => [$row['current_year'], 'current_year', $year->label.' credit already earned under CSL']] as $type => [$days, $part, $label]) {
                if ($days > self::EPSILON) {
                    $posted[$part] = $this->ledger->credit($balance->fresh(), $type, $days, Carbon::parse($year->starts_on),
                        self::KEY_PREFIX.":{$migration->id}:{$part}", [
                            'source_type' => self::SOURCE_TYPE, 'source_id' => $migration->id,
                            'reason' => $label, 'actor' => $actor, 'meta' => $meta + ['part' => $part],
                        ])->id;
                }
            }
            $this->ledger->rebuild($balance->fresh());

            $after = (float) $this->calculator->summary($balance->fresh())['approved_available'];
            if (abs($after - $before) > self::EPSILON) {
                throw new RuntimeException("Reclassifying {$row['code']} would change the available balance ({$before} → {$after}); nothing was kept.");
            }

            app(AuditService::class)->event('LEAVE_V2_OPENING_RECLASSIFIED', AuditService::LEAVE, $migration,
                old: ['opening' => $row['migrated'], 'opening_entry_id' => $opening->id, 'available' => $before],
                new: ['carry_forward' => $row['carry_forward'], 'current_year' => $row['current_year'], 'reversal_entry_id' => $reversal->id,
                    'entry_ids' => $posted, 'available' => $after, 'source_lots' => $meta['source_lots']],
                reason: $why, subjectEmployeeId: $migration->employee_id, actor: $actor);

            return ['status' => self::DONE, 'reason' => 'Reclassified as '.$this->describe($row['carry_forward'], $row['current_year']).'; available unchanged at '.($after + 0).'.'] + $row;
        });
    }

    private function describe(float $carry, float $current): string
    {
        return collect([
            $carry > self::EPSILON ? ($carry + 0).' day(s) Carry Forward' : null,
            $current > self::EPSILON ? ($current + 0).' day(s) current-year credit' : null,
        ])->filter()->implode(' + ') ?: '0 days';
    }
}
