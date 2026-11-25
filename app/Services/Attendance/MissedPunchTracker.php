<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Missed punches, from live attendance data — the ONE definition every
 * screen, alert, weekly count and score uses.
 *
 * Read from the employee's own device punches through PunchTimeline (Face =
 * IN, ID Card = OUT, 60-second duplicates merged):
 *   - missed OUT — two INs in a row (IN → IN): they left without carding out;
 *   - missed IN  — two OUTs in a row (OUT → OUT): they came back without a
 *     Face IN and carded out again (the timeline ignores that second card
 *     tap as having no open session — it is still a missed IN);
 *   - missed check-out — the day ends on an IN, once the check-out is overdue
 *     (shift end + 1 hour; a past day always). A day the nightly auto
 *     checkout closed still counts: the system closed it, not the employee.
 * A card tap before the first Face IN is a stray tap (ignored), not a miss.
 *
 * Regularisation punches are left out, so a corrected day still shows what
 * was missed — marked resolved. A web clock-in day has only its check-out
 * to miss.
 *
 * The weekly allowance counts the working week, Monday to Friday, and starts
 * again every Monday. HR sets it on Attendance Settings (default 5): going
 * over it flags the employee for HR review. An early warning goes out at its
 * own number (default 3; 0 = none).
 */
class MissedPunchTracker
{
    public const DEFAULT_ALLOWANCE = 5;

    public const DEFAULT_WARNING_AT = 3;

    /** Within the warning and the allowance. */
    public const STATUS_OK = 'ok';

    /** At or past the early warning, within the allowance. */
    public const STATUS_WARNING = 'warning';

    /** Over the allowance: flagged for HR review. */
    public const STATUS_EXCEEDED = 'exceeded';

    public const KIND_OUT = 'out';

    public const KIND_IN = 'in';

    public const KIND_CHECKOUT = 'checkout';

    public function __construct(
        private PunchTimeline $timeline,
        private AttendanceCalculator $calculator,
        private ShiftResolver $shifts,
    ) {}

    /**
     * Every missed punch from $from to $to (never past today), by date.
     *
     * @return array<string, list<array{key: string, date: string, kind: string, label: string, after: ?string, before: ?string, after_at: ?Carbon, after_ms: ?int, resolved: bool, auto_closed: bool}>>
     */
    public function between(Employee $employee, CarbonInterface $from, CarbonInterface $to): array
    {
        $start = Carbon::parse($from->toDateString());
        $end = Carbon::parse($to->toDateString())->min(Carbon::today());

        if ($start->gt($end)) {
            return [];
        }

        $punches = AttendancePunch::where('employee_id', $employee->id)
            ->whereBetween('punch_date', [$start->toDateString(), $end->toDateString()])
            ->where('source', '!=', 'regularisation')
            ->orderBy('punched_at')->get()
            ->groupBy(fn (AttendancePunch $p) => $p->punch_date->toDateString());
        $rows = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()->keyBy(fn (Attendance $a) => $a->date->toDateString());
        $regularised = AttendanceRegularisation::where('employee_id', $employee->id)->where('status', 'approved')
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->pluck('work_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();

        $missed = [];
        foreach ($punches->keys()->merge($rows->keys())->unique()->sort() as $date) {
            $found = $this->forDay($employee, Carbon::parse($date), $punches->get($date, collect()), $rows->get($date),
                isset($regularised[$date]));
            if ($found !== []) {
                $missed[$date] = $found;
            }
        }

        return $missed;
    }

    /**
     * The missed punches of one day.
     *
     * @param  Collection<int, AttendancePunch>  $punches  the day's device punches (no regularisation punches)
     * @return list<array{key: string, date: string, kind: string, label: string, after: ?string, before: ?string, after_at: ?Carbon, after_ms: ?int, resolved: bool, auto_closed: bool}>
     */
    public function forDay(Employee $employee, Carbon $day, Collection $punches, ?Attendance $row, bool $regularised = false): array
    {
        $date = $day->toDateString();
        $resolved = $regularised || (bool) $row?->is_regularized;
        $autoClosed = (bool) $row?->isSystemAutoCheckout();
        $shift = $this->shifts->resolve($employee, $date);
        $checkoutOverdue = ! $day->isToday() || now()->greaterThan($this->calculator->missingCheckoutDeadline($day, $shift));
        $missed = [];

        $add = function (string $kind, ?array $before, ?array $after) use (&$missed, $date, $resolved, $autoClosed): void {
            $afterAt = $before ? Carbon::createFromTimestampMs($before['ts_ms'], config('app.timezone')) : null;
            $untilAt = $after ? Carbon::createFromTimestampMs($after['ts_ms'], config('app.timezone')) : null;
            $missed[] = [
                // Both ends, so two misses after the same punch stay two.
                'key' => $date.'|'.$kind.'|'.($afterAt?->format('H:i:s') ?? 'start').'|'.($untilAt?->format('H:i:s') ?? 'end'),
                'date' => $date,
                'kind' => $kind,
                'label' => match ($kind) {
                    self::KIND_OUT => 'Missed OUT between '.$this->clock($before).' and '.$this->clock($after),
                    self::KIND_IN => 'Missed IN between '.$this->clock($before).' and '.$this->clock($after),
                    default => 'No check-out after '.$this->clock($before).($autoClosed ? ' — the system closed the day' : ''),
                },
                'after' => $before ? $this->clock($before) : null,
                'before' => $after ? $this->clock($after) : null,
                'after_at' => $afterAt,
                'after_ms' => $before ? (int) $before['ts_ms'] : null,
                'resolved' => $resolved,
                'auto_closed' => $kind === self::KIND_CHECKOUT && $autoClosed,
            ];
        };

        if ($punches->isNotEmpty()) {
            $processed = $this->timeline->process($punches, $day, null, $shift);
            $nodes = $processed['nodes'] ?? [];
            $real = array_values(array_filter($nodes, fn (array $n) => ($n['type'] ?? '') !== 'missing' && ! empty($n['ts_ms'])));

            // A card tap with no open session is ignored by the timeline. After
            // the day's first Face IN it means they came back without a Face IN
            // and carded out again — a missed IN. Before it, it is only a
            // stray tap. (raw_events follow the punches in time order.)
            $firstIn = collect($real)->firstWhere('dir', 'IN');
            $sorted = $punches->sortBy('punched_at')->values();
            foreach ($processed['raw_events'] ?? [] as $i => $event) {
                $tap = $sorted->get($i);
                if ($tap === null || ($event['flag'] ?? '') !== 'ignored' || ! str_contains((string) ($event['note'] ?? ''), 'no active check-in')) {
                    continue;
                }
                $tapMs = (int) $tap->punched_at->getTimestampMs();
                if ($firstIn === null || $tapMs <= $firstIn['ts_ms']) {
                    continue;
                }
                $before = collect($real)->filter(fn (array $n) => $n['ts_ms'] < $tapMs)->last();
                $add(self::KIND_IN, $before, ['ts_ms' => $tapMs]);
            }

            $previous = null;
            $pending = null;
            foreach ($nodes as $node) {
                if (($node['type'] ?? '') === 'missing') {
                    $pending = ($node['dir'] ?? 'OUT') === 'OUT' ? self::KIND_OUT : self::KIND_IN;

                    continue;
                }
                if ($pending !== null) {
                    $add($pending, $previous, $node);
                    $pending = null;
                }
                $previous = $node;
            }

            // The day ends on an IN: the check-out is missed once it is overdue.
            $last = end($real) ?: null;
            if ($last !== null && ($last['dir'] ?? '') === 'IN' && $checkoutOverdue) {
                $add(self::KIND_CHECKOUT, $last, null);
            }

            usort($missed, fn (array $a, array $b) => [$a['after_ms'] ?? 0, $a['key']] <=> [$b['after_ms'] ?? 0, $b['key']]);

            return $missed;
        }

        // A web clock-in day: only the check-out can be missed.
        if ($row?->check_in && $checkoutOverdue && ($row->check_out === null || $autoClosed)) {
            $add(self::KIND_CHECKOUT, ['ts_ms' => $row->check_in->getTimestampMs(), 'time' => $row->check_in->format('h:i A')], null);
        }

        return $missed;
    }

    /** Missed punches in the Monday–Friday working week holding $day (to today). */
    public function weekCount(Employee $employee, ?CarbonInterface $day = null): int
    {
        $start = self::weekStart($day ?? now());

        return self::countInWeek($this->between($employee, $start, $start->copy()->addDays(4)), $start);
    }

    /** Missed punches the working week allows (Attendance Settings, default 5). */
    public static function allowance(): int
    {
        return max(1, (int) (ShiftResolver::settings()?->missed_punch_weekly_allowance ?? self::DEFAULT_ALLOWANCE));
    }

    /** Missed punches that bring an early warning (Attendance Settings, default 3; 0 = none). */
    public static function warningAt(): int
    {
        return (int) (ShiftResolver::settings()?->missed_punch_warning_at ?? self::DEFAULT_WARNING_AT);
    }

    /** ok, warning or exceeded for a week's count. */
    public static function status(int $count): string
    {
        $warningAt = self::warningAt();

        return match (true) {
            $count > self::allowance() => self::STATUS_EXCEEDED,
            $warningAt > 0 && $count >= $warningAt => self::STATUS_WARNING,
            default => self::STATUS_OK,
        };
    }

    /** The Monday of the week holding $day. */
    public static function weekStart(CarbonInterface $day): Carbon
    {
        return Carbon::parse($day->toDateString())->startOfWeek(Carbon::MONDAY);
    }

    /**
     * Missed punches on the working days (Monday–Friday) of the week starting
     * $weekStart, from a by-date list (between()).
     *
     * @param  array<string, list<array<string, mixed>>>  $byDate
     */
    public static function countInWeek(array $byDate, CarbonInterface $weekStart): int
    {
        $from = $weekStart->toDateString();
        $to = Carbon::parse($from)->addDays(4)->toDateString();

        return collect($byDate)->filter(fn (array $list, string $date) => $date >= $from && $date <= $to)
            ->sum(fn (array $list) => count($list));
    }

    private function clock(?array $node): string
    {
        if ($node === null || empty($node['ts_ms'])) {
            return '—';
        }

        return Carbon::createFromTimestampMs($node['ts_ms'], config('app.timezone'))->toImmutable()->userTime();
    }
}
