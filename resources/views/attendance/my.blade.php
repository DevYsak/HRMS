{{--
    My Attendance — the employee's everyday attendance workspace.

    Header → Punch guide → 4 KPI cards → Today → Today Activity (only when
    something happened today) → Attention (only when something needs action)
    → This Month → Attendance History → Working Hours + Punctuality → Quick
    actions. Today's part refreshes every 30 seconds (refreshToday); the
    month is cached until its data changes. Every figure is read from the
    component's canonical values (AttendanceCalculator via todayCalc, the
    PunchTimeline engine via punchJourney, computeStats via stats,
    monthHistory) — this view arranges them and never recalculates
    attendance.
--}}
@use('App\Enums\AttendanceMode')

<flux:main class="min-h-screen bg-[#F7F7F8] p-4 md:p-6 dark:bg-zinc-950">

@php
    // Today's values come from the component (ShowsTodayActivity::todayContext,
    // read from AttendanceCalculator / PunchTimeline state) so the 30-second
    // refresh and a full render show exactly the same numbers.
    $ctx = $this->todayContext();
    extract($ctx);
    $activity = $this->todayActivity($ctx);
    // This month — computeStats (follows the month selector) and monthHistory.
    $mh = $this->monthHistory;
    $monthTotals = $mh['totals'] ?? [];
@endphp

<div class="mx-auto max-w-[1360px] space-y-6" data-attendance-page @if($emp) wire:poll.30s.visible="refreshToday" @endif>

    {{-- Header --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-[30px] font-bold leading-tight tracking-tight text-zinc-900 dark:text-white">Attendance</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Track your work hours, punches and attendance history.</p>
        </div>
        <div class="flex items-center gap-1 rounded-xl border border-zinc-200 bg-white p-1 shadow-sm dark:border-zinc-800 dark:bg-zinc-900" data-month-selector>
            <button type="button" wire:click="historyPreviousMonth" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-white/5" aria-label="Previous month"><flux:icon.chevron-left class="size-4" /></button>
            <span class="inline-flex min-w-[9.5rem] items-center justify-center gap-2 text-sm font-semibold text-zinc-800 dark:text-zinc-100" wire:loading.class="opacity-50" wire:target="historyPreviousMonth,historyNextMonth"><flux:icon.calendar class="size-4 text-orange-500" /> {{ $mh['label'] ?? now()->format('F Y') }}</span>
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
        @include('attendance.my.activity')
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
