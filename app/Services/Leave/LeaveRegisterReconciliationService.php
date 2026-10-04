<?php

namespace App\Services\Leave;

use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Restates an employee's CSL for one leave year to the HR register, and takes
 * the retired 28-day Annual Leave to zero — through the ledger only.
 *
 * Every change is an append: a reversal of an existing entry, or a new entry
 * with an idempotency key and source "conexus_register". Nothing is edited or
 * deleted, ledger-owned columns are only ever rebuilt from the ledger, and a
 * second run over an already-reconciled employee posts nothing.
 *
 * Per employee, in one transaction, verified before it commits:
 *
 *   Annual Leave  usage that belongs to a leave request moves to CSL (reversed
 *                 in AL, re-posted in CSL with the same request link, so a
 *                 later cancellation returns the day to CSL); every other
 *                 entry is reversed. The AL row ends at zero in every bucket.
 *   CSL           base = register credit, carry forward = register carry,
 *                 usage = register used; any other bucket is reversed to zero.
 *                 Usage the register proves only as a total is posted as one
 *                 aggregate register movement — no leave dates are invented.
 *
 * Refuses (the employee FAILs, nothing is saved for them) rather than guess:
 * an existing encashment that disagrees with the register (it was paid out),
 * recorded leave requests that already exceed the register's used figure, a
 * legacy row the backfill classifies BLOCKED, or duplicate rows.
 */
class LeaveRegisterReconciliationService
{
    public const SOURCE_TYPE = 'conexus_register';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    private const EPSILON = 0.005;

    /** Credit buckets the register states, and the ones it says must be zero. */
    private const CREDIT_TYPES = [
        LeaveLedgerEntry::TYPE_BASE,
        LeaveLedgerEntry::TYPE_CARRY_FORWARD,
        LeaveLedgerEntry::TYPE_ACCRUAL,
        LeaveLedgerEntry::TYPE_ADD_ON,
        LeaveLedgerEntry::TYPE_ADJUSTMENT_CREDIT,
        LeaveLedgerEntry::TYPE_OPENING,
    ];

    /** Debits other than usage and encashment, which the register says are zero. */
    private const ZERO_DEBIT_TYPES = [
        LeaveLedgerEntry::TYPE_ADJUSTMENT_DEBIT,
        LeaveLedgerEntry::TYPE_EXPIRY,
    ];

    private string $source = 'Conexus HR register reconciliation';

    public function __construct(
        private readonly LeaveLedgerService $ledger,
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeaveLedgerBackfillService $backfill,
        private readonly LeaveYearResolver $years,
    ) {}

    // ── Register ────────────────────────────────────────────────────────────

    /**
     * Load and verify the register file: structure, every row's formula and
     * the stated checksums. Throws before anything is touched.
     *
     * @return array{leave_year: string, source: string, checksums: array<string, float|int>, employees: array<int, array<string, mixed>>}
     */
    public function loadRegister(string $path): array
    {
        if (! is_file($path)) {
            throw new DomainException("Register file not found: {$path}");
        }

        $register = require $path;
        $rows = collect($register['employees'] ?? []);

        foreach ($rows as $i => $row) {
            foreach (['name', 'emails', 'credit', 'carry', 'used', 'encashed', 'available'] as $key) {
                if (! array_key_exists($key, $row)) {
                    throw new DomainException('Register row '.($i + 1)." is missing '{$key}'.");
                }
            }

            $computed = round($row['credit'] + $row['carry'] - $row['used'] - $row['encashed'], 2);
            if (abs($computed - (float) $row['available']) > self::EPSILON) {
                throw new DomainException("Register row for {$row['name']}: credit + carry - used - encashed = {$computed}, but available says {$row['available']}.");
            }
        }

        $checks = $register['checksums'] ?? [];
        $actual = [
            'credit' => round($rows->sum('credit'), 2),
            'carry' => round($rows->sum('carry'), 2),
            'used' => round($rows->sum('used'), 2),
            'available' => round($rows->sum('available'), 2),
            'rows' => $rows->count(),
        ];

        foreach ($actual as $key => $value) {
            if (! array_key_exists($key, $checks) || abs((float) $checks[$key] - $value) > self::EPSILON) {
                throw new DomainException("Register checksum '{$key}' is {$value}, expected ".($checks[$key] ?? 'missing').'.');
            }
        }

        $this->source = (string) ($register['source'] ?? $this->source);

        return $register;
    }

    /** The leave year a register label such as "2026/27" names. */
    public function yearFor(string $label): LeaveYear
    {
        if (! preg_match('/^(\d{4})/', $label, $m)) {
            throw new DomainException("Cannot read a leave year from '{$label}'.");
        }

        [$startsOn] = $this->years->boundsFor(Carbon::create((int) $m[1], 12, 31));
        $year = $this->years->forDate($startsOn);

        if ($year->label !== $label) {
            throw new DomainException("Register is for {$label}, but the company leave year starting {$startsOn->toDateString()} is {$year->label}.");
        }

        return $year;
    }

    /** The employee behind a register row: the live login first, then aliases. */
    public function findEmployee(array $row): ?Employee
    {
        foreach ($row['emails'] as $email) {
            $user = User::whereRaw('LOWER(email) = ?', [Str::lower(trim($email))])->first();

            if ($user?->employee) {
                return $user->employee;
            }
        }

        return null;
    }

    // ── Reconciliation ──────────────────────────────────────────────────────

    /**
     * Reconcile one employee. $row is null for an employee outside the
     * register: their Annual Leave is still taken to zero, their CSL is not
     * restated.
     *
     * @return array<int, string> a readable list of the movements posted
     *
     * @throws DomainException|RuntimeException — the whole employee is rolled back
     */
    public function reconcileEmployee(Employee $employee, ?array $row, LeaveYear $year, LeaveType $csl, ?LeaveType $annual, ?User $actor): array
    {
        return DB::transaction(function () use ($employee, $row, $year, $csl, $annual, $actor) {
            $log = [];
            $cslBalance = null;
            $cslFor = function () use (&$cslBalance, &$log, $employee, $csl, $year, $actor): LeaveBalance {
                return $cslBalance ??= $this->ledgerRow($employee, $csl, $year, $actor, $log, create: true);
            };

            if ($annual) {
                foreach ($this->rowsFor($employee, $annual, $year) as $annualBalance) {
                    $annualBalance = $this->ledgerRow($employee, $annual, $year, $actor, $log, existing: $annualBalance);
                    array_push($log, ...$this->neutraliseAnnual($annualBalance, $year, $cslFor, $actor));
                }
            }

            if ($row !== null) {
                array_push($log, ...$this->restateCsl($cslFor(), $year, $row, $actor));
            }

            if ($cslBalance) {
                $this->ledger->rebuild($cslBalance);
            }

            $this->verify($employee, $row, $year, $csl, $annual);

            return $log;
        });
    }

    /**
     * The verified figures for the final report.
     *
     * @return array<string, mixed>
     */
    public function report(Employee $employee, ?array $row, LeaveYear $year, LeaveType $csl, ?LeaveType $annual, ?LeaveType $compOff): array
    {
        $cslRow = $this->rowsFor($employee, $csl, $year)->first();
        $s = $cslRow ? $this->calculator->summary($cslRow->fresh()) : null;

        $annualActive = $annual
            ? round($this->rowsFor($employee, $annual, $year)->sum(fn (LeaveBalance $b) => $this->calculator->summary($b->fresh())['approved_available']), 2)
            : 0.0;

        $compOffAvailable = $compOff
            ? round($this->rowsFor($employee, $compOff, $year)->sum(fn (LeaveBalance $b) => $this->calculator->summary($b->fresh())['approved_available']), 2)
            : 0.0;

        return [
            'name' => $employee->user?->name ?? ('Employee #'.$employee->id),
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_id,
            'csl_credit' => $s ? round($s['base'], 2) : 0.0,
            'csl_carry' => $s ? round($s['carry_forward'], 2) : 0.0,
            'csl_used' => $s ? round($s['used'], 2) : 0.0,
            'csl_encashed' => $s ? round($s['encashed'], 2) : 0.0,
            'csl_available' => $s ? round($s['approved_available'], 2) : 0.0,
            'csl_other' => $s ? round($s['accrued'] + $s['add_on'] + $s['adjustment_credit'] - $s['adjustment_debit'] + $s['opening'] - $s['expired'], 2) : 0.0,
            'comp_off' => $compOffAvailable,
            'mdl_days' => DecemberMandatoryDay::forLeaveYear($year)->count(),
            'legacy_annual_active' => $annualActive,
            'ledger_backed' => (bool) $cslRow?->isLedgerBacked(),
        ];
    }

    /** Whether a report row matches its register row exactly. */
    public function matches(array $report, array $row): bool
    {
        return abs($report['csl_credit'] - (float) $row['credit']) <= self::EPSILON
            && abs($report['csl_carry'] - (float) $row['carry']) <= self::EPSILON
            && abs($report['csl_used'] - (float) $row['used']) <= self::EPSILON
            && abs($report['csl_encashed'] - (float) $row['encashed']) <= self::EPSILON
            && abs($report['csl_available'] - (float) $row['available']) <= self::EPSILON
            && abs($report['csl_other']) <= self::EPSILON
            && abs($report['legacy_annual_active']) <= self::EPSILON
            && $report['ledger_backed'];
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /** @return Collection<int, LeaveBalance> */
    private function rowsFor(Employee $employee, LeaveType $type, LeaveYear $year): Collection
    {
        return LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear()))
            ->get();
    }

    /** The single, ledger-backed row for a type and year — created empty when asked. */
    private function ledgerRow(Employee $employee, LeaveType $type, LeaveYear $year, ?User $actor, array &$log, bool $create = false, ?LeaveBalance $existing = null): LeaveBalance
    {
        if ($existing === null) {
            $rows = $this->rowsFor($employee, $type, $year);

            if ($rows->count() > 1) {
                throw new DomainException("{$type->name}: {$rows->count()} balance rows for {$year->label} (#".$rows->pluck('id')->implode(', #').'). Merge them before reconciling.');
            }

            $existing = $rows->first();
        }

        if ($existing === null) {
            if (! $create) {
                throw new DomainException("{$type->name}: no balance for {$year->label}.");
            }

            $existing = LeaveBalance::create([
                'employee_id' => $employee->id, 'leave_type_id' => $type->id,
                'year' => $year->legacyYear(), 'leave_year_id' => $year->id,
                'allocated_days' => 0, 'used_days' => 0, 'carried_forward_days' => 0,
                'encashed_days' => 0, 'comp_off_credits' => 0,
            ]);
            $log[] = "{$type->name}: opened an empty {$year->label} balance.";
        }

        if (! $existing->isLedgerBacked()) {
            $classification = $this->backfill->migrateRow($existing, $actor);
            $log[] = "{$type->name}: moved the legacy balance onto the ledger ({$classification}).";
            $existing = $existing->fresh();
        }

        return $existing;
    }

    /**
     * Active (not reversed, not themselves reversals) entries of a balance.
     *
     * @return Collection<int, LeaveLedgerEntry>
     */
    private function active(LeaveBalance $balance, LeaveYear $year): Collection
    {
        return LeaveLedgerEntry::where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('leave_year_id', $year->id)
            ->whereNull('reverses_entry_id')
            ->whereDoesntHave('reversedBy')
            ->orderBy('id')
            ->get();
    }

    /** @return array<int, string> */
    private function neutraliseAnnual(LeaveBalance $balance, LeaveYear $year, \Closure $cslFor, ?User $actor): array
    {
        $log = [];
        $entries = $this->active($balance, $year);

        if ($entries->contains(fn (LeaveLedgerEntry $e) => $e->entry_type === LeaveLedgerEntry::TYPE_ENCASHMENT)) {
            throw new DomainException('Annual Leave has an encashment in '.$year->label.' — that was paid out, so HR must decide how it maps to CSL before this employee can be reconciled.');
        }

        // Debits first, so a reversed credit never strands usage it backed.
        foreach ($entries->filter(fn (LeaveLedgerEntry $e) => (float) $e->days < 0) as $entry) {
            $days = abs((float) $entry->days);

            if ($entry->entry_type === LeaveLedgerEntry::TYPE_USAGE && $entry->source_type && $entry->source_type !== self::SOURCE_TYPE) {
                $this->ledger->reverse($entry, $this->source.': Annual Leave usage moved to CSL', $actor);
                $this->ledger->debit($cslFor(), LeaveLedgerEntry::TYPE_USAGE, $days, Carbon::parse($entry->effective_date),
                    self::SOURCE_TYPE.':transfer:'.$entry->id, [
                        // The original link, so cancelling the request later
                        // reverses this entry and returns the day to CSL.
                        'source_type' => $entry->source_type,
                        'source_id' => $entry->source_id,
                        'reason' => $this->source.': moved from Annual Leave',
                        'meta' => ['transferred_from_entry' => $entry->id, 'transferred_from_type' => ConexusLeavePolicyService::LEGACY_ANNUAL_CODE],
                        'actor' => $actor,
                    ]);
                $log[] = "Annual Leave → CSL: moved {$days} day(s) of usage ({$entry->source_type} #{$entry->source_id}).";

                continue;
            }

            $this->ledger->reverse($entry, $this->source.': Annual Leave is not Conexus policy', $actor);
            $log[] = "Annual Leave: reversed {$entry->entry_type} -{$days}.";
        }

        foreach ($entries->filter(fn (LeaveLedgerEntry $e) => (float) $e->days > 0) as $entry) {
            $this->ledger->reverse($entry, $this->source.': Annual Leave is not Conexus policy', $actor);
            $log[] = "Annual Leave: reversed {$entry->entry_type} +".(float) $entry->days.'.';
        }

        $this->ledger->rebuild($balance);

        return $log;
    }

    /** @return array<int, string> */
    private function restateCsl(LeaveBalance $balance, LeaveYear $year, array $row, ?User $actor): array
    {
        $log = [];
        $entries = $this->active($balance, $year);
        $sum = fn (string $type) => round((float) $entries->where('entry_type', $type)->sum('days'), 2);

        $encashed = -$sum(LeaveLedgerEntry::TYPE_ENCASHMENT) + 0.0;
        if (abs($encashed - (float) $row['encashed']) > self::EPSILON) {
            throw new DomainException("CSL shows {$encashed} day(s) encashed; the register says {$row['encashed']}. An encashment was paid out — HR must reconcile it.");
        }

        $options = fn (string $what) => [
            'source_type' => self::SOURCE_TYPE,
            'source_id' => $balance->employee_id,
            'reason' => $this->source.': '.$what,
            'meta' => ['register' => Arr::only($row, ['name', 'credit', 'carry', 'used', 'encashed', 'available'])],
            'actor' => $actor,
        ];

        // Credit buckets: the register states base and carry; the rest are zero.
        $targets = [
            LeaveLedgerEntry::TYPE_BASE => (float) $row['credit'],
            LeaveLedgerEntry::TYPE_CARRY_FORWARD => (float) $row['carry'],
        ];

        foreach (self::CREDIT_TYPES as $type) {
            $target = round($targets[$type] ?? 0.0, 2);
            $current = $sum($type);

            if (abs($current - $target) <= self::EPSILON) {
                continue;
            }

            foreach ($entries->where('entry_type', $type) as $entry) {
                $this->ledger->reverse($entry, $this->source.": restating {$type}", $actor);
            }

            if ($target > self::EPSILON) {
                $label = $type === LeaveLedgerEntry::TYPE_BASE ? 'current-year CSL credit' : 'carry forward';
                $this->ledger->credit($balance, $type, $target, $year->starts_on,
                    $this->key($balance, $year, $type), $options($label));
            }

            $log[] = "CSL {$type}: {$current} → {$target}.";
        }

        foreach (self::ZERO_DEBIT_TYPES as $type) {
            foreach ($entries->where('entry_type', $type) as $entry) {
                $this->ledger->reverse($entry, $this->source.": the register records no {$type}", $actor);
                $log[] = "CSL {$type}: reversed ".(float) $entry->days.'.';
            }
        }

        // Usage: what recorded requests already account for stays; the rest
        // of the register total is one aggregate movement.
        $usage = $entries->where('entry_type', LeaveLedgerEntry::TYPE_USAGE);
        $recorded = round(-(float) $usage->where('source_type', '!=', self::SOURCE_TYPE)->sum('days'), 2) + 0.0;
        $aggregate = $usage->where('source_type', self::SOURCE_TYPE);
        $currentAggregate = round(-(float) $aggregate->sum('days'), 2) + 0.0;
        $neededAggregate = round((float) $row['used'] - $recorded, 2);

        if ($neededAggregate < -self::EPSILON) {
            $recordedLines = $usage->where('source_type', '!=', self::SOURCE_TYPE)
                ->map(fn (LeaveLedgerEntry $e) => $e->effective_date->format('d M Y').' '.(-(float) $e->days + 0.0).'d ('.str_replace('_', ' ', (string) $e->source_type).' #'.$e->source_id.')')
                ->implode('; ');

            throw new DomainException("Recorded leave already accounts for {$recorded} day(s) of CSL usage; the register says {$row['used']}. HR must decide which leave was not taken — recorded: {$recordedLines}.");
        }

        if (abs($currentAggregate - $neededAggregate) > self::EPSILON) {
            foreach ($aggregate as $entry) {
                $this->ledger->reverse($entry, $this->source.': restating register usage', $actor);
            }

            if ($neededAggregate > self::EPSILON) {
                $usageOptions = $options('usage per HR register (aggregate; individual dates not recorded)');
                $usageOptions['meta']['aggregate'] = true;

                $this->ledger->debit($balance, LeaveLedgerEntry::TYPE_USAGE, $neededAggregate, Carbon::today(),
                    $this->key($balance, $year, LeaveLedgerEntry::TYPE_USAGE), $usageOptions);
            }

            $log[] = "CSL usage: recorded requests {$recorded} + register aggregate {$currentAggregate} → {$neededAggregate}.";
        }

        $this->ledger->rebuild($balance);

        return $log;
    }

    /** A fresh idempotency key per posting of a register bucket. */
    private function key(LeaveBalance $balance, LeaveYear $year, string $type): string
    {
        $version = LeaveLedgerEntry::where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('leave_year_id', $year->id)
            ->where('entry_type', $type)
            ->whereNull('reverses_entry_id')
            ->count() + 1;

        return self::SOURCE_TYPE.":{$year->id}:{$balance->employee_id}:{$balance->leave_type_id}:{$type}:v{$version}";
    }

    /** Throws when the rebuilt figures do not say exactly what was intended. */
    private function verify(Employee $employee, ?array $row, LeaveYear $year, LeaveType $csl, ?LeaveType $annual): void
    {
        if ($annual) {
            foreach ($this->rowsFor($employee, $annual, $year) as $balance) {
                $s = $this->calculator->summary($balance->fresh());
                $nonZero = collect(['base', 'carry_forward', 'accrued', 'add_on', 'adjustment_credit', 'adjustment_debit', 'opening', 'expired', 'used', 'encashed', 'approved_available'])
                    ->filter(fn ($k) => abs((float) $s[$k]) > self::EPSILON);

                if ($nonZero->isNotEmpty()) {
                    throw new RuntimeException('Annual Leave did not reach zero: '.$nonZero->map(fn ($k) => "{$k}={$s[$k]}")->implode(', '));
                }
            }
        }

        if ($row === null) {
            return;
        }

        $report = $this->report($employee, $row, $year, $csl, $annual, null);

        if (! $this->matches($report, $row)) {
            throw new RuntimeException(sprintf(
                'CSL verification failed: credit %s/%s, carry %s/%s, used %s/%s, encashed %s/%s, other %s/0, available %s/%s.',
                $report['csl_credit'], $row['credit'], $report['csl_carry'], $row['carry'], $report['csl_used'], $row['used'],
                $report['csl_encashed'], $row['encashed'], $report['csl_other'], $report['csl_available'], $row['available'],
            ));
        }
    }
}
