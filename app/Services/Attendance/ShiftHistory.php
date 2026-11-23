<?php

namespace App\Services\Attendance;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The shift an employee worked on a past day, rebuilt from the audit log.
 *
 * Without it every day was judged against today's shift: someone moved from
 * the 13:00 UK shift to the 10:30 IT shift had every earlier day re-marked
 * hours late the moment attendance was re-synced. Two recorded changes:
 *
 *   - Reassignment: the employee moved from one shift to another
 *     (EmployeeObserver, audited since the first release). Applies from the
 *     day it was made. A first assignment (no shift → a shift) is data
 *     entry, not a change, so days before it keep that first shift.
 *   - Timing edit: a shift's own start / end / grace / hours changed
 *     (ConfigurationAuditObserver, audited since 6 Oct 2026). Days before
 *     the edit keep the timings that applied then.
 *
 * With no recorded change the answer is the employee's current shift, so
 * today — and every live sync — is judged exactly as before.
 *
 * Bound per request / job; flushed whenever an employee or shift audit entry
 * is written, so a change is seen straight away.
 */
class ShiftHistory
{
    /** The shift fields that decide late, overtime and expected hours. */
    public const TIMING_FIELDS = ['name', 'start_time', 'end_time', 'grace_minutes', 'standard_hours', 'ot_threshold_hours', 'break_duration'];

    /** Shift timing edits are in the audit log from this day. */
    public const TIMING_AUDIT_FROM = '2026-10-06';

    /** @var array<int, list<array{date: string, at: Carbon, from: int, to: ?int, by: ?string}>>|null */
    private ?array $reassignments = null;

    /** @var array<int, list<array{date: string, at: Carbon, old: array<string, mixed>, new: array<string, mixed>, by: ?string}>>|null */
    private ?array $timingEdits = null;

    /** @var array<int, ShiftSetting>|null */
    private ?array $shifts = null;

    /** Forget what was loaded, so the next question reads the audit log again. */
    public function flush(): void
    {
        $this->reassignments = null;
        $this->timingEdits = null;
        $this->shifts = null;
    }

    /**
     * The shift, with the timings that applied, for this employee on this day;
     * null when the employee had no shift then (the caller's default applies).
     */
    public function shiftOn(Employee $employee, CarbonInterface $day): ?ShiftSetting
    {
        $shiftId = $this->shiftIdOn($employee, $day);

        if ($shiftId === null) {
            return null;
        }

        $shift = $this->shift($shiftId);

        if ($shift === null && $shiftId === $employee->shift_id) {
            $shift = $employee->shift;
        }

        return $shift ? $this->timingOn($shift, $day) : null;
    }

    /** The shift id the employee was on that day. */
    public function shiftIdOn(Employee $employee, CarbonInterface $day): ?int
    {
        $date = $day->toDateString();

        // Ascending: the first change made after the day says what applied on it.
        foreach ($this->reassignmentsFor($employee->id) as $change) {
            if ($change['date'] > $date) {
                return $change['from'];
            }
        }

        return $employee->shift_id;
    }

    /** The shift as it stood on that day: its timing fields as they were then. */
    public function timingOn(ShiftSetting $shift, CarbonInterface $day): ShiftSetting
    {
        $date = $day->toDateString();
        $then = [];

        // The earliest edit after the day holds, in its old values, what applied on it.
        foreach ($this->timingEditsFor($shift->id) as $edit) {
            if ($edit['date'] <= $date) {
                continue;
            }
            foreach ($edit['old'] as $field => $value) {
                if (! array_key_exists($field, $then)) {
                    $then[$field] = $value;
                }
            }
        }

        if ($then === []) {
            return $shift;
        }

        // A copy for reading only: the stored shift is never touched.
        $past = $shift->replicate();
        $past->setAttribute($shift->getKeyName(), $shift->getKey());

        return $past->forceFill($then);
    }

    /**
     * Every recorded change between two days, oldest first: employee
     * reassignments for these employees, and timing edits of the shifts they
     * were on. Each entry carries the shift and timings before and after.
     *
     * @param  array<int, int>|null  $employeeIds  null = everyone
     * @return list<array{type: string, date: string, at: Carbon, employee_id: ?int, shift_id: int, before: ShiftSetting|null, after: ShiftSetting|null, fields: list<string>, by: ?string}>
     */
    public function changesBetween(CarbonInterface $from, CarbonInterface $to, ?array $employeeIds = null): array
    {
        $start = $from->toDateString();
        $end = $to->toDateString();
        $inRange = fn (string $date): bool => $date >= $start && $date <= $end;
        $changes = [];
        $shiftIds = [];

        $this->load();
        foreach ($this->reassignments as $employeeId => $list) {
            if ($employeeIds !== null && ! in_array($employeeId, $employeeIds, true)) {
                continue;
            }
            foreach ($list as $change) {
                $shiftIds[$change['from']] = true;
                if ($change['to'] !== null) {
                    $shiftIds[$change['to']] = true;
                }
                if (! $inRange($change['date'])) {
                    continue;
                }
                $day = Carbon::parse($change['date']);
                $changes[] = [
                    'type' => 'reassigned',
                    'date' => $change['date'],
                    'at' => $change['at'],
                    'employee_id' => $employeeId,
                    'shift_id' => $change['to'] ?? $change['from'],
                    // The old shift as it stood the day before; the new one from the change day.
                    'before' => ($old = $this->shift($change['from'])) ? $this->timingOn($old, $day->copy()->subDay()) : null,
                    'after' => $change['to'] !== null && ($new = $this->shift($change['to'])) ? $this->timingOn($new, $day) : null,
                    'fields' => ['shift'],
                    'by' => $change['by'],
                ];
            }
        }

        // Timing edits of any shift in scope: the people on it now or moved off / onto it.
        $inScope = $employeeIds === null
            ? null
            : array_keys($shiftIds + array_fill_keys(Employee::whereIn('id', $employeeIds)->whereNotNull('shift_id')->pluck('shift_id')->all(), true));

        foreach ($this->timingEdits as $shiftId => $list) {
            if ($inScope !== null && ! in_array($shiftId, $inScope, true)) {
                continue;
            }
            $shift = $this->shift($shiftId);
            foreach ($list as $edit) {
                if (! $inRange($edit['date']) || $shift === null) {
                    continue;
                }
                $day = Carbon::parse($edit['date']);
                $changes[] = [
                    'type' => 'timing',
                    'date' => $edit['date'],
                    'at' => $edit['at'],
                    'employee_id' => null,
                    'shift_id' => $shiftId,
                    'before' => $this->timingOn($shift, $day->copy()->subDay()),
                    'after' => $this->timingOn($shift, $day),
                    'fields' => array_keys($edit['new']),
                    'by' => $edit['by'],
                ];
            }
        }

        usort($changes, fn (array $a, array $b) => [$a['at']->getTimestamp(), $a['employee_id'] ?? 0] <=> [$b['at']->getTimestamp(), $b['employee_id'] ?? 0]);

        return $changes;
    }

    /** "10:30 – 19:30 · 6 min grace" for a shift as it stood. */
    public static function timingLabel(?ShiftSetting $shift): string
    {
        if ($shift === null || $shift->start_time === null || $shift->end_time === null) {
            return '—';
        }

        $label = Carbon::parse((string) $shift->start_time)->format('H:i').' – '.Carbon::parse((string) $shift->end_time)->format('H:i');

        return $shift->grace_minutes !== null ? $label.' · '.(int) $shift->grace_minutes.' min grace' : $label;
    }

    /** A shift by id, deleted ones included (an old day can still refer to it). */
    public function shift(int $id): ?ShiftSetting
    {
        $this->shifts ??= ShiftSetting::withTrashed()->get()->keyBy('id')->all();

        return $this->shifts[$id] ?? null;
    }

    /** @return list<array{date: string, at: Carbon, from: int, to: ?int, by: ?string}> */
    private function reassignmentsFor(int $employeeId): array
    {
        $this->load();

        return $this->reassignments[$employeeId] ?? [];
    }

    /** @return list<array{date: string, at: Carbon, old: array<string, mixed>, new: array<string, mixed>, by: ?string}> */
    private function timingEditsFor(int $shiftId): array
    {
        $this->load();

        return $this->timingEdits[$shiftId] ?? [];
    }

    /** One read of the audit log per request: both kinds of change, oldest first. */
    private function load(): void
    {
        if ($this->reassignments !== null && $this->timingEdits !== null) {
            return;
        }

        $this->reassignments = [];
        $this->timingEdits = [];

        $logs = AuditLog::query()
            ->where('action', 'updated')
            ->where(fn ($q) => $q
                ->where(fn ($e) => $e->where('auditable_type', Employee::class)->whereJsonContainsKey('new_values->shift_id'))
                ->orWhere('auditable_type', ShiftSetting::class))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'user_id', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'created_at']);

        $names = User::whereIn('id', $logs->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        foreach ($logs as $log) {
            $old = (array) ($log->old_values ?? []);
            $new = (array) ($log->new_values ?? []);
            $entry = [
                'date' => $log->created_at->toDateString(),
                'at' => $log->created_at,
                'by' => $log->user_id ? ($names[$log->user_id] ?? null) : null,
            ];

            if ($log->auditable_type === Employee::class) {
                $from = isset($old['shift_id']) ? (int) $old['shift_id'] : null;
                $to = isset($new['shift_id']) ? (int) $new['shift_id'] : null;

                // A first assignment is data entry; an unchanged id is no change.
                if ($from === null || $from === $to) {
                    continue;
                }

                $this->reassignments[(int) $log->auditable_id][] = $entry + ['from' => $from, 'to' => $to];

                continue;
            }

            $timing = array_intersect_key($new, array_flip(self::TIMING_FIELDS));
            if ($timing === []) {
                continue;
            }

            $this->timingEdits[(int) $log->auditable_id][] = $entry + [
                'old' => array_intersect_key($old, $timing),
                'new' => $timing,
            ];
        }
    }
}
