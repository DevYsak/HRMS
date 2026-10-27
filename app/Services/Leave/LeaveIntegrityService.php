<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveAccrualTransition;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only integrity checks over leave balances, the ledger and requests.
 * Finds what must never happen — and REPORTS it; nothing is repaired here.
 *
 * Each finding: check, employee_id, leave_type_id, reference, detail.
 */
class LeaveIntegrityService
{
    public const DUPLICATE_ACCRUAL = 'duplicate_accrual';

    public const DUPLICATE_DEDUCTION = 'duplicate_deduction';

    public const DUPLICATE_CARRY_FORWARD = 'duplicate_carry_forward';

    public const NEGATIVE_BALANCE = 'negative_balance';

    public const ORPHAN_LEDGER = 'orphan_ledger_row';

    public const BALANCE_LEDGER_MISMATCH = 'balance_ledger_mismatch';

    public const USAGE_MISMATCH = 'approved_paid_vs_ledger_usage';

    public const COMBINED_MISMATCH = 'combined_header_vs_day_rows';

    public const ACCRUAL_BEFORE_START = 'accrual_before_monthly_start';

    public const ACCRUAL_OUTSIDE_PERIOD = 'accrual_outside_eligible_period';

    public const ACCRUAL_OVER_CAP = 'accrual_over_annual_entitlement';

    public const DUPLICATE_KEY = 'duplicate_idempotency_key';

    public const MALFORMED_ACCRUAL_KEY = 'malformed_accrual_key';

    public const CHECKS = [
        self::DUPLICATE_ACCRUAL, self::DUPLICATE_DEDUCTION, self::DUPLICATE_CARRY_FORWARD, self::NEGATIVE_BALANCE,
        self::ORPHAN_LEDGER, self::BALANCE_LEDGER_MISMATCH, self::USAGE_MISMATCH, self::COMBINED_MISMATCH,
        self::ACCRUAL_BEFORE_START, self::ACCRUAL_OUTSIDE_PERIOD, self::ACCRUAL_OVER_CAP, self::DUPLICATE_KEY, self::MALFORMED_ACCRUAL_KEY,
    ];

    private const EPSILON = 0.005;

    public function __construct(private readonly LeaveBalanceCalculator $calculator) {}

    /**
     * @param  array<int, int>|null  $employeeIds  null = everyone
     * @return Collection<int, array{check: string, employee_id: ?int, leave_type_id: ?int, reference: string, detail: string}>
     */
    public function scan(LeaveYear $year, ?array $employeeIds = null): Collection
    {
        $entries = LeaveLedgerEntry::where('leave_year_id', $year->id)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->get();
        $reversed = $entries->whereNotNull('reverses_entry_id')->pluck('reverses_entry_id')->flip();
        $live = $entries->whereNull('reverses_entry_id')->reject(fn (LeaveLedgerEntry $e) => $reversed->has($e->id))->values();

        return collect()
            ->merge($this->duplicateAccruals($live))
            ->merge($this->duplicateDeductions($live))
            ->merge($this->duplicateCarryForwards($live))
            ->merge($this->balances($year, $employeeIds, $entries))
            ->merge($this->orphans($live))
            ->merge($this->usage($year, $employeeIds, $live))
            ->merge($this->combined($year, $employeeIds))
            ->merge($this->duplicateKeys($entries))
            ->merge($this->monthlyAccruals($year, $live))
            ->values();
    }

    /** One idempotency key, more than one row (the unique index should make this impossible). @return Collection<int, array<string, mixed>> */
    private function duplicateKeys(Collection $entries): Collection
    {
        return $entries->groupBy('idempotency_key')->filter(fn (Collection $g) => $g->count() > 1)
            ->map(fn (Collection $g, string $key) => $this->finding(self::DUPLICATE_KEY, $g->first(), 'entries #'.$g->pluck('id')->implode(', #'), "Key {$key} is used {$g->count()} times."))
            ->values();
    }

    /**
     * Automatic monthly Earned Leave accruals: before the employee's
     * "Monthly Accrual Starts From", outside their employment or the leave
     * year, for a month not yet completed when credited, under a key that
     * names another employee / year / month, or more than 12 in a year.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function monthlyAccruals(LeaveYear $year, Collection $live): Collection
    {
        $monthly = $live->where('entry_type', LeaveLedgerEntry::TYPE_ACCRUAL)
            ->filter(fn (LeaveLedgerEntry $e) => str_starts_with((string) $e->idempotency_key, EarnedLeaveAccrualService::KEY_PREFIX.':'));
        if ($monthly->isEmpty()) {
            return collect();
        }

        $employees = Employee::with('exitRecord')->whereIn('id', $monthly->pluck('employee_id')->unique())->get()->keyBy('id');
        $starts = LeaveAccrualTransition::whereIn('employee_id', $employees->keys())->pluck('starts_on', 'employee_id');
        $first = $year->starts_on->format('Y-m');
        $last = $year->ends_on->format('Y-m');
        $found = collect();

        foreach ($monthly as $e) {
            $month = (string) ($e->meta['month'] ?? '');
            $employee = $employees->get($e->employee_id);
            $ref = 'entry #'.$e->id;
            [, $keyEmployee, $keyYear, $keyMonth] = array_pad(explode(':', (string) $e->idempotency_key), 4, null);

            if ((int) $keyEmployee !== (int) $e->employee_id || (int) $keyYear !== (int) $e->leave_year_id || $keyMonth !== $month) {
                $found->push($this->finding(self::MALFORMED_ACCRUAL_KEY, $e, $ref, "Key {$e->idempotency_key} does not match the entry (employee {$e->employee_id}, year {$e->leave_year_id}, month {$month})."));
            }
            $start = $starts->get($e->employee_id);
            if ($start && $month < Carbon::parse($start)->format('Y-m')) {
                $found->push($this->finding(self::ACCRUAL_BEFORE_START, $e, $ref, "Accrued for {$month}, before Monthly Accrual Starts From ".Carbon::parse($start)->toDateString().'.'));
            }
            $joined = $employee?->joining_date ? Carbon::parse($employee->joining_date)->format('Y-m') : null;
            $left = $employee?->exitRecord?->last_working_day ? Carbon::parse($employee->exitRecord->last_working_day)->format('Y-m') : null;
            $why = match (true) {
                $month === '' => 'it names no month',
                $month < $first || $month > $last => "{$month} is outside {$year->label}",
                $joined !== null && $month < $joined => "{$month} is before joining ({$joined})",
                $left !== null && $month > $left => "{$month} is after the last working day ({$left})",
                $e->created_at !== null && Carbon::parse($month.'-01')->endOfMonth()->gt($e->created_at) => "{$month} was not complete when it was credited (".$e->created_at->toDateString().')',
                default => null,
            };
            if ($why !== null) {
                $found->push($this->finding(self::ACCRUAL_OUTSIDE_PERIOD, $e, $ref, "Monthly accrual outside the eligible period: {$why}."));
            }
        }

        $monthly->groupBy(fn (LeaveLedgerEntry $e) => $e->employee_id.':'.$e->leave_type_id)
            ->filter(fn (Collection $g) => (float) $g->sum('days') > EarnedLeaveAccrualService::ANNUAL_DAYS + self::EPSILON)
            ->each(fn (Collection $g) => $found->push($this->finding(self::ACCRUAL_OVER_CAP, $g->first(), 'entries #'.$g->pluck('id')->implode(', #'),
                ((float) $g->sum('days') + 0).' day(s) of monthly accrual in '.$year->label.', above the 12-day annual entitlement.')));

        return $found;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function duplicateAccruals(Collection $live): Collection
    {
        return $live->where('entry_type', LeaveLedgerEntry::TYPE_ACCRUAL)
            ->groupBy(fn (LeaveLedgerEntry $e) => $e->employee_id.':'.$e->leave_type_id.':'.($e->meta['month'] ?? $e->effective_date?->format('Y-m')))
            ->filter(fn (Collection $g) => $g->count() > 1)
            ->map(fn (Collection $g, string $key) => $this->finding(self::DUPLICATE_ACCRUAL, $g->first(),
                'entries #'.$g->pluck('id')->implode(', #'), $g->count().' accrual credits for month '.explode(':', $key)[2].'.'))
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function duplicateDeductions(Collection $live): Collection
    {
        return $live->where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->where('source_type', 'leave_request')
            ->groupBy(fn (LeaveLedgerEntry $e) => $e->source_id.':'.$e->leave_type_id)
            ->filter(fn (Collection $g) => $g->count() > 1)
            ->map(fn (Collection $g) => $this->finding(self::DUPLICATE_DEDUCTION, $g->first(),
                'LR-'.str_pad((string) $g->first()->source_id, 4, '0', STR_PAD_LEFT), $g->count().' live usage entries (#'.$g->pluck('id')->implode(', #').') for one request.'))
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function duplicateCarryForwards(Collection $live): Collection
    {
        return $live->where('entry_type', LeaveLedgerEntry::TYPE_CARRY_FORWARD)->where('source_type', EarnedLeaveRolloverService::SOURCE_TYPE)
            ->groupBy(fn (LeaveLedgerEntry $e) => $e->employee_id.':'.$e->leave_type_id)
            ->filter(fn (Collection $g) => $g->count() > 1)
            ->map(fn (Collection $g) => $this->finding(self::DUPLICATE_CARRY_FORWARD, $g->first(),
                'entries #'.$g->pluck('id')->implode(', #'), $g->count().' Earned Leave carry-forward credits into one year.'))
            ->values();
    }

    /** Negative balances and balance/ledger mismatches. @return Collection<int, array<string, mixed>> */
    private function balances(LeaveYear $year, ?array $employeeIds, Collection $entries): Collection
    {
        $sums = $entries->groupBy(fn (LeaveLedgerEntry $e) => $e->employee_id.':'.$e->leave_type_id)->map(fn (Collection $g) => round((float) $g->sum('days'), 2));

        return LeaveBalance::with(['leaveType' => fn ($q) => $q->withTrashed()])->where('leave_year_id', $year->id)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->get()
            ->flatMap(function (LeaveBalance $balance) use ($sums) {
                $available = (float) $this->calculator->summary($balance)['approved_available'];
                $found = [];
                if ($balance->isLedgerBacked()) {
                    $ledger = (float) ($sums[$balance->employee_id.':'.$balance->leave_type_id] ?? 0);
                    if (abs($available - $ledger) > self::EPSILON) {
                        $found[] = $this->finding(self::BALANCE_LEDGER_MISMATCH, $balance, 'balance #'.$balance->id,
                            "Calculated {$available} vs ledger {$ledger} ({$balance->leaveType?->name}).");
                    }
                }
                if ($available < -self::EPSILON) {
                    $found[] = $this->finding(self::NEGATIVE_BALANCE, $balance, 'balance #'.$balance->id, "Available {$available} ({$balance->leaveType?->name}).");
                }

                return $found;
            });
    }

    /** Usage for a request that is missing, or no longer approved. @return Collection<int, array<string, mixed>> */
    private function orphans(Collection $live): Collection
    {
        $usage = $live->where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->where('source_type', 'leave_request');
        $requests = LeaveRequest::withTrashed()->whereIn('id', $usage->pluck('source_id')->unique())->get()->keyBy('id');

        return $usage->map(function (LeaveLedgerEntry $e) use ($requests) {
            $request = $requests->get($e->source_id);

            return match (true) {
                $request === null => $this->finding(self::ORPHAN_LEDGER, $e, 'entry #'.$e->id, "Usage for request #{$e->source_id}, which does not exist."),
                $request->status !== 'approved' => $this->finding(self::ORPHAN_LEDGER, $e, 'entry #'.$e->id,
                    'Live usage for LR-'.str_pad((string) $request->id, 4, '0', STR_PAD_LEFT)." whose status is {$request->status}."),
                default => null,
            };
        })->filter()->values();
    }

    /** Approved paid days vs the request's live ledger usage. @return Collection<int, array<string, mixed>> */
    private function usage(LeaveYear $year, ?array $employeeIds, Collection $live): Collection
    {
        $usage = $live->where('entry_type', LeaveLedgerEntry::TYPE_USAGE)->where('source_type', 'leave_request')
            ->groupBy('source_id')->map(fn (Collection $g) => round(-(float) $g->sum('days'), 2));

        return LeaveRequest::with(['leaveType' => fn ($q) => $q->withTrashed()])->where('status', 'approved')
            ->whereDate('start_date', '>=', $year->starts_on->toDateString())->whereDate('start_date', '<=', $year->ends_on->toDateString())
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->get()
            ->map(function (LeaveRequest $r) use ($usage) {
                $paid = ($r->leaveType && ! $r->leaveType->is_paid) || $r->effectiveLeaveStatus() === 'unpaid' ? 0.0 : $r->paidDays();
                $posted = (float) ($usage[$r->id] ?? 0);
                if (abs($paid - $posted) <= self::EPSILON || ($posted === 0.0 && ! $r->main_category && ! $r->isCombined())) {
                    // Legacy requests approved before the ledger carry no usage rows; only V2 requests must match.
                    return null;
                }

                return $this->finding(self::USAGE_MISMATCH, $r, 'LR-'.str_pad((string) $r->id, 4, '0', STR_PAD_LEFT),
                    "Approved paid {$paid} day(s) but ledger usage is {$posted}.");
            })->filter()->values();
    }

    /** Combined Leave: header days / LWP vs its day rows. @return Collection<int, array<string, mixed>> */
    private function combined(LeaveYear $year, ?array $employeeIds): Collection
    {
        $requests = LeaveRequest::where('request_mode', LeaveRequest::MODE_COMBINED)
            ->whereDate('start_date', '>=', $year->starts_on->toDateString())->whereDate('start_date', '<=', $year->ends_on->toDateString())
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->get();
        $rows = LeaveRequestDay::whereIn('leave_request_id', $requests->pluck('id'))->get()->groupBy('leave_request_id');

        return $requests->map(function (LeaveRequest $r) use ($rows) {
            $days = $rows->get($r->id, collect());
            $paid = round((float) $days->sum('paid_units'), 2);
            $unpaid = round((float) $days->sum('unpaid_units'), 2);
            if (abs(((float) $r->days) - ($paid + $unpaid)) <= self::EPSILON && abs(((float) ($r->lwp_days ?? 0)) - $unpaid) <= self::EPSILON) {
                return null;
            }

            return $this->finding(self::COMBINED_MISMATCH, $r, 'LR-'.str_pad((string) $r->id, 4, '0', STR_PAD_LEFT),
                "Header {$r->days} day(s), LWP {$r->lwp_days}; day rows {$paid} paid + {$unpaid} unpaid.");
        })->filter()->values();
    }

    /** @return array{check: string, employee_id: ?int, leave_type_id: ?int, reference: string, detail: string} */
    private function finding(string $check, object $subject, string $reference, string $detail): array
    {
        return [
            'check' => $check,
            'employee_id' => $subject->employee_id ?? null,
            'leave_type_id' => $subject->leave_type_id ?? null,
            'reference' => $reference,
            'detail' => $detail,
        ];
    }
}
