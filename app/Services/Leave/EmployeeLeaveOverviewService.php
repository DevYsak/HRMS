<?php

namespace App\Services\Leave;

use App\Models\Attendance;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Services\Attendance\HolidayResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One employee's leave position under the Conexus policy — the single source
 * every employee-facing widget reads (My Time Off hero, My Balances cards,
 * overview, insights, forecast, apply preview, and the dashboard).
 *
 * Every balance figure is LeaveBalanceCalculator's, for the leave year asked
 * (1 July – 30 June), unfloored:
 *
 *   Available Leave = CSL approved available + Comp Off approved available
 *
 * MDL is never part of it — it is six dated shutdown days, shown with their
 * status. Retired types (the 28-day Annual Leave) are never shown. Any other
 * leave type with a balance is listed separately and never added in.
 */
class EmployeeLeaveOverviewService
{
    public function __construct(
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeaveYearResolver $years,
        private readonly HolidayResolver $holidays,
    ) {}

    /**
     * @return array{
     *     year: LeaveYear, policy: array{name: ?string, csl_days: float, mdl_days: int, grant: string},
     *     csl: ?array<string, mixed>, comp_off: ?array<string, mixed>,
     *     mdl: array{dates: array<int, array<string, mixed>>, expected: int, configured: int, remaining: int},
     *     available_leave: float, available_to_request: float, pending_requests: int, taken_this_year: float,
     *     next_holiday: ?object, next_mdl: ?Carbon, others: Collection<int, array<string, mixed>>
     * }
     */
    public function for(?Employee $employee, ?LeaveYear $year = null): array
    {
        $year ??= $this->years->current();
        $policy = $this->policyFor($employee);

        $csl = $employee ? $this->typeCard($employee, $this->cslType(), $year) : null;
        $compOffType = LeaveType::where('code', ConexusLeavePolicyService::COMP_OFF_CODE)->first()
            ?? LeaveType::where('category', 'comp_off')->first();
        $compOff = $employee && $compOffType ? $this->typeCard($employee, $compOffType, $year) : null;

        if ($compOff) {
            $compOff['earned'] = round((float) LeaveLedgerEntry::where('employee_id', $employee->id)
                ->where('leave_type_id', $compOffType->id)
                ->where('leave_year_id', $year->id)
                ->where('entry_type', LeaveLedgerEntry::TYPE_ADD_ON)
                ->sum('days'), 2);
        }

        $mdl = $this->mdl($employee, $year, $compOffType);

        $next = $this->holidays->upcomingHolidays($employee, 1)->first();

        return [
            'year' => $year,
            'policy' => [
                'name' => $policy?->name,
                'csl_days' => ConexusLeavePolicyService::CSL_ANNUAL_DAYS,
                'mdl_days' => (int) ($policy?->mandatory_leave_days ?? ConexusLeavePolicyService::MDL_DAYS),
                'grant' => 'HR credits CSL; no automatic grant schedule is configured.',
            ],
            'csl' => $csl,
            'comp_off' => $compOff,
            'mdl' => $mdl,
            'available_leave' => round(($csl['summary']['approved_available'] ?? 0) + ($compOff['summary']['approved_available'] ?? 0), 2),
            'available_to_request' => round(($csl['summary']['available_to_request'] ?? 0) + ($compOff['summary']['available_to_request'] ?? 0), 2),
            'pending_requests' => $employee
                ? LeaveRequest::where('employee_id', $employee->id)->whereIn('status', LeaveBalanceCalculator::RESERVING_STATUSES)->count()
                : 0,
            'taken_this_year' => $employee
                ? round((float) LeaveRequest::where('employee_id', $employee->id)
                    ->where('status', 'approved')
                    ->whereDate('start_date', '>=', $year->starts_on->toDateString())
                    ->whereDate('start_date', '<=', $year->ends_on->toDateString())
                    ->sum('days'), 2)
                : 0.0,
            'next_holiday' => $next,
            'next_mdl' => collect($mdl['dates'])->firstWhere('status', 'upcoming')['date'] ?? null,
            'others' => $employee ? $this->others($employee, $year, array_filter([$csl['type']->id ?? null, $compOffType?->id])) : collect(),
        ];
    }

    public function cslType(): ?LeaveType
    {
        return LeaveType::where('code', ConexusLeavePolicyService::CSL_CODE)->first();
    }

    /** The one balance row for a type and year, and its calculator summary. */
    public function balanceFor(Employee $employee, LeaveType $type, LeaveYear $year): ?LeaveBalance
    {
        return LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear()))
            ->first();
    }

    /** @return array<string, mixed>|null */
    private function typeCard(Employee $employee, ?LeaveType $type, LeaveYear $year): ?array
    {
        if ($type === null) {
            return null;
        }

        $balance = $this->balanceFor($employee, $type, $year);
        $summary = $balance ? $this->calculator->summary($balance) : $this->emptySummary();

        return [
            'type' => $type,
            'balance' => $balance,
            'summary' => $summary,
            'encashable' => (bool) $type->allow_encashment && $type->category !== 'comp_off',
            'requestable' => (bool) ($type->allow_paid_request || $type->allow_unpaid_request),
        ];
    }

    /**
     * The year's shutdown dates, each Upcoming / Completed / Worked.
     *
     * @return array{dates: array<int, array<string, mixed>>, expected: int, configured: int, remaining: int}
     */
    private function mdl(?Employee $employee, LeaveYear $year, ?LeaveType $compOffType): array
    {
        $days = DecemberMandatoryDay::forLeaveYear($year);
        $today = Carbon::today();

        $worked = $employee
            ? Attendance::where('employee_id', $employee->id)
                ->whereIn('date', $days->map(fn ($d) => $d->date->toDateString()))
                ->whereNotNull('check_in')
                ->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->flip()
            : collect();

        $compOffKeys = $employee && $compOffType
            ? LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $compOffType->id)
                ->where('entry_type', LeaveLedgerEntry::TYPE_ADD_ON)
                ->whereNull('reverses_entry_id')
                ->pluck('idempotency_key')->flip()
            : collect();

        $dates = $days->map(function (DecemberMandatoryDay $d) use ($today, $worked, $compOffKeys, $employee, $compOffType) {
            $key = $d->date->toDateString();
            $status = match (true) {
                $worked->has($key) => 'worked',
                $d->date->lt($today) => 'completed',
                default => 'upcoming',
            };

            return [
                'date' => Carbon::parse($key),
                'description' => $d->description,
                'status' => $status,
                'comp_off_earned' => $status === 'worked' && $employee && $compOffType
                    && $compOffKeys->has("comp_off:{$employee->id}:{$compOffType->id}:{$key}"),
            ];
        })->values()->all();

        $policy = $this->policyFor($employee);

        return [
            'dates' => $dates,
            'expected' => (int) ($policy?->mandatory_leave_days ?? ConexusLeavePolicyService::MDL_DAYS),
            'configured' => count($dates),
            'remaining' => collect($dates)->where('status', 'upcoming')->count(),
        ];
    }

    /**
     * Other leave types holding a balance this year (special or statutory
     * leave), shown on their own and never added to Available Leave.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function others(Employee $employee, LeaveYear $year, array $excludeTypeIds): Collection
    {
        return LeaveBalance::with('leaveType')
            ->where('employee_id', $employee->id)
            ->whereNotIn('leave_type_id', $excludeTypeIds)
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear()))
            ->get()
            // Retired types (the 28-day Annual Leave) are history, not a balance.
            ->reject(fn (LeaveBalance $b) => $b->leaveType === null || $b->leaveType->trashed())
            ->map(fn (LeaveBalance $b) => ['type' => $b->leaveType, 'summary' => $this->calculator->summary($b)])
            ->filter(fn (array $o) => abs($o['summary']['credits']) > 0.005 || abs($o['summary']['used']) > 0.005)
            ->sortBy(fn (array $o) => $o['type']->name)
            ->values();
    }

    private function policyFor(?Employee $employee): ?LeavePolicy
    {
        return ($employee?->leave_policy_id ? LeavePolicy::find($employee->leave_policy_id) : null) ?? LeavePolicy::default();
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        return [
            'base' => 0.0, 'carry_forward' => 0.0, 'accrued' => 0.0, 'add_on' => 0.0,
            'adjustment_credit' => 0.0, 'adjustment_debit' => 0.0, 'opening' => 0.0,
            'expired' => 0.0, 'used' => 0.0, 'encashed' => 0.0, 'credits' => 0.0,
            'approved_available' => 0.0, 'pending' => 0.0, 'available_to_request' => 0.0,
            'ledger_backed' => false, 'ledger_status' => null,
        ];
    }
}
