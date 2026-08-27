<?php

namespace App\Services\Leave;

use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Attendance\AttendanceScoreEngine;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turning a past absence into approved leave.
 *
 * This is not a second approval engine. The request is an
 * AttendanceRegularisation carrying category='leave', and it travels the
 * existing manager → HR → admin chain with the existing approvers,
 * notifications, observer and audit trail. What lives here is only what
 * happens at the end of that chain, which differs for leave: a balance is
 * deducted and the day is marked as leave rather than punches being rewritten.
 *
 * Raw biometric punches are never touched. The attendance row's status changes
 * and the day is rescored by the existing engine; the punch history underneath
 * it stays exactly as the device recorded it.
 */
class LeaveRegularisationService
{
    public function __construct(private readonly AttendanceScoreEngine $scores) {}

    /**
     * Raise a leave regularisation.
     *
     * Everything that could make this request wrong is checked before the row
     * exists, so an invalid request never enters the approval queue for a
     * manager to discover.
     *
     * @throws RuntimeException with a message written to be shown to the requester
     */
    public function submit(
        Employee $employee,
        LeaveType $type,
        CarbonInterface $from,
        CarbonInterface $to,
        string $reason,
        User $requestedBy,
        ?float $duration = null,
        ?string $remarks = null,
        ?string $documentPath = null,
    ): AttendanceRegularisation {
        $days = $duration ?? $this->workingDaysBetween($from, $to);

        $this->assertValid($employee, $type, $from, $to, $days, $documentPath);

        return DB::transaction(function () use ($employee, $type, $from, $to, $reason, $requestedBy, $days, $remarks, $documentPath) {
            return AttendanceRegularisation::create([
                'employee_id' => $employee->id,
                // The existing attendance row for that day, when there is one.
                // A fully absent day may have none, and that is not an error.
                'attendance_id' => $this->attendanceOn($employee, $from)?->id,
                'work_date' => $from->toDateString(),
                'regularisation_type' => 'leave',
                'category' => 'leave',
                'leave_type_id' => $type->id,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'duration' => $days,
                'reason' => $reason,
                'remarks' => $remarks,
                'attachment_path' => $documentPath,
                'status' => 'pending',
                'stage' => 'manager_review',
                'applied_by' => $requestedBy->id,
            ]);
        });
    }

    /**
     * Apply an approved leave regularisation.
     *
     * Reached only from AttendanceService::applyRegularisation, which is the
     * single place any regularisation is written — so a leave correction goes
     * through the same approval gate, trail and applied_via marking as every
     * other kind.
     */
    public function apply(AttendanceRegularisation $reg, int $reviewerId): ?Attendance
    {
        if ($reg->category !== 'leave' || ! $reg->leave_type_id) {
            return null;
        }

        $employee = $reg->employee;
        $type = LeaveType::find($reg->leave_type_id);

        if (! $employee || ! $type) {
            return null;
        }

        $days = (float) ($reg->duration ?? 1);
        $from = Carbon::parse($reg->from_date ?? $reg->work_date);
        $to = Carbon::parse($reg->to_date ?? $reg->work_date);

        return DB::transaction(function () use ($reg, $employee, $type, $days, $from, $to, $reviewerId) {
            $balance = $this->balanceFor($employee, $type, $from);
            $before = $this->available($balance);

            $balance->used_days = round((float) $balance->used_days + $days, 2);
            $balance->save();

            $after = $this->available($balance->fresh());

            // Recorded against the existing adjustments table rather than a
            // parallel one, but tagged: payroll and reporting have to be able
            // to tell a regularised day from an HR correction.
            LeaveBalanceAdjustment::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'action' => 'debit',
                'source' => 'regularisation',
                'source_id' => $reg->id,
                'days' => $days,
                'previous_balance' => $before,
                'new_balance' => $after,
                'reason' => 'Leave regularisation #'.$reg->id,
                'remarks' => $reg->reason,
                'adjusted_by' => $reviewerId,
                'adjusted_at' => now(),
            ]);

            $previousStatus = $this->markAttendanceAsLeave($employee, $from, $to);

            $reg->forceFill([
                'previous_balance' => $before,
                'new_balance' => $after,
                'previous_attendance_status' => $previousStatus,
            ])->save();

            $this->audit($reg, $employee, $type, $days, $before, $after, $previousStatus, $reviewerId);

            return $reg->attendance?->fresh() ?? $this->attendanceOn($employee, $from);
        });
    }

    /** Withdraw a request that has not been decided yet. */
    public function cancel(AttendanceRegularisation $reg, User $actor): AttendanceRegularisation
    {
        if ($reg->status !== 'pending') {
            throw new RuntimeException('Only a pending regularisation can be cancelled.');
        }

        $trail = $reg->approval_trail ?? [];
        $trail[] = [
            'stage' => $reg->stage,
            'action' => 'cancelled',
            'by' => $actor->id,
            'name' => $actor->name,
            'at' => now()->toDateTimeString(),
        ];

        $reg->update([
            'status' => 'cancelled',
            'cancelled_by' => $actor->id,
            'cancelled_at' => now(),
            'approval_trail' => $trail,
        ]);

        return $reg->fresh();
    }

    /**
     * Everything that must hold before a request may exist.
     *
     * @throws RuntimeException
     */
    private function assertValid(Employee $employee, LeaveType $type, CarbonInterface $from, CarbonInterface $to, float $days, ?string $documentPath): void
    {
        if ($to->lt($from)) {
            throw new RuntimeException('The end date cannot be before the start date.');
        }

        if ($days <= 0) {
            throw new RuntimeException('A regularisation must cover at least part of a day.');
        }

        $max = (float) config('leave_regularisation.max_days_per_request', 5);
        if ($max > 0 && $days > $max) {
            throw new RuntimeException("A regularisation may not cover more than {$max} days. Raise a leave request instead.");
        }

        if (! config('leave_regularisation.allow_future_dates', false) && $to->isFuture()) {
            throw new RuntimeException('A regularisation corrects a past date. Use a leave request for future dates.');
        }

        $window = (int) config('leave_regularisation.window_days', 30);
        if ($window > 0 && $from->lt(now()->subDays($window)->startOfDay())) {
            throw new RuntimeException("Regularisation is only allowed within {$window} days. Ask HR to make a manual correction.");
        }

        if (config('leave_regularisation.require_document', false) && ! $documentPath) {
            throw new RuntimeException('A supporting document is required for a leave regularisation.');
        }

        if ($type->trashed() ?? false) {
            throw new RuntimeException('That leave type is no longer active.');
        }

        // A day already covered by a decided leave request is not an absence.
        if ($this->hasApprovedLeaveBetween($employee, $from, $to)) {
            throw new RuntimeException('This employee already has approved leave covering that period.');
        }

        if ($this->hasOverlappingRegularisation($employee, $from, $to)) {
            throw new RuntimeException('A leave regularisation already exists for that period.');
        }

        if (config('leave_regularisation.require_sufficient_balance', true)) {
            $balance = $this->balanceFor($employee, $type, $from, create: false);
            $available = $balance ? $this->available($balance) : 0.0;

            if ($available < $days) {
                throw new RuntimeException("Not enough {$type->name} balance — {$available} available, {$days} requested.");
            }
        }
    }

    private function hasApprovedLeaveBetween(Employee $employee, CarbonInterface $from, CarbonInterface $to): bool
    {
        return LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->exists();
    }

    private function hasOverlappingRegularisation(Employee $employee, CarbonInterface $from, CarbonInterface $to): bool
    {
        return AttendanceRegularisation::where('employee_id', $employee->id)
            ->where('category', 'leave')
            ->whereIn('status', ['pending', 'approved'])
            ->where(function ($q) use ($from, $to) {
                $q->where(function ($range) use ($from, $to) {
                    $range->whereNotNull('from_date')
                        ->whereDate('from_date', '<=', $to->toDateString())
                        ->whereDate('to_date', '>=', $from->toDateString());
                })->orWhere(function ($single) use ($from, $to) {
                    $single->whereNull('from_date')
                        ->whereDate('work_date', '>=', $from->toDateString())
                        ->whereDate('work_date', '<=', $to->toDateString());
                });
            })
            ->exists();
    }

    /**
     * Mark the affected days as leave and rescore them.
     *
     * The attendance row's status changes; check_in, check_out and every punch
     * beneath them are left alone. The engine still owns what the raw data
     * means — this only tells it the day was authorised.
     *
     * @return string|null the status the first affected day held before
     */
    private function markAttendanceAsLeave(Employee $employee, CarbonInterface $from, CarbonInterface $to): ?string
    {
        $previous = null;

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $attendance = $this->attendanceOn($employee, $day);

            if ($attendance) {
                $previous ??= $attendance->status;
                // Punch columns deliberately untouched.
                $attendance->update(['status' => 'leave', 'is_regularized' => true]);
            } else {
                $previous ??= 'no_record';
                Attendance::create([
                    'employee_id' => $employee->id,
                    'date' => $day->toDateString(),
                    'check_in' => Carbon::parse($day->toDateString().' 00:00:00'),
                    'status' => 'leave',
                    'work_mode' => 'office',
                    'is_regularized' => true,
                ]);
            }

            $this->scores->scoreDay($employee, $day->toDateString());
        }

        return $previous;
    }

    private function attendanceOn(Employee $employee, CarbonInterface $date): ?Attendance
    {
        return Attendance::where('employee_id', $employee->id)
            ->whereDate('date', $date->toDateString())
            ->first();
    }

    /**
     * The balance the regularised date belongs to.
     *
     * Anchored to the leave date, never to today and never to "the most recent
     * row". A regularisation is by definition about the past: correcting a
     * 20 June 2025 absence must reach the 2024/25 balance whether it is
     * actioned in July 2025 or a year later. Picking the latest row instead
     * would quietly debit whichever year happened to be newest.
     */
    private function balanceFor(Employee $employee, LeaveType $type, CarbonInterface $date, bool $create = true): ?LeaveBalance
    {
        $year = app(LeaveYearResolver::class)->legacyYearFor($date);

        $balance = LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $year)
            ->first();

        if ($balance || ! $create) {
            return $balance;
        }

        return LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => $year,
            'allocated_days' => 0,
            'used_days' => 0,
        ]);
    }

    private function available(LeaveBalance $balance): float
    {
        return round(
            (float) $balance->allocated_days
            - (float) $balance->used_days
            - (float) ($balance->encashed_days ?? 0),
            2
        );
    }

    /** Working days, weekends excluded — a Saturday absence is not leave. */
    private function workingDaysBetween(CarbonInterface $from, CarbonInterface $to): float
    {
        $days = 0;

        foreach (CarbonPeriod::create($from, $to) as $day) {
            if (! $day->isWeekend()) {
                $days++;
            }
        }

        return (float) $days;
    }

    private function audit(
        AttendanceRegularisation $reg,
        Employee $employee,
        LeaveType $type,
        float $days,
        float $before,
        float $after,
        ?string $previousStatus,
        int $reviewerId,
    ): void {
        AuditLog::record(
            $reg,
            'leave.regularisation_approved',
            [
                'available_days' => $before,
                'attendance_status' => $previousStatus,
            ],
            [
                'available_days' => $after,
                'attendance_status' => 'leave',
                'leave_type' => $type->name,
                'leave_type_id' => $type->id,
                'duration' => $days,
                'source' => 'regularisation',
                'regularisation_request_id' => $reg->id,
                'from_date' => $reg->from_date?->toDateString() ?? $reg->work_date?->toDateString(),
                'to_date' => $reg->to_date?->toDateString() ?? $reg->work_date?->toDateString(),
                'approved_by' => $reviewerId,
                'approved_at' => now()->toDateTimeString(),
            ],
            $reg->reason,
            $employee->id,
        );
    }
}
