<?php

namespace App\Services\Leave;

use App\Models\LeaveBalance;
use App\Models\LeaveCreditConsumption;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveYear;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The canonical leave balance ledger (Phase 2A).
 *
 * Every movement of a ledger-backed balance is posted here as an immutable
 * entry; leave_balances is then rebuilt from those entries. Three properties
 * matter and are enforced in this one place:
 *
 *  1. Idempotency — each movement carries a unique key; posting the same key
 *     twice returns the first entry instead of moving the balance again.
 *  2. Traceable consumption — a debit records which credit lots it took days
 *     from (LeaveCreditConsumption), in the default order:
 *        carry forward → add-on → accrual → base → HR credit → opening,
 *     earliest expiry first within each. Expiry is therefore exact: only the
 *     unconsumed part of a lot ever expires.
 *  3. History is never edited — corrections are reversals that point at the
 *     entry they undo, and a reversed debit returns days to the very lots it
 *     consumed.
 *
 * A closed leave year accepts no ordinary postings.
 */
class LeaveLedgerService
{
    private const EPSILON = 0.001;

    public function __construct(private readonly LeaveYearResolver $years) {}

    /**
     * Post a credit lot.
     *
     * @param  array{expires_on?: CarbonInterface|string|null, source_type?: string|null, source_id?: int|null, reason?: string|null, meta?: array<string,mixed>|null, actor?: User|null, allow_closed_year?: bool}  $options
     */
    public function credit(LeaveBalance $balance, string $type, float $days, CarbonInterface $effectiveDate, string $key, array $options = []): LeaveLedgerEntry
    {
        if (! in_array($type, LeaveLedgerEntry::CREDIT_TYPES, true)) {
            throw new InvalidArgumentException("'{$type}' is not a credit entry type.");
        }

        if ($days <= 0) {
            throw new DomainException('A credit must be greater than zero days.');
        }

        return $this->transaction($balance, function (LeaveYear $year) use ($balance, $type, $days, $effectiveDate, $key, $options) {
            if ($existing = LeaveLedgerEntry::where('idempotency_key', $key)->first()) {
                return $existing;
            }

            $entry = $this->insert($balance, $year, [
                'entry_type' => $type,
                'days' => round($days, 2),
                'effective_date' => $effectiveDate,
                'expires_on' => $options['expires_on'] ?? null,
                'idempotency_key' => $key,
            ] + $this->common($options));

            $this->settleUnbacked($balance, $year, $entry);

            return $entry;
        }, $options);
    }

    /**
     * Post a debit and consume credit lots for it. $days is the positive
     * amount taken; the entry is stored negative. Whatever no lot covers is
     * recorded as unbacked — a real negative balance, never floored away.
     *
     * @param  array{source_type?: string|null, source_id?: int|null, reason?: string|null, meta?: array<string,mixed>|null, actor?: User|null, allow_closed_year?: bool}  $options
     */
    public function debit(LeaveBalance $balance, string $type, float $days, CarbonInterface $effectiveDate, string $key, array $options = []): LeaveLedgerEntry
    {
        if (in_array($type, LeaveLedgerEntry::CREDIT_TYPES, true)) {
            throw new InvalidArgumentException("'{$type}' is a credit entry type.");
        }

        if ($days <= 0) {
            throw new DomainException('A debit must be greater than zero days.');
        }

        return $this->transaction($balance, function (LeaveYear $year) use ($balance, $type, $days, $effectiveDate, $key, $options) {
            if ($existing = LeaveLedgerEntry::where('idempotency_key', $key)->first()) {
                return $existing;
            }

            $entry = $this->insert($balance, $year, [
                'entry_type' => $type,
                'days' => -round($days, 2),
                'effective_date' => $effectiveDate,
                'idempotency_key' => $key,
            ] + $this->common($options));

            $this->consume($entry, $this->eligibleCredits($balance, $year, $effectiveDate), round($days, 2), allowUnbacked: true);

            return $entry;
        }, $options);
    }

    /**
     * Undo an entry with a new, opposite entry that points at it.
     *
     * A reversed debit returns its days to exactly the lots it consumed; a
     * reversed credit takes back whatever of it is still unconsumed, and any
     * part already used becomes a visible shortfall. Reversing twice is
     * refused — reverses_entry_id is unique.
     */
    public function reverse(LeaveLedgerEntry $entry, string $reason, ?User $actor = null, array $options = []): LeaveLedgerEntry
    {
        if ($entry->isReversal()) {
            throw new DomainException('A reversal cannot itself be reversed; post a new movement instead.');
        }

        $balance = $this->balanceOf($entry);

        return $this->transaction($balance, function (LeaveYear $year) use ($entry, $reason, $actor, $balance, $options) {
            if (LeaveLedgerEntry::where('reverses_entry_id', $entry->id)->exists()) {
                throw new DomainException('This ledger entry has already been reversed.');
            }

            $reversal = $this->insert($balance, $year, [
                'entry_type' => $entry->entry_type,
                'days' => -(float) $entry->days,
                'effective_date' => Carbon::today(),
                'expires_on' => null,
                'reverses_entry_id' => $entry->id,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'idempotency_key' => 'reversal:'.$entry->id,
                'reason' => $reason,
                'meta' => $options['meta'] ?? null,
                'created_by' => $actor?->id,
            ]);

            if ((float) $entry->days < 0) {
                $this->returnConsumptions($entry, $reversal);
            } else {
                // Take back the credit itself first; anything already used is
                // now uncovered.
                $this->consume($reversal, collect([$entry]), (float) $entry->days, allowUnbacked: true);
            }

            return $reversal;
        }, $options + ['allow_closed_year' => $options['allow_closed_year'] ?? false]);
    }

    /**
     * Expire whatever remains of a credit lot. Only the unconsumed part
     * expires; returns null when nothing is left.
     */
    public function expireCredit(LeaveLedgerEntry $credit, CarbonInterface $on, ?User $actor = null, ?string $reason = null): ?LeaveLedgerEntry
    {
        if (! $credit->isCredit()) {
            throw new InvalidArgumentException('Only a credit lot can expire.');
        }

        $balance = $this->balanceOf($credit);

        return $this->transaction($balance, function (LeaveYear $year) use ($credit, $on, $actor, $reason, $balance) {
            $remaining = $this->remaining($credit);

            if ($remaining <= self::EPSILON) {
                return null;
            }

            $sequence = LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_EXPIRY)
                ->where('source_type', 'ledger_credit')->where('source_id', $credit->id)->count() + 1;

            $expiry = $this->insert($balance, $year, [
                'entry_type' => LeaveLedgerEntry::TYPE_EXPIRY,
                'days' => -$remaining,
                'effective_date' => $on,
                'source_type' => 'ledger_credit',
                'source_id' => $credit->id,
                'idempotency_key' => "expiry:credit:{$credit->id}:{$sequence}",
                'reason' => $reason ?? 'Unused '.str_replace('_', ' ', $credit->entry_type).' expired',
                'created_by' => $actor?->id,
            ]);

            $this->consume($expiry, collect([$credit]), $remaining, allowUnbacked: false);

            return $expiry;
        }, ['allow_closed_year' => true]);
    }

    /** Days of a credit lot not yet consumed. */
    public function remaining(LeaveLedgerEntry $credit): float
    {
        $consumed = (float) LeaveCreditConsumption::where('credit_entry_id', $credit->id)->sum('days');

        return round((float) $credit->days - $consumed, 2);
    }

    /** The live (not reversed) entry of a type posted for a domain record, if any. */
    public function activeEntryFor(string $sourceType, int $sourceId, string $entryType): ?LeaveLedgerEntry
    {
        return LeaveLedgerEntry::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('entry_type', $entryType)
            ->whereNull('reverses_entry_id')
            ->whereDoesntHave('reversedBy')
            ->latest('id')
            ->first();
    }

    /** Next version number for a re-postable movement of a domain record. */
    public function nextVersion(string $sourceType, int $sourceId, string $entryType): int
    {
        return LeaveLedgerEntry::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('entry_type', $entryType)
            ->whereNull('reverses_entry_id')
            ->count() + 1;
    }

    /** @return Collection<int, LeaveLedgerEntry> */
    public function entriesFor(LeaveBalance $balance): Collection
    {
        $year = $this->yearOf($balance);

        return LeaveLedgerEntry::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('leave_year_id', $year->id)
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Rewrite a balance row's figures from the ledger. The row is a summary;
     * this is the only path allowed to write a ledger-backed row's figures.
     */
    public function rebuild(LeaveBalance $balance): LeaveBalance
    {
        $year = $this->yearOf($balance);

        $totals = LeaveLedgerEntry::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('leave_year_id', $year->id)
            ->selectRaw('entry_type, SUM(days) as total')
            ->groupBy('entry_type')
            ->pluck('total', 'entry_type')
            ->map(fn ($v) => round((float) $v, 2));

        $sum = fn (string $type) => (float) ($totals[$type] ?? 0);

        $compOff = (float) LeaveLedgerEntry::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('leave_year_id', $year->id)
            ->where('entry_type', LeaveLedgerEntry::TYPE_ADD_ON)
            ->get()
            ->filter(fn (LeaveLedgerEntry $e) => (($e->meta['add_on_type'] ?? null) === 'comp_off_credit')
                || (($e->reverses?->meta['add_on_type'] ?? null) === 'comp_off_credit'))
            ->sum('days');

        $figures = [
            'base_days' => $sum(LeaveLedgerEntry::TYPE_BASE),
            'carried_forward_days' => $sum(LeaveLedgerEntry::TYPE_CARRY_FORWARD),
            'accrued_days' => $sum(LeaveLedgerEntry::TYPE_ACCRUAL),
            'add_on_days' => $sum(LeaveLedgerEntry::TYPE_ADD_ON),
            'adjustment_credit_days' => $sum(LeaveLedgerEntry::TYPE_ADJUSTMENT_CREDIT),
            'adjustment_debit_days' => -$sum(LeaveLedgerEntry::TYPE_ADJUSTMENT_DEBIT),
            'opening_days' => $sum(LeaveLedgerEntry::TYPE_OPENING),
            'expired_days' => -$sum(LeaveLedgerEntry::TYPE_EXPIRY),
            'used_days' => -$sum(LeaveLedgerEntry::TYPE_USAGE),
            'encashed_days' => -$sum(LeaveLedgerEntry::TYPE_ENCASHMENT),
            'comp_off_credits' => round($compOff, 2),
        ];

        // allocated_days keeps its historic meaning — the net of every credit
        // — so every screen reading allocated - used - encashed stays right.
        $figures['allocated_days'] = round(
            $figures['base_days'] + $figures['carried_forward_days'] + $figures['accrued_days']
            + $figures['add_on_days'] + $figures['adjustment_credit_days'] + $figures['opening_days']
            - $figures['adjustment_debit_days'] - $figures['expired_days'],
            2,
        );

        LeaveBalance::$rebuildingFromLedger = true;

        try {
            $balance->forceFill($figures + ['leave_year_id' => $year->id])->save();
        } finally {
            LeaveBalance::$rebuildingFromLedger = false;
        }

        return $balance->refresh();
    }

    /** The leave year a balance row belongs to, linking it if it was never linked. */
    public function yearOf(LeaveBalance $balance): LeaveYear
    {
        if ($balance->leave_year_id && ($year = LeaveYear::find($balance->leave_year_id))) {
            return $year;
        }

        // 31 December of the legacy start-year always lies in the leave year
        // that starts in that calendar year, whatever the start month.
        return LeaveYear::whereYear('starts_on', $balance->year)->orderBy('starts_on')->first()
            ?? $this->years->forDate(Carbon::create((int) $balance->year, 12, 31));
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * Run a posting under a row lock on the balance, refusing closed years.
     *
     * @template T
     *
     * @param  callable(LeaveYear): T  $work
     * @return T
     */
    private function transaction(LeaveBalance $balance, callable $work, array $options)
    {
        return DB::transaction(function () use ($balance, $work, $options) {
            // Serialises concurrent postings against the same balance.
            LeaveBalance::whereKey($balance->getKey())->lockForUpdate()->first();

            $year = $this->yearOf($balance);

            if ($year->isClosed() && empty($options['allow_closed_year'])) {
                throw new DomainException("Leave year {$year->label} is closed; its balances can only change through an authorised historical correction.");
            }

            return $work($year);
        });
    }

    private function insert(LeaveBalance $balance, LeaveYear $year, array $attributes): LeaveLedgerEntry
    {
        return LeaveLedgerEntry::create($attributes + [
            'employee_id' => $balance->employee_id,
            'leave_type_id' => $balance->leave_type_id,
            'leave_year_id' => $year->id,
            'bucket' => LeaveLedgerEntry::BUCKET_FOR[$attributes['entry_type']],
        ]);
    }

    /** @return array<string, mixed> */
    private function common(array $options): array
    {
        return [
            'source_type' => $options['source_type'] ?? null,
            'source_id' => $options['source_id'] ?? null,
            'reason' => $options['reason'] ?? null,
            'meta' => $options['meta'] ?? null,
            'created_by' => ($options['actor'] ?? null)?->id,
        ];
    }

    /**
     * Credit lots of this balance that still hold days and have not expired
     * by $asOf, in consumption order.
     *
     * @return Collection<int, LeaveLedgerEntry>
     */
    private function eligibleCredits(LeaveBalance $balance, LeaveYear $year, CarbonInterface $asOf): Collection
    {
        return LeaveLedgerEntry::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('leave_year_id', $year->id)
            ->whereIn('entry_type', LeaveLedgerEntry::CREDIT_TYPES)
            ->whereNull('reverses_entry_id')
            ->where('days', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $asOf->toDateString()))
            ->get()
            ->sortBy([
                fn (LeaveLedgerEntry $a, LeaveLedgerEntry $b) => LeaveLedgerEntry::CONSUMPTION_RANK[$a->entry_type] <=> LeaveLedgerEntry::CONSUMPTION_RANK[$b->entry_type],
                // Earliest expiry first; a lot that never expires goes last.
                fn (LeaveLedgerEntry $a, LeaveLedgerEntry $b) => ($a->expires_on?->timestamp ?? PHP_INT_MAX) <=> ($b->expires_on?->timestamp ?? PHP_INT_MAX),
                fn (LeaveLedgerEntry $a, LeaveLedgerEntry $b) => [$a->effective_date->timestamp, $a->id] <=> [$b->effective_date->timestamp, $b->id],
            ])
            ->values();
    }

    /**
     * Take $days from the given lots in order, recording each take. What no
     * lot covers is recorded against no credit (unbacked) when allowed.
     *
     * @param  Collection<int, LeaveLedgerEntry>  $credits
     */
    private function consume(LeaveLedgerEntry $debit, Collection $credits, float $days, bool $allowUnbacked): void
    {
        $needed = round($days, 2);

        foreach ($credits as $credit) {
            if ($needed <= self::EPSILON) {
                break;
            }

            $take = min($this->remaining($credit), $needed);

            if ($take <= self::EPSILON) {
                continue;
            }

            LeaveCreditConsumption::create(['debit_entry_id' => $debit->id, 'credit_entry_id' => $credit->id, 'days' => round($take, 2)]);
            $needed = round($needed - $take, 2);
        }

        if ($needed > self::EPSILON && $allowUnbacked) {
            LeaveCreditConsumption::create(['debit_entry_id' => $debit->id, 'credit_entry_id' => null, 'days' => $needed]);
        }
    }

    /**
     * Give a reversed debit's days back to the lots it took them from. Any
     * returned days landing on a lot that has since expired are expired
     * again straight away, so a late cancellation cannot revive dead leave.
     */
    private function returnConsumptions(LeaveLedgerEntry $original, LeaveLedgerEntry $reversal): void
    {
        $byCredit = LeaveCreditConsumption::where('debit_entry_id', $original->id)
            ->get()
            ->groupBy(fn (LeaveCreditConsumption $c) => $c->credit_entry_id ?? 'unbacked')
            ->map(fn (Collection $rows) => round($rows->sum(fn ($r) => (float) $r->days), 2));

        $expired = [];

        foreach ($byCredit as $creditId => $days) {
            if (abs($days) <= self::EPSILON) {
                continue;
            }

            LeaveCreditConsumption::create([
                'debit_entry_id' => $reversal->id,
                'credit_entry_id' => $creditId === 'unbacked' ? null : (int) $creditId,
                'days' => -$days,
            ]);

            if ($creditId !== 'unbacked') {
                $credit = LeaveLedgerEntry::find((int) $creditId);
                if ($credit?->expires_on && $credit->expires_on->lt(Carbon::today())) {
                    $expired[] = $credit;
                }
            }
        }

        foreach ($expired as $credit) {
            $this->expireCredit($credit, Carbon::today(), null, 'Returned days landed on an expired lot');
        }
    }

    /**
     * A new credit first covers any days previously taken with nothing to
     * take them from, oldest first. Recorded as a pair of rows on the
     * original debit (cover from the new lot, release the unbacked amount),
     * so a later reversal of that debit still returns days to the right lot.
     */
    private function settleUnbacked(LeaveBalance $balance, LeaveYear $year, LeaveLedgerEntry $credit): void
    {
        $debitIds = LeaveLedgerEntry::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('leave_year_id', $year->id)
            ->where('days', '<', 0)
            ->orderBy('effective_date')->orderBy('id')
            ->pluck('id');

        if ($debitIds->isEmpty()) {
            return;
        }

        $unbacked = LeaveCreditConsumption::whereIn('debit_entry_id', $debitIds)
            ->whereNull('credit_entry_id')
            ->get()
            ->groupBy('debit_entry_id')
            ->map(fn (Collection $rows) => round($rows->sum(fn ($r) => (float) $r->days), 2))
            ->filter(fn (float $days) => $days > self::EPSILON);

        foreach ($debitIds as $debitId) {
            $open = $unbacked[$debitId] ?? 0.0;
            $available = $this->remaining($credit);

            if ($open <= self::EPSILON || $available <= self::EPSILON) {
                continue;
            }

            $take = round(min($open, $available), 2);
            LeaveCreditConsumption::create(['debit_entry_id' => $debitId, 'credit_entry_id' => $credit->id, 'days' => $take]);
            LeaveCreditConsumption::create(['debit_entry_id' => $debitId, 'credit_entry_id' => null, 'days' => -$take]);
        }
    }

    private function balanceOf(LeaveLedgerEntry $entry): LeaveBalance
    {
        $year = LeaveYear::findOrFail($entry->leave_year_id);

        return LeaveBalance::where('employee_id', $entry->employee_id)
            ->where('leave_type_id', $entry->leave_type_id)
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear()))
            ->firstOrFail();
    }
}
