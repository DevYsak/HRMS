<?php

namespace App\Services\Attendance;

use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\ShiftSetting;
use Illuminate\Support\Carbon;

/**
 * Resolves the shift window an employee is working on a given day.
 *
 * This is the ONE place that turns "which shift, what times, what grace" into
 * concrete instants — every attendance rule (late, overtime, expected hours,
 * auto punch-out) reads from the returned {@see ResolvedShift} rather than a
 * hardcoded literal. Resolution order, all DB-driven:
 *
 *   1. The employee's assigned ShiftSetting (employees.shift_id).
 *   2. The shift HR nominated as the company default (is_default).
 *
 * Returns null when neither applies. Callers all guard for that and skip,
 * which is the point: an unassigned employee gets no attendance judgement at
 * all rather than a confidently wrong one.
 *
 * This previously fell back to ShiftSetting::query()->first() — an arbitrary
 * row, ordered by nothing in particular. An unassigned UK Sales employee was
 * therefore scored against the 10:30 IT window and marked hours late every
 * single day, with nothing on screen to say why. Which shift covers an
 * unassigned employee is a policy decision, so it is now stated explicitly by
 * HR or not made at all.
 */
class ShiftResolver
{
    public function resolve(Employee $employee, Carbon|string $date): ?ResolvedShift
    {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);

        $shift = $employee->shift ?? self::companyDefault();
        $settings = AttendanceSetting::query()->first();

        // No assigned shift and no nominated default: refuse to guess.
        if (! $shift) {
            return null;
        }

        [$startRaw, $endRaw, $grace, $standard, $threshold, $break, $name] = $this->sourceValues($shift, $settings);

        if ($startRaw === null || $endRaw === null) {
            return null;
        }

        $start = $this->anchor($startRaw, $day);
        $end = $this->anchor($endRaw, $day);

        // Overnight shift (e.g. 22:00 → 06:00): the end lands on the next day.
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        $buffer = (int) ($settings->auto_checkout_buffer_minutes ?? 30);
        $otCloseAt = $this->anchor($settings->ot_auto_close_time ?? '23:59:00', $day);
        if ($otCloseAt->lessThanOrEqualTo($start)) {
            $otCloseAt->addDay();
        }

        return new ResolvedShift(
            name: $name,
            start: $start,
            end: $end,
            graceMinutes: $grace,
            standardHours: $standard,
            otThresholdHours: $threshold,
            breakMinutes: $break,
            autoCheckoutBufferMinutes: $buffer,
            otAutoCloseAt: $otCloseAt,
        );
    }

    /**
     * The shift HR nominated to cover employees without an explicit assignment.
     *
     * Memoised per request: resolve() is called once per employee per day in
     * scoring and report loops, and this would otherwise be a query each time.
     */
    public static function companyDefault(): ?ShiftSetting
    {
        return once(fn (): ?ShiftSetting => ShiftSetting::query()->where('is_default', true)->first());
    }

    /**
     * Whether attendance can be judged for this employee at all.
     *
     * Surfaces on the employee's own page as "Shift not assigned" and in the
     * HR data-quality flags, so a silent gap becomes a visible task.
     */
    public static function hasResolvableShift(Employee $employee): bool
    {
        return $employee->shift_id !== null || self::companyDefault() !== null;
    }

    /**
     * The shift's own window, with the global attendance setting supplying only
     * the grace period when the shift row leaves it null.
     *
     * The global setting no longer contributes a start/end. Those values
     * (09:00-18:00 here) match no shift the company actually runs, so using
     * them produced late marks against a working day nobody works.
     *
     * @return array{0: mixed, 1: mixed, 2: int, 3: float, 4: float, 5: int, 6: string}
     */
    protected function sourceValues(ShiftSetting $shift, ?AttendanceSetting $settings): array
    {
        return [
            $shift->start_time,
            $shift->end_time,
            (int) ($shift->grace_minutes ?? $settings->late_grace_period ?? 0),
            (float) ($shift->standard_hours ?? 9.0),
            (float) ($shift->ot_threshold_hours ?? $shift->standard_hours ?? 9.0),
            (int) ($shift->break_duration ?? 0),
            $shift->name ?? 'Shift',
        ];
    }

    /** Anchor a stored time (H:i:s, or a datetime/Carbon) onto the target day. */
    protected function anchor(mixed $time, Carbon $day): Carbon
    {
        $parsed = $time instanceof Carbon ? $time->copy() : Carbon::parse((string) $time);

        return $day->copy()->setTime($parsed->hour, $parsed->minute, $parsed->second);
    }
}
