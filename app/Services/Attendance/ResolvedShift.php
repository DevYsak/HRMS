<?php

namespace App\Services\Attendance;

use Illuminate\Support\Carbon;

/**
 * A fully-resolved shift window for one employee on one calendar day.
 *
 * Every attendance calculation — late marks, expected hours, overtime, the
 * auto punch-out boundary — reads its numbers from here so nothing is ever
 * hardcoded. All fields originate from the employee's assigned ShiftSetting
 * (or the global AttendanceSetting fallback), resolved by {@see ShiftResolver}.
 */
readonly class ResolvedShift
{
    public function __construct(
        public string $name,
        public Carbon $start,
        public Carbon $end,
        public int $graceMinutes,
        public float $standardHours,
        public float $otThresholdHours,
        public int $breakMinutes,
        public int $autoCheckoutBufferMinutes,
        public Carbon $otAutoCloseAt,
    ) {}

    /** The instant after which an arrival is counted late (start + grace). */
    public function lateCutoff(): Carbon
    {
        return $this->start->copy()->addMinutes($this->graceMinutes);
    }

    /** Whether a check-in at the given time is late for this shift. */
    public function isLate(Carbon $checkIn): bool
    {
        return $checkIn->gt($this->lateCutoff());
    }

    /** Minutes past the grace cutoff (0 when on time). */
    public function lateMinutes(Carbon $checkIn): int
    {
        return $this->isLate($checkIn) ? (int) $this->lateCutoff()->diffInMinutes($checkIn) : 0;
    }

    /** Expected working minutes for a full shift day (standard hours). */
    public function expectedMinutes(): int
    {
        return (int) round($this->standardHours * 60);
    }

    /**
     * The instant the auto punch-out engine may close a still-open day
     * (shift end + configured buffer). The OUT is stamped at {@see $end}, not here.
     */
    public function autoCheckoutTriggerAt(): Carbon
    {
        return $this->end->copy()->addMinutes($this->autoCheckoutBufferMinutes);
    }
}
