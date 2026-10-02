<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveEncashment;
use App\Models\LeaveEscalation;
use App\Models\LeaveRequest;
use App\Models\LeaveRolloverRecord;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The numbers behind HR → Leave Management (Phase 2D): the summary cards and
 * the employee balance table. Read-only; every figure comes from the ledger
 * summary (leave_balances buckets) and the calculator, never re-derived.
 */
class LeaveManagementService
{
    public function __construct(
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeaveExpiryService $expiry,
        private readonly LeaveYearResolver $years,
    ) {}

    /** @return array<string, int> */
    public function cards(LeaveYear $year): array
    {
        $today = Carbon::today()->toDateString();
        $eligible = Employee::whereIn('status', LeaveRuleResolver::ELIGIBLE_STATUSES);
        $inYear = fn () => LeaveBalance::where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear()));

        $withBalance = $inYear()->distinct()->pluck('employee_id');
        $previous = LeaveYear::where('ends_on', '<', $year->starts_on)->orderByDesc('ends_on')->first();

        return [
            'total_employees' => (clone $eligible)->count(),
            'missing_balances' => (clone $eligible)->whereNotIn('id', $withBalance)->count(),
            'pending_manager' => LeaveRequest::where('status', 'pending')->count(),
            'pending_hr' => LeaveRequest::where('status', 'pending_hr')->count(),
            'needs_info' => LeaveRequest::where('status', 'more_info_requested')->count(),
            'escalated' => LeaveEscalation::where('resolved', false)->count(),
            'encashment_pending' => LeaveEncashment::whereIn('status', ['pending', 'pending_finance'])->count(),
            'on_leave_today' => LeaveRequest::where('status', 'approved')->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->count(),
            'upcoming_leave' => LeaveRequest::where('status', 'approved')->whereDate('start_date', '>', $today)
                ->whereDate('start_date', '<=', Carbon::today()->addDays(30)->toDateString())->count(),
            'negative_balances' => $inYear()->whereRaw('allocated_days - used_days - COALESCE(encashed_days, 0) < -0.005')->count(),
            'carry_forward_pending' => $previous
                ? LeaveRolloverRecord::where('from_leave_year_id', $previous->id)->where('status', LeaveRolloverRecord::NEEDS_HR_REVIEW)->count()
                : 0,
            'expiring_leave' => $this->expiry->upcoming(30)->count(),
            'no_policy' => (clone $eligible)->whereNull('leave_policy_id')->count(),
            'reconciliation_issues' => $inYear()->where(fn ($q) => $q->whereNull('ledger_migrated_at')->orWhere('ledger_status', LeaveBalance::LEDGER_NEEDS_HR_REVIEW))->count(),
        ];
    }

    /**
     * One row per employee for one leave type and year.
     *
     * @param  array{search?: ?string, department_id?: ?int, manager_id?: ?int, office_id?: ?int, employment_type_id?: ?int, status?: ?string, policy_id?: ?int, flag?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(LeaveYear $year, LeaveType $type, array $filters = []): Collection
    {
        $today = Carbon::today()->toDateString();
        $search = trim((string) ($filters['search'] ?? ''));

        $employees = Employee::with(['user', 'department', 'manager', 'leavePolicy'])
            ->when(($filters['status'] ?? null) === null, fn ($q) => $q->whereIn('status', LeaveRuleResolver::ELIGIBLE_STATUSES))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($filters['manager_id'] ?? null, fn ($q, $v) => $q->where('manager_id', $v))
            ->when($filters['office_id'] ?? null, fn ($q, $v) => $q->where('office_id', $v))
            ->when($filters['employment_type_id'] ?? null, fn ($q, $v) => $q->where('employment_type_id', $v))
            ->when($filters['policy_id'] ?? null, fn ($q, $v) => $q->where('leave_policy_id', $v))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('employee_id', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))))
            ->orderBy('id')
            ->get();

        $balances = LeaveBalance::whereIn('employee_id', $employees->pluck('id'))
            ->where('leave_type_id', $type->id)
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear()))
            ->get()->keyBy('employee_id');

        $pendingIds = LeaveRequest::whereIn('status', LeaveBalanceCalculator::RESERVING_STATUSES)->distinct()->pluck('employee_id')->flip();
        $onLeaveIds = LeaveRequest::where('status', 'approved')->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->pluck('employee_id')->flip();
        $upcomingIds = LeaveRequest::where('status', 'approved')->whereDate('start_date', '>', $today)
            ->whereDate('start_date', '<=', Carbon::today()->addDays(30)->toDateString())->pluck('employee_id')->flip();
        $expiringIds = $this->expiry->upcoming(30)->pluck('employee_id')->flip();
        $nextAccrual = $type->is_monthly_accrual ? Carbon::today()->addMonthNoOverflow()->startOfMonth()->format('d M Y') : null;

        $rows = $employees->map(function (Employee $e) use ($balances, $pendingIds, $onLeaveIds, $upcomingIds, $expiringIds, $year, $nextAccrual) {
            $balance = $balances->get($e->id);
            $s = $balance ? $this->calculator->summary($balance) : null;

            return [
                'employee_id' => $e->id,
                'name' => $e->user?->name ?? 'Employee #'.$e->id,
                'code' => $e->employee_id,
                'department' => $e->department?->name,
                'manager' => $e->manager?->name,
                'status' => $e->status?->label() ?? (string) $e->status,
                'policy' => $e->leavePolicy?->name,
                'leave_year' => $year->label,
                'balance_id' => $balance?->id,
                'has_balance' => $balance !== null,
                'ledger_status' => $balance?->ledger_status,
                'base' => $s['base'] ?? null,
                'carry_forward' => $s['carry_forward'] ?? null,
                'add_on' => $s['add_on'] ?? null,
                'accrued' => $s['accrued'] ?? null,
                'adjustment' => $s ? round($s['adjustment_credit'] + $s['opening'] - $s['adjustment_debit'], 2) : null,
                'used' => $s['used'] ?? null,
                'pending' => $s['pending'] ?? null,
                'expired' => $s['expired'] ?? null,
                'available' => $s['approved_available'] ?? null,
                'available_to_request' => $s['available_to_request'] ?? null,
                'next_accrual' => $nextAccrual,
                'flags' => [
                    'missing' => $balance === null,
                    'negative' => $s !== null && $s['approved_available'] < -0.005,
                    'pending' => $pendingIds->has($e->id),
                    'on_leave' => $onLeaveIds->has($e->id),
                    'upcoming' => $upcomingIds->has($e->id),
                    'expiring' => $expiringIds->has($e->id),
                    'no_policy' => $e->leave_policy_id === null,
                    'review' => $balance !== null && ($balance->ledger_migrated_at === null || $balance->ledger_status === LeaveBalance::LEDGER_NEEDS_HR_REVIEW),
                ],
            ];
        });

        return ($flag = $filters['flag'] ?? null)
            ? $rows->filter(fn (array $r) => $r['flags'][$flag] ?? false)->values()
            : $rows;
    }

    /**
     * Every leave type for one employee and year, with all buckets.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function employeeDetail(Employee $employee, LeaveYear $year): Collection
    {
        $balances = LeaveBalance::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear()))
            ->get();

        return $balances->map(fn (LeaveBalance $b) => [
            'balance' => $b,
            'leave_type' => $b->leaveType,
            'summary' => $this->calculator->summary($b),
        ])->sortBy(fn ($r) => $r['leave_type']?->name)->values();
    }
}
