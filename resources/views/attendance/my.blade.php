{{--
    My Attendance — the employee's everyday attendance workspace.

    Hero → Punch guide → 4 KPI cards → Today Activity (only when something
    happened today) → Attention (only when something needs action) + Today's
    Summary + Quick Stats → Attendance History (server-side filters, pages,
    export) → Working Hours + Punctuality + Quick Actions. Today's punch
    table, sessions and raw scans open in one drawer (today-drawer).

    Today's part refreshes every 30 seconds (refreshToday); the month is
    cached until its data changes. Every figure is read from the component's
    canonical values (AttendanceCalculator via todayCalc, the PunchTimeline
    engine via punchJourney, computeStats via stats, monthHistory) — this
    view arranges them and never recalculates attendance.
--}}
@use('App\Enums\AttendanceMode')

<flux:main class="min-h-screen bg-[#F5F6FA] p-4 md:p-6 dark:bg-[#0A1020]">

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

    // Shared surface styles for this page (light + navy dark mode).
    $ui = [
        'card' => 'rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.04),0_12px_32px_-18px_rgba(16,24,40,0.12)] dark:border-white/[0.07] dark:bg-[#111A2E] dark:shadow-none',
        'lift' => 'transition duration-200 motion-safe:hover:-translate-y-0.5 hover:shadow-[0_1px_2px_rgba(16,24,40,0.05),0_18px_40px_-20px_rgba(16,24,40,0.22)] dark:hover:border-white/[0.12]',
        'title' => 'text-[16px] font-semibold tracking-tight text-zinc-900 dark:text-white',
        'sub' => 'text-xs text-zinc-500 dark:text-slate-400',
        'tile' => 'flex size-9 shrink-0 items-center justify-center rounded-xl',
        'inset' => 'rounded-xl bg-zinc-50/80 ring-1 ring-inset ring-zinc-100 dark:bg-white/[0.03] dark:ring-white/[0.05]',
        'field' => 'block w-full min-w-0 rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-800 shadow-sm transition focus:border-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-400/25 dark:border-white/10 dark:bg-[#0D1526] dark:text-slate-100 dark:[color-scheme:dark]',
        'btn' => 'inline-flex items-center justify-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-3.5 py-2 text-sm font-semibold text-zinc-700 shadow-sm transition hover:border-orange-300 hover:text-orange-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-400/50 dark:border-white/10 dark:bg-white/[0.04] dark:text-slate-200 dark:hover:border-orange-400/40 dark:hover:text-orange-300',
        'btnPrimary' => 'inline-flex items-center justify-center gap-1.5 rounded-xl bg-gradient-to-b from-orange-500 to-orange-600 px-4 py-2 text-sm font-semibold text-white shadow-[0_6px_16px_-6px_rgba(249,115,22,0.7)] transition hover:from-orange-500 hover:to-orange-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-400/60 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-[#111A2E]',
    ];
@endphp

<div class="mx-auto max-w-[1400px] space-y-5" data-attendance-page @if($emp) wire:poll.30s.visible="refreshToday" @endif>

    @include('attendance.my.hero')

    <x-attendance.biometric-notice />

    @if($emp === null)
        <div class="{{ $ui['card'] }} p-6 text-sm text-zinc-500 dark:text-slate-400">
            Your account is not linked to an employee record, so there is no attendance to show.
        </div>
    @else
        @include('attendance.my.kpis')
        @include('attendance.my.activity')
        @include('attendance.my.overview')
        @include('attendance.my.wfh-report')
        @include('attendance.my.history')

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2 xl:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)_minmax(0,0.85fr)]" data-trends>
            @include('attendance.my.trends')
            @include('attendance.my.actions')
        </div>

        @include('attendance.my.today-drawer')
    @endif
</div>

@include('attendance.my.modals')

</flux:main>
