@use('App\Enums\AttendanceMode')
@use('App\Enums\PunchMethod')
@use('App\Support\UserAgent')
@use('Illuminate\Support\Facades\Storage')

<flux:main class="bg-zinc-50 dark:bg-zinc-950 min-h-screen" x-data="{
    currentTime: '',
    updateClock() {
        const now = new Date();
        this.currentTime = now.toLocaleTimeString('en-IN', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
}" x-init="updateClock(); setInterval(() => updateClock(), 1000)">

@php
    $emp = auth()->user()->employee;

    // ── Period-scoped counts (from computeStats) ──
    $presentCount = (int) ($stats['present'] ?? 0);
    $lateCount    = (int) ($stats['late'] ?? 0);
    $absentCount  = (int) ($stats['absent'] ?? 0);
    $leaveCount   = (int) ($stats['leaves'] ?? 0);
    $onTimeCount  = max(0, $presentCount - $lateCount);

    $pStart = match($statsPeriod) {
        'this_week'  => now()->startOfWeek(\Carbon\Carbon::SUNDAY),
        'last_month' => now()->subMonth()->startOfMonth(),
        '3_months'   => now()->subMonths(2)->startOfMonth(),
        'year'       => now()->startOfYear(),
        default      => now()->startOfMonth(),
    };
    $pEnd = ($statsPeriod === 'last_month') ? now()->subMonth()->endOfMonth() : now();
    if ($pEnd->gt(now())) { $pEnd = now(); }
    $totalWorkingDays = max(1, (int) $pStart->diffInDaysFiltered(fn($d) => ! $d->isSunday(), $pEnd));

    $attPct = round(min(100, ($presentCount / $totalWorkingDays) * 100), 1);
    $score  = (int) ($analytics['attendance_score'] ?? 0);
    $compliance = (int) ($analytics['shift_compliance'] ?? 0);

    // ── Overtime (net hours beyond the standard day) ──
    $stdHours = (float) ($shift->standard_hours ?? 9);
    $otHours  = round(collect($chartDaily)->sum(fn($d) => max(0, (float) $d['hours'] - $stdHours)), 1);
    $otDays   = collect($chartDaily)->filter(fn($d) => (float) $d['hours'] > $stdHours)->count();

    // ── Today's live state ──
    $heroMode = AttendanceMode::tryFromValue($todayAttendance->work_mode ?? $workMode);
    $isIn   = $todayAttendance && ! $todayAttendance->check_out;
    $isDone = $todayAttendance && $todayAttendance->check_out;
    $workedMin = 0;
    if ($todayAttendance && $todayAttendance->check_in) {
        $endT = $todayAttendance->check_out ?? now();
        $workedMin = max(0, (int) $todayAttendance->check_in->diffInMinutes($endT) - (int) ($todayAttendance->break_minutes ?? 0));
    }
    $targetMin = (int) round($stdHours * 60);
    $progress  = $targetMin > 0 ? min(100, (int) round($workedMin / $targetMin * 100)) : 0;
    $workedLabel = intdiv($workedMin, 60).'h '.str_pad((string) ($workedMin % 60), 2, '0', STR_PAD_LEFT).'m';
    $targetLabel = intdiv($targetMin, 60).'h '.($targetMin % 60).'m';
    $liveStart = $isIn ? $todayAttendance->check_in->timestamp : null;

    // ── Missing-punch detection (past days without a check-out) ──
    $missingPunches = collect($history)
        ->filter(fn($i) => (! $i->check_out && ! $i->date->isToday()) || $i->missing_checkout)
        ->sortByDesc('date')->values();
@endphp

{{-- ═══════════════════════════════════════════════
     COMMAND BAR — live status · timer · clock in/out · shift
═══════════════════════════════════════════════ --}}
<div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-zinc-900 via-zinc-900 to-black px-5 py-4 text-white shadow-lg"
     x-data="{ start: {{ $liveStart ?? 'null' }}, live: '{{ $workedLabel }}',
        tick(){ if(this.start===null) return; const s=Math.floor(Date.now()/1000)-this.start; const h=Math.floor(s/3600),m=Math.floor((s%3600)/60); this.live=h+'h '+String(m).padStart(2,'0')+'m'; } }"
     x-init="tick(); if(start!==null) setInterval(()=>tick(),1000)">
    <div class="pointer-events-none absolute -right-16 -top-20 size-60 rounded-full blur-3xl" style="background:radial-gradient(circle, {{ $heroMode->hex() }}33, transparent 70%)"></div>

    <div class="relative flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        {{-- Identity + status --}}
        <div class="flex items-center gap-3.5">
            <div class="relative shrink-0">
                @if($emp?->photo)
                    <img src="{{ Storage::url($emp->photo) }}" alt="{{ auth()->user()->name }}" class="size-14 rounded-xl object-cover ring-2 ring-white/10">
                @else
                    <div class="flex size-14 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-amber-400 text-lg font-black">{{ auth()->user()->initials() }}</div>
                @endif
                <span class="absolute -bottom-1 -right-1 size-4 rounded-full border-2 border-zinc-900 {{ $isIn ? 'animate-pulse bg-emerald-500' : ($isDone ? 'bg-zinc-500' : 'bg-amber-500') }}"></span>
            </div>
            <div class="min-w-0">
                <div class="mb-0.5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[10px] font-bold tracking-wider" style="background: {{ $heroMode->hex() }}26; color: {{ $heroMode->hex() }}">
                        <span class="size-1.5 rounded-full {{ $isIn ? 'animate-pulse' : '' }}" style="background: {{ $heroMode->hex() }}"></span>
                        {{ $isIn ? strtoupper($heroMode->shortLabel()).' · ACTIVE' : ($isDone ? 'DAY COMPLETE' : 'NOT CLOCKED IN') }}
                    </span>
                </div>
                <h1 class="truncate text-lg font-black tracking-tight">{{ auth()->user()->name }}</h1>
                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-zinc-400">
                    <span class="inline-flex items-center gap-1"><flux:icon.clock class="size-3" /> {{ $shift->name ?? 'Shift' }}
                        @if($shift) · {{ \Carbon\Carbon::parse($shift->start_time)->format('g:i A') }}–{{ \Carbon\Carbon::parse($shift->end_time)->format('g:i A') }}@endif
                    </span>
                    @if($emp?->manager)<span class="inline-flex items-center gap-1"><flux:icon.user class="size-3" /> {{ $emp->manager->name }}</span>@endif
                    <span class="inline-flex items-center gap-1 tabular-nums"><flux:icon.calendar-days class="size-3" /> {{ now()->format('D, d M Y') }}</span>
                </div>
            </div>
        </div>

        {{-- Live timer + progress + actions --}}
        <div class="flex items-center gap-4">
            <div class="hidden text-right sm:block">
                <div class="text-[9px] font-bold uppercase tracking-widest text-zinc-500">Current Time</div>
                <div class="text-lg font-black tabular-nums" x-text="currentTime"></div>
            </div>
            <div class="relative grid size-16 shrink-0 place-items-center">
                <svg class="size-16 -rotate-90" viewBox="0 0 36 36">
                    <circle cx="18" cy="18" r="15.9" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="3.5" />
                    <circle cx="18" cy="18" r="15.9" fill="none" stroke="{{ $heroMode->hex() }}" stroke-width="3.5" stroke-linecap="round" stroke-dasharray="{{ $progress }}, 100" />
                </svg>
                <div class="absolute text-center">
                    <div class="text-sm font-black leading-none tabular-nums" x-text="live">{{ $workedLabel }}</div>
                    <div class="text-[8px] text-zinc-500">/ {{ $targetLabel }}</div>
                </div>
            </div>
            <div class="flex flex-col gap-2">
                @if(! $todayAttendance)
                    <button type="button" @click="$flux.modal('punch-capture').show(); $dispatch('open-punch', { action: 'in' })"
                        class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2 text-sm font-bold shadow-lg shadow-brand-500/20 transition hover:bg-brand-700 active:scale-95">
                        <flux:icon.finger-print class="size-4" /> Clock In
                    </button>
                @elseif($isIn)
                    <div class="flex gap-2">
                        @if($activeBreak)
                            <button wire:click="endBreak" class="inline-flex items-center gap-1.5 rounded-xl bg-amber-500 px-3 py-2 text-sm font-bold transition hover:bg-amber-600 active:scale-95"><flux:icon.play class="size-4" /> Resume</button>
                        @else
                            <button wire:click="startBreak" class="inline-flex items-center gap-1.5 rounded-xl bg-white/10 px-3 py-2 text-sm font-bold transition hover:bg-white/20 active:scale-95"><flux:icon.pause class="size-4" /> Break</button>
                        @endif
                        <button type="button" @click="$flux.modal('punch-capture').show(); $dispatch('open-punch', { action: 'out' })"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3 py-2 text-sm font-bold transition hover:bg-brand-700 active:scale-95">
                            <flux:icon.arrow-right-start-on-rectangle class="size-4" /> End Day
                        </button>
                    </div>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-500/20 px-4 py-2 text-sm font-bold text-emerald-400"><flux:icon.check-badge class="size-4" /> Completed</span>
                @endif
                @if($activeBreak)
                    <span class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-amber-500/15 px-2 py-1 text-[10px] font-bold text-amber-300"><span class="size-1.5 animate-pulse rounded-full bg-amber-400"></span> On break since {{ \Carbon\Carbon::parse($activeBreak['break_start'])->format('h:i A') }}</span>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="mt-4 space-y-4">

{{-- ═══════════════════════════════════════════════
     MISSING PUNCH WARNING (orange, prominent)
═══════════════════════════════════════════════ --}}
@if($missingPunches->isNotEmpty())
    <div class="flex flex-col gap-3 rounded-2xl border border-amber-300 bg-gradient-to-r from-amber-50 to-orange-50 p-4 shadow-sm dark:border-amber-800/60 dark:from-amber-950/30 dark:to-orange-950/20 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="mt-0.5 inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-500 text-white shadow-lg shadow-amber-500/30">
                <flux:icon.exclamation-triangle class="size-5" />
            </span>
            <div>
                <div class="text-sm font-black text-amber-900 dark:text-amber-200">{{ $missingPunches->count() }} missing punch{{ $missingPunches->count() > 1 ? 'es' : '' }} detected</div>
                <div class="mt-0.5 text-xs text-amber-700 dark:text-amber-300/80">
                    Incomplete on
                    {{ $missingPunches->take(4)->map(fn($m) => $m->date->format('d M'))->implode(', ') }}{{ $missingPunches->count() > 4 ? ' +'.($missingPunches->count() - 4).' more' : '' }}.
                    Regularise to correct working hours &amp; attendance.
                </div>
            </div>
        </div>
        <button wire:click="openRegularisation('{{ $missingPunches->first()->date->toDateString() }}')"
            class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-bold text-white shadow-md shadow-amber-500/30 transition hover:bg-amber-600 active:scale-95">
            <flux:icon.pencil-square class="size-4" /> Request Regularization
        </button>
    </div>
@endif

{{-- ═══════════════════════════════════════════════
     6 KPI CARDS
═══════════════════════════════════════════════ --}}
@php
    $kpis = [
        ['label' => 'Attendance',    'value' => $attPct.'%',                 'sub' => 'present rate',                    'icon' => 'chart-pie',            'color' => '#10b981'],
        ['label' => 'Present Days',  'value' => $presentCount,               'sub' => 'of '.$totalWorkingDays.' working', 'icon' => 'check-badge',          'color' => '#3b82f6'],
        ['label' => 'Working Hours', 'value' => $stats['hours'] ?? '0h',     'sub' => 'this period',                     'icon' => 'clock',                'color' => '#6366f1'],
        ['label' => 'Late Arrivals', 'value' => $lateCount,                  'sub' => 'this period',                     'icon' => 'exclamation-triangle', 'color' => '#f59e0b'],
        ['label' => 'Overtime',      'value' => $otHours.'h',                'sub' => $otDays.' day(s)',                 'icon' => 'bolt',                 'color' => '#8b5cf6'],
        ['label' => 'Score',         'value' => $score.'/100',               'sub' => $compliance.'% on-time',           'icon' => 'shield-check',         'color' => $score >= 80 ? '#14b8a6' : ($score >= 60 ? '#f59e0b' : '#ef4444')],
    ];
@endphp
<div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
    @foreach($kpis as $k)
        <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
            <span class="inline-flex size-9 items-center justify-center rounded-xl" style="background: {{ $k['color'] }}1a; color: {{ $k['color'] }};">
                <flux:icon :icon="$k['icon']" class="size-4" />
            </span>
            <div class="mt-2.5 text-2xl font-black tabular-nums text-zinc-900 dark:text-white">{{ $k['value'] }}</div>
            <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">{{ $k['label'] }}</div>
            <div class="text-[11px] text-zinc-400">{{ $k['sub'] }}</div>
        </div>
    @endforeach
</div>

{{-- Period filter --}}
<div class="flex items-center gap-2 overflow-x-auto pb-1">
    <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Period</span>
    @foreach(['this_week' => 'Week', 'this_month' => 'Month', 'last_month' => 'Last Month', '3_months' => '3 Months', 'year' => 'Year'] as $val => $label)
        <button wire:click="$set('statsPeriod', '{{ $val }}')"
            class="shrink-0 rounded-lg px-3 py-1 text-xs font-bold transition {{ $statsPeriod === $val ? 'bg-brand-600 text-white shadow-sm' : 'bg-white text-zinc-500 hover:bg-zinc-100 dark:bg-zinc-900 dark:hover:bg-zinc-800' }}">{{ $label }}</button>
    @endforeach
</div>

{{-- ═══════════════════════════════════════════════
     MAIN GRID — LEFT analytics · RIGHT live widgets
═══════════════════════════════════════════════ --}}
@php
    $modeBreakdown = $analytics['mode_breakdown'] ?? [];
    $lateTrend     = $analytics['late_trend'] ?? [];
    $axis = ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false]];

    $hoursChart = [
        'chart' => ['type' => 'area', 'height' => 240, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'speed' => 700]],
        'colors' => ['#6366F1'], 'dataLabels' => ['enabled' => false], 'stroke' => ['curve' => 'smooth', 'width' => 3],
        'fill' => ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.35, 'opacityTo' => 0.02, 'stops' => [0, 90]]],
        'grid' => ['borderColor' => '#E5E7EB', 'strokeDashArray' => 4, 'padding' => ['left' => 8, 'right' => 8]],
        'xaxis' => array_merge($axis, ['categories' => collect($chartDaily)->pluck('label')->all(), 'tickAmount' => min(10, max(1, count($chartDaily)))]),
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]],
        'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Hours', 'data' => collect($chartDaily)->pluck('hours')->all()]],
    ];

    $attendanceDonut = [
        'chart' => ['type' => 'donut', 'height' => 240, 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'speed' => 700]],
        'labels' => ['On-time', 'Late', 'Absent', 'On Leave'],
        'colors' => ['#10b981', '#f59e0b', '#ef4444', '#8b5cf6'],
        'series' => [$onTimeCount, $lateCount, $absentCount, $leaveCount],
        'legend' => ['position' => 'bottom', 'fontSize' => '11px', 'labels' => ['colors' => '#9CA3AF']],
        'dataLabels' => ['enabled' => false], 'stroke' => ['width' => 0],
        'plotOptions' => ['pie' => ['donut' => ['size' => '70%', 'labels' => ['show' => true, 'total' => ['show' => true, 'label' => 'Days', 'fontSize' => '11px', 'color' => '#9CA3AF']]]]],
        'tooltip' => ['theme' => 'light'],
    ];

    $lateChart = [
        'chart' => ['type' => 'bar', 'height' => 200, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'speed' => 700]],
        'colors' => ['#F59E0B'], 'plotOptions' => ['bar' => ['borderRadius' => 6, 'columnWidth' => '45%']],
        'dataLabels' => ['enabled' => false], 'grid' => ['borderColor' => '#E5E7EB', 'strokeDashArray' => 4],
        'xaxis' => array_merge($axis, ['categories' => collect($lateTrend)->pluck('month')->all()]),
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '11px']]],
        'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Late days', 'data' => collect($lateTrend)->pluck('late')->all()]],
    ];

    // Heatmap intensity buckets (emerald scale) by net hours worked.
    $heatColor = function ($h) use ($stdHours) {
        if ($h <= 0)          return 'bg-zinc-100 dark:bg-zinc-800';
        if ($h < 4)           return 'bg-emerald-200 dark:bg-emerald-900/50';
        if ($h < 7)           return 'bg-emerald-300 dark:bg-emerald-700/70';
        if ($h < $stdHours)   return 'bg-emerald-400 dark:bg-emerald-600';
        return 'bg-emerald-600 dark:bg-emerald-500';
    };
@endphp
<div class="grid grid-cols-1 gap-4 lg:grid-cols-12">

    {{-- LEFT — analytics (8/12) --}}
    <div class="space-y-4 lg:col-span-8">

        {{-- Working Hours Trend + Attendance Breakdown --}}
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-5">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 xl:col-span-3">
                <div class="mb-1 flex items-center justify-between">
                    <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Working Hours Trend</div>
                    <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-bold text-indigo-600 dark:bg-indigo-900/20">{{ ucwords(str_replace('_', ' ', $statsPeriod)) }}</span>
                </div>
                @if(count($chartDaily) > 0)
                    <x-dashboard.chart :options="$hoursChart" id="hours-chart" wire:key="hours-{{ $statsPeriod }}" class="-mb-2" />
                @else
                    <div class="flex h-[240px] items-center justify-center text-xs text-zinc-300">No hours logged.</div>
                @endif
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 xl:col-span-2">
                <div class="mb-1 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Attendance Trend</div>
                @if($presentCount + $absentCount + $leaveCount > 0)
                    <x-dashboard.chart :options="$attendanceDonut" id="att-donut" wire:key="donut-{{ $statsPeriod }}" class="grid place-items-center" />
                @else
                    <div class="flex h-[240px] items-center justify-center text-xs text-zinc-300">No attendance yet.</div>
                @endif
            </div>
        </div>

        {{-- Heatmap --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-3 flex items-center justify-between">
                <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Attendance Heatmap · hours per day</div>
                <div class="flex items-center gap-1 text-[10px] text-zinc-400">
                    Less
                    <span class="size-2.5 rounded-sm bg-zinc-100 dark:bg-zinc-800"></span>
                    <span class="size-2.5 rounded-sm bg-emerald-200 dark:bg-emerald-900/50"></span>
                    <span class="size-2.5 rounded-sm bg-emerald-400 dark:bg-emerald-600"></span>
                    <span class="size-2.5 rounded-sm bg-emerald-600 dark:bg-emerald-500"></span>
                    More
                </div>
            </div>
            @if(count($chartDaily) > 0)
                <div class="flex flex-wrap gap-1.5">
                    @foreach($chartDaily as $d)
                        <div class="size-6 rounded-md {{ $heatColor((float) $d['hours']) }} transition-transform hover:scale-125"
                            title="{{ $d['label'] }} · {{ $d['hours'] }}h{{ $d['late'] ? ' · late' : '' }}"></div>
                    @endforeach
                </div>
            @else
                <div class="py-6 text-center text-xs text-zinc-300">No data for this period.</div>
            @endif
        </div>

        {{-- Late Arrival Trend + Office/WFH/Hybrid --}}
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="mb-1 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Late Arrival Trend · 6 months</div>
                <x-dashboard.chart :options="$lateChart" id="late-chart" wire:key="late-trend" class="-mb-2" />
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="mb-3 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Office · WFH · Hybrid Summary</div>
                @php $totMode = max(1, array_sum($modeBreakdown)); @endphp
                @if(empty($modeBreakdown))
                    <div class="py-6 text-center text-xs text-zinc-300">No attendance yet.</div>
                @else
                    <div class="space-y-3">
                        @foreach($modeBreakdown as $mv => $count)
                            @php $m = AttendanceMode::tryFromValue($mv); $pct = round($count / $totMode * 100); @endphp
                            <div>
                                <div class="mb-1 flex items-center justify-between text-xs">
                                    <span class="flex items-center gap-1.5 font-semibold text-zinc-600 dark:text-zinc-300"><span class="size-2.5 rounded-full {{ $m->dotClass() }}"></span>{{ $m->label() }}</span>
                                    <span class="font-black text-zinc-900 dark:text-white">{{ $count }} <span class="text-[10px] font-medium text-zinc-400">· {{ $pct }}%</span></span>
                                </div>
                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                    <div class="h-full rounded-full {{ $m->dotClass() }} transition-all duration-700" style="width: {{ $pct }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- RIGHT — live widgets (4/12) --}}
    <div class="space-y-4 lg:col-span-4">

        {{-- Today's Timeline (vertical, colour-coded) --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-4 flex items-center justify-between">
                <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Today's Timeline</div>
                <span class="text-[10px] font-semibold text-zinc-400">{{ now()->format('D, d M') }}</span>
            </div>
            @if(count($todayTimeline) > 0)
                <div class="relative space-y-4 pl-1">
                    @foreach($todayTimeline as $ev)
                        @php
                            [$dot, $ring] = match($ev['type']) {
                                'in'     => ['bg-emerald-500', 'ring-emerald-500/20'],
                                'late'   => ['bg-rose-500', 'ring-rose-500/20'],
                                'break'  => ['bg-amber-500', 'ring-amber-500/20'],
                                'resume' => ['bg-blue-500', 'ring-blue-500/20'],
                                'out'    => ['bg-zinc-800 dark:bg-zinc-200', 'ring-zinc-500/20'],
                                default  => ['bg-zinc-400', 'ring-zinc-500/20'],
                            };
                        @endphp
                        <div class="relative flex items-start gap-3">
                            @unless($loop->last && ! $activeBreak)
                                <span class="absolute left-[5px] top-4 h-full w-px bg-zinc-200 dark:bg-zinc-700"></span>
                            @endunless
                            <span class="mt-1 size-2.5 shrink-0 rounded-full ring-4 {{ $dot }} {{ $ring }}"></span>
                            <div class="flex-1 -mt-0.5">
                                <div class="text-xs font-bold text-zinc-800 dark:text-zinc-100">{{ $ev['title'] }}</div>
                                <div class="text-[11px] tabular-nums text-zinc-400">{{ $ev['time'] }}</div>
                                @if(! empty($ev['photo']) || (! empty($ev['lat']) && ! empty($ev['lng'])) || ! empty($ev['method']))
                                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                        @if(! empty($ev['method']))
                                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold {{ $ev['method']->chipClass() }}">
                                                <flux:icon :icon="$ev['method']->icon()" class="size-3" /> {{ $ev['method']->label() }}
                                            </span>
                                        @endif
                                        @if(! empty($ev['photo']))
                                            <a href="{{ Storage::url($ev['photo']) }}" target="_blank" title="View punch photo">
                                                <img src="{{ Storage::url($ev['photo']) }}" alt="Punch selfie" class="size-8 rounded-lg object-cover ring-1 ring-zinc-200 transition hover:ring-brand-400 dark:ring-zinc-700">
                                            </a>
                                        @endif
                                        @if(! empty($ev['lat']) && ! empty($ev['lng']))
                                            <a href="https://www.google.com/maps?q={{ $ev['lat'] }},{{ $ev['lng'] }}" target="_blank" rel="noopener"
                                                class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] font-semibold text-zinc-600 transition hover:text-brand-600 dark:bg-zinc-800 dark:text-zinc-300">
                                                <flux:icon.map-pin class="size-3" /> GPS
                                            </a>
                                        @endif
                                        @if(! empty($ev['device']) && $ev['device']['browser'] !== 'Unknown')
                                            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300" title="{{ $ev['device']['label'] }}">
                                                <flux:icon :icon="$ev['device']['icon']" class="size-3" /> {{ $ev['device']['browser'] }}
                                            </span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                    @if($activeBreak)
                        <div class="relative flex items-start gap-3">
                            <span class="mt-1 size-2.5 shrink-0 animate-pulse rounded-full bg-amber-500 ring-4 ring-amber-500/20"></span>
                            <div class="flex-1 -mt-0.5 text-xs font-bold text-amber-600 dark:text-amber-400">On break…</div>
                        </div>
                    @endif
                </div>
            @else
                <div class="flex h-32 flex-col items-center justify-center text-center text-zinc-300 dark:text-zinc-600">
                    <flux:icon.clock class="mb-2 size-8" />
                    <p class="text-xs">Not clocked in yet today.</p>
                    <button type="button" @click="$flux.modal('regularisation-modal').show()" class="mt-2 text-[11px] font-bold text-brand-600 hover:underline">Request regularization →</button>
                </div>
            @endif
        </div>

        {{-- Monthly Analytics --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-3 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Monthly Analytics</div>
            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/40">
                    <div class="text-2xl font-black {{ $score >= 80 ? 'text-emerald-600' : ($score >= 60 ? 'text-amber-600' : 'text-rose-600') }}">{{ $score }}<span class="text-xs text-zinc-400">/100</span></div>
                    <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">Attendance Score</div>
                </div>
                <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/40">
                    <div class="text-2xl font-black text-zinc-900 dark:text-white">{{ $compliance }}%</div>
                    <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">Shift Compliance</div>
                </div>
                <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/40">
                    <div class="text-2xl font-black text-zinc-900 dark:text-white">{{ $analytics['avg_break'] ?? 0 }}<span class="text-xs text-zinc-400">m</span></div>
                    <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">Avg Break</div>
                </div>
                <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/40">
                    <div class="text-2xl font-black text-violet-600 dark:text-violet-400">{{ $otHours }}<span class="text-xs text-zinc-400">h</span></div>
                    <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">Overtime</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════
     CALENDAR (larger) + side panel
═══════════════════════════════════════════════ --}}
<div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 lg:col-span-8">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ $calendarMonth->format('F Y') }}</h3>
            <div class="flex gap-1">
                <button wire:click="previousMonth" class="rounded-lg border border-zinc-200 p-1.5 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"><flux:icon.chevron-left class="size-4 text-zinc-500" /></button>
                <button wire:click="nextMonth" class="rounded-lg border border-zinc-200 p-1.5 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"><flux:icon.chevron-right class="size-4 text-zinc-500" /></button>
            </div>
        </div>
        <div class="mb-2 grid grid-cols-7 gap-1.5">
            @foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d)
                <div class="pb-1 text-center text-[10px] font-bold uppercase tracking-widest text-zinc-400">{{ substr($d,0,3) }}</div>
            @endforeach
        </div>
        <div class="grid grid-cols-7 gap-1.5">
            @foreach($calendarDays as $day)
                @php
                    $dayDate = \Carbon\Carbon::parse($day['date']);
                    if ($day['is_today']) {
                        $cellClass = 'bg-brand-600 border-brand-600 text-white shadow-md shadow-brand-500/20 z-10';
                        $numClass = 'text-white'; $dotColor = '';
                    } elseif (! $day['in_month']) {
                        $cellClass = 'opacity-0 pointer-events-none border-transparent'; $numClass = 'text-zinc-300'; $dotColor = '';
                    } else {
                        [$cellClass, $dotColor, $numClass] = match($day['status']) {
                            'present' => ['bg-emerald-50 dark:bg-emerald-950/20 border-emerald-200 dark:border-emerald-900/40', 'bg-emerald-500', 'text-zinc-700 dark:text-zinc-200'],
                            'late'    => ['bg-amber-50 dark:bg-amber-950/20 border-amber-200 dark:border-amber-900/40', 'bg-amber-500', 'text-zinc-700 dark:text-zinc-200'],
                            'leave'   => ['bg-violet-50 dark:bg-violet-950/20 border-violet-200 dark:border-violet-900/40', 'bg-violet-500', 'text-zinc-700 dark:text-zinc-200'],
                            'holiday' => ['bg-blue-50 dark:bg-blue-950/20 border-blue-200 dark:border-blue-900/40', 'bg-blue-500', 'text-zinc-700 dark:text-zinc-200'],
                            'absent'  => $dayDate->isPast()
                                ? ['bg-rose-50 dark:bg-rose-950/20 border-rose-100 dark:border-rose-900/30', 'bg-rose-400', 'text-zinc-600 dark:text-zinc-300']
                                : ['bg-white dark:bg-zinc-900 border-zinc-100 dark:border-zinc-800', '', 'text-zinc-400'],
                            default   => ['bg-white dark:bg-zinc-900 border-zinc-50 dark:border-zinc-800', '', 'text-zinc-300'],
                        };
                        if ($day['status'] === 'present' && ! empty($day['mode'])) {
                            $dotColor = AttendanceMode::tryFromValue($day['mode'])->dotClass();
                        }
                    }
                @endphp
                <div class="flex aspect-square flex-col items-center justify-center rounded-xl border transition-all {{ $cellClass }}">
                    <span class="text-sm font-bold leading-none {{ $numClass }}">{{ $day['day'] }}</span>
                    @if($dotColor && ! $day['is_today'])
                        <div class="mt-1 size-1.5 rounded-full {{ $dotColor }}"></div>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="mt-4 flex flex-wrap gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
            @foreach([['bg-emerald-500','Present'],['bg-amber-500','Late'],['bg-rose-400','Absent'],['bg-violet-500','Leave'],['bg-blue-500','Holiday'],['bg-brand-600','Today']] as [$c,$l])
                <div class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-400"><div class="size-2 rounded-full {{ $c }}"></div> {{ $l }}</div>
            @endforeach
        </div>
    </div>

    {{-- Shift details + holidays --}}
    <div class="space-y-4 lg:col-span-4">
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-3 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Shift Details</div>
            @if($shift)
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/40">
                        <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Start</div>
                        <div class="mt-0.5 text-sm font-black text-zinc-800 dark:text-zinc-100">{{ \Carbon\Carbon::parse($shift->start_time)->format('g:i A') }}</div>
                    </div>
                    <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/40">
                        <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Grace</div>
                        <div class="mt-0.5 text-sm font-black text-zinc-800 dark:text-zinc-100">{{ $shift->grace_minutes ?? 5 }}m</div>
                    </div>
                    <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/40">
                        <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">End</div>
                        <div class="mt-0.5 text-sm font-black text-zinc-800 dark:text-zinc-100">{{ \Carbon\Carbon::parse($shift->end_time)->format('g:i A') }}</div>
                    </div>
                </div>
                <div class="mt-2 text-center text-[11px] text-zinc-400">{{ $shift->name ?? 'Shift' }} · {{ $stdHours }}h standard day</div>
            @else
                <p class="text-xs text-zinc-400">No shift configured. Contact HR.</p>
            @endif
        </div>

        @if(count($monthHolidays) > 0)
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="mb-3 flex items-center gap-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400"><flux:icon.sparkles class="size-3.5 text-blue-500" /> Holidays · {{ $calendarMonth->format('M Y') }}</h3>
                <div class="space-y-2">
                    @foreach($monthHolidays as $h)
                        <div class="flex items-center gap-3">
                            <div class="w-9 shrink-0 rounded-lg bg-blue-50 p-1 text-center dark:bg-blue-950/40">
                                <div class="text-sm font-black leading-none text-blue-700 dark:text-blue-400">{{ \Carbon\Carbon::parse($h->date)->format('j') }}</div>
                                <div class="text-[8px] font-bold uppercase text-blue-400">{{ \Carbon\Carbon::parse($h->date)->format('M') }}</div>
                            </div>
                            <div>
                                <div class="text-xs font-bold text-zinc-800 dark:text-zinc-200">{{ $h->name }}</div>
                                <div class="text-[9px] text-zinc-400">{{ \Carbon\Carbon::parse($h->date)->format('l') }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>

{{-- ═══════════════════════════════════════════════
     ATTENDANCE LOG — full width
═══════════════════════════════════════════════ --}}
<div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-5 py-3.5 dark:border-zinc-800">
        <h3 class="flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white"><flux:icon.clock class="size-4 text-brand-500" /> Attendance Log</h3>
        <div class="flex items-center gap-2">
            <select wire:model.live="logMode" class="rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-xs font-semibold text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                <option value="">All modes</option>
                @foreach(AttendanceMode::cases() as $mode)
                    <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                @endforeach
            </select>
            <span class="text-[10px] uppercase tracking-widest text-zinc-400">{{ $calendarMonth->format('M Y') }}</span>
        </div>
    </div>
    <div class="overflow-x-auto">
        @php $rows = $logMode !== '' ? collect($history)->where('work_mode', $logMode)->values() : collect($history); @endphp
        @if($rows->count() > 0)
            <table class="w-full text-left">
                <thead class="border-b border-zinc-100 bg-zinc-50/70 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <tr class="text-[9px] font-bold uppercase tracking-widest text-zinc-400">
                        <th class="px-5 py-2.5 font-bold">Date</th>
                        <th class="py-2.5 font-bold">Check In</th>
                        <th class="py-2.5 font-bold">Check Out</th>
                        <th class="py-2.5 font-bold">Status</th>
                        <th class="py-2.5 font-bold">Device</th>
                        <th class="py-2.5 text-right font-bold">Hours</th>
                        <th class="px-5 py-2.5 text-right font-bold">Mode / Method</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @foreach($rows as $item)
                        @php
                            $sc = match($item->status) { 'on_time' => 'text-emerald-600', 'late' => 'text-amber-600', default => 'text-zinc-400' };
                            $bg = match($item->status) { 'on_time' => 'bg-emerald-500', 'late' => 'bg-amber-500', default => 'bg-zinc-300 dark:bg-zinc-600' };
                            $rowMode = AttendanceMode::tryFromValue($item->work_mode);
                            $dev = UserAgent::parse($item->check_in_user_agent);
                            $rowMethod = PunchMethod::tryFrom((string) $item->check_in_method);
                            $isMissing = (! $item->check_out && ! $item->date->isToday()) || $item->missing_checkout;
                        @endphp
                        <tr wire:click="showPunchDetail('{{ $item->date->toDateString() }}')"
                            class="group cursor-pointer transition-colors hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30 {{ $isMissing ? 'bg-amber-50/40 dark:bg-amber-950/10' : '' }}">
                            <td class="px-5 py-2.5">
                                <div class="flex items-center gap-2">
                                    <span class="size-1.5 shrink-0 rounded-full {{ $bg }}"></span>
                                    <div>
                                        <div class="text-xs font-black leading-none text-zinc-900 dark:text-white">{{ $item->date->format('d M') }}</div>
                                        <div class="text-[9px] font-bold uppercase text-zinc-400">{{ $item->date->format('D') }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-2.5 font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ $item->check_in->format('h:i A') }}</td>
                            <td class="py-2.5 font-mono text-xs">
                                @if($item->check_out) <span class="text-zinc-700 dark:text-zinc-300">{{ $item->check_out->format('h:i A') }}</span>
                                @elseif($item->date->isToday()) <span class="animate-pulse font-bold text-brand-600">live</span>
                                @else <span class="font-bold text-amber-500">missing</span>
                                @endif
                            </td>
                            <td class="py-2.5">
                                <span class="text-[10px] font-bold uppercase tracking-wider {{ $sc }}">{{ match($item->status) { 'on_time' => 'On Time', 'late' => 'Late', default => ucfirst($item->status) } }}</span>
                            </td>
                            <td class="py-2.5">
                                @if($dev['browser'] !== 'Unknown')
                                    <span class="inline-flex items-center gap-1 text-[10px] text-zinc-400" title="{{ $dev['label'] }}"><flux:icon :icon="$dev['icon']" class="size-3" /> {{ $dev['os'] }}</span>
                                @else <span class="text-[10px] text-zinc-300">—</span>@endif
                            </td>
                            <td class="py-2.5 text-right">
                                @if($item->check_out)
                                    @php $df = $item->check_in->diff($item->check_out); $h = $df->h + $df->d*24; @endphp
                                    <span class="text-xs font-bold tabular-nums {{ $h >= 9 ? 'text-emerald-600' : 'text-zinc-700 dark:text-white' }}">{{ $h }}h {{ $df->i }}m</span>
                                @elseif($item->date->isToday())
                                    @php $df = now()->diff($item->check_in); @endphp
                                    <span class="animate-pulse text-xs font-bold tabular-nums text-brand-600">{{ $df->h }}h {{ $df->i }}m</span>
                                @else <span class="text-xs text-zinc-300">—</span>@endif
                                @if($isMissing)
                                    <button wire:click.stop="openRegularisation('{{ $item->date->toDateString() }}')" class="block w-full text-right text-[9px] font-bold text-amber-600 hover:underline">Fix →</button>
                                @endif
                            </td>
                            <td class="px-5 py-2.5 text-right">
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[8px] font-bold uppercase tracking-wide {{ $rowMode->chipClass() }}">{{ $rowMode->shortLabel() }}</span>
                                @if($rowMethod)
                                    <span class="ml-1 inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-[8px] font-bold {{ $rowMethod->chipClass() }}" title="Punched via {{ $rowMethod->label() }}"><flux:icon :icon="$rowMethod->icon()" class="size-2.5" /> {{ $rowMethod->label() }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="py-12 text-center text-sm text-zinc-400">
                <flux:icon.clock class="mx-auto mb-2 size-8 opacity-30" />
                @if($logMode !== '')
                    No {{ AttendanceMode::tryFromValue($logMode)->label() }} records in {{ $calendarMonth->format('F Y') }}.
                    <button wire:click="$set('logMode', '')" class="font-bold text-brand-600 hover:underline">Clear filter</button>
                @else
                    No records for {{ $calendarMonth->format('F Y') }}
                @endif
            </div>
        @endif
    </div>
</div>

</div>{{-- end spacing wrapper --}}

{{-- ═══════════════════════════════════════════════
     PUNCH DETAIL MODAL
═══════════════════════════════════════════════ --}}
<flux:modal name="punch-detail" class="max-w-lg">
    @if($detail)
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="lg">Punch Details</flux:heading>
                    <flux:subheading>{{ $detail['date'] }}</flux:subheading>
                </div>
                @php $dMode = AttendanceMode::tryFromValue($detail['mode']); @endphp
                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-bold {{ $dMode->chipClass() }}">
                    <flux:icon :icon="$dMode->icon()" class="size-3.5" /> {{ $dMode->label() }}
                </span>
            </div>

            {{-- Summary --}}
            <div class="grid grid-cols-4 gap-2 text-center">
                <div class="rounded-xl bg-zinc-50 p-2.5 dark:bg-zinc-800/40">
                    <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $detail['total_hours'] ?? '—' }}<span class="text-[10px] text-zinc-400">h</span></div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Worked</div>
                </div>
                <div class="rounded-xl bg-zinc-50 p-2.5 dark:bg-zinc-800/40">
                    <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $detail['break_minutes'] }}<span class="text-[10px] text-zinc-400">m</span></div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Break</div>
                </div>
                <div class="rounded-xl bg-zinc-50 p-2.5 dark:bg-zinc-800/40">
                    <div class="text-sm font-black {{ $detail['is_late'] ? 'text-amber-600' : 'text-emerald-600' }}">{{ $detail['is_late'] ? 'Late' : 'On Time' }}</div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Status</div>
                </div>
                <div class="rounded-xl bg-zinc-50 p-2.5 dark:bg-zinc-800/40">
                    <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $detail['is_late'] ? $detail['late_minutes'].'m' : '—' }}</div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Late by</div>
                </div>
            </div>

            {{-- In / Out punch cards --}}
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach(['in' => 'Check In', 'out' => 'Check Out'] as $key => $title)
                    @php $p = $detail[$key]; @endphp
                    <div class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-800">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-widest {{ $key === 'in' ? 'text-emerald-600' : 'text-zinc-500' }}">{{ $title }}</span>
                            <span class="text-sm font-black tabular-nums text-zinc-900 dark:text-white">{{ $p['time'] ?? '—' }}</span>
                        </div>
                        @if($p['photo'])
                            <a href="{{ Storage::url($p['photo']) }}" target="_blank">
                                <img src="{{ Storage::url($p['photo']) }}" alt="Punch selfie" class="mb-2 h-24 w-full rounded-xl object-cover ring-1 ring-zinc-200 dark:ring-zinc-700">
                            </a>
                        @endif
                        <div class="space-y-1.5 text-[11px]">
                            @if($p['method'])
                                <div class="flex items-center gap-1.5 text-zinc-600 dark:text-zinc-300"><flux:icon :icon="$p['method_icon']" class="size-3.5 text-zinc-400" /> {{ $p['method'] }}</div>
                            @endif
                            <div class="flex items-center gap-1.5 text-zinc-600 dark:text-zinc-300"><flux:icon.computer-desktop class="size-3.5 text-zinc-400" /> {{ $p['device'] }}</div>
                            @if($p['ip'])
                                <div class="flex items-center gap-1.5 text-zinc-500"><flux:icon.globe-alt class="size-3.5 text-zinc-400" /> {{ $p['ip'] }}</div>
                            @endif
                            @if($p['lat'] && $p['lng'])
                                <a href="https://www.google.com/maps?q={{ $p['lat'] }},{{ $p['lng'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 font-semibold text-brand-600 hover:underline"><flux:icon.map-pin class="size-3.5" /> View location</a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex justify-end pt-1">
                <flux:button @click="$flux.modal('punch-detail').close()">Close</flux:button>
            </div>
        </div>
    @endif
</flux:modal>

{{-- ═══════════════════════════════════════════════
     REGULARISATION MODAL
═══════════════════════════════════════════════ --}}
<flux:modal name="regularisation-modal" class="max-w-md">
    <div class="space-y-5">
        <div>
            <flux:heading size="lg">Regularisation Request</flux:heading>
            <flux:subheading>Request a correction for a missing or wrong punch. HR approval auto-updates your hours &amp; attendance.</flux:subheading>
        </div>
        <div class="space-y-4">
            <flux:input wire:model="regDate" label="Date of Work" type="date" />
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="regCheckIn" label="Correct Check-in" type="time" />
                <flux:input wire:model="regCheckOut" label="Correct Check-out" type="time" />
            </div>
            <flux:textarea wire:model="regReason" label="Reason" placeholder="e.g. Forgot to clock out, system glitch..." rows="3" />
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <flux:button @click="$flux.modal('regularisation-modal').close()">Cancel</flux:button>
            <flux:button wire:click="submitRegularisation" variant="primary">Submit Request</flux:button>
        </div>
    </div>
</flux:modal>

{{-- ═══════════════════════════════════════════════
     PUNCH CAPTURE MODAL — selfie + geolocation
═══════════════════════════════════════════════ --}}
<flux:modal name="punch-capture" class="max-w-md"
    x-data="{
        action: 'in', lat: null, lng: null, photo: null,
        stream: null, status: 'idle', geoStatus: 'pending', busy: false,
        async openCapture(action) {
            this.action = action; this.photo = null; this.lat = null; this.lng = null;
            this.geoStatus = 'pending'; this.busy = false;
            this.getLocation();
            await this.startCamera();
        },
        getLocation() {
            if (! ('geolocation' in navigator)) { this.geoStatus = 'unavailable'; return; }
            navigator.geolocation.getCurrentPosition(
                p => { this.lat = +p.coords.latitude.toFixed(6); this.lng = +p.coords.longitude.toFixed(6); this.geoStatus = 'ok'; },
                () => { this.geoStatus = 'denied'; },
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
            );
        },
        async startCamera() {
            if (! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia) { this.status = 'nocamera'; return; }
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
                this.status = 'camera';
                this.$nextTick(() => { if (this.$refs.video) this.$refs.video.srcObject = this.stream; });
            } catch (e) { this.status = 'nocamera'; }
        },
        capture() {
            const v = this.$refs.video, c = this.$refs.canvas;
            if (! v) return;
            const w = 360, h = Math.round(w * (v.videoHeight || 480) / (v.videoWidth || 640));
            c.width = w; c.height = h;
            c.getContext('2d').drawImage(v, 0, 0, w, h);
            this.photo = c.toDataURL('image/jpeg', 0.7);
            this.stopCamera(); this.status = 'preview';
        },
        retake() { this.photo = null; this.startCamera(); },
        stopCamera() { if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; } },
        cleanup() { this.stopCamera(); this.status = 'idle'; this.busy = false; },
        async submit() {
            if (this.busy) return;
            this.busy = true;
            try {
                if (this.action === 'in') { await this.$wire.checkIn(this.lat, this.lng, this.photo); }
                else { await this.$wire.checkOut(this.lat, this.lng, this.photo); }
            } finally {
                this.cleanup();
                this.$flux.modal('punch-capture').close();
            }
        }
    }"
    x-on:open-punch.window="openCapture($event.detail.action)"
    x-on:close="cleanup()">
    <div class="space-y-4">
        <div>
            <flux:heading size="lg" x-text="action === 'in' ? 'Clock In' : 'End Work Day'">Clock In</flux:heading>
            <flux:subheading>Confirm with a quick selfie &amp; your location.</flux:subheading>
        </div>

        <div class="relative aspect-[4/3] w-full overflow-hidden rounded-2xl bg-zinc-900">
            <video x-ref="video" autoplay playsinline muted x-show="status === 'camera'" class="h-full w-full object-cover"></video>
            <img :src="photo" x-show="status === 'preview' && photo" class="h-full w-full object-cover" alt="Selfie preview">
            <div x-show="status === 'idle'" class="absolute inset-0 flex items-center justify-center text-zinc-500">
                <flux:icon.camera class="size-9 animate-pulse" />
            </div>
            <div x-show="status === 'nocamera'" class="absolute inset-0 flex flex-col items-center justify-center px-6 text-center text-zinc-400">
                <flux:icon.video-camera-slash class="mb-2 size-9" />
                <p class="text-xs">Camera unavailable — you can still clock in without a photo.</p>
            </div>
            <canvas x-ref="canvas" class="hidden"></canvas>
        </div>

        <div class="flex items-center gap-2 rounded-xl bg-zinc-50 px-3 py-2 text-xs dark:bg-zinc-800/50">
            <flux:icon.map-pin class="size-4 shrink-0"
                ::class="geoStatus === 'ok' ? 'text-emerald-500' : (geoStatus === 'pending' ? 'text-zinc-400 animate-pulse' : 'text-amber-500')" />
            <span x-show="geoStatus === 'pending'" class="text-zinc-400">Getting your location…</span>
            <span x-show="geoStatus === 'ok'" class="font-semibold text-emerald-600 dark:text-emerald-400" x-text="'Location captured · ' + lat + ', ' + lng"></span>
            <span x-show="geoStatus === 'denied'" class="text-amber-600 dark:text-amber-400">Location off — clocking in without it.</span>
            <span x-show="geoStatus === 'unavailable'" class="text-amber-600 dark:text-amber-400">Location unavailable on this device.</span>
        </div>

        <div class="flex items-center justify-between gap-2 pt-1">
            <button type="button" @click="cleanup(); $flux.modal('punch-capture').close()"
                class="rounded-xl px-4 py-2 text-sm font-bold text-zinc-500 transition hover:bg-zinc-100 dark:hover:bg-zinc-800">Cancel</button>
            <div class="flex items-center gap-2">
                <button type="button" x-show="status === 'preview'" @click="retake()"
                    class="rounded-xl bg-zinc-100 px-4 py-2 text-sm font-bold text-zinc-600 transition hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200">Retake</button>
                <button type="button" x-show="status === 'camera'" @click="capture()"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-zinc-800 px-4 py-2 text-sm font-bold text-white transition hover:bg-zinc-900 dark:bg-zinc-700">
                    <flux:icon.camera class="size-4" /> Capture
                </button>
                <button type="button" @click="submit()" x-bind:disabled="busy"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-5 py-2 text-sm font-bold text-white shadow-lg shadow-brand-500/20 transition hover:bg-brand-700 disabled:opacity-50"
                    x-text="busy ? 'Saving…' : (action === 'in' ? 'Clock In' : 'Clock Out')">Clock In</button>
            </div>
        </div>
        <p class="text-center text-[10px] text-zinc-400">Photo &amp; location are optional — you can clock in without them.</p>
    </div>
</flux:modal>

</flux:main>
