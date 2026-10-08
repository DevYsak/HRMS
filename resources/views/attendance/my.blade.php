{{--
    My Attendance — the employee's everyday attendance workspace.

    Header → 4 KPI cards → Today (timeline + totals) → Attention (only when
    something needs action) → This Month → Attendance History → 2 trends →
    Quick actions. Every figure is read from the component's canonical values
    (AttendanceCalculator via todayCalc, the PunchTimeline engine via
    punchJourney, computeStats via stats, monthHistory) — this view arranges
    them and never recalculates attendance.
--}}
@use('App\Enums\AttendanceMode')

<flux:main class="min-h-screen bg-[#F7F7F8] p-4 md:p-6 dark:bg-zinc-950">

@php
    $emp = auth()->user()->employee;
    $pj = $punchJourney;
    $mh = $this->monthHistory;
    $monthTotals = $mh['totals'] ?? [];
    $isCurrentMonth = ($mh['month'] ?? '') === now()->format('Y-m');
    $hm = fn (int $minutes): string => intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';

    // Today — AttendanceCalculator (worked = final out − first in, breaks never deducted).
    $workedMin = (int) ($todayCalc['worked_minutes'] ?? 0);
    $breakMin = (int) ($todayCalc['break_minutes'] ?? 0);
    $stdHours = (float) ($shift->standard_hours ?? 9);
    $targetMin = (int) round($stdHours * 60);
    $breakAllowance = (int) ($shift->break_duration ?? 0);
    $firstIn = $todayCalc['first_in'] ?? $pj['first_in'] ?? null;
    $lastOut = $todayCalc['last_out'] ?? $pj['last_out'] ?? null;
    $isLate = (bool) ($todayCalc['is_late'] ?? $todayAttendance?->is_late ?? false);
    $lateMinutes = (int) ($todayCalc['late_minutes'] ?? 0);
    $isLive = (bool) ($pj['live'] ?? false) || ($todayAttendance && ! $todayAttendance->check_out && (int) ($pj['raw_count'] ?? 0) === 0);
    $missingOut = ! $isLive && ((bool) ($todayCalc['missing_checkout'] ?? false) || (bool) ($pj['missing_out'] ?? false));
    $isIn = $todayAttendance && ! $todayAttendance->check_out;
    $heroMode = AttendanceMode::tryFromValue($todayAttendance->work_mode ?? $workMode);
    // Alerts that need action ("worked beyond shift" is information, not an issue).
    $issues = collect($attendanceAlerts)->reject(fn (array $a) => ($a['type'] ?? '') === 'overtime');
    $todayRow = $isCurrentMonth ? collect($mh['rows'] ?? [])->firstWhere('date', today()->toDateString()) : null;

    // Live "Worked today": closed engine sessions + the running one (web punch: since check-in).
    $liveStartMs = null;
    $liveBaseMin = $workedMin;
    if ((int) ($pj['raw_count'] ?? 0) > 0) {
        if ($pj['live']) {
            $liveStartMs = $pj['live_start_ms'];
            $liveBaseMin = $workedMin - (int) $pj['live_elapsed_minutes'];
        }
    } elseif ($isIn && $todayAttendance?->check_in) {
        $liveStartMs = $todayAttendance->check_in->getTimestampMs();
        $liveBaseMin = 0;
    }

    $shiftWindow = (! $this->shiftUnassigned && $shift?->start_time && $shift?->end_time)
        ? \Carbon\Carbon::parse($shift->start_time)->format('g:i A').' – '.\Carbon\Carbon::parse($shift->end_time)->format('g:i A')
        : null;

    // This month — computeStats (follows the month selector) and monthHistory.
    $present = (int) ($stats['present'] ?? 0);
    $late = (int) ($stats['late'] ?? 0);
    $scheduled = (int) ($stats['scheduled'] ?? 0);
    $attendanceRate = $scheduled > 0 ? (int) round(min(100, $present / $scheduled * 100)) : null;
@endphp

<div class="mx-auto max-w-[1360px] space-y-5" data-attendance-page>

    {{-- Header --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-[30px] font-bold leading-tight tracking-tight text-zinc-900 dark:text-white">Attendance</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Track your work hours, punches and attendance history.</p>
        </div>
        <div class="flex items-center gap-1 rounded-xl border border-zinc-200 bg-white p-1 shadow-sm dark:border-zinc-800 dark:bg-zinc-900" data-month-selector>
            <button type="button" wire:click="historyPreviousMonth" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-white/5" aria-label="Previous month"><flux:icon.chevron-left class="size-4" /></button>
            <span class="min-w-[8.5rem] text-center text-sm font-semibold text-zinc-800 dark:text-zinc-100" wire:loading.class="opacity-50" wire:target="historyPreviousMonth,historyNextMonth">{{ $mh['label'] ?? now()->format('F Y') }}</span>
            <button type="button" wire:click="historyNextMonth" @disabled($isCurrentMonth) class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 disabled:opacity-30 dark:hover:bg-white/5" aria-label="Next month"><flux:icon.chevron-right class="size-4" /></button>
        </div>
    </header>

    <x-attendance.biometric-notice />

    @if($emp === null)
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 text-sm text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900">
            Your account is not linked to an employee record, so there is no attendance to show.
        </div>
    @else
        @include('attendance.my.kpis')
        @include('attendance.my.today')
        @include('attendance.my.attention')
        @include('attendance.my.wfh-report')
        @include('attendance.my.month')
        @include('attendance.my.history')
        @include('attendance.my.trends')
        @include('attendance.my.actions')
    @endif
</div>

@include('attendance.my.modals')

</flux:main>
