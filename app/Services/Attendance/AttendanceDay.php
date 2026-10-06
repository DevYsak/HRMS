<?php

namespace App\Services\Attendance;

use Carbon\CarbonInterface;

/**
 * One employee's attendance for one day, as calculated by
 * {@see AttendanceCalculator} — the only place these figures are derived.
 *
 * Pulse v3.1: worked = final clock-out − first clock-in. Breaks are reported
 * for information and never deducted.
 */
readonly class AttendanceDay
{
    public const STATUS_PRESENT = 'present';

    public const STATUS_LATE = 'late';

    public const STATUS_WORKING = 'working';

    public const STATUS_MISSING_CHECKOUT = 'missing_checkout';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_NOT_CHECKED_IN = 'not_checked_in';

    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_WEEKLY_OFF = 'weekly_off';

    public const STATUS_HOLIDAY = 'holiday';

    public const STATUS_LEAVE = 'leave';

    public const STATUS_NOT_EMPLOYED = 'not_employed';

    public function __construct(
        public CarbonInterface $date,
        /** WorkingDayResolver classification (working_day, weekly_off, public_holiday, …). */
        public string $dayType,
        public ?CarbonInterface $firstIn,
        public ?CarbonInterface $lastOut,
        /** Still clocked in today; worked runs to "now". */
        public bool $live,
        /** last out − first in (or now − first in while live). Breaks never deducted. */
        public int $workedMinutes,
        /** Time away between sessions / logged breaks — informational only. */
        public int $breakMinutes,
        public bool $excessBreak,
        public bool $isLate,
        public int $lateMinutes,
        public int $expectedMinutes,
        /** Worked beyond the standard day, approved or not (informational). */
        public int $beyondShiftMinutes,
        public bool $hasApprovedOt,
        /** max(worked − standard, 0) only when an approved OT request exists, else 0. */
        public int $approvedOtMinutes,
        public bool $missingCheckout,
        public bool $regularised,
        /** regularised | punches | attendance | none */
        public string $source,
        public string $status,
        public ?ResolvedShift $shift,
    ) {}

    public function workedHours(): float
    {
        return round($this->workedMinutes / 60, 2);
    }

    public function approvedOtHours(): float
    {
        return round($this->approvedOtMinutes / 60, 2);
    }

    /** Present in any form (on time, late, still working or awaiting a checkout). */
    public function isPresent(): bool
    {
        return $this->firstIn !== null;
    }

    /** Weekly off, public holiday, MDL shutdown, approved leave or outside employment — never absent. */
    public function isNonWorkingDay(): bool
    {
        return $this->dayType !== WorkingDayResolver::WORKING_DAY;
    }

    public function isAbsent(): bool
    {
        return $this->status === self::STATUS_ABSENT;
    }

    public static function hm(int $minutes): string
    {
        return intdiv(max(0, $minutes), 60).'h '.str_pad((string) (max(0, $minutes) % 60), 2, '0', STR_PAD_LEFT).'m';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'date' => $this->date->toDateString(),
            'day_type' => $this->dayType,
            'first_in' => $this->firstIn?->format('h:i A'),
            'last_out' => $this->lastOut?->format('h:i A'),
            'live' => $this->live,
            'worked_minutes' => $this->workedMinutes,
            'worked_label' => self::hm($this->workedMinutes),
            'break_minutes' => $this->breakMinutes,
            'excess_break' => $this->excessBreak,
            'is_late' => $this->isLate,
            'late_minutes' => $this->lateMinutes,
            'expected_minutes' => $this->expectedMinutes,
            'beyond_shift_minutes' => $this->beyondShiftMinutes,
            'has_approved_ot' => $this->hasApprovedOt,
            'approved_ot_minutes' => $this->approvedOtMinutes,
            'missing_checkout' => $this->missingCheckout,
            'regularised' => $this->regularised,
            'source' => $this->source,
            'status' => $this->status,
        ];
    }
}
