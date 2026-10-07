<?php

namespace App\Services;

use App\Enums\AttendanceMode;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\BreakLog;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\AttendanceScoreEngine;
use App\Services\Attendance\HolidayResolver;
use App\Services\Attendance\ResolvedShift;
use App\Services\Attendance\ShiftResolver;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\Audit\AuditService;
use App\Services\Leave\LeaveRegularisationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public function __construct(protected ShiftResolver $shifts) {}

    /**
     * Record an arrival.
     *
     * The shift is optional because a punch is a fact and lateness is a
     * judgement. An employee HR has not yet assigned a shift to must still be
     * able to record that they are at work — barring them would lose the
     * attendance record entirely over a configuration gap. Without a shift
     * there is simply no window to be late against, so the day is stored as
     * on_time with zero late minutes and the score engine skips it.
     */
    public function checkIn(Employee $employee, ?ShiftSetting $shift, array $payload = []): Attendance
    {
        $now = Carbon::now();
        $isLate = false;
        $lateMinutes = 0;

        if ($shift && $shift->start_time) {
            $shiftStart = Carbon::parse($shift->start_time, config('app.timezone'))
                ->setDate($now->year, $now->month, $now->day);
            $cutoff = $shiftStart->copy()->addMinutes((int) $shift->grace_minutes);

            // Minute precision (spec §3.2: 10:35 is on time, 10:36 is late).
            $arrivedAt = $now->copy()->startOfMinute();
            $isLate = $arrivedAt->gt($cutoff);
            $lateMinutes = $isLate ? (int) $cutoff->diffInMinutes($arrivedAt) : 0;
        }

        // A weekly off or holiday has no shift to be late for: the punch is
        // kept (Worked on Weekly Off), never flagged late.
        if ($isLate && app(WorkingDayResolver::class)->classify($employee, $now, withLeave: false) !== WorkingDayResolver::WORKING_DAY) {
            $isLate = false;
            $lateMinutes = 0;
        }

        return Attendance::create([
            'employee_id' => $employee->id,
            'date' => $now->toDateString(),
            'check_in' => $now,
            'check_in_ip' => $payload['ip'] ?? request()->ip(),
            'check_in_lat' => $payload['lat'] ?? null,
            'check_in_lng' => $payload['lng'] ?? null,
            'check_in_photo' => $payload['photo'] ?? null,
            'check_in_user_agent' => $payload['user_agent'] ?? request()->userAgent(),
            'work_mode' => in_array($mode = $payload['work_mode'] ?? 'office', AttendanceMode::values(), true) ? $mode : 'office',
            'status' => $isLate ? 'late' : 'on_time',
            'is_late' => $isLate,
            'late_minutes' => $lateMinutes,
        ]);
    }

    public function checkOut(Attendance $attendance, array $payload = []): Attendance
    {
        $now = Carbon::now();
        // Pulse v3.1: final clock-out − first clock-in; breaks are not deducted.
        $totalHours = app(AttendanceCalculator::class)->storedHours($attendance->check_in, $now);

        $attendance->update([
            'check_out' => $now,
            'check_out_ip' => $payload['ip'] ?? request()->ip(),
            'check_out_lat' => $payload['lat'] ?? null,
            'check_out_lng' => $payload['lng'] ?? null,
            'check_out_photo' => $payload['photo'] ?? null,
            'check_out_user_agent' => $payload['user_agent'] ?? request()->userAgent(),
            'total_hours' => $totalHours,
            // A real clock-out ends any "missing check-out" flag raised earlier.
            'missing_checkout' => false,
        ]);

        $this->creditCompOffIfEligible($attendance->fresh(['employee.shift', 'employee.office']), $now);

        return $attendance->fresh();
    }

    public function startBreak(Attendance $attendance): ?BreakLog
    {
        if ($attendance->check_out || $attendance->activeBreak()->exists()) {
            return null;
        }

        return BreakLog::create([
            'attendance_id' => $attendance->id,
            'employee_id' => $attendance->employee_id,
            'break_start' => Carbon::now(),
        ]);
    }

    public function endBreak(Attendance $attendance): ?BreakLog
    {
        $activeBreak = $attendance->activeBreak()->first();

        if (! $activeBreak) {
            return null;
        }

        $now = Carbon::now();
        $minutes = (int) $activeBreak->break_start->diffInMinutes($now);

        $activeBreak->update([
            'break_end' => $now,
            'duration_minutes' => $minutes,
        ]);

        $attendance->update([
            'break_minutes' => (int) $attendance->breakLogs()->whereNotNull('break_end')->sum('duration_minutes'),
        ]);

        return $activeBreak->fresh();
    }

    /**
     * Approve a regularisation request.
     * Routed directly to HR (HR Review → Approved): a holder of "Approve
     * Regularisations (HR)" approves and the correction is applied in the
     * same step. Managers no longer approve. Raw biometric logs are never
     * modified, and every action lands in the approval_trail audit.
     *
     * Returns the updated Attendance once applied.
     */
    public function approveRegularisation(AttendanceRegularisation $regularisation, int $reviewerId, ?string $comment = null): ?Attendance
    {
        if ($regularisation->status !== 'pending') {
            return $regularisation->attendance;
        }

        app(ApprovalGuard::class)->assertCanDecide($reviewerId, $regularisation->employee);

        $reviewer = User::find($reviewerId);
        $this->assertDecidesRegularisations($reviewer);

        $trail = $regularisation->approval_trail ?? [];
        $trail[] = [
            'stage' => 'hr_review',
            'action' => 'approved',
            'by' => $reviewerId,
            'name' => $reviewer?->name,
            'comment' => $comment,
            'at' => now()->toDateTimeString(),
        ];

        // HR's approval is final: the correction is applied now.
        return $this->applyRegularisation($regularisation, $reviewerId, $comment, $trail, 'hr_direct');
    }

    /**
     * Regularisations are routed directly to HR: only a holder of
     * "Approve Regularisations (HR)" decides them (Super Admin always).
     * Managers see their team's requests but no longer approve them.
     *
     * @throws \DomainException
     */
    private function assertDecidesRegularisations(?User $reviewer): void
    {
        if (! $reviewer?->canApproveRegularisations()) {
            throw new \DomainException('Regularisation requests are approved by HR.');
        }
    }

    /**
     * HR applies a correction immediately, without waiting for admin approval.
     *
     * The staged chain still exists and is still the default: this is a
     * deliberate, separately authorised shortcut for the case HR actually
     * hits — a punch is plainly wrong, HR has the evidence, and the employee's
     * hours should not stay wrong until an admin happens to look.
     *
     * It reaches the same applied state through the same application routine
     * as the admin path, so there is exactly one implementation of "correct an
     * attendance day". What differs is only the audit: the trail records a
     * fast-tracked action at hr_review, and applied_via marks the route.
     *
     * @throws \DomainException when the caller may not fast-track
     */
    public function fastTrackRegularisation(AttendanceRegularisation $regularisation, int $reviewerId, ?string $comment = null): ?Attendance
    {
        if ($regularisation->status !== 'pending') {
            return $regularisation->attendance;
        }

        app(ApprovalGuard::class)->assertCanDecide($reviewerId, $regularisation->employee);

        $reviewer = User::find($reviewerId);

        // Explicitly authorised, and not merely by being an approver: managers
        // approve regularisations but must not apply them unreviewed.
        // manage_attendance is held by HR, directors and super admins only.
        if (! $reviewer?->hasPermission('manage_attendance')) {
            throw new \DomainException('You are not authorised to apply a regularisation directly.');
        }

        $trail = $regularisation->approval_trail ?? [];
        $trail[] = [
            'stage' => $regularisation->stage ?: 'manager_review',
            'action' => 'fast_tracked',
            'by' => $reviewerId,
            'name' => $reviewer->name,
            'comment' => $comment,
            'at' => now()->toDateTimeString(),
        ];

        return $this->applyRegularisation($regularisation, $reviewerId, $comment, $trail, 'hr_fast_path');
    }

    /**
     * The one place a regularisation is actually written onto attendance.
     *
     * Both routes end here — the full manager → HR → admin chain and HR's
     * fast-path — so the correction, the original-value snapshot, the break and
     * late recompute, the punch journey, the OT filing and the rescore happen
     * identically however the request was approved. A second implementation is
     * how two routes end up producing different attendance from the same
     * request.
     *
     * @param  array<int, array<string, mixed>>  $trail
     * @param  string  $via  admin_chain | hr_fast_path
     */
    protected function applyRegularisation(
        AttendanceRegularisation $regularisation,
        int $reviewerId,
        ?string $comment,
        array $trail,
        string $via,
    ): ?Attendance {
        $before = Attendance::where('employee_id', $regularisation->employee_id)
            ->whereDate('date', $regularisation->work_date)->first();
        $snapshot = fn (?Attendance $a): ?array => $a ? [
            'check_in' => $a->check_in?->format('Y-m-d H:i'),
            'check_out' => $a->check_out?->format('Y-m-d H:i'),
            'status' => $a->status,
            'total_hours' => $a->total_hours !== null ? (float) $a->total_hours : null,
            'is_regularized' => (bool) $a->is_regularized,
        ] : null;

        $attendance = $this->applyRegularisationInTransaction($regularisation, $reviewerId, $comment, $trail, $via);

        // One categorised event with the day before and after — the decision
        // the manual-correction screens used to log with the after-state
        // stored as "old".
        app(AuditService::class)->event(
            $via === 'hr_fast_path' ? 'ATTENDANCE_MANUALLY_CORRECTED' : 'ATTENDANCE_REGULARISATION_APPROVED',
            AuditService::ATTENDANCE,
            $regularisation,
            old: ['attendance' => $snapshot($before)],
            new: ['attendance' => $snapshot($attendance), 'applied_via' => $via, 'category' => $regularisation->category],
            reason: $comment,
            subjectEmployeeId: $regularisation->employee_id,
            actor: User::find($reviewerId),
        );

        return $attendance;
    }

    /**
     * Apply an approved regularisation again after HR corrected its times —
     * through the one application routine, so the corrected day, punches, OT
     * and score are produced exactly as on first approval. The caller has
     * already reverted the previous correction and records the audit.
     */
    public function reapplyCorrectedRegularisation(AttendanceRegularisation $regularisation, User $actor, string $reason): ?Attendance
    {
        $trail = $regularisation->approval_trail ?? [];
        $trail[] = [
            'stage' => 'hr_review',
            'action' => 'corrected',
            'by' => $actor->id,
            'name' => $actor->name,
            'comment' => $reason,
            'at' => now()->toDateTimeString(),
        ];

        return $this->applyRegularisationInTransaction($regularisation, $actor->id, $reason, $trail, 'hr_correction');
    }

    /** @param  array<int, array<string, mixed>>  $trail */
    private function applyRegularisationInTransaction(
        AttendanceRegularisation $regularisation,
        int $reviewerId,
        ?string $comment,
        array $trail,
        string $via,
    ): ?Attendance {
        return DB::transaction(function () use ($regularisation, $reviewerId, $comment, $trail, $via) {
            $regularisation->update([
                'status' => 'approved',
                'stage' => 'admin_approval',
                'reviewer_id' => $reviewerId,
                'reviewer_comment' => $comment,
                'approval_trail' => $trail,
                'reviewed_at' => now(),
                // Who actually rewrote the attendance, and by which route —
                // reviewer_id alone cannot distinguish the two paths.
                'applied_by' => $reviewerId,
                'applied_at' => now(),
                'applied_via' => $via,
            ]);

            // A leave regularisation converts a past absence into approved
            // leave: a balance is deducted and the day is marked, rather than
            // punches being rewritten. It reaches this point through exactly
            // the same chain, trail and applied_via marking as every other
            // kind, which is the reason it lives behind this gate rather than
            // in a pipeline of its own.
            if ($regularisation->category === 'leave') {
                return app(LeaveRegularisationService::class)->apply($regularisation, $reviewerId);
            }

            // Half-day regularisation: mark the day as half-day rather than
            // rewriting punch times. Seeds check_in on a fully-absent day so the
            // NOT NULL column holds.
            if ($regularisation->regularisation_type === 'half_day') {
                $workDate = Carbon::parse($regularisation->work_date)->toDateString();
                // Seed a fully-absent half-day at the employee's shift start
                // (DB-driven), never a hardcoded clock time.
                $shift = $regularisation->employee
                    ? $this->shifts->resolve($regularisation->employee, $workDate)
                    : null;
                $seedCheckIn = $shift?->start ?? Carbon::parse($workDate.' 00:00:00');
                $attendance = $regularisation->attendance
                    ?? Attendance::firstOrCreate(
                        ['employee_id' => $regularisation->employee_id, 'date' => $regularisation->work_date],
                        ['check_in' => $seedCheckIn, 'status' => 'half_day', 'work_mode' => 'office'],
                    );

                if (! $regularisation->attendance_id) {
                    $regularisation->update(['attendance_id' => $attendance->id]);
                }

                $attendance->update(['status' => 'half_day', 'is_regularized' => true]);

                // Rescore the corrected day so the attendance score and its
                // audit breakdown reflect the approved correction immediately.
                if ($regularisation->employee) {
                    app(AttendanceScoreEngine::class)->scoreDay($regularisation->employee, $workDate);
                }

                return $attendance->fresh();
            }

            // requested_check_in/out are TIME columns — anchor them to the work
            // date so the corrected punch/attendance lands on the right day (not
            // "today" when the regularisation is loaded fresh from the DB).
            $workDate = Carbon::parse($regularisation->work_date)->toDateString();
            $checkIn = Carbon::parse($workDate.' '.Carbon::parse($regularisation->requested_check_in)->format('H:i:s'));
            $checkOut = Carbon::parse($workDate.' '.Carbon::parse($regularisation->requested_check_out)->format('H:i:s'));

            // A night shift clocks out on the following calendar day. Both
            // times are TIME columns anchored to the work date, so without this
            // a 22:00 → 06:00 correction spans minus sixteen hours and the
            // clamp below books the whole shift as zero hours worked.
            if ($checkOut->lessThanOrEqualTo($checkIn)) {
                $checkOut->addDay();
            }

            // Seed check_in/out on create so regularising a fully-absent day
            // (no existing attendance row) doesn't violate the NOT NULL columns.
            $attendance = $regularisation->attendance
                ?? Attendance::firstOrCreate(
                    ['employee_id' => $regularisation->employee_id, 'date' => $regularisation->work_date],
                    ['check_in' => $checkIn, 'check_out' => $checkOut, 'status' => 'on_time', 'work_mode' => 'office'],
                );

            if (! $regularisation->attendance_id) {
                $regularisation->update(['attendance_id' => $attendance->id]);
            }

            // Resolved before the hours are worked out: the break fallback
            // needs the shift's unpaid break, not just the late calculation.
            $shift = $regularisation->employee
                ? $this->shifts->resolve($regularisation->employee, $workDate)
                : null;

            // Pulse v3.1: worked = corrected final out − corrected first in.
            // The break figure is kept for information and is not deducted.
            $breakMinutes = $this->breakMinutesForCorrection($attendance, $checkIn, $checkOut, $shift);

            // Preserve the ORIGINAL punch immutably the first time this day is
            // corrected — the raw punch is never lost, only snapshotted. Later
            // re-approvals keep the very first original.
            $original = [];
            if ($attendance->original_check_in === null && $attendance->original_check_out === null) {
                $original = [
                    'original_check_in' => $attendance->check_in,
                    'original_check_out' => $attendance->check_out,
                ];
            }

            // Late is recomputed against the shift, NOT forced to on_time — a
            // punch corrected to 10:40 on an 09:00 shift is still late.
            $isLate = $shift ? $shift->isLate($checkIn) : (bool) $attendance->is_late;
            $lateMinutes = $shift ? $shift->lateMinutes($checkIn) : (int) ($attendance->late_minutes ?? 0);

            // Times + methods: keep any existing value when the request left a
            // field null (only the corrected punch is overwritten).
            $punchFields = array_filter([
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'check_in_method' => $regularisation->check_in_method,
                'check_out_method' => $regularisation->check_out_method,
            ], fn ($v) => $v !== null);

            $attendance->update($original + $punchFields + [
                'break_minutes' => $breakMinutes,
                'total_hours' => app(AttendanceCalculator::class)->storedHours($checkIn, $checkOut),
                'status' => $isLate ? 'late' : 'on_time',
                'is_late' => $isLate,
                'late_minutes' => $lateMinutes,
                'missing_checkout' => false,
                'is_auto_checkout' => false,   // a real correction supersedes any system auto-close
                'auto_checkout_reason' => null,
                'is_regularized' => true,
            ]);

            // Write the corrected in/out into the punch journey (with the
            // declared method) so the employee's Attendance Journey reflects the
            // approved fix — not just the summary times. Without this the journey
            // is built purely from biometric punches and the correction is
            // invisible to the employee even though the admin summary shows it.
            $this->writeRegularisedPunches($regularisation, $checkIn, $checkOut);

            // If the corrected day now exceeds the OT threshold, file the OT
            // request and auto-approve it under the same reviewer so overtime
            // flows straight to payroll — approving the regularisation approves
            // the overtime it produced (materialises the OvertimeRecord).
            $otRequest = app(OvertimeService::class)->autoCreateFromAttendance($attendance->fresh(['employee.shift']));
            if ($otRequest) {
                app(OvertimeService::class)->approve($otRequest, $reviewerId, 'Auto-approved with attendance regularisation.');
            }

            // Rescore the corrected day so the attendance score and its audit
            // breakdown reflect the approved correction immediately.
            if ($regularisation->employee) {
                app(AttendanceScoreEngine::class)->scoreDay($regularisation->employee, $workDate);
            }

            return $attendance->fresh();
        });
    }

    /**
     * Upsert the corrected check-in / check-out as journey punches so an
     * approved regularisation appears in the employee's Attendance Journey.
     * Keyed on (employee, punched_at) for idempotency and tagged
     * source='regularisation' so it is distinguishable from device punches.
     * Near-duplicate device punches (a few seconds off) are harmless — the
     * PunchClassifier collapses them when the journey is rendered.
     */
    private function writeRegularisedPunches(AttendanceRegularisation $regularisation, Carbon $checkIn, Carbon $checkOut): void
    {
        $employee = $regularisation->employee;
        $date = Carbon::parse($regularisation->work_date)->toDateString();

        foreach ([[$checkIn, $regularisation->check_in_method, 'in'], [$checkOut, $regularisation->check_out_method, 'out']] as [$time, $method, $direction]) {
            // A device punch already at that exact second is the raw log —
            // it is never rewritten (or later removed) as a correction.
            $existing = AttendancePunch::where('employee_id', $regularisation->employee_id)->where('punched_at', $time)->first();
            if ($existing && $existing->source !== 'regularisation') {
                continue;
            }

            AttendancePunch::updateOrCreate(
                ['employee_id' => $regularisation->employee_id, 'punched_at' => $time],
                [
                    'employee_code' => $employee?->employee_code,
                    'punch_date' => $date,
                    'method' => $method ?: 'id_card',
                    'direction' => $direction,   // pairs correctly in the direction-based timeline
                    'verify_raw' => $method,
                    'source' => 'regularisation',
                ],
            );
        }
    }

    /** A rejection at ANY stage ends the workflow; the action joins the audit trail. */
    /**
     * @param  bool  $override  HR's explicit, commented override of an already
     *                          approved request (All Attendance). Every other
     *                          caller may only reject a pending one.
     */
    public function rejectRegularisation(AttendanceRegularisation $regularisation, int $reviewerId, string $comment, bool $override = false): AttendanceRegularisation
    {
        // Only a request still awaiting a decision: "rejecting" an approved one
        // flips its status while the corrected attendance, OT and leave it
        // already applied stay in place — so it is never an incidental action.
        if ($regularisation->status !== 'pending' && ! $override) {
            throw new \DomainException('This regularisation has already been decided.');
        }

        app(ApprovalGuard::class)->assertCanDecide($reviewerId, $regularisation->employee);
        $this->assertDecidesRegularisations(User::find($reviewerId));

        $trail = $regularisation->approval_trail ?? [];
        $trail[] = [
            'stage' => $regularisation->stage ?: 'hr_review',
            'action' => 'rejected',
            'by' => $reviewerId,
            'name' => User::find($reviewerId)?->name,
            'comment' => $comment,
            'at' => now()->toDateTimeString(),
        ];

        $oldStatus = $regularisation->status;

        $regularisation->update([
            'status' => 'rejected',
            'reviewer_id' => $reviewerId,
            'reviewer_comment' => $comment,
            'approval_trail' => $trail,
            'reviewed_at' => now(),
        ]);

        app(AuditService::class)->event(
            $override ? 'ATTENDANCE_REGULARISATION_OVERRIDDEN' : 'ATTENDANCE_REGULARISATION_REJECTED',
            AuditService::ATTENDANCE,
            $regularisation,
            old: ['status' => $oldStatus],
            new: ['status' => 'rejected', 'work_date' => Carbon::parse($regularisation->work_date)->toDateString()],
            reason: $comment,
            subjectEmployeeId: $regularisation->employee_id,
            actor: User::find($reviewerId),
        );

        return $regularisation->fresh();
    }

    /**
     * Break minutes to record on a corrected day — informational only, never
     * deducted from worked hours (Pulse v3.1).
     *
     * What actually happened: break logs inside the corrected window, else the
     * figure the day already carried. Nothing is invented — a corrected day
     * with no recorded breaks has none.
     *
     * Never exceeds the corrected span.
     */
    private function breakMinutesForCorrection(
        Attendance $attendance,
        Carbon $checkIn,
        Carbon $checkOut,
        ?ResolvedShift $shift,
    ): int {
        $grossMinutes = (int) $checkIn->diffInMinutes($checkOut);

        $logged = (int) BreakLog::where('employee_id', $attendance->employee_id)
            ->whereNotNull('break_end')
            ->where('break_start', '>=', $checkIn)
            ->where('break_end', '<=', $checkOut)
            ->sum('duration_minutes');

        if ($logged > 0) {
            return min($logged, $grossMinutes);
        }

        // No logs: keep whatever the day already carried, clamped to the span.
        $fallback = (int) ($attendance->break_minutes ?: 0);

        return max(0, min($fallback, $grossMinutes));
    }

    protected function creditCompOffIfEligible(Attendance $attendance, Carbon $date): void
    {
        $employee = $attendance->employee;

        if (! $employee) {
            return;
        }

        $isMandatoryDay = DecemberMandatoryDay::isMandatory($date);
        $isUkHoliday = $this->resolveHolidayCountry($employee) === 'UK'
            && PublicHoliday::isHoliday($date, 'UK');

        if (! $isMandatoryDay && ! $isUkHoliday) {
            return;
        }

        app(LeaveService::class)->creditCompOff($employee, $date);
    }

    /**
     * Delegates to HolidayResolver, which is now the single home for the
     * country rule. Kept as a thin method so existing callers here read the
     * same as before.
     */
    protected function resolveHolidayCountry(Employee $employee): string
    {
        return app(HolidayResolver::class)->resolveCountry($employee);
    }
}
