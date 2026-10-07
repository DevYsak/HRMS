<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\OtRequest;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\Audit\AuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Edit, delete and revert attendance regularisations.
 *
 *  - Super Admin / HR (Approve Regularisations, in scope): edit and delete any
 *    regularisation; an approved one only after explicit confirmation.
 *  - Employee: their own request, and only while it is still pending.
 *
 * Reverting an approved correction removes ONLY the punches that request
 * wrote (source "regularisation", at its own times) and rebuilds the day from
 * the genuine biometric punches — raw device logs are never modified or
 * deleted. Every edit, delete and revert is audited with who, when, the old
 * and new values and the reason.
 */
class RegularisationManager
{
    public function __construct(
        private readonly ShiftResolver $shifts,
        private readonly PunchTimeline $timeline,
        private readonly AttendanceCalculator $calculator,
    ) {}

    public function isOwner(User $actor, AttendanceRegularisation $regularisation): bool
    {
        return $regularisation->employee !== null && (int) $regularisation->employee->user_id === (int) $actor->id;
    }

    /** HR (or Super Admin) acting on someone else's request inside their scope. */
    public function actsAsHr(User $actor, AttendanceRegularisation $regularisation): bool
    {
        return ! $this->isOwner($actor, $regularisation)
            && $regularisation->employee !== null
            && $actor->canApproveRegularisations()
            && $actor->coversEmployee($regularisation->employee);
    }

    public function canEdit(User $actor, AttendanceRegularisation $regularisation): bool
    {
        return $this->allowed($actor, $regularisation);
    }

    public function canDelete(User $actor, AttendanceRegularisation $regularisation): bool
    {
        return $this->allowed($actor, $regularisation);
    }

    /** An approved request changed attendance — touching it needs confirmation. */
    public function needsConfirmation(AttendanceRegularisation $regularisation): bool
    {
        return $regularisation->status === 'approved';
    }

    /** Why the actor cannot change this request, or null when they can. */
    public function lockReason(User $actor, AttendanceRegularisation $regularisation): ?string
    {
        if ($this->allowed($actor, $regularisation)) {
            return null;
        }

        if ($this->isOwner($actor, $regularisation)) {
            return 'Only a pending request can be changed. Ask HR to correct a decided one.';
        }

        if ($regularisation->isLeave() && $regularisation->status === 'approved') {
            return 'An approved leave regularisation is corrected from the leave corrections screen, so the balance ledger stays right.';
        }

        return 'You cannot change this regularisation.';
    }

    /**
     * Change a regularisation's requested times, half-day period or reason.
     *
     * @param  array{check_in?: ?string, check_out?: ?string, reason?: ?string, half_day_period?: ?string}  $input  times as HH:MM
     *
     * @throws AuthorizationException
     * @throws \DomainException
     */
    public function update(AttendanceRegularisation $regularisation, User $actor, array $input, string $reason, bool $confirmed = false): AttendanceRegularisation
    {
        $this->authorize($actor, $regularisation);
        $reason = $this->assertReason($reason);
        $this->assertConfirmed($regularisation, $confirmed);

        return DB::transaction(function () use ($regularisation, $actor, $input, $reason) {
            $regularisation = $this->lockFresh($regularisation);
            $changes = $this->changesFrom($regularisation, $input);
            $old = $this->fields($regularisation);

            if ($changes === []) {
                throw new \DomainException('Nothing was changed.');
            }

            if ($regularisation->status === 'approved') {
                $attendanceBefore = $this->snapshot($this->attendanceFor($regularisation));
                $this->revert($regularisation, $actor);

                $regularisation->fill($changes)->save();
                app(AttendanceService::class)->reapplyCorrectedRegularisation($regularisation->fresh(), $actor, $reason);
                $regularisation->refresh();

                app(AuditService::class)->event('ATTENDANCE_REGULARISATION_CORRECTED', AuditService::ATTENDANCE, $regularisation,
                    old: ['regularisation' => $old, 'attendance' => $attendanceBefore],
                    new: ['regularisation' => $this->fields($regularisation), 'attendance' => $this->snapshot($this->attendanceFor($regularisation))],
                    reason: $reason, subjectEmployeeId: $regularisation->employee_id, actor: $actor);

                return $regularisation;
            }

            $regularisation->fill($changes + ['approval_trail' => $this->trail($regularisation, $actor, 'edited', $reason)])->save();

            app(AuditService::class)->event('ATTENDANCE_REGULARISATION_UPDATED', AuditService::ATTENDANCE, $regularisation,
                old: ['regularisation' => $old],
                new: ['regularisation' => $this->fields($regularisation)],
                reason: $reason, subjectEmployeeId: $regularisation->employee_id, actor: $actor);

            return $regularisation->fresh();
        });
    }

    /**
     * Delete a regularisation. An approved one is reverted first: its own
     * punches go, and the day is rebuilt from the genuine biometric punches.
     *
     * @throws AuthorizationException
     * @throws \DomainException
     */
    public function delete(AttendanceRegularisation $regularisation, User $actor, string $reason, bool $confirmed = false): void
    {
        $this->authorize($actor, $regularisation);
        $reason = $this->assertReason($reason);
        $this->assertConfirmed($regularisation, $confirmed);

        DB::transaction(function () use ($regularisation, $actor, $reason) {
            $regularisation = $this->lockFresh($regularisation);
            $old = $this->fields($regularisation);

            if ($regularisation->status === 'approved') {
                $attendanceBefore = $this->snapshot($this->attendanceFor($regularisation));
                $attendanceAfter = $this->snapshot($this->revert($regularisation, $actor));

                app(AuditService::class)->event('ATTENDANCE_REGULARISATION_REVERTED', AuditService::ATTENDANCE, $regularisation,
                    old: ['attendance' => $attendanceBefore],
                    new: ['attendance' => $attendanceAfter, 'rebuilt_from' => 'biometric punches'],
                    reason: $reason, subjectEmployeeId: $regularisation->employee_id, actor: $actor);
            }

            $regularisation->fill([
                'approval_trail' => $this->trail($regularisation, $actor, 'deleted', $reason),
                'deleted_by' => $actor->id,
            ])->save();
            $regularisation->delete();

            app(AuditService::class)->event('ATTENDANCE_REGULARISATION_DELETED', AuditService::ATTENDANCE, $regularisation,
                old: ['regularisation' => $old],
                new: null,
                reason: $reason, subjectEmployeeId: $regularisation->employee_id, actor: $actor);
        });
    }

    /**
     * Undo what an approved regularisation wrote: its own punches, the OT it
     * produced (unless already paid), and the corrected times — rebuilt from
     * the genuine punches, or from another approved correction of the same
     * day. Returns the day's attendance afterwards (null if nothing genuine
     * remains and the row the correction created is gone).
     *
     * @throws \DomainException
     */
    public function revert(AttendanceRegularisation $regularisation, User $actor): ?Attendance
    {
        if ($regularisation->isLeave()) {
            throw new \DomainException('An approved leave regularisation is corrected from the leave corrections screen.');
        }

        $date = Carbon::parse($regularisation->work_date)->toDateString();
        $others = $this->otherApprovedFor($regularisation);
        $latestOther = $others->first();

        if (! $others->contains(fn (AttendanceRegularisation $r) => $this->writesPunches($r))) {
            $this->withdrawOvertime($regularisation, $date);
        }

        $this->removeOwnPunches($regularisation, $others);

        $attendance = Attendance::where('employee_id', $regularisation->employee_id)->whereDate('date', $date)->first();
        if ($attendance === null) {
            return null;
        }

        $attendance = $latestOther
            ? $this->rebuildFromRegularisation($attendance, $latestOther)
            : $this->rebuildFromGenuine($attendance);

        if ($regularisation->employee) {
            app(AttendanceScoreEngine::class)->scoreDay($regularisation->employee, $date);
        }

        return $attendance;
    }

    /** @throws AuthorizationException */
    private function authorize(User $actor, AttendanceRegularisation $regularisation): void
    {
        if (! $this->allowed($actor, $regularisation)) {
            throw new AuthorizationException($this->lockReason($actor, $regularisation) ?? 'You cannot change this regularisation.');
        }
    }

    private function allowed(User $actor, AttendanceRegularisation $regularisation): bool
    {
        if ($regularisation->trashed() || $regularisation->status === 'cancelled') {
            return false;
        }

        if ($this->isOwner($actor, $regularisation)) {
            return $regularisation->status === 'pending';
        }

        if (! $this->actsAsHr($actor, $regularisation)) {
            return false;
        }

        return ! ($regularisation->isLeave() && $regularisation->status === 'approved');
    }

    /** @throws \DomainException */
    private function assertReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new \DomainException('Give a reason for the change.');
        }

        return $reason;
    }

    /** @throws \DomainException */
    private function assertConfirmed(AttendanceRegularisation $regularisation, bool $confirmed): void
    {
        if ($this->needsConfirmation($regularisation) && ! $confirmed) {
            throw new \DomainException('This regularisation is approved and changed attendance — confirm before changing it.');
        }
    }

    /**
     * Re-read the row under a lock and make sure it was not decided or
     * deleted by someone else since the actor opened it.
     *
     * @throws \DomainException
     */
    private function lockFresh(AttendanceRegularisation $regularisation): AttendanceRegularisation
    {
        $fresh = AttendanceRegularisation::whereKey($regularisation->getKey())->lockForUpdate()->first();

        if ($fresh === null || $fresh->status !== $regularisation->status) {
            throw new \DomainException('This regularisation changed while you had it open — reload and try again.');
        }

        return $fresh;
    }

    /**
     * The attribute changes the input asks for, limited to what the request's
     * kind allows (a leave regularisation changes only its reason here).
     *
     * @param  array{check_in?: ?string, check_out?: ?string, reason?: ?string, half_day_period?: ?string}  $input
     * @return array<string, mixed>
     *
     * @throws \DomainException
     */
    private function changesFrom(AttendanceRegularisation $regularisation, array $input): array
    {
        $changes = [];
        $date = Carbon::parse($regularisation->work_date)->toDateString();

        if (isset($input['reason']) && trim((string) $input['reason']) !== '' && trim((string) $input['reason']) !== $regularisation->reason) {
            $changes['reason'] = trim((string) $input['reason']);
        }

        if ($regularisation->isLeave()) {
            return $changes;
        }

        if ($regularisation->regularisation_type === 'half_day') {
            $period = $input['half_day_period'] ?? null;
            if ($period !== null && $period !== $regularisation->half_day_period) {
                if (! in_array($period, ['first', 'second'], true)) {
                    throw new \DomainException('Choose the first or second half.');
                }
                $changes['half_day_period'] = $period;
            }

            return $changes;
        }

        foreach (['check_in' => 'requested_check_in', 'check_out' => 'requested_check_out'] as $key => $column) {
            $time = $input[$key] ?? null;
            if ($time === null || $time === '') {
                continue;
            }
            if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
                throw new \DomainException('Enter times as HH:MM.');
            }
            $current = $regularisation->{$column} ? Carbon::parse($regularisation->{$column})->format('H:i') : null;
            if ($time !== $current) {
                $changes[$column] = $date.' '.$time.':00';
            }
        }

        return $changes;
    }

    /** @return array<string, mixed> */
    private function fields(AttendanceRegularisation $regularisation): array
    {
        return [
            'status' => $regularisation->status,
            'work_date' => Carbon::parse($regularisation->work_date)->toDateString(),
            'type' => $regularisation->isLeave() ? 'leave' : ($regularisation->regularisation_type ?: 'punch'),
            'check_in' => $regularisation->requested_check_in ? Carbon::parse($regularisation->requested_check_in)->format('H:i') : null,
            'check_out' => $regularisation->requested_check_out ? Carbon::parse($regularisation->requested_check_out)->format('H:i') : null,
            'half_day_period' => $regularisation->half_day_period,
            'reason' => $regularisation->reason,
        ];
    }

    /** @return array<string, mixed>|null */
    private function snapshot(?Attendance $attendance): ?array
    {
        return $attendance ? [
            'check_in' => $attendance->check_in?->format('Y-m-d H:i'),
            'check_out' => $attendance->check_out?->format('Y-m-d H:i'),
            'status' => $attendance->status,
            'total_hours' => $attendance->total_hours !== null ? (float) $attendance->total_hours : null,
            'is_regularized' => (bool) $attendance->is_regularized,
        ] : null;
    }

    /** @return array<int, array<string, mixed>> */
    private function trail(AttendanceRegularisation $regularisation, User $actor, string $action, string $reason): array
    {
        $trail = $regularisation->approval_trail ?? [];
        $trail[] = [
            'stage' => $regularisation->stage ?: 'hr_review',
            'action' => $action,
            'by' => $actor->id,
            'name' => $actor->name,
            'comment' => $reason,
            'at' => now()->toDateTimeString(),
        ];

        return $trail;
    }

    private function attendanceFor(AttendanceRegularisation $regularisation): ?Attendance
    {
        return Attendance::where('employee_id', $regularisation->employee_id)
            ->whereDate('date', Carbon::parse($regularisation->work_date)->toDateString())
            ->first();
    }

    /**
     * Other approved regularisations of the same employee and day, latest first.
     *
     * @return Collection<int, AttendanceRegularisation>
     */
    private function otherApprovedFor(AttendanceRegularisation $regularisation): Collection
    {
        return AttendanceRegularisation::where('employee_id', $regularisation->employee_id)
            ->whereDate('work_date', Carbon::parse($regularisation->work_date)->toDateString())
            ->where('status', 'approved')
            ->whereKeyNot($regularisation->getKey())
            ->where(fn ($q) => $q->whereNull('category')->orWhere('category', '!=', 'leave'))
            ->orderByDesc('applied_at')->orderByDesc('id')
            ->get();
    }

    private function writesPunches(AttendanceRegularisation $regularisation): bool
    {
        return ! $regularisation->isLeave() && $regularisation->regularisation_type !== 'half_day';
    }

    /**
     * The instants a punch regularisation wrote, anchored to its work date
     * (an out at or before the in is the next morning, as on approval).
     *
     * @return array<int, Carbon>
     */
    private function punchInstants(AttendanceRegularisation $regularisation): array
    {
        if (! $this->writesPunches($regularisation) || ! $regularisation->requested_check_in || ! $regularisation->requested_check_out) {
            return [];
        }

        $date = Carbon::parse($regularisation->work_date)->toDateString();
        $in = Carbon::parse($date.' '.Carbon::parse($regularisation->requested_check_in)->format('H:i:s'));
        $out = Carbon::parse($date.' '.Carbon::parse($regularisation->requested_check_out)->format('H:i:s'));
        if ($out->lessThanOrEqualTo($in)) {
            $out = $out->copy()->addDay();
        }

        return [$in, $out];
    }

    /**
     * Remove the punches this request wrote — only source "regularisation",
     * only at its own instants, and never one another approved correction of
     * the day still relies on.
     *
     * @param  Collection<int, AttendanceRegularisation>  $others
     */
    private function removeOwnPunches(AttendanceRegularisation $regularisation, Collection $others): void
    {
        $keep = $others->flatMap(fn (AttendanceRegularisation $r) => $this->punchInstants($r))
            ->map(fn (Carbon $t) => $t->format('Y-m-d H:i:s'))->all();

        $times = collect($this->punchInstants($regularisation))
            ->map(fn (Carbon $t) => $t->format('Y-m-d H:i:s'))
            ->reject(fn (string $t) => in_array($t, $keep, true))
            ->values()->all();

        if ($times === []) {
            return;
        }

        AttendancePunch::where('employee_id', $regularisation->employee_id)
            ->where('source', 'regularisation')
            ->whereIn('punched_at', $times)
            ->delete();
    }

    /**
     * Withdraw the overtime the correction filed. Paid overtime is payroll
     * history and blocks the revert instead.
     *
     * @throws \DomainException
     */
    private function withdrawOvertime(AttendanceRegularisation $regularisation, string $date): void
    {
        $requests = OtRequest::with('overtimeRecord')
            ->where('employee_id', $regularisation->employee_id)
            ->whereDate('work_date', $date)
            ->where('source', 'regularisation')
            ->whereIn('status', ['pending', 'approved'])
            ->get();

        if ($requests->contains(fn (OtRequest $ot) => $ot->overtimeRecord && ($ot->overtimeRecord->is_paid || $ot->overtimeRecord->payslip_id))) {
            throw new \DomainException('The overtime this correction produced has already been paid — correct it in payroll first.');
        }

        foreach ($requests as $ot) {
            $ot->overtimeRecord?->delete();
            $ot->update([
                'status' => 'cancelled',
                'reviewer_comment' => 'Withdrawn — the regularisation that produced it was reverted.',
                'reviewed_at' => now(),
            ]);
        }
    }

    /** The day as another approved correction of it still states. */
    private function rebuildFromRegularisation(Attendance $attendance, AttendanceRegularisation $other): ?Attendance
    {
        if ($other->regularisation_type === 'half_day') {
            $attendance = $this->rebuildFromGenuine($attendance);
            $attendance?->update(['status' => 'half_day', 'is_regularized' => true]);

            return $attendance?->fresh();
        }

        [$in, $out] = $this->punchInstants($other) + [null, null];
        if ($in === null) {
            return $this->rebuildFromGenuine($attendance);
        }

        $attendance->update($this->timesAndStatus($attendance, $in, $out) + ['is_regularized' => true]);

        return $attendance->fresh();
    }

    /**
     * The day as the genuine biometric punches show it — never a punch a
     * regularisation wrote. With no genuine punch, the times recorded before
     * the first correction come back; with nothing genuine at all, the row the
     * correction created is removed (the day returns to absent).
     */
    private function rebuildFromGenuine(Attendance $attendance): ?Attendance
    {
        $day = Carbon::parse($attendance->date->toDateString());
        $genuine = AttendancePunch::where('employee_id', $attendance->employee_id)
            ->whereDate('punch_date', $day->toDateString())
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'regularisation'))
            ->orderBy('punched_at')
            ->get();

        $in = null;
        $out = null;
        $breakMinutes = null;
        $methods = [];

        if ($genuine->isNotEmpty()) {
            $timeline = $this->timeline->process($genuine, $day);
            $in = $timeline['first_in_at'];
            $out = $timeline['last_out_at'];
            $breakMinutes = (int) $timeline['break_minutes'];
            $methods = [
                'check_in_method' => $in ? $genuine->first(fn (AttendancePunch $p) => $p->punched_at->equalTo($in))?->method : null,
                'check_out_method' => $out ? $genuine->first(fn (AttendancePunch $p) => $p->punched_at->equalTo($out))?->method : null,
            ];
        }

        if ($in === null && $attendance->original_check_in !== null) {
            $in = Carbon::parse($attendance->original_check_in);
            $out = $attendance->original_check_out ? Carbon::parse($attendance->original_check_out) : null;
        }

        if ($in === null) {
            $hasOwnEvidence = $attendance->check_in_ip || $attendance->check_in_photo || $attendance->check_in_user_agent
                || $attendance->breakLogs()->exists();

            if (! $hasOwnEvidence) {
                $attendance->delete();

                return null;
            }

            $in = Carbon::parse($attendance->check_in);
            $out = $attendance->check_out ? Carbon::parse($attendance->check_out) : null;
        }

        $attendance->update($this->timesAndStatus($attendance, $in, $out) + $methods + [
            'break_minutes' => $breakMinutes ?? (int) ($attendance->break_minutes ?? 0),
            'original_check_in' => null,
            'original_check_out' => null,
            'is_regularized' => false,
        ]);

        return $attendance->fresh();
    }

    /**
     * Times, hours, late and status for a rebuilt day. A status the
     * correction did not set (remote, holiday worked) is kept.
     *
     * @return array<string, mixed>
     */
    private function timesAndStatus(Attendance $attendance, Carbon $in, ?Carbon $out): array
    {
        $day = Carbon::parse($attendance->date->toDateString());
        $shift = $attendance->employee ? $this->shifts->resolve($attendance->employee, $day->toDateString()) : null;
        $isLate = $shift ? $shift->isLate($in) : (bool) $attendance->is_late;
        $values = [
            'check_in' => $in,
            'check_out' => $out,
            'total_hours' => $this->calculator->storedHours($in, $out),
            'is_late' => $isLate,
            'late_minutes' => $shift ? $shift->lateMinutes($in) : (int) ($attendance->late_minutes ?? 0),
            'missing_checkout' => $out === null && $day->lt(Carbon::today()),
            'is_auto_checkout' => false,
            'auto_checkout_reason' => null,
        ];

        if (in_array($attendance->status, ['on_time', 'late', 'half_day', 'absent', null], true)) {
            $values['status'] = $isLate ? 'late' : 'on_time';
        }

        return $values;
    }
}
