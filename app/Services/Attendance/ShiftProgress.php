<?php

namespace App\Services\Attendance;

/**
 * How far through their shift an employee is today.
 *
 * A pure value object: it runs no queries and computes no attendance of its
 * own. Worked minutes arrive from PunchTimeline and the expected duration from
 * the employee's ResolvedShift, so the ring can never disagree with the numbers
 * shown beside it.
 *
 * Two rules matter more than the arithmetic:
 *
 *  - Without a resolvable shift there is no expected duration, so there is no
 *    progress to report. The card says so instead of showing 0% against an
 *    invented nine-hour day, which would tell an unassigned employee they are
 *    behind on a schedule nobody gave them.
 *
 *  - Progress stops at 100%. Time worked beyond the standard day is overtime
 *    and belongs to the OT rules, which pay it only against an approved
 *    request. Letting the ring run past full would imply the excess is simply
 *    credited, which is not how overtime works here.
 */
readonly class ShiftProgress
{
    /** No shift assigned and no company default — nothing can be measured. */
    public const STATE_UNASSIGNED = 'unassigned';

    /** Leave, holiday or weekly off: not a day to be measured against. */
    public const STATE_NON_WORKING = 'non_working';

    public const STATE_NOT_STARTED = 'not_started';

    public const STATE_WORKING = 'working';

    public const STATE_COMPLETED = 'completed';

    private function __construct(
        public string $state,
        public ?int $expectedMinutes = null,
        public ?int $workedMinutes = null,
        public ?int $remainingMinutes = null,
        public ?int $percent = null,
        public ?string $note = null,
    ) {}

    /**
     * The employee has no resolvable shift.
     *
     * Every figure is deliberately null rather than zero: "0h worked of 9h"
     * is a judgement, and there is no shift to judge against.
     */
    public static function unassigned(): self
    {
        return new self(self::STATE_UNASSIGNED, note: 'Shift not assigned');
    }

    /** A day the employee was never expected to work. */
    public static function nonWorking(string $note): self
    {
        return new self(self::STATE_NON_WORKING, note: $note);
    }

    /**
     * Progress against a real shift.
     *
     * @param  ResolvedShift  $shift  the employee's own shift, or the company default
     * @param  int  $workedMinutes  from the attendance engine, including any live session
     * @param  bool  $clockedOut  whether the day is closed
     */
    public static function of(ResolvedShift $shift, int $workedMinutes, bool $clockedOut = false): self
    {
        $expected = $shift->expectedMinutes();

        // A shift with no configured standard duration cannot be measured
        // against either — better to say nothing than to guess at nine hours.
        if ($expected <= 0) {
            return new self(self::STATE_UNASSIGNED, note: 'Shift has no standard hours');
        }

        $worked = max(0, $workedMinutes);

        $state = match (true) {
            $clockedOut => self::STATE_COMPLETED,
            $worked > 0 => self::STATE_WORKING,
            default => self::STATE_NOT_STARTED,
        };

        return new self(
            state: $state,
            expectedMinutes: $expected,
            workedMinutes: $worked,
            // Never negative: an employee past their standard day has nothing
            // remaining, not a debt owed back to them.
            remainingMinutes: max(0, $expected - $worked),
            // Never past full: the excess is overtime, not extra progress.
            percent: min(100, (int) floor($worked / $expected * 100)),
            note: null,
        );
    }

    /** Whether there are real figures to render. */
    public function isMeasurable(): bool
    {
        return $this->expectedMinutes !== null;
    }

    /** Minutes worked beyond the standard day — overtime's business, not the ring's. */
    public function overtimeMinutes(): int
    {
        if (! $this->isMeasurable()) {
            return 0;
        }

        return max(0, $this->workedMinutes - $this->expectedMinutes);
    }

    public function workedLabel(): string
    {
        return $this->isMeasurable() ? self::hm($this->workedMinutes) : '—';
    }

    public function remainingLabel(): string
    {
        return $this->isMeasurable() ? self::hm($this->remainingMinutes) : '—';
    }

    public function expectedLabel(): string
    {
        return $this->isMeasurable() ? self::hm($this->expectedMinutes) : '—';
    }

    public function percentLabel(): string
    {
        return $this->isMeasurable() ? $this->percent.'%' : '—';
    }

    /** Headline shown under the ring. */
    public function statusLabel(): string
    {
        return match ($this->state) {
            self::STATE_UNASSIGNED, self::STATE_NON_WORKING => $this->note ?? '—',
            self::STATE_NOT_STARTED => 'Not clocked in',
            self::STATE_COMPLETED => 'Shift completed',
            default => 'Working',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'expected_minutes' => $this->expectedMinutes,
            'worked_minutes' => $this->workedMinutes,
            'remaining_minutes' => $this->remainingMinutes,
            'percent' => $this->percent,
            'note' => $this->note,
            'measurable' => $this->isMeasurable(),
            'overtime_minutes' => $this->overtimeMinutes(),
            'expected_label' => $this->expectedLabel(),
            'worked_label' => $this->workedLabel(),
            'remaining_label' => $this->remainingLabel(),
            'percent_label' => $this->percentLabel(),
            'status_label' => $this->statusLabel(),
        ];
    }

    private static function hm(int $minutes): string
    {
        return intdiv($minutes, 60).'h '.($minutes % 60).'m';
    }
}
