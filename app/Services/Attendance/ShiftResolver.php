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
 *   2. Any ShiftSetting, when the employee has none assigned yet.
 *   3. The global AttendanceSetting (shift_start / shift_end / grace).
 *
 * Returns null only when the app has neither a shift nor an attendance setting
 * configured — callers skip rather than invent a time.
 */
class ShiftResolver
{
    public function resolve(Employee $employee, Carbon|string $date): ?ResolvedShift
    {
        $day = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);

        $shift = $employee->shift ?? ShiftSetting::query()->first();
        $settings = AttendanceSetting::query()->first();

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
     * Pull the raw window values from the shift (authoritative) or fall back to
     * the global attendance setting for the pieces a shift can't provide.
     *
     * @return array{0: mixed, 1: mixed, 2: int, 3: float, 4: float, 5: int, 6: string}
     */
    protected function sourceValues(?ShiftSetting $shift, ?AttendanceSetting $settings): array
    {
        if ($shift) {
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

        if ($settings) {
            $start = Carbon::parse($settings->shift_start);
            $end = Carbon::parse($settings->shift_end);
            $standard = round($start->floatDiffInHours($end->lessThanOrEqualTo($start) ? $end->copy()->addDay() : $end), 2);

            return [
                $settings->shift_start,
                $settings->shift_end,
                (int) ($settings->late_grace_period ?? 0),
                $standard,
                $standard,
                0,
                'Default Shift',
            ];
        }

        return [null, null, 0, 9.0, 9.0, 0, 'Shift'];
    }

    /** Anchor a stored time (H:i:s, or a datetime/Carbon) onto the target day. */
    protected function anchor(mixed $time, Carbon $day): Carbon
    {
        $parsed = $time instanceof Carbon ? $time->copy() : Carbon::parse((string) $time);

        return $day->copy()->setTime($parsed->hour, $parsed->minute, $parsed->second);
    }
}
