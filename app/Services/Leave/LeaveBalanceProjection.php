<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Services\LeaveService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a paid request can draw on at its own dates — the one calculation the
 * request drawer, submit, approval and the HR request page all read, so the
 * employee and HR never see different numbers.
 *
 *   Balance today       credits − leave already taken (the ledger), with
 *                       leave approved for later dates added back: it is
 *                       booked at its own start date below, never twice
 *   + Expected accrual  Earned Leave months completed BEFORE the leave starts
 *                       and not yet credited — exactly the months the monthly
 *                       job will credit (EarnedLeaveAccrualService::planFor:
 *                       Monthly Accrual Starts From, joining date, last
 *                       working day, the 12-day cap, the leave year). The
 *                       month the leave falls in never counts.
 *   − Bookings          every other approved or pending paid request in the
 *                       leave year, each at its own start date (a past-dated
 *                       pending request at today)
 *
 * Checked date by date: at this request's start, and at the start of every
 * later booking, what has been earned by then must cover everything booked by
 * then (plus the policy's negative-balance limit). So a December request can
 * use October and November accrual, and a later booking can rely on later
 * accrual — but nothing is ever promised twice. "capacity" is the most paid
 * days this request can take.
 *
 * Read-only: it never writes a balance, a ledger entry or a request.
 */
class LeaveBalanceProjection
{
    private const EPSILON = 0.005;

    /** Statuses whose paid days are already deducted (approval) or reserved (pending). */
    private const BOOKED_STATUSES = [...LeaveBalanceCalculator::RESERVING_STATUSES, 'approved'];

    public function __construct(
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeaveYearResolver $years,
        private readonly LeaveBalanceSource $sources,
        private readonly LeavePolicyAssignmentResolver $assignments,
        private readonly LeaveRuleResolver $rules,
    ) {}

    /**
     * @return array{
     *     balance_type_id: int, balance_type: string, year: string, today: string, start: string,
     *     approved_available: float, pending: float, available_now: float, booked_ahead: float, balance_today: float,
     *     expected_accrual: float, expected_months: array<int, string>, accrual_note: ?string,
     *     booked_by_start: float, available_at_start: float, kept_for_later: float, negative_limit: float, capacity: float,
     *     limited_by: ?array<string, mixed>, bookings: array<int, array<string, mixed>>
     * }
     */
    public function for(Employee $employee, LeaveType $balanceType, CarbonInterface $start, ?int $excludeRequestId = null, ?CarbonInterface $today = null): array
    {
        $today = Carbon::parse($today ?? Carbon::today())->startOfDay();
        $start = Carbon::parse($start)->startOfDay();
        $year = $this->years->forDate($start);
        $from = $start->max($today);

        $row = app(LeaveService::class)->balanceForDate($employee->id, $balanceType->id, $start);
        $summary = $row ? $this->calculator->summary($row, $excludeRequestId) : ['approved_available' => 0.0, 'pending' => 0.0];
        $approved = round((float) $summary['approved_available'], 2);
        $bookings = $this->bookings($employee, $balanceType, $year, $today, $excludeRequestId);
        // Leave approved for a later date is already deducted; it is booked at its own date instead.
        $ahead = round((float) LeaveRequest::where('employee_id', $employee->id)->chargedTo($balanceType->id)->where('status', 'approved')
            ->whereDate('start_date', '>', $today->toDateString())
            ->whereDate('start_date', '>=', $year->starts_on->toDateString())->whereDate('start_date', '<=', $year->ends_on->toDateString())
            ->get()->filter(fn (LeaveRequest $r) => $this->paidStatus($r))->sum(fn (LeaveRequest $r) => $r->paidDays()), 2);
        $balanceToday = round($approved + $ahead, 2);

        $checkpoints = collect([$from])->merge($bookings->pluck('at')->filter(fn (Carbon $at) => $at->gt($from)))
            ->unique(fn (Carbon $at) => $at->toDateString())->sortBy(fn (Carbon $at) => $at->toDateString())->values();
        [$accrual, $note] = $this->accrual($employee, $balanceType, $year, $checkpoints->last());
        $earnedBy = fn (Carbon $at) => round($balanceToday + count(array_filter($accrual, fn (string $m) => $this->monthEnd($m)->lt($at))) * EarnedLeaveAccrualService::MONTHLY_DAYS, 2);
        $bookedBy = fn (Carbon $at) => round((float) $bookings->filter(fn (array $b) => $b['at']->lte($at))->sum('paid_days'), 2);
        $limit = round((float) ($this->rules->settings($employee, $balanceType, $start)['negative_balance_limit'] ?? 0), 2);

        // The tightest checkpoint on or after this request's start.
        $atStart = round($earnedBy($from) - $bookedBy($from), 2);
        $capacity = $atStart;
        $limitedBy = null;
        foreach ($checkpoints->slice(1) as $at) {
            $room = round($earnedBy($at) - $bookedBy($at), 2);
            if ($room < $capacity - self::EPSILON) {
                $capacity = $room;
                $limitedBy = $bookings->first(fn (array $b) => $b['at']->isSameDay($at));
            }
        }
        $expected = array_values(array_filter($accrual, fn (string $m) => $this->monthEnd($m)->lt($from)));

        return [
            'balance_type_id' => $balanceType->id,
            'balance_type' => $balanceType->name,
            'year' => $year->label,
            'today' => $today->toDateString(),
            'start' => $start->toDateString(),
            'approved_available' => $approved,
            'pending' => round((float) $summary['pending'], 2),
            'available_now' => round($approved - (float) $summary['pending'], 2),
            'booked_ahead' => $ahead,
            'balance_today' => $balanceToday,
            'expected_accrual' => round(count($expected) * EarnedLeaveAccrualService::MONTHLY_DAYS, 2),
            'expected_months' => $expected,
            'accrual_note' => $note,
            'booked_by_start' => $bookedBy($from),
            'available_at_start' => $atStart,
            'kept_for_later' => round($atStart - $capacity, 2),
            'negative_limit' => $limit,
            'capacity' => round($capacity + $limit, 2),
            'limited_by' => $limitedBy ? array_diff_key($limitedBy, ['at' => true]) : null,
            'bookings' => $bookings->map(fn (array $b) => array_diff_key($b, ['at' => true]))->values()->all(),
        ];
    }

    /**
     * Every other approved or pending paid request in the leave year, booked at
     * its start date (a pending one dated in the past at today). Approved leave
     * that has already started is in the ledger, so it is not booked again.
     *
     * @return Collection<int, array{id: int, reference: string, status: string, start_date: string, end_date: string, days: float, paid_days: float, reason: ?string, at: Carbon}>
     */
    private function bookings(Employee $employee, LeaveType $type, LeaveYear $year, Carbon $today, ?int $excludeRequestId): Collection
    {
        return LeaveRequest::with('leaveType')->where('employee_id', $employee->id)->chargedTo($type->id)->whereIn('status', self::BOOKED_STATUSES)
            ->whereDate('start_date', '>=', $year->starts_on->toDateString())->whereDate('start_date', '<=', $year->ends_on->toDateString())
            ->when($excludeRequestId, fn ($q, $id) => $q->whereKeyNot($id))
            ->orderBy('start_date')->orderBy('id')->get()
            ->filter(fn (LeaveRequest $r) => $this->paidStatus($r) && $r->paidDays() > 0
                && ($r->status !== 'approved' || Carbon::parse($r->start_date)->gt($today)))
            ->map(fn (LeaveRequest $r) => [
                'id' => $r->id,
                'reference' => 'LR-'.str_pad((string) $r->id, 4, '0', STR_PAD_LEFT),
                'status' => $r->status,
                'start_date' => Carbon::parse($r->start_date)->toDateString(),
                'end_date' => Carbon::parse($r->end_date)->toDateString(),
                'days' => round((float) $r->days, 2),
                'paid_days' => $r->paidDays(),
                'reason' => $r->leaveType?->name,
                'at' => Carbon::parse($r->start_date)->startOfDay()->max($today),
            ])->values();
    }

    /** Paid: a pending request asked as paid; an approved one approved as paid (as the deduction reads it). */
    private function paidStatus(LeaveRequest $request): bool
    {
        $status = $request->status === 'approved'
            ? ($request->approved_leave_status ?? $request->requested_leave_status ?? ($request->leaveType?->is_paid ? 'paid' : 'unpaid'))
            : ($request->requested_leave_status ?? 'paid');

        return $status === 'paid';
    }

    /**
     * The uncredited monthly accrual months the job will credit up to $until,
     * for the Earned Leave pool only. Nothing is projected while accrual is
     * held for review or blocked — the reason is reported instead.
     *
     * @return array{0: array<int, string>, 1: ?string}
     */
    private function accrual(Employee $employee, LeaveType $type, LeaveYear $year, Carbon $until): array
    {
        $pool = $this->sources->poolOf($this->assignments->policyFor($employee, $until));
        if (! $pool || $pool->id !== $type->id) {
            return [[], null];
        }

        $plan = app(EarnedLeaveAccrualService::class)->planFor($employee, $year, $until);

        return in_array($plan['status'], [EarnedLeaveAccrualService::READY, EarnedLeaveAccrualService::NO_CHANGE], true)
            ? [array_values($plan['due_months']), null]
            : [[], 'Expected monthly accrual is not counted: '.$plan['reason']];
    }

    /**
     * The sum behind a figure, in words — empty when it is just today's
     * balance (no expected accrual, no other booking).
     *
     * @param  array<string, mixed>|null  $p  a for() result
     */
    public static function explain(?array $p): string
    {
        if ($p === null || ($p['expected_accrual'] <= 0 && $p['bookings'] === [] && abs($p['booked_ahead']) < self::EPSILON)) {
            return '';
        }
        $n = fn (float $v) => (string) ($v + 0);
        $months = collect($p['expected_months'])->map(fn (string $m) => Carbon::parse($m.'-01')->format('M'))->implode(', ');

        return sprintf(' For %s: %s balance today + %s expected monthly accrual%s − %s already booked on or before that date%s = %s.',
            Carbon::parse(max($p['start'], $p['today']))->format('d M Y'), $n($p['balance_today']), $n($p['expected_accrual']), $months !== '' ? " ({$months})" : '',
            $n($p['booked_by_start']),
            $p['kept_for_later'] > self::EPSILON ? ' − '.$n($p['kept_for_later']).' kept for your leave on '.Carbon::parse($p['limited_by']['start_date'] ?? $p['start'])->format('d M Y') : '',
            $n(round($p['available_at_start'] - $p['kept_for_later'], 2)));
    }

    private function monthEnd(string $month): Carbon
    {
        return Carbon::parse($month.'-01')->endOfMonth()->startOfDay();
    }
}
