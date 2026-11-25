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
 * screen, alert, monthly count and score uses.
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
 * The allowance counts every day of the calendar month, weekends included,
 * and starts again on the 1st. HR sets it on Attendance Settings (default
 * 5): going over it flags the employee for HR review. An early warning goes
 * out at its own number (default 3; 0 = none).
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

    /**
     * One day's punches as a tracked trail, for Punch Details: every punch
     * with what the system did with it — counted, a correction from an
     * approved regularisation, a duplicate it merged, a tap it ignored, a
     * device punch a correction replaced — and every missed punch at the
     * place it was missed, with whether it has been corrected. Read from the
     * same PunchTimeline and missed-punch rules as everything else.
     *
     * @return array{rows: list<array{ts: int, state: string, time: ?string, seconds: ?string, title: string, note: ?string, source: ?string, device: ?string}>, counts: array<string, int>}
     */
    public function dayTrail(Employee $employee, Carbon $day): array
    {
        $date = $day->toDateString();
        $punches = AttendancePunch::where('employee_id', $employee->id)->whereDate('punch_date', $date)->orderBy('punched_at')->get();
        $row = Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->first();
        $regularised = AttendanceRegularisation::where('employee_id', $employee->id)->where('status', 'approved')
            ->whereDate('work_date', $date)->exists();

        return $this->trail($employee, $day, $punches, $row, $regularised);
    }

    /**
     * dayTrail() from data already loaded — for screens that show many
     * employee-days at once (Live Attendance: By Day, By Employee, Punch Audit).
     *
     * @param  Collection<int, AttendancePunch>  $punches  every punch of the day, corrections included
     * @return array{rows: list<array{ts: int, state: string, time: ?string, seconds: ?string, title: string, note: ?string, source: ?string, device: ?string}>, counts: array<string, int>}
     */
    public function trail(Employee $employee, Carbon $day, Collection $punches, ?Attendance $row, bool $regularised): array
    {
        $date = $day->toDateString();
        $rows = [];

        if ($punches->isNotEmpty()) {
            $sorted = $punches->sortBy('punched_at')->values();
            $events = $this->timeline->process($sorted, $day, null, $this->shifts->resolve($employee, $date))['raw_events'] ?? [];

            foreach ($sorted as $i => $punch) {
                $event = $events[$i] ?? [];
                $direction = strtoupper((string) ($event['direction'] ?? $punch->direction ?? ''));
                $correction = $punch->source === 'regularisation';
                $state = match ($event['flag'] ?? 'kept') {
                    'duplicate', 'retry' => 'duplicate',
                    'ignored' => 'ignored',
                    'superseded' => 'superseded',
                    default => $correction ? 'corrected' : 'counted',
                };

                $rows[] = [
                    'ts' => (int) $punch->punched_at->getTimestampMs(),
                    'state' => $state,
                    'time' => $punch->punched_at->toImmutable()->userTime(),
                    'seconds' => $punch->punched_at->format('s'),
                    'title' => trim(($correction ? 'Correction' : ($punch->methodEnum()?->label() ?? 'Punch')).' '.$direction),
                    'note' => match ($state) {
                        'corrected' => 'Added by an approved regularisation',
                        'duplicate' => 'Read twice — merged into the punch next to it',
                        'superseded' => 'Replaced by the approved correction',
                        'ignored' => $event['note'] ?? 'Ignored',
                        default => 'Counted',
                    },
                    'source' => $punch->source,
                    'device' => $punch->device_serial,
                ];
            }
        }

        $devicePunches = $punches->where('source', '!=', 'regularisation')->values();
        foreach ($this->forDay($employee, $day, $devicePunches, $row, $regularised) as $missed) {
            $rows[] = [
                'ts' => (int) ($missed['after_ms'] ?? 0) + 1,
                'state' => $missed['resolved'] ? 'missed_corrected' : 'missed',
                'time' => null,
                'seconds' => null,
                'title' => match ($missed['kind']) {
                    self::KIND_IN => 'Missed IN',
                    self::KIND_OUT => 'Missed OUT',
                    default => 'Missed check-out',
                },
                'note' => $missed['label'].($missed['resolved'] ? ' — corrected by an approved regularisation' : ' — not regularised'),
                'source' => null,
                'device' => null,
            ];
        }

        usort($rows, fn (array $a, array $b) => $a['ts'] <=> $b['ts']);

        $counts = array_count_values(array_column($rows, 'state'));

        return ['rows' => $rows, 'counts' => array_merge(array_fill_keys(
            ['counted', 'corrected', 'missed', 'missed_corrected', 'duplicate', 'ignored', 'superseded'], 0), $counts)];
    }

    /** Missed punches in the calendar month holding $day, every day of it (to today). */
    public function monthCount(Employee $employee, ?CarbonInterface $day = null): int
    {
        $start = self::monthStart($day ?? now());

        return self::countInMonth($this->between($employee, $start, $start->copy()->endOfMonth()), $start);
    }

    /** Missed punches a calendar month allows (Attendance Settings, default 5). */
    public static function allowance(): int
    {
        return max(1, (int) (ShiftResolver::settings()?->missed_punch_monthly_allowance ?? self::DEFAULT_ALLOWANCE));
    }

    /** Missed punches that bring an early warning (Attendance Settings, default 3; 0 = none). */
    public static function warningAt(): int
    {
        return (int) (ShiftResolver::settings()?->missed_punch_warning_at ?? self::DEFAULT_WARNING_AT);
    }

    /** ok, warning or exceeded for a month's count. */
    public static function status(int $count): string
    {
        $warningAt = self::warningAt();

        return match (true) {
            $count > self::allowance() => self::STATUS_EXCEEDED,
            $warningAt > 0 && $count >= $warningAt => self::STATUS_WARNING,
            default => self::STATUS_OK,
        };
    }

    /** The 1st of the month holding $day. */
    public static function monthStart(CarbonInterface $day): Carbon
    {
        return Carbon::parse($day->toDateString())->startOfMonth();
    }

    /** "November 2026". */
    public static function monthLabel(CarbonInterface $monthStart): string
    {
        return $monthStart->format('F Y');
    }

    /**
     * Missed punches on every day of the calendar month starting $monthStart,
     * weekends included, from a by-date list (between()).
     *
     * @param  array<string, list<array<string, mixed>>>  $byDate
     */
    public static function countInMonth(array $byDate, CarbonInterface $monthStart): int
    {
        $from = $monthStart->toDateString();
        $to = Carbon::parse($from)->endOfMonth()->toDateString();

        return collect($byDate)
            ->filter(fn (array $list, string $date) => $date >= $from && $date <= $to)
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
