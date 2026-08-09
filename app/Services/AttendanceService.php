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
use App\Services\Attendance\AttendanceScoreEngine;
use App\Services\Attendance\ResolvedShift;
use App\Services\Attendance\ShiftResolver;
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

            $isLate = $now->gt($cutoff);
            $lateMinutes = $isLate ? (int) $cutoff->diffInMinutes($now) : 0;
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
        $grossMinutes = (int) $attendance->check_in->diffInMinutes($now);
        $totalHours = round(max(0, $grossMinutes - (int) ($attendance->break_minutes ?? 0)) / 60, 2);

        $attendance->update([
            'check_out' => $now,
            'check_out_ip' => $payload['ip'] ?? request()->ip(),
            'check_out_lat' => $payload['lat'] ?? null,
            'check_out_lng' => $payload['lng'] ?? null,
            'check_out_photo' => $payload['photo'] ?? null,
            'check_out_user_agent' => $payload['user_agent'] ?? request()->userAgent(),
            'total_hours' => $totalHours,
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
     * Advance a regularisation through the approval chain
     * (Manager Review → HR Review → Admin Approval → Approved).
     *
     * Each reviewer clears every stage their role covers: a manager clears
     * manager_review, HR clears through hr_review, a super admin finalises.
     * Attendance is only touched at FINAL approval — raw biometric logs are
     * never modified, and every action lands in the approval_trail audit.
     *
     * Returns the updated Attendance at final approval, null when the request
     * merely advanced a stage (or the reviewer can't act at the current stage).
     */
    public function approveRegularisation(AttendanceRegularisation $regularisation, int $reviewerId, ?string $comment = null): ?Attendance
    {
        if ($regularisation->status !== 'pending') {
            return $regularisation->attendance;
        }

        $reviewer = User::find($reviewerId);
        $level = $this->approvalLevel($reviewer);
        $currentStage = $regularisation->stage ?: 'manager_review';
        $current = AttendanceRegularisation::STAGES[$currentStage] ?? 1;

        // Can't act at a stage above the reviewer's role.
        if ($level < $current) {
            return null;
        }

        $trail = $regularisation->approval_trail ?? [];
        $trail[] = [
            'stage' => $currentStage,
            'action' => 'approved',
            'by' => $reviewerId,
            'name' => $reviewer?->name,
            'comment' => $comment,
            'at' => now()->toDateTimeString(),
        ];

        // Not the final authority yet → advance to the next stage and stop.
        if ($level < 3) {
            $nextStage = array_search($level + 1, AttendanceRegularisation::STAGES, true);
            $regularisation->update(['stage' => $nextStage, 'approval_trail' => $trail]);

            return null;
        }

        return DB::transaction(function () use ($regularisation, $reviewerId, $comment, $trail) {
            $regularisation->update([
                'status' => 'approved',
                'stage' => 'admin_approval',
                'reviewer_id' => $reviewerId,
                'reviewer_comment' => $comment,
                'approval_trail' => $trail,
                'reviewed_at' => now(),
            ]);

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

            $grossMinutes = (int) $checkIn->diffInMinutes($checkOut);
            $breakMinutes = $this->breakMinutesForCorrection($attendance, $checkIn, $checkOut, $shift);
            $netMinutes = max(0, $grossMinutes - $breakMinutes);

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
                'total_hours' => round($netMinutes / 60, 2),
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
    public function rejectRegularisation(AttendanceRegularisation $regularisation, int $reviewerId, string $comment): AttendanceRegularisation
    {
        $trail = $regularisation->approval_trail ?? [];
        $trail[] = [
            'stage' => $regularisation->stage ?: 'manager_review',
            'action' => 'rejected',
            'by' => $reviewerId,
            'name' => User::find($reviewerId)?->name,
            'comment' => $comment,
            'at' => now()->toDateTimeString(),
        ];

        $regularisation->update([
            'status' => 'rejected',
            'reviewer_id' => $reviewerId,
            'reviewer_comment' => $comment,
            'approval_trail' => $trail,
            'reviewed_at' => now(),
        ]);

        return $regularisation->fresh();
    }

    /**
     * Workflow authority: HR and super admin are final (3), managers clear
     * manager_review (1).
     *
     * HR used to sit at 2, which meant an HR approval only advanced the request
     * to admin_approval and never corrected the attendance — HR pressed
     * Approve, the row said approved-in-progress, and the employee's hours
     * stayed wrong until a super admin happened to look. HR owns attendance
     * corrections, so HR finalises. The admin_approval stage is retained so
     * requests already parked there stay valid and can now be cleared by HR.
     */
    protected function approvalLevel(?User $user): int
    {
        return match (true) {
            $user === null => 1,
            $user->isSuperAdmin() || $user->assignedRole?->slug === 'super_admin' => 3,
            $user->isHrAdmin() => 3,
            default => 1,
        };
    }

    /**
     * Unpaid break to deduct from a corrected day.
     *
     * Prefers what actually happened — break logs overlapping the corrected
     * window — and falls back to the shift's standard break when there are
     * none. That fallback is what makes a regularised absent day honest: such a
     * day has no break logs at all, so without it a 09:00–18:00 correction
     * books nine straight hours with no lunch deducted.
     *
     * Never exceeds the worked span, so the net can't go negative.
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

        // No logs: keep whatever the day already carried, else the shift's own
        // unpaid break. Both are clamped to the corrected span.
        $fallback = (int) ($attendance->break_minutes ?: $shift?->breakMinutes ?: 0);

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

    protected function resolveHolidayCountry(Employee $employee): string
    {
        $officeCountry = strtoupper((string) ($employee->office?->country ?? ''));
        if (in_array($officeCountry, ['UK', 'UNITED KINGDOM', 'GB', 'GREAT BRITAIN'], true)) {
            return 'UK';
        }

        $shiftName = strtoupper((string) ($employee->shift?->name ?? ''));
        if (str_contains($shiftName, 'UK')) {
            return 'UK';
        }

        return 'IN';
    }
}
