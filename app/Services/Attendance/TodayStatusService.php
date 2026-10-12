<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Today's attendance counts for a set of people — the one summary every
 * screen shows (Team View, Department View, Command Center, the HR
 * dashboard, Department Overview, the AI assistant).
 *
 * It never calculates: each person's status comes from
 * {@see AttendanceStatusResolver::currentForMany()} (calculator, punch
 * timeline, shift, weekly off, holiday calendar, approved leave), and this
 * class only puts each person into exactly one bucket.
 *
 * Metric definitions (shown as tooltips on the cards):
 *  - present          checked in today (working, on break, completed or missing a checkout)
 *  - working_now      clocked in right now
 *  - late             arrived after shift start + grace (never on a day off)
 *  - missing_checkout an open check-in past shift end + 1h
 *  - no_checkin       expected today, not in yet, shift not over
 *  - absent           expected today, not in, shift over
 *  - on_leave         approved leave today
 *  - weekly_off       the company's weekly off day
 *  - holiday          a public holiday or MDL shutdown in their calendar
 *  - not_employed     before joining / after leaving
 *  - expected         everyone who was due to work today
 */
class TodayStatusService
{
    public const LABELS = [
        'present' => 'Present Today',
        'working_now' => 'Present Now',
        'late' => 'Late',
        'missing_checkout' => 'Missing Checkout',
        'no_checkin' => 'No Check-in',
        'absent' => 'Absent',
        'on_leave' => 'On Leave',
        'weekly_off' => 'Weekly Off',
        'holiday' => 'Holiday',
    ];

    public const HINTS = [
        'present' => 'Checked in today, including anyone who has already clocked out.',
        'working_now' => 'Clocked in right now.',
        'late' => 'Arrived after shift start plus grace. Never counted on a day off.',
        'missing_checkout' => 'Still clocked in more than an hour after shift end.',
        'no_checkin' => 'Due to work today and not in yet — their shift has not ended.',
        'absent' => 'Due to work today, never checked in, and their shift is over.',
        'on_leave' => 'On approved leave today — not absent.',
        'weekly_off' => 'Today is their weekly off — not absent.',
        'holiday' => 'A public holiday or shutdown day for them — not absent.',
    ];

    public function __construct(private readonly AttendanceStatusResolver $resolver) {}

    /**
     * @param  Collection<int, int>|array<int, int>  $employeeIds
     * @return array{total: int, expected: int, present: int, working_now: int, completed: int, late: int, missing_checkout: int, no_checkin: int, absent: int, on_leave: int, weekly_off: int, holiday: int, not_employed: int, worked_on_off: int, day_off: bool, rows: array<int, array<string, mixed>>}
     */
    public function summary(Collection|array $employeeIds, ?CarbonInterface $now = null): array
    {
        $ids = collect($employeeIds)->map(fn ($id) => (int) $id)->unique()->values();

        $summary = array_fill_keys(['total', 'expected', 'present', 'working_now', 'completed', 'late', 'missing_checkout', 'no_checkin', 'absent', 'on_leave', 'weekly_off', 'holiday', 'not_employed', 'worked_on_off'], 0);
        $summary['day_off'] = false;
        $summary['rows'] = [];

        if ($ids->isEmpty()) {
            return $summary;
        }

        $employees = Employee::whereIn('id', $ids)->get();
        $rows = $this->resolver->currentForMany($employees, null, $now);

        foreach ($rows as $employeeId => $status) {
            $summary['total']++;
            $bucket = $this->bucket($status);
            $summary[$bucket]++;

            if ($bucket === 'present') {
                if ($status['live']) {
                    $summary['working_now']++;
                }
                if ($status['state'] === AttendanceStatusResolver::COMPLETED) {
                    $summary['completed']++;
                }
                if ($status['state'] === AttendanceStatusResolver::MISSING_CHECKOUT) {
                    $summary['missing_checkout']++;
                }
                if ($status['day']->isLate) {
                    $summary['late']++;
                }
                if ($status['day']->dayType !== WorkingDayResolver::WORKING_DAY) {
                    $summary['worked_on_off']++;
                }
            }

            $summary['rows'][(int) $employeeId] = $status + ['bucket' => $bucket];
        }

        $summary['expected'] = $summary['total'] - $summary['on_leave'] - $summary['weekly_off'] - $summary['holiday'] - $summary['not_employed'];
        // Nobody was due today (a weekly off or holiday for everyone).
        $summary['day_off'] = $summary['total'] > 0 && $summary['expected'] === 0;

        return $summary;
    }

    /** The one bucket a person's status belongs to. */
    public function bucket(array $status): string
    {
        if ($status['state'] !== AttendanceStatusResolver::NOT_IN) {
            return 'present';
        }

        return match ($status['reason']) {
            'weekly_off' => 'weekly_off',
            'holiday', 'mdl' => 'holiday',
            'leave' => 'on_leave',
            'not_employed' => 'not_employed',
            'absent' => 'absent',
            default => 'no_checkin',
        };
    }
}
