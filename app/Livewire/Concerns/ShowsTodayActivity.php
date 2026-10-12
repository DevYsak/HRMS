<?php

namespace App\Livewire\Concerns;

use App\Enums\AttendanceMode;
use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendanceRegularisation;
use App\Models\BreakLog;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\HolidayResolver;
use App\Services\Attendance\WorkingDayResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * My Attendance — the live "today" part of the page: the values the KPI
 * cards, Today, Today Activity and Needs Attention read, the Today Activity
 * events, and the 30-second refresh that reloads only today.
 *
 * Nothing here calculates attendance. Every figure comes from the canonical
 * services the page already loads — AttendanceCalculator (todayCalc), the
 * PunchTimeline engine (punchJourney), the classified journey and today's
 * break logs — and is only arranged for display.
 */
trait ShowsTodayActivity
{
    /**
     * Reload today's canonical state only (polled every 30 s by the page).
     * The month history is cached and rebuilt only when its data changes.
     */
    public function refreshToday(): void
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return;
        }

        $this->todayAttendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('date', Carbon::today()->toDateString())
            ->with('regularisation')
            ->first();
        $this->activeBreak = $this->todayAttendance
            ? BreakLog::where('attendance_id', $this->todayAttendance->id)->whereNull('break_end')->first()
            : null;
        $this->loadTodayTimeline($employee);
        $this->todaySummary = AttendanceDailySummary::where('employee_id', $employee->id)
            ->whereDate('date', Carbon::today()->toDateString())
            ->first();
        $this->attendanceAlerts = $this->buildAttendanceAlerts();
        unset($this->myRegularisations);
    }

    /**
     * Today's punch timeline, classified journey and canonical day — shared by
     * the full load and the 30-second refresh so both read the same way.
     */
    protected function loadTodayTimeline($employee): void
    {
        $this->punchJourney = $this->buildPunchJourney($employee);
        $this->attendanceJourney = $this->buildAttendanceJourney($employee);
        $this->todayCalc = app(AttendanceCalculator::class)
            ->forDay($employee, Carbon::today(), $this->todayAttendance)
            ->toArray();
        // LIVE / missing checkout come from the shared calculation, not from
        // "the last raw punch is an IN": past the cutoff (shift end + 1h) the
        // journey stops showing as live.
        $this->punchJourney['live'] = (bool) ($this->punchJourney['live'] ?? false) && (bool) $this->todayCalc['live'];
        $this->punchJourney['missing_out'] = (bool) $this->todayCalc['missing_checkout'] || (bool) ($this->punchJourney['missing_out'] ?? false);
    }

    /**
     * Today's day type for the status card and chip when there is no punch
     * (Holiday, Weekly off, Leave…), from the same classification the month
     * history uses — without building the whole month.
     *
     * @return array{status: string, holiday: ?string}
     */
    protected function todayDayStatus(): array
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return ['status' => 'Today', 'holiday' => null];
        }

        $today = Carbon::today();
        $state = app(WorkingDayResolver::class)->classify($employee, $today);

        $status = match ($state) {
            WorkingDayResolver::PUBLIC_HOLIDAY => 'Holiday',
            WorkingDayResolver::MDL_SHUTDOWN => 'MDL shutdown',
            WorkingDayResolver::WEEKLY_OFF => WorkingDayResolver::WEEKLY_OFF_LABEL,
            WorkingDayResolver::APPROVED_LEAVE => 'On leave',
            default => 'Today',
        };

        $holiday = $state === WorkingDayResolver::PUBLIC_HOLIDAY
            ? app(HolidayResolver::class)->keyedForEmployee($employee, $today, $today)->get($today->toDateString())?->name
            : null;

        return ['status' => $status, 'holiday' => $holiday];
    }

    /**
     * The values the live part of the page reads, derived only from the
     * component's canonical state (todayCalc, punchJourney, the alerts, the
     * stats). Used by every render, including the 30-second refresh.
     *
     * @return array<string, mixed>
     */
    protected function todayContext(): array
    {
        $pj = $this->punchJourney;
        $todayCalc = $this->todayCalc;
        $todayAttendance = $this->todayAttendance;
        $shift = $this->shift;
        $hm = fn (int $minutes): string => intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';

        $workedMin = (int) ($todayCalc['worked_minutes'] ?? 0);
        $breakMin = (int) ($todayCalc['break_minutes'] ?? 0);
        $stdHours = (float) ($shift->standard_hours ?? 9);
        $firstIn = $todayCalc['first_in'] ?? $pj['first_in'] ?? null;
        $lastOut = $todayCalc['last_out'] ?? $pj['last_out'] ?? null;
        $isLive = (bool) ($pj['live'] ?? false) || ($todayAttendance && ! $todayAttendance->check_out && (int) ($pj['raw_count'] ?? 0) === 0);
        // Closed by the nightly auto checkout at the shift end (not a real OUT).
        $autoClosed = ($todayCalc['source'] ?? null) === AttendanceCalculator::SOURCE_AUTO_CHECKOUT;
        $missingOut = ! $isLive && ! $autoClosed && ((bool) ($todayCalc['missing_checkout'] ?? false) || (bool) ($pj['missing_out'] ?? false));
        $isIn = $todayAttendance && ! $todayAttendance->check_out;

        // Alerts that need action ("worked beyond shift" is information, not an issue).
        $issues = collect($this->attendanceAlerts)->reject(fn (array $a) => ($a['type'] ?? '') === 'overtime');
        // Today's missing punch comes from the engine's timeline (the alert list
        // leaves today to the timeline); it feeds Attention and the Today footer.
        $todayKeyAtt = Carbon::today()->toDateString();
        $todayMissingDir = collect($pj['nodes'] ?? [])->firstWhere('type', 'missing')['dir'] ?? ($missingOut ? 'OUT' : null);
        $todayMissing = ($todayMissingDir !== null || ! empty($pj['needs_regularization']))
            && ! $issues->contains(fn (array $a) => ($a['date'] ?? null) === $todayKeyAtt && str_starts_with((string) ($a['type'] ?? ''), 'missing'));

        // Live "Worked today": closed engine sessions + the running one (web punch: since check-in).
        $liveStartMs = null;
        $liveBaseMin = $workedMin;
        if ((int) ($pj['raw_count'] ?? 0) > 0) {
            if ($pj['live'] ?? false) {
                $liveStartMs = $pj['live_start_ms'];
                $liveBaseMin = $workedMin - (int) ($pj['live_elapsed_minutes'] ?? 0);
            }
        } elseif ($isIn && $todayAttendance?->check_in) {
            $liveStartMs = $todayAttendance->check_in->getTimestampMs();
            $liveBaseMin = 0;
        }

        $month = Carbon::createFromFormat('!Y-m', $this->historyMonth ?: now()->format('Y-m'));
        $present = (int) ($this->stats['present'] ?? 0);
        $scheduled = (int) ($this->stats['scheduled'] ?? 0);

        return [
            'emp' => Auth::user()->employee,
            'pj' => $pj,
            'hm' => $hm,
            'workedMin' => $workedMin,
            'breakMin' => $breakMin,
            'stdHours' => $stdHours,
            'targetMin' => (int) round($stdHours * 60),
            'breakAllowance' => (int) ($shift->break_duration ?? 0),
            'firstIn' => $firstIn,
            'lastOut' => $lastOut,
            'isLate' => (bool) ($todayCalc['is_late'] ?? $todayAttendance?->is_late ?? false),
            'lateMinutes' => (int) ($todayCalc['late_minutes'] ?? 0),
            'isLive' => $isLive,
            'autoClosed' => $autoClosed,
            'missingOut' => $missingOut,
            'isIn' => $isIn,
            'heroMode' => AttendanceMode::tryFromValue($todayAttendance->work_mode ?? $this->workMode),
            'issues' => $issues,
            'todayKeyAtt' => $todayKeyAtt,
            'todayMissingDir' => $todayMissingDir,
            'todayMissing' => $todayMissing,
            'issueCount' => $issues->count() + (int) $todayMissing,
            'todayRow' => $this->todayDayStatus(),
            'liveStartMs' => $liveStartMs,
            'liveBaseMin' => $liveBaseMin,
            'shiftWindow' => (! $this->shiftUnassigned && $shift?->start_time && $shift?->end_time)
                ? Carbon::parse($shift->start_time)->format('g:i A').' – '.Carbon::parse($shift->end_time)->format('g:i A')
                : null,
            'isCurrentMonth' => $month->isSameMonth(Carbon::today()),
            'monthLabel' => $month->format('F Y'),
            'present' => $present,
            'late' => (int) ($this->stats['late'] ?? 0),
            'scheduled' => $scheduled,
            'attendanceRate' => $scheduled > 0 ? (int) round(min(100, $present / $scheduled * 100)) : null,
        ];
    }

    /**
     * Today Activity: today's effective events in time order — each kept punch
     * from the PunchTimeline engine (Face = IN, Card = OUT; raw device
     * direction is never shown as the truth), web punches, break start / end
     * from today's break logs, the nightly auto checkout and regularised
     * punches — plus the live-clock state. Empty when nothing happened today.
     *
     * @param  array<string, mixed>  $ctx  from todayContext()
     * @return array{events: array<int, array<string, mixed>>, state: string, clock_start_ms: ?int, since: ?string}
     */
    protected function todayActivity(array $ctx): array
    {
        $tz = config('app.timezone');
        $events = [];
        $fmt = fn (int $ms, string $format) => Carbon::createFromTimestampMs($ms, $tz)->format($format);

        // Approved regularisation for today (the employee's own — reason and approver are theirs to see).
        $regularisation = AttendanceRegularisation::with('reviewer')
            ->where('employee_id', Auth::user()->employee?->id)
            ->whereDate('work_date', Carbon::today()->toDateString())
            ->where('status', 'approved')
            ->latest('id')
            ->first();

        $firstInIndex = null;
        foreach ($this->punchJourney['nodes'] ?? [] as $node) {
            if (($node['type'] ?? '') === 'missing' || empty($node['ts_ms'])) {
                continue;
            }

            $isIn = ($node['dir'] ?? '') === 'IN';
            $isReg = ($node['source'] ?? '') === 'regularisation';
            $method = $node['method'] ?? null;
            $sourceLabel = match (true) {
                $isReg => 'Regularisation',
                ($node['source'] ?? '') === 'web' => 'Web punch',
                $method === 'face' => 'Biometric Face',
                in_array($method, ['id_card', 'physical_card'], true) => 'Biometric Card',
                $method === 'fingerprint' => 'Biometric Fingerprint',
                default => 'Biometric',
            };
            $methodTitle = match (true) {
                $method === 'face' => 'Face',
                in_array($method, ['id_card', 'physical_card'], true) => 'ID Card',
                ($node['source'] ?? '') === 'web' => 'Web',
                default => $node['method_label'] ?? 'Punch',
            };

            $kind = $isReg ? 'regularised' : ($isIn ? 'in' : 'out');
            $details = [
                'Event' => $isReg ? 'Regularised '.($isIn ? 'IN' : 'OUT') : ($method === 'face' ? 'Face Recognition' : ($methodTitle === 'ID Card' ? 'ID Card' : $methodTitle.' punch')),
                'Time' => $fmt((int) $node['ts_ms'], 'h:i:s A'),
                'Effective event' => $isIn ? 'IN' : 'OUT',
                'Source' => $sourceLabel,
            ];
            if ($isReg) {
                $details['Note'] = 'Manually corrected attendance entry';
                if ($regularisation?->reviewer) {
                    $details['Approved by'] = $regularisation->reviewer->name;
                }
                if ($regularisation?->reason) {
                    $details['Reason'] = $regularisation->reason;
                }
            }

            $events[] = [
                'ts' => (int) $node['ts_ms'],
                'kind' => $kind,
                'time' => $fmt((int) $node['ts_ms'], 'h:i A'),
                'title' => $isReg ? 'Regularised '.($isIn ? 'IN' : 'OUT') : $methodTitle.' '.($isIn ? 'IN' : 'OUT'),
                'badge' => $isReg ? 'REG' : ($isIn ? 'IN' : 'OUT'),
                'details' => $details,
            ];
            if ($isIn && $firstInIndex === null) {
                $firstInIndex = array_key_last($events);
            }
        }

        // A web / mobile clock with no punch rows: the attendance row's own times.
        $att = $this->todayAttendance;
        if ($events === [] && $att?->check_in) {
            $events[] = [
                'ts' => $att->check_in->getTimestampMs(), 'kind' => 'in', 'time' => $att->check_in->format('h:i A'),
                'title' => 'Web IN', 'badge' => 'IN',
                'details' => ['Event' => 'Web clock-in', 'Time' => $att->check_in->format('h:i:s A'), 'Effective event' => 'IN', 'Source' => ucfirst((string) ($att->check_in_method ?: 'web'))],
            ];
            $firstInIndex = 0;
            if ($att->check_out && ! $ctx['autoClosed']) {
                $events[] = [
                    'ts' => $att->check_out->getTimestampMs(), 'kind' => 'out', 'time' => $att->check_out->format('h:i A'),
                    'title' => 'Web OUT', 'badge' => 'OUT',
                    'details' => ['Event' => 'Web clock-out', 'Time' => $att->check_out->format('h:i:s A'), 'Effective event' => 'OUT', 'Source' => ucfirst((string) ($att->check_out_method ?: 'web'))],
                ];
            }
        }

        if ($firstInIndex !== null) {
            $events[$firstInIndex]['details']['Status'] = $ctx['isLate'] ? 'Late'.($ctx['lateMinutes'] > 0 ? ' · '.$ctx['lateMinutes'].'m' : '') : 'On time';
        }

        // Breaks taken with the Start / End break buttons.
        if ($att) {
            foreach (BreakLog::where('attendance_id', $att->id)->orderBy('break_start')->get() as $break) {
                $events[] = [
                    'ts' => $break->break_start->getTimestampMs(), 'kind' => 'break_start', 'time' => $break->break_start->format('h:i A'),
                    'title' => 'Break Start', 'badge' => 'BREAK',
                    'details' => ['Event' => 'Break started', 'Time' => $break->break_start->format('h:i:s A'), 'Note' => 'Breaks are shown for information and never deducted from worked time'],
                ];
                if ($break->break_end) {
                    $events[] = [
                        'ts' => $break->break_end->getTimestampMs(), 'kind' => 'break_end', 'time' => $break->break_end->format('h:i A'),
                        'title' => 'Break End', 'badge' => 'BREAK',
                        'details' => ['Event' => 'Break ended', 'Time' => $break->break_end->format('h:i:s A'), 'Duration' => (int) $break->duration_minutes.'m'],
                    ];
                }
            }
        }

        // The nightly auto checkout closed the day at the shift end (not a real OUT).
        if ($ctx['autoClosed'] && $att?->check_out) {
            $events[] = [
                'ts' => $att->check_out->getTimestampMs(), 'kind' => 'auto', 'time' => $att->check_out->format('h:i A'),
                'title' => 'Auto Checkout', 'badge' => 'AUTO',
                'details' => ['Event' => 'Auto checkout', 'Time' => $att->check_out->format('h:i:s A'), 'Effective event' => 'OUT', 'Source' => 'System', 'Note' => Attendance::AUTO_CHECKOUT_EXPLANATION],
            ];
        }

        usort($events, fn ($a, $b) => $a['ts'] <=> $b['ts']);

        $onBreak = $this->activeBreak !== null;
        $state = match (true) {
            $events === [] => 'none',
            $ctx['isLive'] && $onBreak => 'break',
            $ctx['isLive'] => 'working',
            $ctx['autoClosed'] => 'auto',
            $ctx['missingOut'] => 'missing',
            (bool) $ctx['lastOut'] => 'out',
            default => 'idle',
        };

        // The clock counts from the latest effective IN of the open session.
        $clockStart = $state === 'working' ? $ctx['liveStartMs'] : null;

        return [
            'events' => $events,
            'state' => $state,
            'clock_start_ms' => $clockStart,
            'since' => $clockStart ? $fmt((int) $clockStart, 'h:i A') : null,
        ];
    }
}
