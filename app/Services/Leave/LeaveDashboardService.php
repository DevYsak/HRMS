<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Security\PeopleScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The HR dashboard's leave figures, over the viewer's reach
 * (LeaveRequestAccess): who is on leave today, what is pending, upcoming,
 * unpaid this month, and whose Earned Leave is low or below zero. Balances
 * come from LeaveBalanceCalculator — never raw columns. Read only.
 */
class LeaveDashboardService
{
    /** At or below this many days of Earned Leave, an employee is flagged as low. */
    public const LOW_EARNED_DAYS = 2.0;

    public const PENDING = ['pending', 'pending_hr', 'more_info_requested'];

    public function __construct(
        private readonly LeaveRequestAccess $access,
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeaveYearResolver $years,
    ) {}

    /**
     * @return array{on_leave_today: int, pending: int, upcoming: int, low_earned: int, unpaid_requests: int, unpaid_days: float, exceptions: int, month: string}
     */
    public function cards(User $user): array
    {
        $ids = $this->access->employeeIds($user);
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();
        $earned = $this->earnedAvailable($ids);

        $unpaid = $this->requests($ids)->where('status', 'approved')
            ->whereDate('start_date', '<=', $monthEnd->toDateString())->whereDate('end_date', '>=', $monthStart->toDateString())
            ->where(fn (Builder $w) => $w->where('lwp_days', '>', 0)->orWhereHas('leaveType', fn (Builder $t) => $t->withTrashed()->where('is_paid', false)))
            ->with(['leaveType' => fn ($q) => $q->withTrashed()])->get();

        return [
            'on_leave_today' => $this->requests($ids)->where('status', 'approved')
                ->whereDate('start_date', '<=', $today->toDateString())->whereDate('end_date', '>=', $today->toDateString())
                ->distinct()->count('employee_id'),
            'pending' => $this->requests($ids)->whereIn('status', self::PENDING)->count(),
            'upcoming' => $this->requests($ids)->where('status', 'approved')
                ->whereDate('start_date', '>', $today->toDateString())->whereDate('start_date', '<=', $today->copy()->addDays(30)->toDateString())->count(),
            'low_earned' => $earned->filter(fn (float $available) => $available >= 0 && $available <= self::LOW_EARNED_DAYS)->count(),
            'unpaid_requests' => $unpaid->count(),
            'unpaid_days' => round($unpaid->sum(fn (LeaveRequest $r) => LeaveRequestPresenter::unpaid($r)), 2),
            'exceptions' => $earned->filter(fn (float $available) => $available < 0)->count(),
            'month' => $today->format('M Y'),
        ];
    }

    /** @return Collection<int, LeaveRequest> */
    public function pending(User $user, int $limit = 8): Collection
    {
        return $this->requests($this->access->employeeIds($user))
            ->whereIn('status', self::PENDING)
            ->with(['employee.user', 'employee.department', 'employee.manager', 'leaveType' => fn ($q) => $q->withTrashed()])
            ->orderBy('start_date')->limit($limit)->get();
    }

    /**
     * Earned Leave available (approved) for every working employee in reach
     * whose policy names an Earned Leave balance — employee id => days. An
     * employee with no balance row yet counts as 0.
     *
     * @param  array<int, int>|null  $ids  null = company-wide
     * @return Collection<int, float>
     */
    public function earnedAvailable(?array $ids): Collection
    {
        $year = $this->years->forDate(Carbon::today());
        $resolver = app(LeavePolicyAssignmentResolver::class);
        $poolOf = LeavePolicy::whereNotNull('earned_leave_type_id')->pluck('earned_leave_type_id', 'id');

        if ($poolOf->isEmpty()) {
            return collect();
        }

        $employees = Employee::query()->whereIn('status', PeopleScope::WORKING_STATUSES)
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('id', $ids))->get();

        $pools = $employees->mapWithKeys(fn (Employee $e) => [$e->id => $poolOf[$resolver->policyFor($e, Carbon::today())?->id] ?? null])->filter();

        $balances = LeaveBalance::whereIn('employee_id', $pools->keys())->whereIn('leave_type_id', $pools->unique()->values())
            ->where(fn (Builder $q) => $q->where('leave_year_id', $year->id)->orWhere(fn (Builder $w) => $w->whereNull('leave_year_id')->where('year', $year->legacyYear())))
            ->get()->keyBy(fn (LeaveBalance $b) => $b->employee_id.':'.$b->leave_type_id);

        return $pools->map(function (int $poolId, int $employeeId) use ($balances) {
            $balance = $balances[$employeeId.':'.$poolId] ?? null;

            return $balance ? (float) $this->calculator->summary($balance)['approved_available'] : 0.0;
        });
    }

    public function year(): LeaveYear
    {
        return $this->years->forDate(Carbon::today());
    }

    /** @param  array<int, int>|null  $ids */
    private function requests(?array $ids): Builder
    {
        return LeaveRequest::query()->when($ids !== null, fn (Builder $q) => $q->whereIn('employee_id', $ids));
    }
}
