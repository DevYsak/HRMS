@use('App\Enums\AttendanceMode')
@use('App\Enums\PunchMethod')
@use('App\Services\Attendance\AttendanceRegister')

@php
    $hm = fn (int $m): string => intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m';
    $short = fn (int $m): string => $m >= 60 ? intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m' : $m.'m';
    // A shift's length the way people say it: "9h", "8h 30m".
    $shiftLength = fn (int $m): string => $m % 60 === 0 ? intdiv($m, 60).'h' : $short($m);
    // Worked against the shift — the same tones as the Live board.
    $progressBar = ['on_track' => 'bg-emerald-500', 'nearing' => 'bg-lime-500', 'done' => 'bg-yellow-400', 'beyond' => 'bg-orange-500', 'way_beyond' => 'bg-rose-500'];
    $progressText = ['on_track' => 'text-emerald-600 dark:text-emerald-400', 'nearing' => 'text-lime-600 dark:text-lime-400', 'done' => 'text-yellow-600 dark:text-yellow-400', 'beyond' => 'text-orange-600 dark:text-orange-400', 'way_beyond' => 'text-rose-600 dark:text-rose-400'];
    $chipTone = [
        'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
        'red' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30',
        'blue' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/30',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/30',
        'zinc' => 'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10',
    ];
    // Wall-clock labels for the timeline (minutes from the date's midnight), in the reader's 12 / 24-hour choice.
    $is24h = app(\App\Support\DateDisplay::class)->timeFormat() === 'H:i';
    $clockAt = fn (int $minute): string => \Carbon\CarbonImmutable::today()->addMinutes($minute)->format($is24h ? 'H:i' : 'h:i A');
    $hourAt = fn (int $minute): string => $is24h ? sprintf('%02d:00', intdiv($minute, 60) % 24) : \Carbon\CarbonImmutable::today()->addMinutes($minute)->format('g A');
    // Hour marks on round hours, at most $most of them so the labels never touch.
    $ticksFor = function (array $window, int $most = 6): array {
        $span = max(1, $window['to'] - $window['from']);
        $step = collect([60, 120, 180, 240, 360, 480])->first(fn (int $s) => $span / $s <= $most) ?? 480;
        $ticks = [];
        for ($m = (int) ceil($window['from'] / $step) * $step; $m <= $window['to']; $m += $step) {
            $ticks[] = ['at' => $m, 'pct' => round(($m - $window['from']) * 100 / $span, 3)];
        }

        return $ticks;
    };
    $canCorrect = auth()->user()->canManageEmployees();
    $canSeeLive = auth()->user()->can('view_live_attendance');
    $ghostLink = 'inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-bold text-zinc-600 transition hover:bg-orange-50 hover:text-orange-600 dark:text-zinc-300 dark:hover:bg-white/5';
@endphp

<flux:main class="min-h-screen bg-[#FFF8F3] p-4 md:p-6">
<div class="mx-auto max-w-[1600px]">

<x-attendance.biometric-notice class="mb-3" />

{{-- ═══════════════ HEADER ═══════════════ --}}
<header class="mb-4 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="pulse-page-title">Employees Attendance</h1>
        <div class="mt-0.5 flex items-center gap-1.5 text-xs text-zinc-400">
            <span>Dashboard</span><flux:icon.chevron-right class="size-3" /><span>Attendance</span><flux:icon.chevron-right class="size-3" /><span class="font-semibold text-orange-500">All Employees</span>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <x-attendance.live-button />
        <button wire:click="exportCsv" type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white px-3 py-2 text-xs font-bold text-zinc-600 shadow-sm transition hover:bg-orange-50 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-300"><flux:icon.arrow-down-tray class="size-4 text-orange-500" /> Export CSV</button>
        @if($canCorrect)
            <button wire:click="openMarkModal" type="button" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-amber-500 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-orange-300/40 transition hover:shadow-xl dark:shadow-none"><flux:icon.pencil-square class="size-4" /> Mark Attendance</button>
        @endif
    </div>
</header>

{{-- ═══════════════ PERIOD + QUICK LINKS ═══════════════ --}}
<div class="ui-surface mb-4 flex flex-wrap items-center justify-between gap-3 px-3 py-2.5">
    <div class="flex flex-wrap items-center gap-2">
        <div class="flex items-center gap-1 rounded-xl border border-orange-100 bg-white p-1 shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <button wire:click="previousWeek" type="button" aria-label="Previous week" class="flex size-7 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-orange-50 hover:text-orange-500"><flux:icon.chevron-left class="size-4" /></button>
            <button wire:click="thisWeek" type="button" class="min-w-[9rem] px-2 py-1 text-center text-xs font-bold text-zinc-700 transition hover:text-orange-500 dark:text-zinc-200" title="Jump to this week">{{ $weekLabel }}</button>
            <button wire:click="nextWeek" type="button" aria-label="Next week" class="flex size-7 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-orange-50 hover:text-orange-500"><flux:icon.chevron-right class="size-4" /></button>
        </div>
        <div class="flex items-center gap-1.5 rounded-xl border {{ $date ? 'border-orange-400 ring-1 ring-orange-200 dark:ring-orange-500/30' : 'border-orange-100 dark:border-white/10' }} bg-white px-2 py-1 shadow-sm dark:bg-zinc-900" title="Pick a single date (overrides the week)">
            <span class="text-xs font-bold uppercase tracking-wider {{ $date ? 'text-orange-500' : 'text-zinc-400' }}">Day</span>
            <input type="date" wire:model.live="date" max="{{ now()->toDateString() }}" class="w-[8rem] border-0 bg-transparent p-1 text-xs font-semibold text-zinc-600 focus:ring-0 dark:text-zinc-300">
            @if($date)
                <button type="button" wire:click="$set('date', '')" class="rounded-md p-0.5 text-zinc-400 hover:bg-orange-50 hover:text-orange-600" aria-label="Back to the week"><flux:icon.x-mark class="size-3.5" /></button>
            @endif
        </div>
    </div>
    <nav class="flex flex-wrap items-center gap-0.5" aria-label="Attendance tools">
        <a href="{{ route('attendance.command-center') }}" class="{{ $ghostLink }}"><flux:icon.bolt class="size-4 text-orange-500" /> Command Center</a>
        <a href="{{ route('attendance.reports') }}" class="{{ $ghostLink }}"><flux:icon.document-chart-bar class="size-4 text-orange-500" /> Reports</a>
        <a href="{{ route('attendance.executive') }}" class="{{ $ghostLink }}"><flux:icon.presentation-chart-line class="size-4 text-orange-500" /> Executive</a>
        @can('manage_biometric')
            <a href="{{ route('attendance.biometric-control') }}" class="{{ $ghostLink }}"><flux:icon.finger-print class="size-4 text-orange-500" /> Biometric</a>
        @endcan
    </nav>
</div>

<div class="space-y-4">

{{-- ═══════════════ KPI TILES (the current filter) ═══════════════ --}}
@php
    $kpis = [
        ['label' => 'Total Employees', 'value' => $stats['total'], 'sub' => 'active workforce', 'icon' => 'users', 'color' => '#71717a', 'pct' => null],
        ['label' => 'Present', 'value' => $stats['present'], 'sub' => $stats['present_pct'].'% of expected', 'icon' => 'check-circle', 'color' => '#10b981', 'pct' => $stats['present_pct']],
        ['label' => 'Absent', 'value' => $stats['absent'], 'sub' => $stats['absent_pct'].'% of expected', 'icon' => 'x-circle', 'color' => '#ef4444', 'pct' => $stats['absent_pct']],
        ['label' => 'On Time', 'value' => $stats['on_time'], 'sub' => 'within grace', 'icon' => 'clock', 'color' => '#3b82f6', 'pct' => $stats['present'] > 0 ? round($stats['on_time'] * 100 / $stats['present'], 1) : 0],
        ['label' => 'Late Arrivals', 'value' => $stats['late'], 'sub' => $stats['late_pct'].'% of present', 'icon' => 'exclamation-triangle', 'color' => '#f59e0b', 'pct' => $stats['late_pct']],
        ['label' => 'WFH / Hybrid', 'value' => $stats['wfh'], 'sub' => 'working remotely', 'icon' => 'home', 'color' => '#8b5cf6', 'pct' => $stats['present'] > 0 ? min(100, round($stats['wfh'] * 100 / $stats['present'], 1)) : 0],
    ];
@endphp
<div class="flex items-center gap-2 text-[11px] font-bold text-zinc-400">
    <flux:icon.funnel class="size-3.5 text-orange-400" /> Metrics below reflect the current filter · <span class="text-orange-500">{{ $weekLabel }}</span>
</div>
<div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6" data-kpi-tiles>
    @foreach($kpis as $k)
        <div class="ui-surface relative overflow-hidden p-4 transition hover:-translate-y-0.5 hover:shadow-md">
            <span class="pointer-events-none absolute -right-6 -top-6 size-20 rounded-full opacity-[0.07]" style="background: {{ $k['color'] }}"></span>
            <div class="flex items-start justify-between gap-2">
                <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl" style="background: {{ $k['color'] }}1a; color: {{ $k['color'] }};"><flux:icon :icon="$k['icon']" class="size-[18px]" /></span>
                @if($k['pct'] !== null)
                    <span class="rounded-full px-1.5 py-0.5 text-xs font-bold tabular-nums" style="background: {{ $k['color'] }}14; color: {{ $k['color'] }};">{{ rtrim(rtrim(number_format((float) $k['pct'], 1), '0'), '.') }}%</span>
                @endif
            </div>
            <div class="mt-3 text-2xl font-black leading-none tabular-nums text-zinc-900 dark:text-white">{{ $k['value'] }}</div>
            <div class="mt-1 line-clamp-2 break-words text-[11px] font-bold uppercase leading-snug tracking-wide text-zinc-400">{{ $k['label'] }}</div>
            <div class="line-clamp-2 text-xs leading-snug text-zinc-400">{{ $k['sub'] }}</div>
            @if($k['pct'] !== null)
                <div class="mt-2.5 h-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full" style="width: {{ min(100, (float) $k['pct']) }}%; background: {{ $k['color'] }};"></div></div>
            @endif
        </div>
    @endforeach
</div>

{{-- ═══════════════ PENDING REGULARISATIONS ═══════════════ --}}
@if($pendingRegularisations->isNotEmpty())
    <div class="rounded-[18px] border border-amber-300 bg-gradient-to-r from-amber-50 to-orange-50 p-4 shadow-sm">
        <div class="mb-3 flex items-center gap-3">
            <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-500 text-white shadow-lg shadow-amber-200"><flux:icon.exclamation-triangle class="size-5" /></span>
            <div>
                <div class="text-sm font-black text-amber-900">{{ $pendingRegularisationCount }} pending regularisation {{ \Illuminate\Support\Str::plural('request', $pendingRegularisationCount) }}</div>
                <div class="text-xs text-amber-700">Approving auto-updates the employee's hours, overtime, score and payroll attendance.</div>
            </div>
        </div>
        <div class="space-y-2">
            @foreach($pendingRegularisations->take(5) as $req)
                @include('livewire.attendance.partials.pending-regularisation-row')
            @endforeach
        </div>
        @if($pendingRegularisationCount > 5)
            <div class="mt-3 flex items-center justify-between text-xs">
                <span class="text-amber-800">Showing the oldest 5 of {{ $pendingRegularisationCount }}</span>
                <button type="button" @click="$flux.modal('pending-regularisations').show()" class="font-bold text-amber-800 underline-offset-2 hover:underline">View all {{ $pendingRegularisationCount }} requests</button>
            </div>
        @endif
    </div>

    @if($pendingRegularisationCount > 5)
        <flux:modal name="pending-regularisations" flyout class="w-full md:w-[42rem]" data-pending-regularisations-all>
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Pending regularisations</flux:heading>
                    <flux:subheading>{{ $pendingRegularisationCount }} waiting · oldest first</flux:subheading>
                </div>
                <div class="space-y-2">
                    @foreach($pendingRegularisations as $req)
                        @include('livewire.attendance.partials.pending-regularisation-row')
                    @endforeach
                </div>
                @if($pendingRegularisationCount > $pendingRegularisations->count())
                    <p class="text-xs text-zinc-500">Showing the oldest {{ $pendingRegularisations->count() }}.
                        @if(\Route::has('attendance.command-center') && app(\App\Services\Help\RouteAccess::class)->allows(auth()->user(), 'attendance.command-center'))
                            <a href="{{ route('attendance.command-center') }}" wire:navigate class="font-semibold text-orange-600 hover:underline">Open the full queue</a>
                        @endif
                    </p>
                @endif
            </div>
        </flux:modal>
    @endif
@endif

{{-- ═══════════════ DECIDED REGULARISATIONS (HR may correct / delete) ═══════════════ --}}
@if($decidedRegularisations->isNotEmpty())
    <details class="ui-surface group mb-4" data-decided-regularisations>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3">
            <span class="flex items-center gap-2 text-sm font-black text-zinc-900 dark:text-white"><flux:icon.clipboard-document-check class="size-4 text-orange-500" /> Decided regularisations <span class="text-xs font-semibold text-zinc-400">· last 45 days</span></span>
            <flux:icon.chevron-down class="size-4 text-zinc-400 transition group-open:rotate-180" />
        </summary>
        <div class="space-y-1.5 border-t border-zinc-100 px-4 py-3 dark:border-zinc-800">
            @foreach($decidedRegularisations as $done)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-zinc-50/70 px-3 py-2 text-xs dark:bg-zinc-800/40">
                    <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-0.5">
                        <span class="font-black text-zinc-900 dark:text-white">{{ $done->employee?->user?->name ?? '—' }}</span>
                        <span class="text-zinc-500 dark:text-zinc-400">{{ \Carbon\Carbon::parse($done->work_date)->toImmutable()->userDate() }}</span>
                        @if($done->requested_check_in)
                            <span class="tabular-nums font-bold text-zinc-600 dark:text-zinc-300">{{ $done->requestedWindowLabel() }}</span>
                        @endif
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-bold uppercase {{ $done->status === 'approved' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-600' }}">{{ $done->status }}</span>
                    </div>
                    @include('livewire.attendance.partials.regularisation-actions', ['reg' => $done])
                </div>
            @endforeach
        </div>
    </details>
@endif

{{-- ═══════════════ PRESENCE TREND ═══════════════ --}}
@php
    $trendChart = [
        'chart' => ['type' => 'bar', 'height' => 200, 'stacked' => false, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'speed' => 600]],
        'colors' => ['#F97316', '#FBBF24'],
        'plotOptions' => ['bar' => ['borderRadius' => 4, 'columnWidth' => '45%']],
        'dataLabels' => ['enabled' => false],
        'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'legend' => ['position' => 'top', 'horizontalAlign' => 'right', 'fontSize' => '11px', 'labels' => ['colors' => '#9CA3AF']],
        'xaxis' => ['categories' => collect($trend)->pluck('label')->all(), 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false]],
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]],
        'tooltip' => ['theme' => 'light'],
        'series' => [
            ['name' => 'Present', 'data' => collect($trend)->pluck('present')->all()],
            ['name' => 'Late', 'data' => collect($trend)->pluck('late')->all()],
        ],
    ];
@endphp
@if(count($trend) > 0 && collect($trend)->sum('present') > 0)
    <div class="ui-surface p-5">
        <div class="mb-1 flex items-center justify-between">
            <div class="text-sm font-black text-zinc-900 dark:text-white">Presence Overview</div>
            <span class="rounded-full bg-orange-50 px-2 py-0.5 text-xs font-bold text-orange-500">{{ $weekLabel }}</span>
        </div>
        <x-dashboard.chart :options="$trendChart" id="presence-trend" wire:key="trend-{{ $dateFrom }}-{{ $dateTo }}" class="-mb-2" />
    </div>
@endif

{{-- ═══════════════ ATTENDANCE LOG — date-wise ═══════════════
     One fact in one place: the day's status (with how late) in Status, the
     first in and last out in In · Out, the shape of the day on the bar,
     every punch on hover and in History. No overflow-hidden here: it
     clipped the filter dropdowns in the header. --}}
<section class="ui-surface" data-attendance-log>
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-orange-100/70 px-5 py-4 dark:border-white/10">
        <div>
            <h3 class="flex items-center gap-2 text-base font-bold text-zinc-900 dark:text-white">
                <span class="inline-flex size-8 items-center justify-center rounded-lg bg-orange-50 text-orange-500 dark:bg-orange-500/10"><flux:icon.clock class="size-4" /></span>
                Attendance Log
                <span class="text-sm font-medium text-zinc-400">· {{ $attendances->total() }} {{ \Illuminate\Support\Str::plural('record', $attendances->total()) }}</span>
            </h3>
            <p class="mt-1 text-[13px] text-zinc-500 dark:text-zinc-400">Date by date, in order of arrival. Select a row for that person's whole day.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative">
                <flux:icon.magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search employee…"
                    class="w-48 rounded-lg border border-orange-100 bg-white py-1.5 pl-8 pr-2.5 text-[13px] text-zinc-700 placeholder:text-zinc-400 focus:border-orange-400 focus:ring-0 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-200">
            </div>
            @if($filterDepartments->isNotEmpty())
                <x-clean-select model="filterDepartment" :live="true"
                    :options="array_merge([['value' => '', 'label' => 'All departments']], $filterDepartments->map(fn ($d) => ['value' => (string) $d->id, 'label' => $d->name])->all())" />
            @endif
            @if($filterTeams->isNotEmpty())
                <x-clean-select model="filterTeam" :live="true"
                    :options="array_merge([['value' => '', 'label' => 'All teams']], $filterTeams->map(fn ($t) => ['value' => (string) $t->id, 'label' => $t->name])->all())" />
            @endif
            <x-clean-select model="filterEmployee" :live="true"
                :options="array_merge([['value' => '', 'label' => 'All employees']], $filterEmployees->map(fn ($e) => ['value' => (string) $e->id, 'label' => $e->user?->name ?? $e->employee_id])->all())" />
            <x-clean-select model="status" :live="true"
                :options="[
                    ['value' => '', 'label' => 'All status'],
                    ['value' => 'on_time', 'label' => 'On Time'],
                    ['value' => 'late', 'label' => 'Late'],
                    ['value' => 'remote', 'label' => 'Remote'],
                    ['value' => 'absent', 'label' => 'Absent'],
                    ['value' => 'missed_punch', 'label' => 'Missed punch'],
                    ['value' => 'multiple_breaks', 'label' => 'Multiple breaks'],
                ]" />
            {{-- Date range — filter the punch logs for an arbitrary span --}}
            <div class="flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900" title="Filter logs by date range">
                <flux:icon.calendar-days class="size-4 text-zinc-400" />
                <input type="date" wire:model.live="dateFrom" max="{{ $dateTo && $dateTo < now()->toDateString() ? $dateTo : now()->toDateString() }}" aria-label="From date"
                    @disabled($date) title="{{ $date ? 'A single day is selected — clear it to use a range' : 'From date' }}"
                    class="w-[8rem] border-0 bg-transparent p-0.5 text-[13px] tabular-nums text-zinc-700 focus:ring-0 disabled:opacity-40 dark:text-zinc-200">
                <span class="text-zinc-300">–</span>
                <input type="date" wire:model.live="dateTo" min="{{ $dateFrom }}" max="{{ now()->toDateString() }}" aria-label="To date"
                    @disabled($date) title="{{ $date ? 'A single day is selected — clear it to use a range' : 'To date' }}"
                    class="w-[8rem] border-0 bg-transparent p-0.5 text-[13px] tabular-nums text-zinc-700 focus:ring-0 disabled:opacity-40 dark:text-zinc-200">
            </div>
            @if($hasActiveFilters)
                <button wire:click="clearFilters" type="button" data-clear-filters class="inline-flex items-center gap-1 rounded-lg bg-zinc-100 px-2.5 py-1.5 text-[13px] font-semibold text-zinc-600 transition hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"><flux:icon.x-mark class="size-4" /> Clear filters</button>
            @endif
        </div>
    </div>

    {{-- What the log shows (or why nothing), the audit filters, and how to read the bar. --}}
    @php
        $auditChip = fn (bool $active, string $tone) => $active
            ? ['amber' => 'border-amber-500 bg-amber-500 text-white', 'rose' => 'border-rose-500 bg-rose-500 text-white', 'violet' => 'border-violet-500 bg-violet-500 text-white'][$tone]
            : ['amber' => 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300', 'rose' => 'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300', 'violet' => 'border-violet-200 bg-violet-50 text-violet-700 hover:bg-violet-100 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-300'][$tone];
    @endphp
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-orange-100/70 px-5 py-3 dark:border-white/10">
        <div class="flex flex-wrap items-center gap-2" data-audit-chips>
            @if($rangeError)
                <span class="inline-flex items-center gap-1.5 text-[13px] font-semibold text-rose-600 dark:text-rose-400" role="alert" data-range-error><flux:icon.exclamation-triangle class="size-4" /> {{ $rangeError }}</span>
            @else
                <span class="mr-1 text-[13px] text-zinc-500 dark:text-zinc-400" data-showing><span class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $showingLabel }}</span>{{ $date ? ' (single day)' : '' }}</span>
            @endif
            @foreach([
                ['late', 'Late', $stats['late'], 'amber'],
                ['missed_punch', 'Missed punches', $auditCounts['missed'] ?? null, 'rose'],
                ['multiple_breaks', 'Multiple breaks', $auditCounts['multiple_breaks'] ?? null, 'violet'],
            ] as [$key, $chipLabel, $count, $tone])
                <button type="button" wire:click="$set('status', '{{ $status === $key ? '' : $key }}')" data-audit-chip="{{ $key }}"
                    class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition {{ $auditChip($status === $key, $tone) }}">
                    {{ $chipLabel }} <span class="tabular-nums">{{ $count ?? '—' }}</span>
                </button>
            @endforeach
            @unless($auditCounts)
                <span class="text-xs text-zinc-500 dark:text-zinc-400">Narrow the dates or employees to count missed punches and breaks.</span>
            @endunless
        </div>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400" data-log-legend>
            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-4 rounded-full bg-orange-200 ring-1 ring-inset ring-orange-300"></span> Shift</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-4 rounded-full bg-emerald-500"></span> Worked</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-4 rounded-full bg-amber-400"></span> Auto check-out</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-4 rounded-full border border-dashed border-rose-400 bg-rose-100"></span> No check-out</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2 rotate-45 rounded-[2px] bg-rose-500"></span> Missed punch</span>
        </div>
    </div>

    @if($punchLog)
        @include('livewire.attendance.partials.employee-punch-log', ['log' => $punchLog])
    @endif

    <div class="overflow-x-auto">
        @if($attendances->count() > 0)
            <table class="w-full min-w-[1120px] text-left" data-log-table>
                <thead class="border-b border-orange-100/70 bg-orange-50/40 dark:border-white/10 dark:bg-white/[0.02]">
                    <tr class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        <th class="w-[220px] px-5 py-2.5">Employee</th>
                        <th class="w-[130px] py-2.5">In · Out</th>
                        <th class="px-4 py-2.5">Day</th>
                        <th class="w-[180px] py-2.5 pr-5">Worked vs shift</th>
                        <th class="w-[96px] py-2.5">Breaks</th>
                        <th class="w-[150px] py-2.5">Status</th>
                        <th class="w-[130px] px-5 py-2.5 text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                @foreach(collect($attendances->items())->groupBy(fn ($a) => $a->date->toDateString()) as $groupDate => $groupRows)
                    @php
                        $group = $dayGroups[$groupDate] ?? ['window' => AttendanceRegister::window([]), 'records' => $groupRows->count(), 'present' => 0, 'late' => 0, 'missed' => null];
                        $groupTicks = $ticksFor($group['window'], 4);
                        $groupDay = \Carbon\CarbonImmutable::parse($groupDate);
                    @endphp
                    <tbody wire:key="log-day-{{ $groupDate }}" data-log-date="{{ $groupDate }}" class="divide-y divide-zinc-100 border-b border-orange-100/70 dark:divide-white/5 dark:border-white/10">
                        <tr class="bg-orange-50/50 dark:bg-white/[0.03]">
                            <td colspan="2" class="px-5 py-2.5">
                                <div class="flex items-center gap-2.5">
                                    <flux:icon.calendar-days class="size-4 text-orange-500" />
                                    <span class="text-[13px] font-semibold text-zinc-900 dark:text-white">{{ $groupDay->format('l') }}, {{ $groupDay->userDate() }}</span>
                                    @if($groupDay->isToday())<span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">Today</span>@endif
                                </div>
                            </td>
                            <td class="px-4 pb-1 pt-2.5 align-bottom">
                                <div class="relative h-4" aria-hidden="true">
                                    @foreach($groupTicks as $tick)
                                        @if($tick['pct'] > 3 && $tick['pct'] < 97)
                                            <span class="absolute -translate-x-1/2 whitespace-nowrap text-[11px] tabular-nums text-zinc-400" style="left: {{ $tick['pct'] }}%">{{ $hourAt($tick['at']) }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                            <td colspan="4" class="px-5 py-2.5 text-right">
                                <span class="text-xs text-zinc-500 dark:text-zinc-400" data-log-date-totals>
                                    <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $group['present'] }}</span> present
                                    @if($group['late'] > 0) · <span class="font-semibold text-amber-700 dark:text-amber-400">{{ $group['late'] }}</span> late @endif
                                    @if(($group['missed'] ?? 0) > 0) · <span class="font-semibold text-rose-600 dark:text-rose-400">{{ $group['missed'] }}</span> missed {{ \Illuminate\Support\Str::plural('punch', $group['missed']) }} @endif
                                </span>
                            </td>
                        </tr>

                        @foreach($groupRows as $log)
                            @php
                                $line = $lines[$log->employee_id.'|'.$groupDate] ?? null;
                                $name = $log->employee?->user?->name ?? '—';
                                $offLabel = $log->weeklyOffLabel();
                                $lateMinutes = $log->is_late ? (int) ($log->late_minutes ?? 0) : 0;
                                [$badge, $statusLabel] = $offLabel ? [$chipTone['zinc'], $offLabel] : match ($log->status) {
                                    'on_time' => [$chipTone['green'], 'Present'],
                                    'late' => [$chipTone['amber'], 'Late'],
                                    'absent' => [$chipTone['red'], 'Absent'],
                                    'remote' => [$chipTone['blue'], 'Remote'],
                                    default => [$chipTone['zinc'], ucfirst($log->status ?? '—')],
                                };
                                if (! $offLabel && $log->is_late && $log->status !== 'late') {
                                    [$badge, $statusLabel] = [$chipTone['amber'], 'Late'];
                                }
                                $rowMode = AttendanceMode::tryFromValue($log->work_mode ?? 'office');
                                // Face (IN) and ID card (OUT) are the norm — say the method only when it is something else.
                                $oddMethods = collect([PunchMethod::tryFrom((string) $log->check_in_method), PunchMethod::tryFrom((string) $log->check_out_method)])
                                    ->filter(fn ($m) => $m && ! in_array($m, [PunchMethod::Face, PunchMethod::IdCard], true))->unique();
                                $rowAudit = $audit[$log->id] ?? null;
                                $rowState = $rowStatus[$log->id] ?? [];
                                $pct = $line && $line['expected_minutes'] > 0 ? (int) floor($line['worked_minutes'] * 100 / $line['expected_minutes']) : 0;
                                $missedCount = max($line['missed_count'] ?? 0, ($rowAudit['missed'] ?? false) ? 1 : 0);
                            @endphp
                            <tr wire:key="log-{{ $log->id }}" wire:click="openDayHistory({{ $log->employee_id }}, '{{ $groupDate }}')" data-log-row="{{ $log->id }}"
                                class="cursor-pointer align-middle text-[13px] text-zinc-700 transition dark:text-zinc-200 {{ $log->is_regularized ? 'bg-blue-50/40 hover:bg-blue-50 dark:bg-blue-950/20' : 'hover:bg-orange-50/50 dark:hover:bg-white/[0.03]' }}">
                                {{-- Employee --}}
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="relative shrink-0">
                                            <div class="flex size-9 items-center justify-center rounded-full bg-orange-100 text-xs font-semibold text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">{{ $line['initials'] ?? \App\Services\Attendance\LiveAttendanceService::initials($name) }}</div>
                                            @if($line['live'] ?? false)<span class="absolute -bottom-0.5 -right-0.5 size-3 rounded-full border-2 border-white bg-emerald-500 dark:border-zinc-900" title="In now"></span>@endif
                                        </div>
                                        <div class="min-w-0">
                                            <div class="truncate font-semibold text-zinc-900 dark:text-white">{{ $name }}</div>
                                            <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $log->employee?->employee_id ?? '—' }}@if($line && $line['department'] !== '—') · {{ $line['department'] }}@endif</div>
                                        </div>
                                    </div>
                                </td>

                                {{-- In · Out --}}
                                <td class="py-3 pr-3">
                                    <div class="whitespace-nowrap font-semibold tabular-nums {{ $log->is_regularized ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-900 dark:text-white' }}">
                                        {{ $log->check_in?->toImmutable()->userTime() ?? '—' }}
                                        <span class="font-normal text-zinc-300 dark:text-zinc-600">→</span>
                                        @if($log->check_out)
                                            <span class="{{ $log->is_auto_checkout ? 'text-orange-600 dark:text-orange-400' : '' }}">{{ $log->check_out->toImmutable()->userTime() }}</span>
                                        @elseif($log->check_in && ($rowState['live'] ?? false))
                                            <span class="inline-flex items-center gap-1 font-medium text-emerald-600 dark:text-emerald-400" data-attendance-state="{{ $rowState['state'] }}"><span class="size-1.5 animate-pulse rounded-full bg-emerald-500"></span>{{ ($rowState['on_break'] ?? false) ? 'On break' : 'In now' }}</span>
                                        @elseif($log->check_in && ($rowState['last_out'] ?? null))
                                            <span data-attendance-state="completed">{{ $rowState['last_out']->toImmutable()->userTime() }}</span>
                                        @elseif($log->check_in)
                                            <span class="font-medium text-rose-600 dark:text-rose-400" data-attendance-state="missing_checkout">no out</span>
                                        @else
                                            <span class="text-zinc-300">—</span>
                                        @endif
                                    </div>
                                    @php
                                        $inNote = collect([
                                            $log->is_auto_checkout ? 'Closed by the auto check-out' : null,
                                            $log->original_check_in && $log->check_in && ! $log->original_check_in->equalTo($log->check_in) ? 'In was '.$log->original_check_in->toImmutable()->userTime() : null,
                                            $log->original_check_out && $log->check_out && ! $log->original_check_out->equalTo($log->check_out) ? 'Out was '.$log->original_check_out->toImmutable()->userTime() : null,
                                            $oddMethods->isNotEmpty() ? $oddMethods->map->label()->implode(' / ') : null,
                                        ])->filter();
                                    @endphp
                                    @if($inNote->isNotEmpty())
                                        <div class="mt-0.5 text-xs {{ $log->is_auto_checkout ? 'text-orange-600 dark:text-orange-400' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $inNote->implode(' · ') }}</div>
                                    @endif
                                </td>

                                {{-- The day: timeline bar, and every punch on hover --}}
                                <td class="px-4 py-3" data-punches>
                                    @if($line)
                                        @include('livewire.attendance.partials.day-timeline-bar', ['line' => $line, 'window' => $group['window'], 'ticks' => $groupTicks, 'size' => 'sm'])
                                    @endif
                                    <div class="mt-1.5" wire:click.stop>
                                        <x-attendance.punch-timeline :punches="$punchesByDay[$log->employee_id.'|'.$groupDate] ?? []" :title="'All punches — '.$log->date->toImmutable()->userDate()" />
                                    </div>
                                </td>

                                {{-- Worked vs shift --}}
                                <td class="py-3 pr-5" data-worked-cell>
                                    @if($line && $line['missing_checkout'])
                                        <div class="font-semibold text-rose-600 dark:text-rose-400">Not counted</div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">No check-out punch</div>
                                    @elseif($line && $line['present'])
                                        <div class="flex items-baseline justify-between gap-2 tabular-nums">
                                            <span><span class="font-semibold text-zinc-900 dark:text-white">{{ $short($line['worked_minutes']) }}</span> <span class="text-xs text-zinc-400">/ {{ $shiftLength($line['expected_minutes']) }}{{ $line['live'] ? ' so far' : '' }}</span></span>
                                            <span class="text-xs font-semibold {{ $progressText[$line['progress']] }}">{{ $pct }}%</span>
                                        </div>
                                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10" title="{{ $pct }}% of a {{ $shiftLength($line['expected_minutes']) }} shift{{ $line['ot_minutes'] > 0 ? ' · '.$short($line['ot_minutes']).' approved overtime' : '' }}">
                                            <div class="h-full rounded-full {{ $progressBar[$line['progress']] }}" style="width: {{ min(100, $pct) }}%"></div>
                                        </div>
                                    @else
                                        @php $hrs = app(\App\Services\Attendance\AttendanceCalculator::class)->workedHours($log); @endphp
                                        <span class="font-semibold tabular-nums {{ $hrs > 0 ? 'text-zinc-900 dark:text-white' : 'text-zinc-300' }}">{{ $hrs > 0 ? number_format($hrs, 1).'h' : '—' }}</span>
                                    @endif
                                </td>

                                {{-- Breaks --}}
                                <td class="py-3" data-break-cell>
                                    @php $breaks = (int) ($rowAudit['breaks'] ?? 0); @endphp
                                    @if($breaks > 0 || ($line['break_minutes'] ?? 0) > 0)
                                        <div class="font-semibold tabular-nums {{ ($line['excess_break'] ?? false) ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-900 dark:text-white' }}">{{ $short($line['break_minutes'] ?? (int) ($log->break_minutes ?? 0)) }}</div>
                                        <div class="text-xs {{ $breaks >= 2 ? 'text-violet-600 dark:text-violet-300' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $breaks }} {{ \Illuminate\Support\Str::plural('break', $breaks) }}</div>
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                    @endif
                                </td>

                                {{-- Status: one chip, then only the exceptions --}}
                                <td class="py-3">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $badge }}">{{ $statusLabel }}@if($lateMinutes > 0 && ! $offLabel)<span class="ml-1 font-medium opacity-80">· {{ $short($lateMinutes) }}</span>@endif</span>
                                    @php $flags = ($rowAudit['missed'] ?? false) || $log->is_regularized || ($rowMode && $rowMode->value !== 'office'); @endphp
                                    @if($flags)
                                        <div class="mt-1 flex flex-wrap gap-x-2 gap-y-0.5 text-xs font-medium">
                                            @if($rowAudit['missed'] ?? false)<span class="text-rose-600 dark:text-rose-400" data-flag="missed_punch">{{ $missedCount > 1 ? $missedCount.' missed punches' : 'Missed punch' }}</span>@endif
                                            @if($log->is_regularized)<span class="text-blue-600 dark:text-blue-300">Regularised</span>@endif
                                            @if($rowMode && $rowMode->value !== 'office')<span class="text-sky-600 dark:text-sky-300">{{ $rowMode->shortLabel() }}</span>@endif
                                        </div>
                                    @endif
                                </td>

                                {{-- Actions --}}
                                <td class="px-5 py-3 text-right" wire:click.stop>
                                    <div class="inline-flex items-center gap-1">
                                        <button type="button" wire:click="openDayHistory({{ $log->employee_id }}, '{{ $groupDate }}')" data-day-history-open="{{ $log->id }}"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-zinc-700 transition hover:border-orange-300 hover:bg-orange-50 hover:text-orange-700 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-orange-500/10">
                                            <flux:icon.clock class="size-4" /> History
                                        </button>
                                        <flux:dropdown position="bottom" align="end">
                                            <button type="button" class="inline-flex size-8 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10 dark:hover:text-zinc-200" aria-label="More actions for {{ $name }}"><flux:icon.ellipsis-vertical class="size-4" /></button>
                                            <flux:menu>
                                                <flux:menu.item icon="identification" wire:click="openEmployeeDrawer({{ $log->employee_id }})">Employee 360°</flux:menu.item>
                                                @if((string) $filterEmployee !== (string) $log->employee_id)
                                                    <flux:menu.item icon="list-bullet" wire:click="$set('filterEmployee', '{{ $log->employee_id }}')" data-open-punch-log="{{ $log->employee_id }}">Punch log for the range</flux:menu.item>
                                                @endif
                                                @if($canSeeLive)
                                                    <flux:menu.item icon="signal" href="{{ route('attendance.live', ['tab' => 'employee', 'employee' => $log->employee_id, 'from' => $log->date->copy()->subDays(29)->toDateString(), 'to' => $groupDate]) }}">Last 30 days</flux:menu.item>
                                                @endif
                                                @if($canCorrect)
                                                    <flux:menu.separator />
                                                    <flux:menu.item icon="pencil-square" wire:click="openMarkModal({{ $log->employee_id }}, '{{ $groupDate }}')">Correct this day</flux:menu.item>
                                                @endif
                                            </flux:menu>
                                        </flux:dropdown>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        @else
            <div class="py-14 text-center text-sm text-zinc-500">
                <flux:icon.calendar-days class="mx-auto mb-2 size-9 opacity-30" />
                {{ $rangeError ? 'Fix the dates to see attendance records.' : 'No attendance records for these filters.' }}
                <div class="mt-1 text-[13px] text-zinc-400">Try another week or date range, pick another employee, or clear the filters.</div>
            </div>
        @endif
    </div>
    @if($attendances->hasPages())
        <div class="border-t border-orange-100/70 px-5 py-3 dark:border-white/10">
            {{ $attendances->links() }}
        </div>
    @endif
</section>

</div>{{-- /space-y --}}

{{-- ══════════════════════════════════════════
EMPLOYEE 360 DRAWER (480px, right)
══════════════════════════════════════════ --}}
@if($drawerEmployeeId && ! empty($drawer))
    <div class="fixed inset-0 z-40" x-data="{ show: false }" x-init="requestAnimationFrame(() => show = true)"
         x-on:keydown.escape.window="$wire.closeDrawer()">
        <div class="absolute inset-0 bg-black/30 backdrop-blur-sm transition-opacity duration-300"
             :class="show ? 'opacity-100' : 'opacity-0'" @click="$wire.closeDrawer()"></div>

        <aside class="absolute right-0 top-0 flex h-full w-[480px] max-w-full flex-col bg-[#FFF8F3] dark:bg-white/5 shadow-2xl transition-transform duration-300 ease-out"
               :class="show ? 'translate-x-0' : 'translate-x-full'">

            {{-- Header --}}
            <div class="flex items-start justify-between gap-3 border-b border-orange-100 bg-white dark:bg-zinc-900 px-5 py-4">
                <div class="flex items-center gap-3">
                    @if($drawer['photo'])
                        <img src="{{ \Storage::url($drawer['photo']) }}" alt="{{ $drawer['name'] }}" class="size-12 rounded-2xl object-cover ring-2 ring-orange-100">
                    @else
                        <div class="flex size-12 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-amber-400 text-sm font-black text-white">{{ collect(explode(' ', $drawer['name']))->map(fn($n) => $n[0] ?? '')->take(2)->join('') }}</div>
                    @endif
                    <div class="min-w-0">
                        <div class="truncate text-sm font-black text-zinc-900 dark:text-white">{{ $drawer['name'] }}</div>
                        <div class="text-xs text-zinc-400">{{ $drawer['code'] }} · {{ $drawer['designation'] }}</div>
                        <div class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-zinc-500 dark:text-zinc-400">
                            <span class="inline-flex items-center gap-1"><flux:icon.building-office-2 class="size-3 text-orange-400" /> {{ $drawer['department'] }}</span>
                            <span class="inline-flex items-center gap-1"><flux:icon.user class="size-3 text-orange-400" /> {{ $drawer['manager'] }}</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    @php
                        [$dBadge, $dDot] = match($drawer['status']) {
                            'Working' => ['bg-emerald-100 text-emerald-700', 'bg-emerald-500 animate-pulse'],
                            'On Break' => ['bg-orange-100 text-orange-700', 'bg-orange-500 animate-pulse'],
                            'Completed' => ['bg-rose-100 text-rose-600', 'bg-rose-500'],
                            'Missing Checkout' => ['bg-amber-100 text-amber-700', 'bg-amber-500'],
                            default => ['bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400', 'bg-zinc-400'],
                        };
                    @endphp
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-bold {{ $dBadge }}"><span class="size-1.5 rounded-full {{ $dDot }}"></span>{{ $drawer['status'] }}</span>
                    <button type="button" @click="$wire.closeDrawer()" class="rounded-lg p-1 text-zinc-400 transition hover:bg-orange-50 hover:text-zinc-600 dark:text-zinc-300"><flux:icon.x-mark class="size-5" /></button>
                </div>
            </div>

            {{-- Body --}}
            <div class="flex-1 space-y-3 overflow-y-auto p-4">

                {{-- KPI row --}}
                <div class="grid grid-cols-4 gap-2">
                    @foreach([
                        ['Attendance', $drawer['attendance_pct'] === null ? '—' : $drawer['attendance_pct'].'%', '#10b981'],
                        ['Score', $drawer['score'].'/100', '#F97316'],
                        ['Late (mo.)', $drawer['late_count'], $drawer['late_count'] ? '#f59e0b' : '#10b981'],
                        ['Leave Bal.', rtrim(rtrim(number_format($drawer['leave_balance'], 1), '0'), '.'), '#14b8a6'],
                    ] as [$k, $v, $c])
                        <div class="rounded-xl border border-orange-100/70 bg-white dark:bg-zinc-900 p-2.5 text-center shadow-sm">
                            <div class="text-sm font-black tabular-nums" style="color: {{ $c }}">{{ $v }}</div>
                            <div class="text-[11px] font-bold uppercase tracking-wider text-zinc-400">{{ $k }}</div>
                        </div>
                    @endforeach
                </div>

                {{-- Today --}}
                <div class="ui-surface p-4">
                    <div class="mb-2 text-xs font-bold uppercase tracking-widest text-zinc-400">Today</div>
                    @if($drawer['today'])
                        <div class="grid grid-cols-3 gap-2 text-center">
                            @foreach([
                                ['In', $drawer['today']['in'] ?? '—'], ['Out', $drawer['today']['out'] ?? ($drawer['today']['out_note'] ?? '—')],
                                ['Worked', $drawer['today']['worked']], ['Break', $drawer['today']['break'].'m'],
                                ['Overtime', $drawer['today']['overtime']], ['Mode', strtoupper($drawer['today']['mode'] ?? '—')],
                            ] as [$k, $v])
                                <div class="rounded-lg bg-orange-50/50 px-2 py-1.5"><div class="text-xs font-black tabular-nums text-zinc-900 dark:text-white">{{ $v }}</div><div class="text-[11px] font-bold uppercase text-zinc-400">{{ $k }}</div></div>
                            @endforeach
                        </div>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                            @if($drawer['today']['device'])<span class="inline-flex items-center gap-1"><flux:icon.cpu-chip class="size-3 text-orange-400" /> {{ $drawer['today']['device'] }}</span>@endif
                            @if($drawer['today']['location'])<span class="inline-flex items-center gap-1"><flux:icon.map-pin class="size-3 text-orange-400" /> {{ $drawer['today']['location'] }}</span>@endif
                            @if($drawer['today']['is_late'])<span class="font-bold text-amber-600">Late today</span>@endif
                        </div>
                    @else
                        <p class="text-xs text-zinc-400">No punch recorded today.</p>
                    @endif
                </div>

                {{-- Processed timeline (single source of truth) + raw device audit log --}}
                @if(! empty($drawer['punches']))
                    <div class="ui-surface p-4" x-data="{ showRaw: false }">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs font-bold uppercase tracking-widest text-zinc-400">Processed Timeline · {{ $drawer['punch_count'] ?? count($drawer['punches']) }} punches</span>
                            @if(! empty($drawer['needs_regularization']))<span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold uppercase text-amber-700">Needs Regularization</span>@endif
                        </div>
                        @if(! empty($drawer['punches_partial']))
                            <div class="mb-2 flex items-center gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                                <flux:icon.information-circle class="size-3.5" /> Partial local stream — full stream in the v2 dashboard.
                            </div>
                        @endif
                        <div class="max-h-36 space-y-1 overflow-y-auto">
                            @foreach($drawer['punches'] as $p)
                                @php $isMiss = ($p['type'] ?? '') === 'missing'; @endphp
                                <div class="flex items-center gap-2 rounded-lg {{ $isMiss ? 'bg-amber-50 dark:bg-amber-500/10' : 'bg-zinc-50/70 dark:bg-zinc-800/40' }} px-2.5 py-1 text-xs">
                                    <flux:icon :icon="$p['icon']" class="size-3.5 {{ $isMiss ? 'text-amber-500' : 'text-orange-400' }}" />
                                    <span class="font-black tabular-nums text-zinc-800 dark:text-zinc-100">{{ $p['time'] }}</span>
                                    <span class="rounded-full px-1.5 py-0.5 text-[11px] font-bold {{ $isMiss ? 'bg-amber-100 text-amber-700' : (($p['dir'] ?? '') === 'IN' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700') }}">{{ $isMiss ? 'MISSING '.$p['dir'] : $p['dir'] }}</span>
                                    <span class="text-xs text-zinc-400">{{ $p['method'] ?? '' }}@if(! empty($p['guidance'])) · {{ $p['guidance'] }}@endif</span>
                                </div>
                            @endforeach
                        </div>
                        {{-- Validated sessions --}}
                        @if(! empty($drawer['sessions']))
                            <div class="mt-2 border-t border-zinc-100 dark:border-zinc-800 pt-2 space-y-0.5">
                                @foreach($drawer['sessions'] as $s)
                                    <div class="flex items-center justify-between text-xs {{ ! empty($s['missing']) ? 'text-amber-600 font-semibold' : 'text-zinc-500 dark:text-zinc-400' }}">
                                        <span>Session {{ $s['index'] }} · {{ $s['in'] ?? '⚠' }} → {{ $s['out'] ?? (! empty($s['live']) ? 'now' : '⚠') }}</span>
                                        <span class="font-bold">{{ $s['label'] }}{{ ! empty($s['live']) ? ' · live' : '' }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        {{-- Raw device log — audit only --}}
                        @if(! empty($drawer['raw_punches']))
                            <button type="button" @click="showRaw = !showRaw" class="mt-2 flex w-full items-center justify-between border-t border-zinc-100 dark:border-zinc-800 pt-2 text-xs font-bold uppercase tracking-widest text-zinc-400 hover:text-orange-500">
                                <span>Raw device log · {{ count($drawer['raw_punches']) }} events
                                    @if(($drawer['duplicate_count'] ?? 0) + ($drawer['conflict_count'] ?? 0) > 0)
                                        <span class="ml-1 normal-case tracking-normal font-semibold text-zinc-400">({{ $drawer['duplicate_count'] }} dup · {{ $drawer['conflict_count'] }} retry merged)</span>
                                    @endif
                                </span>
                                <flux:icon.chevron-down class="size-3.5" ::class="showRaw ? 'rotate-180' : ''" />
                            </button>
                            <div x-show="showRaw" x-collapse class="mt-1 max-h-40 space-y-0.5 overflow-y-auto">
                                @foreach($drawer['raw_punches'] as $rp)
                                    <div class="flex items-center gap-2 rounded px-2 py-1 text-xs {{ $rp['flag'] === 'kept' ? 'bg-zinc-50/60 dark:bg-zinc-800/30' : 'bg-zinc-50/30 dark:bg-zinc-800/10 opacity-60' }}" @if($rp['note']) title="{{ $rp['note'] }}" @endif>
                                        <span class="tabular-nums font-bold text-zinc-700 dark:text-zinc-200">{{ $rp['time'] }}</span>
                                        @if($rp['direction'])<span class="uppercase text-zinc-400">{{ $rp['direction'] }}</span>@endif
                                        <span class="text-zinc-400">{{ $rp['method'] }}@if(! empty($rp['guidance'])) · {{ $rp['guidance'] }}@endif</span>
                                        <span class="ml-auto rounded-full px-1.5 py-0.5 text-[11px] font-bold uppercase {{ $rp['flag'] === 'kept' ? 'bg-emerald-100 text-emerald-700' : ($rp['flag'] === 'retry' ? 'bg-violet-100 text-violet-700' : 'bg-zinc-200 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-300') }}">{{ $rp['flag'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Pending regularizations --}}
                @if(! empty($drawer['pending']))
                    <div class="rounded-2xl border border-amber-300 bg-gradient-to-r from-amber-50 to-orange-50 p-4 shadow-sm">
                        <div class="mb-2 text-xs font-bold uppercase tracking-widest text-amber-700">Pending Regularisation · {{ count($drawer['pending']) }}</div>
                        <div class="space-y-2">
                            @foreach($drawer['pending'] as $pr)
                                <div class="rounded-xl border border-amber-200/70 bg-white/80 dark:bg-zinc-900/80 p-2.5">
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="font-black text-zinc-900 dark:text-white">{{ $pr['date'] }}</span>
                                        <span class="flex items-center gap-1.5">
                                            @if(! empty($pr['stage']))<span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-amber-700">{{ $pr['stage'] }}</span>@endif
                                            <span class="tabular-nums font-bold text-amber-700">{{ $pr['window'] }}</span>
                                        </span>
                                    </div>
                                    <p class="mt-0.5 truncate text-xs italic text-zinc-400">“{{ $pr['reason'] }}”</p>
                                    @if(! empty($pr['handled_by']))
                                        <div class="mt-1.5 inline-flex items-center gap-1.5 rounded-lg bg-sky-50 px-2.5 py-1 text-xs font-bold text-sky-700 dark:bg-sky-500/10 dark:text-sky-400">
                                            <flux:icon.lock-closed class="size-3" /> Being handled by {{ $pr['handled_by'] }}
                                        </div>
                                    @else
                                        <div class="mt-1.5 flex gap-1.5">
                                            @if(! auth()->user()->canApproveRegularisations())
                                            <span class="inline-flex shrink-0 items-center rounded-lg bg-zinc-100 px-2.5 py-1 text-xs font-bold uppercase text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">Awaiting HR</span>
                                            @else
                                            <button wire:click="quickApproveRegularisation({{ $pr['id'] }})" class="inline-flex items-center gap-1 rounded-lg bg-emerald-500 px-2.5 py-1 text-xs font-bold text-white transition hover:bg-emerald-600"><flux:icon.check class="size-3" /> Approve</button>
                                            <button wire:click="openReviewModal({{ $pr['id'] }})" class="inline-flex items-center gap-1 rounded-lg bg-rose-500 px-2.5 py-1 text-xs font-bold text-white transition hover:bg-rose-600"><flux:icon.x-mark class="size-3" /> Reject</button>
                                            <button wire:click="openRegularisationEdit({{ $pr['id'] }})" class="inline-flex items-center gap-1 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-2.5 py-1 text-xs font-bold text-zinc-600 dark:text-zinc-300 transition hover:bg-zinc-50 dark:bg-zinc-800/50"><flux:icon.pencil-square class="size-3" /> Edit</button>
                                            <button wire:click="openRegularisationDelete({{ $pr['id'] }})" class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-xs font-bold text-rose-600 transition hover:bg-rose-50 dark:border-rose-500/30 dark:bg-zinc-900"><flux:icon.trash class="size-3" /> Delete</button>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Late + break history --}}
                <div class="grid grid-cols-2 gap-3">
                    <div class="ui-surface p-3">
                        <div class="mb-1.5 text-[11px] font-bold uppercase tracking-widest text-zinc-400">Late History</div>
                        @forelse($drawer['late_history'] as $lh)
                            <div class="flex items-center justify-between py-0.5 text-[11px]"><span class="text-zinc-600 dark:text-zinc-300">{{ $lh['date'] }}</span><span class="font-bold text-amber-600">+{{ $lh['mins'] }}m</span></div>
                        @empty<p class="text-xs text-emerald-600">No late arrivals 🎉</p>@endforelse
                    </div>
                    <div class="ui-surface p-3">
                        <div class="mb-1.5 text-[11px] font-bold uppercase tracking-widest text-zinc-400">Break History</div>
                        @forelse($drawer['break_history'] as $bh)
                            <div class="flex items-center justify-between py-0.5 text-[11px]"><span class="text-zinc-600 dark:text-zinc-300">{{ $bh['date'] }}</span><span class="font-bold {{ $bh['mins'] > 60 ? 'text-amber-600' : 'text-zinc-800 dark:text-zinc-100' }}">{{ $bh['mins'] }}m</span></div>
                        @empty<p class="text-xs text-zinc-400">No breaks recorded.</p>@endforelse
                    </div>
                </div>

                {{-- Attendance history — Daily / Weekly toggle --}}
                <div class="ui-surface p-4" x-data="{ tab: 'daily' }">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="text-xs font-bold uppercase tracking-widest text-zinc-400">Attendance History</div>
                        <div class="flex items-center gap-0.5 rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800">
                            <button type="button" @click="tab = 'daily'" :class="tab === 'daily' ? 'bg-white text-zinc-800 shadow-sm dark:bg-zinc-700 dark:text-white' : 'text-zinc-400'" class="rounded-md px-2 py-0.5 text-xs font-bold transition">Daily</button>
                            <button type="button" @click="tab = 'weekly'" :class="tab === 'weekly' ? 'bg-white text-zinc-800 shadow-sm dark:bg-zinc-700 dark:text-white' : 'text-zinc-400'" class="rounded-md px-2 py-0.5 text-xs font-bold transition">Weekly</button>
                        </div>
                    </div>

                    {{-- Daily (last 7 days) --}}
                    <div x-show="tab === 'daily'" class="space-y-1">
                        @forelse($drawer['history'] as $h)
                            @php $hc = match($h['status']) { 'on_time' => 'text-emerald-600', 'late' => 'text-amber-600', default => 'text-zinc-400' }; @endphp
                            <div class="flex items-center justify-between rounded-lg px-2 py-1 text-[11px] odd:bg-orange-50/40">
                                <span class="w-12 font-bold text-zinc-700 dark:text-zinc-200">{{ $h['date'] }}</span>
                                <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ $h['in'] ?? '—' }} → {{ $h['out'] ?? '—' }}</span>
                                <span class="font-black tabular-nums text-zinc-800 dark:text-zinc-100">{{ $h['hours'] ? number_format((float) $h['hours'], 1).'h' : '—' }}</span>
                                <span class="text-[11px] font-bold uppercase {{ $hc }}">{{ str_replace('_', ' ', $h['status']) }}</span>
                            </div>
                        @empty<p class="text-xs text-zinc-400">No records this month.</p>@endforelse
                    </div>

                    {{-- Weekly rollup (this month) --}}
                    <div x-show="tab === 'weekly'" x-cloak class="space-y-1">
                        @forelse($drawer['weekly_history'] as $w)
                            <div class="flex items-center justify-between rounded-lg px-2 py-1 text-[11px] odd:bg-orange-50/40">
                                <span class="font-bold text-zinc-700 dark:text-zinc-200">{{ $w['label'] }}</span>
                                <span class="tabular-nums text-zinc-500 dark:text-zinc-400">{{ $w['present'] }}d present</span>
                                <span class="font-black tabular-nums text-zinc-800 dark:text-zinc-100">{{ number_format((float) $w['hours'], 1) }}h</span>
                                <span class="text-[11px] font-bold uppercase {{ $w['late'] > 0 ? 'text-amber-600' : 'text-emerald-600' }}">{{ $w['late'] }} late</span>
                            </div>
                        @empty<p class="text-xs text-zinc-400">No records this month.</p>@endforelse
                    </div>
                </div>
            </div>
        </aside>
    </div>
@endif

{{-- Day history: one person's date, from a log row --}}
@if($dayHistory)
    @include('livewire.attendance.partials.day-history')
@endif

{{-- ══════════════════════════════════════════
REVIEW REGULARISATION MODAL
══════════════════════════════════════════ --}}
@if($showReviewModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
         x-data x-on:keydown.escape.window="$wire.closeReviewModal()">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.closeReviewModal()"></div>
        <div class="relative max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white dark:bg-zinc-900 p-6 shadow-xl ring ring-black/5">
            <button type="button" @click="$wire.closeReviewModal()" class="absolute right-4 top-4 text-zinc-400 transition-colors hover:text-zinc-600 dark:text-zinc-300">
                <flux:icon.x-mark class="size-5" />
            </button>

            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">Review Regularisation</flux:heading>
                    <flux:subheading>Approving auto-updates hours, overtime, score &amp; payroll.</flux:subheading>
                </div>
                @if($activeRequest)
                    @if($regularisationLocked)
                        <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                            <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-500" />
                            <div>
                                <p class="text-sm font-semibold text-amber-800">Already approved by Super Admin ({{ $lockedByName }})</p>
                                <p class="mt-0.5 text-xs text-amber-700">A mandatory comment is required to override this approval.</p>
                            </div>
                        </div>
                    @endif
                    <div class="space-y-3 rounded-xl border border-orange-100 bg-orange-50/40 p-4 text-sm">
                        <div class="flex items-center justify-between"><span class="text-zinc-500 dark:text-zinc-400">Employee</span><span class="font-bold text-zinc-900 dark:text-white">{{ $activeRequest->employee?->user?->name ?? '—' }}</span></div>
                        <div class="flex items-center justify-between"><span class="text-zinc-500 dark:text-zinc-400">Date</span><span class="font-bold text-zinc-900 dark:text-white">{{ \Carbon\Carbon::parse($activeRequest->work_date ?? $activeRequest->date)->toImmutable()->userDate() }}</span></div>
                        <div class="flex items-center justify-between"><span class="text-zinc-500 dark:text-zinc-400">Requested Time</span>
                            <span class="tabular-nums font-bold text-orange-600">{{ $activeRequest->requestedWindowLabel() }}</span>
                        </div>
                        @if($activeRequest->attendance)
                            <div class="flex items-center justify-between"><span class="text-zinc-500 dark:text-zinc-400">Recorded Time</span>
                                <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ $activeRequest->attendance->check_in?->toImmutable()->userTime() ?? '—' }} → {{ $activeRequest->attendance->check_out?->toImmutable()->userTime() ?? '—' }}</span>
                            </div>
                        @endif
                        <div class="border-t border-orange-100 pt-2">
                            <p class="mb-1 text-xs uppercase tracking-wider text-zinc-400">Reason</p>
                            <p class="text-xs italic text-zinc-700 dark:text-zinc-200">"{{ $activeRequest->reason }}"</p>
                        </div>
                        @if($activeRequest->attachment_path)
                            <div class="flex items-center justify-between border-t border-orange-100 pt-2">
                                <span class="text-xs uppercase tracking-wider text-zinc-400">Attachment</span>
                                <a href="{{ \Illuminate\Support\Facades\Storage::url($activeRequest->attachment_path) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs font-bold text-orange-600 hover:underline"><flux:icon.paper-clip class="size-3.5" /> View document</a>
                            </div>
                        @endif
                        {{-- Approval chain progress --}}
                        <div class="border-t border-orange-100 pt-2">
                            <p class="mb-1.5 text-xs uppercase tracking-wider text-zinc-400">Approval Flow · currently at <b class="text-orange-600">{{ $activeRequest->stageLabel() }}</b></p>
                            @php $stageNum = \App\Models\AttendanceRegularisation::STAGES[$activeRequest->stage ?? 'manager_review'] ?? 1; @endphp
                            <div class="flex items-center gap-1.5">
                                @foreach(['Manager', 'HR', 'Admin'] as $i => $st)
                                    <span class="flex-1 rounded-full px-2 py-1 text-center text-xs font-bold {{ $activeRequest->status === 'approved' || $stageNum > $i + 1 ? 'bg-emerald-100 text-emerald-700' : ($stageNum === $i + 1 && $activeRequest->status === 'pending' ? 'bg-orange-100 text-orange-700 ring-1 ring-orange-300' : 'bg-zinc-100 text-zinc-400 dark:bg-zinc-800') }}">{{ $st }}</span>
                                    @if($i < 2)<flux:icon.chevron-right class="size-3 shrink-0 text-zinc-300" />@endif
                                @endforeach
                            </div>
                            @if(! empty($activeRequest->approval_trail))
                                <div class="mt-2 space-y-1">
                                    @foreach($activeRequest->approval_trail as $step)
                                        <div class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                            @php [$stepIcon, $stepTone] = match ($step['action'] ?? '') { 'approved' => ['check-circle', 'text-emerald-500'], 'edited', 'corrected' => ['pencil-square', 'text-sky-500'], default => ['x-circle', 'text-rose-500'] }; @endphp
                                            <flux:icon :icon="$stepIcon" class="size-3 {{ $stepTone }}" />
                                            <b>{{ $step['name'] ?? 'Reviewer' }}</b> {{ $step['action'] ?? '' }} at {{ str_replace('_', ' ', $step['stage'] ?? '') }} · {{ \Carbon\Carbon::parse($step['at'])->toImmutable()->userDateTime() }}
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                    <flux:textarea wire:model="reviewComment"
                        label="{{ $regularisationLocked ? 'Comment (Required for Override)' : 'HR Comment (Required for Rejection)' }}"
                        rows="2" placeholder="Add a comment..." />
                    @error('reviewComment')
                        <p class="text-xs text-red-500">{{ $message }}</p>
                    @enderror
                    <div class="flex justify-end gap-2 border-t border-zinc-100 dark:border-zinc-800 pt-3">
                        <button type="button" @click="$wire.closeReviewModal()" class="rounded-xl border border-zinc-200 dark:border-zinc-800 px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 transition-colors hover:bg-zinc-50 dark:bg-zinc-800/50">Cancel</button>
                        <flux:button wire:click="rejectRegularisation" variant="ghost" class="!text-red-600 hover:!bg-red-50">Reject</flux:button>
                        <flux:button wire:click="approveRegularisation" variant="primary">Approve</flux:button>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif

@include('livewire.attendance.partials.regularisation-manage-modal')

{{-- ══════════════════════════════════════════
HR MARK ATTENDANCE MODAL
══════════════════════════════════════════ --}}
@if($showMarkModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
         x-data x-on:keydown.escape.window="$wire.set('showMarkModal', false)">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showMarkModal', false)"></div>
        <div class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white dark:bg-zinc-900 p-6 shadow-xl ring ring-black/5">
            <button type="button" @click="$wire.set('showMarkModal', false)" class="absolute right-4 top-4 text-zinc-400 transition-colors hover:text-zinc-600 dark:text-zinc-300">
                <flux:icon.x-mark class="size-5" />
            </button>

            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="shrink-0 rounded-xl bg-orange-50 p-2.5"><flux:icon.pencil-square class="size-5 text-orange-500" /></div>
                    <div>
                        <flux:heading size="lg">Mark Attendance for Employee</flux:heading>
                        <flux:subheading>Applies straight away and is recorded against your name.</flux:subheading>
                    </div>
                </div>
                <div class="flex gap-3 rounded-xl border border-amber-100 bg-amber-50 p-3">
                    <flux:icon.information-circle class="mt-0.5 size-4 shrink-0 text-amber-600" />
                    <p class="text-xs leading-relaxed text-amber-700">The corrected times replace the day's check-in and check-out at once — hours, overtime, score and payroll follow — and the employee is told. The original punches are kept.</p>
                </div>
                <div class="space-y-4">
                    <x-clean-select model="markEmployeeId" label="Employee" placeholder="Select employee..." :live="false" :required="true"
                        :options="collect($allEmployees)->map(fn ($emp) => ['value' => $emp->id, 'label' => $emp->user->name.' ('.($emp->employee_id ?? 'No ID').')'])->all()" />
                    <flux:input wire:model="markDate" type="date" label="Date of Attendance" max="{{ now()->format('Y-m-d') }}" required />
                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="markCheckIn" type="time" label="Check-in Time" required />
                        <flux:input wire:model="markCheckOut" type="time" label="Check-out Time" required />
                    </div>
                    <div>
                        <flux:label>Work Mode</flux:label>
                        <div class="mt-1.5 grid grid-cols-3 gap-2">
                            @foreach(['office' => 'Office', 'wfh' => 'WFH', 'remote' => 'Remote'] as $val => $label)
                                <button type="button" wire:click="$set('markWorkMode', '{{ $val }}')"
                                    class="rounded-xl border py-2 text-sm font-bold transition-all {{ $markWorkMode === $val ? 'border-orange-400 bg-orange-50 text-orange-700' : 'border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400 hover:border-zinc-300' }}">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                    <flux:textarea wire:model="markReason" label="Reason for HR Entry" placeholder="e.g. Employee's biometric failed, system issue..." rows="2" required />
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="$wire.set('showMarkModal', false)" class="rounded-xl border border-zinc-200 dark:border-zinc-800 px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 transition-colors hover:bg-zinc-50 dark:bg-zinc-800/50">Cancel</button>
                    <flux:button wire:click="submitMarkAttendance" variant="primary" icon="check">Apply correction</flux:button>
                </div>
            </div>
        </div>
    </div>
@endif

</div>{{-- /max-w --}}
</flux:main>
