<flux:main class="bg-zinc-50 dark:bg-zinc-950 min-h-screen" x-data="{
    currentTime: '',
    updateClock() {
        const now = new Date();
        this.currentTime = now.toLocaleTimeString('en-IN', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
}" x-init="updateClock(); setInterval(() => updateClock(), 1000)">

@php
    $presentCount = (int)($stats['present'] ?? 0);
    $lateCount    = (int)($stats['late'] ?? 0);
    $absentCount  = (int)($stats['absent'] ?? 0);
    $leaveCount   = (int)($stats['leaves'] ?? 0);

    // Calculate working days (Mon–Sat) using Carbon's built-in — no manual loop
    $pStart = match($statsPeriod) {
        'last_month' => now()->subMonth()->startOfMonth(),
        '3_months'   => now()->subMonths(2)->startOfMonth(),
        'year'       => now()->startOfYear(),
        default      => now()->startOfMonth(),
    };
    $pEnd = match($statsPeriod) {
        'last_month' => now()->subMonth()->endOfMonth(),
        default      => now()->endOfDay(),
    };
    // Cap end at today so we don't count future days
    if ($pEnd->gt(now())) { $pEnd = now(); }

    $totalWorkingDays = max(1, (int) $pStart->diffInDaysFiltered(
        fn($d) => !$d->isSunday(),
        $pEnd
    ));

    $attPct    = round(($presentCount / $totalWorkingDays) * 100, 1);
    $latePct   = round(($lateCount    / $totalWorkingDays) * 100);
    $absentPct = round(($absentCount  / $totalWorkingDays) * 100);
@endphp

{{-- ═══════════════════════════════════════════════
     HEADER
═══════════════════════════════════════════════ --}}
@php
    $emp = auth()->user()->employee;
    $heroMode = \App\Enums\AttendanceMode::tryFromValue($todayAttendance->work_mode ?? $workMode);
    $isIn = $todayAttendance && ! $todayAttendance->check_out;
    $isDone = $todayAttendance && $todayAttendance->check_out;

    $workedMin = 0;
    if ($todayAttendance && $todayAttendance->check_in) {
        $endT = $todayAttendance->check_out ?? now();
        $workedMin = max(0, (int) $todayAttendance->check_in->diffInMinutes($endT) - (int) ($todayAttendance->break_minutes ?? 0));
    }
    $targetMin = (int) round((($shift->standard_hours ?? 9)) * 60);
    $progress = $targetMin > 0 ? min(100, (int) round($workedMin / $targetMin * 100)) : 0;
    $workedLabel = intdiv($workedMin, 60).'h '.str_pad((string) ($workedMin % 60), 2, '0', STR_PAD_LEFT).'m';
    $targetLabel = intdiv($targetMin, 60).'h '.($targetMin % 60).'m';
    $liveStart = $isIn ? $todayAttendance->check_in->timestamp : null;
@endphp

{{-- ═══════════════════════════════════════════════
     HERO — live, mode-aware command card
═══════════════════════════════════════════════ --}}
<div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-zinc-900 via-zinc-900 to-black p-6 text-white shadow-xl md:p-8"
     x-data="{ start: {{ $liveStart ?? 'null' }}, live: '{{ $workedLabel }}',
        tick(){ if(this.start===null) return; const s=Math.floor(Date.now()/1000)-this.start; const h=Math.floor(s/3600),m=Math.floor((s%3600)/60); this.live=h+'h '+String(m).padStart(2,'0')+'m'; } }"
     x-init="tick(); if(start!==null) setInterval(()=>tick(),1000)">
    <div class="pointer-events-none absolute -right-20 -top-24 size-72 rounded-full blur-3xl" style="background:radial-gradient(circle, {{ $heroMode->hex() }}40, transparent 70%)"></div>
    <div class="pointer-events-none absolute -bottom-20 left-1/4 size-64 rounded-full blur-3xl" style="background:radial-gradient(circle, {{ $heroMode->hex() }}18, transparent 70%)"></div>

    <div class="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
        {{-- Identity --}}
        <div class="flex items-start gap-4">
            <div class="relative shrink-0">
                @if($emp?->photo)
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($emp->photo) }}" alt="{{ auth()->user()->name }}"
                        class="size-16 rounded-2xl object-cover ring-2 ring-white/10 md:size-[72px]">
                @else
                    <div class="flex size-16 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-amber-400 text-xl font-black md:size-[72px]">{{ auth()->user()->initials() }}</div>
                @endif
                <span class="absolute -bottom-1 -right-1 size-4 rounded-full border-2 border-zinc-900 {{ $isIn ? 'animate-pulse bg-emerald-500' : ($isDone ? 'bg-zinc-500' : 'bg-amber-500') }}"></span>
            </div>
            <div class="min-w-0">
                <div class="mb-1 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-bold tracking-wider" style="background: {{ $heroMode->hex() }}26; color: {{ $heroMode->hex() }}">
                        <span class="size-1.5 rounded-full {{ $isIn ? 'animate-pulse' : '' }}" style="background: {{ $heroMode->hex() }}"></span>
                        {{ $isIn ? strtoupper($heroMode->shortLabel()).' ACTIVE' : ($isDone ? 'COMPLETED' : 'NOT CLOCKED IN') }}
                    </span>
                    <span class="text-xs text-zinc-400">{{ now()->format('l, d M Y') }}</span>
                </div>
                <h1 class="flex items-center gap-2 text-2xl font-black tracking-tight md:text-3xl">
                    <flux:icon :name="$heroMode->icon()" class="size-6" /> {{ ($isIn || $isDone) ? $heroMode->label() : 'My Attendance' }}
                </h1>
                <p class="mt-1 text-sm text-zinc-400">{{ $shiftLabel }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-white/5 px-2.5 py-1 text-xs text-zinc-300"><flux:icon.map-pin class="size-3.5" /> {{ $heroMode->shortLabel() }}</span>
                    @if($shift)<span class="inline-flex items-center gap-1.5 rounded-lg bg-white/5 px-2.5 py-1 text-xs text-zinc-300"><flux:icon.clock class="size-3.5" /> {{ $shift->name ?? 'Shift' }}</span>@endif
                    @if($emp?->manager)<span class="inline-flex items-center gap-1.5 rounded-lg bg-white/5 px-2.5 py-1 text-xs text-zinc-300"><flux:icon.user class="size-3.5" /> {{ $emp->manager->name }}</span>@endif
                </div>
            </div>
        </div>

        {{-- Progress + actions --}}
        <div class="flex items-center gap-5">
            <div class="relative grid size-24 shrink-0 place-items-center">
                <svg class="size-24 -rotate-90" viewBox="0 0 36 36">
                    <circle cx="18" cy="18" r="15.9" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="3" />
                    <circle cx="18" cy="18" r="15.9" fill="none" stroke="{{ $heroMode->hex() }}" stroke-width="3" stroke-linecap="round" stroke-dasharray="{{ $progress }}, 100" />
                </svg>
                <div class="absolute text-xl font-black">{{ $progress }}%</div>
            </div>
            <div>
                <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">Today's Progress</div>
                <div class="mt-0.5 text-lg font-black tabular-nums" x-text="live">{{ $workedLabel }}</div>
                <div class="text-[11px] text-zinc-400">of {{ $targetLabel }} target</div>
                <div class="mt-3 flex flex-wrap gap-2">
                    @if(! $todayAttendance)
                        <button wire:click="checkIn"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold shadow-lg shadow-brand-500/20 transition hover:bg-brand-700 active:scale-95">
                            <flux:icon.finger-print class="size-4" /> Clock In · {{ strtoupper($workMode) }}
                        </button>
                    @elseif(! $todayAttendance->check_out)
                        @if($activeBreak)
                            <button wire:click="endBreak" class="inline-flex items-center gap-1.5 rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-bold transition hover:bg-amber-600 active:scale-95"><flux:icon.play class="size-4" /> Resume</button>
                        @else
                            <button wire:click="startBreak" class="inline-flex items-center gap-1.5 rounded-xl bg-white/10 px-4 py-2.5 text-sm font-bold transition hover:bg-white/20 active:scale-95"><flux:icon.pause class="size-4" /> Break</button>
                        @endif
                        <button wire:click="checkOut" wire:confirm="End your work day?"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold transition hover:bg-brand-700 active:scale-95">
                            <flux:icon.arrow-right-start-on-rectangle class="size-4" /> End Work Day
                        </button>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-500/20 px-4 py-2.5 text-sm font-bold text-emerald-400"><flux:icon.check-badge class="size-4" /> Day complete</span>
                    @endif
                    <button @click="$flux.modal('regularisation-modal').show()"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-white/5 px-3 py-2.5 text-sm font-bold transition hover:bg-white/10" title="Regularise">
                        <flux:icon.pencil-square class="size-4" />
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="p-4 md:p-6 space-y-5">

{{-- ═══════════════════════════════════════════════
     PERIOD FILTER PILLS
═══════════════════════════════════════════════ --}}
<div class="flex items-center gap-2 flex-wrap">
    @foreach(['this_week' => 'This Week', 'this_month' => 'This Month', 'last_month' => 'Last Month', '3_months' => '3 Months', 'year' => 'This Year'] as $val => $label)
        <button wire:click="$set('statsPeriod', '{{ $val }}')"
            class="px-4 py-1.5 rounded-xl text-xs font-bold border transition-all
                {{ $statsPeriod === $val
                    ? 'bg-brand-600 border-brand-600 text-white shadow-sm'
                    : 'bg-white dark:bg-zinc-900 border-zinc-200 dark:border-zinc-700 text-zinc-500 hover:border-zinc-300' }}">
            {{ $label }}
        </button>
    @endforeach
</div>

{{-- ═══════════════════════════════════════════════
     KPI CARDS — animated counters + inline sparklines
═══════════════════════════════════════════════ --}}
@php
    /** Inline SVG sparkline from a numeric series. */
    if (! function_exists('pulse_spark')) {
        function pulse_spark(array $vals, string $color, bool $fill = true): string
        {
            $vals = array_values(array_map('floatval', $vals));
            if (count($vals) < 2) {
                $vals = count($vals) === 1 ? [$vals[0], $vals[0]] : [0, 0];
            }
            $w = 100;
            $h = 30;
            $max = max($vals);
            $min = min($vals);
            $range = ($max - $min) ?: 1;
            $n = count($vals);
            $pts = [];
            foreach ($vals as $i => $v) {
                $x = round($i / ($n - 1) * $w, 2);
                $y = round($h - 3 - (($v - $min) / $range) * ($h - 6), 2);
                $pts[] = "{$x},{$y}";
            }
            $line = implode(' ', $pts);
            $svg = '<svg viewBox="0 0 '.$w.' '.$h.'" preserveAspectRatio="none" class="h-8 w-full overflow-visible">';
            if ($fill) {
                $svg .= '<polyline points="0,'.$h.' '.$line.' '.$w.','.$h.'" fill="'.$color.'" fill-opacity="0.10" stroke="none"/>';
            }
            $svg .= '<polyline points="'.$line.'" fill="none" stroke="'.$color.'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>';
            [$lx, $ly] = explode(',', end($pts));
            $svg .= '<circle cx="'.$lx.'" cy="'.$ly.'" r="2.5" fill="'.$color.'"/></svg>';

            return $svg;
        }
    }

    $hoursSeries = collect($chartDaily)->pluck('hours')->map(fn ($v) => (float) $v)->all();
    $breakSeries = collect($chartDaily)->pluck('break')->map(fn ($v) => (float) $v)->all();
    $lateSeries  = collect($analytics['late_trend'] ?? [])->pluck('late')->map(fn ($v) => (float) $v)->all();
    $avgBreakMin = (int) ($analytics['avg_break'] ?? 0);

    $kpis = [
        ['label' => 'Working Hours', 'value' => $stats['hours'] ?? '0h', 'raw' => null,               'sub' => 'total this period',       'icon' => 'clock',              'color' => '#10b981', 'text' => 'text-emerald-600 dark:text-emerald-400', 'spark' => $hoursSeries],
        ['label' => 'Avg Break',     'value' => null,                    'raw' => $avgBreakMin,        'suffix' => 'm', 'sub' => 'per working day',        'icon' => 'pause',              'color' => '#f59e0b', 'text' => 'text-amber-600 dark:text-amber-400',     'spark' => $breakSeries],
        ['label' => 'Attendance',    'value' => null,                    'raw' => (float) $attPct,     'suffix' => '%', 'sub' => 'present rate',           'icon' => 'chart-pie',          'color' => '#6366f1', 'text' => 'text-indigo-600 dark:text-indigo-400',   'bar' => (float) $attPct],
        ['label' => 'Present Days',  'value' => null,                    'raw' => (int) $presentCount, 'sub' => 'of '.$totalWorkingDays.' working days', 'icon' => 'check-badge',        'color' => '#3b82f6', 'text' => 'text-blue-600 dark:text-blue-400',       'bar' => $totalWorkingDays ? round($presentCount / $totalWorkingDays * 100) : 0],
        ['label' => 'Late Arrivals', 'value' => null,                    'raw' => (int) $lateCount,    'sub' => 'this period',            'icon' => 'exclamation-triangle', 'color' => '#ef4444', 'text' => 'text-rose-600 dark:text-rose-400',       'spark' => $lateSeries],
    ];
@endphp
<div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
    @foreach($kpis as $k)
        <div class="group relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-start justify-between">
                <span class="inline-flex size-9 items-center justify-center rounded-xl" style="background: {{ $k['color'] }}1a; color: {{ $k['color'] }};">
                    <flux:icon :icon="$k['icon']" class="size-4" />
                </span>
                <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">{{ $k['label'] }}</span>
            </div>

            <div class="mt-3 text-2xl font-black tabular-nums text-zinc-900 dark:text-white">
                @if($k['value'] !== null)
                    {{ $k['value'] }}
                @else
                    <span x-data="{ n: 0 }" x-init="$nextTick(() => { let t = @js($k['raw']), s = 0, step = t/28 || t; let iv = setInterval(() => { s += step; if (s >= t) { s = t; clearInterval(iv); } n = @js(is_int($k['raw'])) ? Math.round(s) : Math.round(s*10)/10; }, 18); })" x-text="n">{{ $k['raw'] }}</span>{{ $k['suffix'] ?? '' }}
                @endif
            </div>
            <div class="text-[11px] text-zinc-400">{{ $k['sub'] }}</div>

            <div class="mt-2 h-8">
                @if(! empty($k['spark']))
                    {!! pulse_spark($k['spark'], $k['color']) !!}
                @elseif(isset($k['bar']))
                    <div class="mt-4 h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                        <div class="h-full rounded-full transition-all duration-700" style="width: {{ min(100, $k['bar']) }}%; background: {{ $k['color'] }};"></div>
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>

{{-- ─── ATTENDANCE ANALYTICS ─── --}}
<div class="grid grid-cols-1 gap-4 lg:grid-cols-4 mb-5">
    {{-- Attendance score --}}
    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Attendance Score</div>
        @php $score = (int) ($analytics['attendance_score'] ?? 0); @endphp
        <div class="mt-2 text-4xl font-black {{ $score >= 80 ? 'text-emerald-600' : ($score >= 60 ? 'text-amber-600' : 'text-rose-600') }}">{{ $score }}<span class="text-base text-zinc-400">/100</span></div>
        <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
            <div class="h-full rounded-full {{ $score >= 80 ? 'bg-emerald-500' : ($score >= 60 ? 'bg-amber-500' : 'bg-rose-500') }}" style="width: {{ $score }}%"></div>
        </div>
    </div>
    {{-- Shift compliance --}}
    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Shift Compliance</div>
        <div class="mt-2 text-4xl font-black text-zinc-900 dark:text-white">{{ $analytics['shift_compliance'] ?? 0 }}%</div>
        <div class="mt-2 text-[11px] text-zinc-400">on-time of working days</div>
    </div>
    {{-- Work pattern --}}
    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Work Pattern</div>
        @php $breakdown = $analytics['mode_breakdown'] ?? []; $totMode = max(1, array_sum($breakdown)); @endphp
        @if(empty($breakdown))
            <div class="mt-2 text-sm text-zinc-400">No attendance yet.</div>
        @else
            <div class="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm">
                @foreach($breakdown as $mv => $count)
                    @php $m = \App\Enums\AttendanceMode::tryFromValue($mv); @endphp
                    <span class="inline-flex items-baseline gap-1">
                        <span class="font-black text-zinc-900 dark:text-white">{{ $count }}</span>
                        <span class="text-xs text-zinc-400">{{ $m->shortLabel() }}</span>
                    </span>
                @endforeach
            </div>
            <div class="mt-3 flex h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                @foreach($breakdown as $mv => $count)
                    <div class="h-full {{ \App\Enums\AttendanceMode::tryFromValue($mv)->dotClass() }}" style="width: {{ round($count / $totMode * 100) }}%"></div>
                @endforeach
            </div>
        @endif
    </div>
    {{-- Break analytics --}}
    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Break Analytics</div>
        <div class="mt-2 text-4xl font-black text-zinc-900 dark:text-white">{{ $analytics['avg_break'] ?? 0 }}<span class="text-base text-zinc-400">m</span></div>
        <div class="mt-2 text-[11px] {{ ($analytics['excess_breaks'] ?? 0) > 0 ? 'text-amber-600' : 'text-zinc-400' }}">{{ $analytics['excess_breaks'] ?? 0 }} excess-break days</div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════
     ANALYTICS CHARTS (ApexCharts)
═══════════════════════════════════════════════ --}}
@php
    $modeBreakdown = $analytics['mode_breakdown'] ?? [];
    $lateTrend = $analytics['late_trend'] ?? [];

    $hoursChart = [
        'chart' => ['type' => 'area', 'height' => 260, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'easing' => 'easeinout', 'speed' => 800]],
        'colors' => ['#F97316'],
        'dataLabels' => ['enabled' => false],
        'stroke' => ['curve' => 'smooth', 'width' => 3],
        'fill' => ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.4, 'opacityTo' => 0.02, 'stops' => [0, 90]]],
        'grid' => ['borderColor' => '#F1E7DD', 'strokeDashArray' => 4, 'padding' => ['left' => 8, 'right' => 8]],
        'xaxis' => ['categories' => collect($chartDaily)->pluck('label')->all(), 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px'], 'hideOverlappingLabels' => true], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false], 'tickAmount' => min(10, max(1, count($chartDaily)))],
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]],
        'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Hours', 'data' => collect($chartDaily)->pluck('hours')->all()]],
    ];

    $modeDonut = [
        'chart' => ['type' => 'donut', 'height' => 240, 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'speed' => 700]],
        'labels' => collect($modeBreakdown)->keys()->map(fn ($k) => \App\Enums\AttendanceMode::tryFromValue($k)->shortLabel())->all(),
        'colors' => collect($modeBreakdown)->keys()->map(fn ($k) => \App\Enums\AttendanceMode::tryFromValue($k)->hex())->all(),
        'series' => collect($modeBreakdown)->values()->map(fn ($v) => (int) $v)->all(),
        'legend' => ['show' => false],
        'dataLabels' => ['enabled' => false],
        'stroke' => ['width' => 0],
        'plotOptions' => ['pie' => ['donut' => ['size' => '72%']]],
        'tooltip' => ['theme' => 'light'],
    ];

    $lateChart = [
        'chart' => ['type' => 'bar', 'height' => 200, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'speed' => 800]],
        'colors' => ['#F59E0B'],
        'plotOptions' => ['bar' => ['borderRadius' => 6, 'columnWidth' => '45%']],
        'dataLabels' => ['enabled' => false],
        'grid' => ['borderColor' => '#F1E7DD', 'strokeDashArray' => 4],
        'xaxis' => ['categories' => collect($lateTrend)->pluck('month')->all(), 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '11px']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false]],
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '11px']]],
        'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Late days', 'data' => collect($lateTrend)->pluck('late')->all()]],
    ];
@endphp

<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    {{-- Working Hours Trend --}}
    <div class="lg:col-span-2 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mb-2 flex items-center justify-between">
            <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Working Hours Trend</div>
            <div class="rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-bold text-brand-600 dark:bg-brand-900/20">{{ ucwords(str_replace('_', ' ', $statsPeriod)) }}</div>
        </div>
        @if(count($chartDaily) > 0)
            <x-dashboard.chart :options="$hoursChart" wire:key="hours-{{ $statsPeriod }}" class="-mb-2" />
        @else
            <div class="flex h-[260px] flex-col items-center justify-center text-zinc-300 dark:text-zinc-600">
                <flux:icon.chart-bar class="mb-2 size-10" /><p class="text-xs">No hours logged in this period.</p>
            </div>
        @endif
    </div>

    {{-- Work Mode Split --}}
    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mb-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Work Mode Split</div>
        @if(!empty($modeBreakdown))
            <x-dashboard.chart :options="$modeDonut" wire:key="mode-{{ $statsPeriod }}" class="grid place-items-center" />
            <div class="mt-3 space-y-1.5">
                @foreach($modeBreakdown as $mv => $count)
                    @php $m = \App\Enums\AttendanceMode::tryFromValue($mv); @endphp
                    <div class="flex items-center justify-between text-xs">
                        <span class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300"><span class="size-2.5 rounded-full {{ $m->dotClass() }}"></span>{{ $m->label() }}</span>
                        <span class="font-bold text-zinc-900 dark:text-white">{{ $count }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <div class="flex h-[220px] flex-col items-center justify-center text-zinc-300 dark:text-zinc-600">
                <flux:icon.chart-pie class="mb-2 size-10" /><p class="text-xs">No attendance yet.</p>
            </div>
        @endif
    </div>
</div>

{{-- Late Arrival Trend --}}
<div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    <div class="mb-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Late Arrival Trend (6 months)</div>
    <x-dashboard.chart :options="$lateChart" wire:key="late-trend" class="-mb-2" />
</div>

{{-- ─── AI ATTENDANCE INSIGHTS (only when OPENAI_API_KEY is set) ─── --}}
@if($aiEnabled)
    <div class="mb-5 rounded-2xl border border-indigo-200 bg-gradient-to-br from-indigo-50 to-white p-5 shadow-sm dark:border-indigo-900/50 dark:from-indigo-950/20 dark:to-zinc-900">
        <div class="mb-3 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="rounded-lg bg-indigo-100 p-1.5 dark:bg-indigo-900/40"><flux:icon.sparkles class="size-4 text-indigo-600 dark:text-indigo-400" /></span>
                <h3 class="text-xs font-black uppercase tracking-[0.12em] text-zinc-600 dark:text-zinc-300">AI Attendance Insights</h3>
            </div>
            <button type="button" wire:click="generateAiInsight" wire:loading.attr="disabled" wire:target="generateAiInsight"
                class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-bold text-white transition-colors hover:bg-indigo-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="generateAiInsight">{{ $aiInsight ? 'Regenerate' : 'Generate' }}</span>
                <span wire:loading wire:target="generateAiInsight">Analysing…</span>
            </button>
        </div>
        @if($aiInsight)
            <div class="whitespace-pre-line text-sm text-zinc-700 dark:text-zinc-200">{{ $aiInsight }}</div>
        @else
            <p class="text-xs text-zinc-400">Generate a plain-language summary of attendance anomalies, burnout risk and late-arrival patterns.</p>
        @endif
    </div>
@endif

{{-- ═══════════════════════════════════════════════
     MAIN BODY: Clock + Calendar
═══════════════════════════════════════════════ --}}
<div class="grid grid-cols-1 lg:grid-cols-12 gap-5">

    {{-- ── LEFT: Clock Card ── --}}
    <div class="lg:col-span-3 bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden">

        {{-- Live Clock --}}
        <div class="bg-zinc-900 dark:bg-zinc-950 px-6 py-5 text-center relative overflow-hidden">
            <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_center,_rgba(16,185,129,0.08),_transparent_70%)]"></div>
            <div class="relative">
                <div class="text-[10px] font-bold text-zinc-500 uppercase tracking-widest mb-1">Current Time · IST</div>
                <div class="text-3xl font-black text-white tracking-tighter tabular-nums" x-text="currentTime"></div>
                <div class="text-zinc-500 text-xs mt-1">{{ now()->format('l, d M Y') }}</div>
            </div>
        </div>

        <div class="p-5 space-y-4">
            {{-- Today's Status --}}
            @if($todayAttendance)
                <div class="rounded-xl {{ $todayAttendance->is_late ? 'bg-rose-50 dark:bg-rose-950/30 border-rose-200 dark:border-rose-900/40' : 'bg-emerald-50 dark:bg-emerald-950/30 border-emerald-200 dark:border-emerald-900/40' }} border p-4">
                    <div class="flex justify-between items-start">
                        <div>
                            <div class="text-[9px] font-bold text-zinc-400 uppercase tracking-widest">Clocked In</div>
                            <div class="text-2xl font-black text-zinc-900 dark:text-white mt-0.5 tabular-nums">
                                {{ $todayAttendance->check_in->format('h:i') }}<span class="text-sm font-medium text-zinc-400 ml-1">{{ $todayAttendance->check_in->format('A') }}</span>
                            </div>
                        </div>
                        @if($todayAttendance->is_late)
                            <span class="px-2 py-1 bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-400 text-[9px] font-black rounded-lg">
                                @php
                                $lm = (int)($todayAttendance->late_minutes ?? 0);
                                $lateLabel = $lm >= 60 ? floor($lm/60).'h '.($lm%60).'m' : $lm.'m';
                            @endphp
                            LATE {{ $lateLabel }}
                            </span>
                        @else
                            <span class="px-2 py-1 bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400 text-[9px] font-black rounded-lg">ON TIME</span>
                        @endif
                    </div>
                    @if($todayAttendance->check_out)
                        <div class="mt-2 pt-2 border-t border-zinc-100 dark:border-zinc-800 flex justify-between text-xs text-zinc-500">
                            <span>Out: <strong class="text-zinc-700 dark:text-zinc-300 font-mono">{{ $todayAttendance->check_out->format('h:i A') }}</strong></span>
                            @php $diff = $todayAttendance->check_out->diff($todayAttendance->check_in); @endphp
                            <span>{{ $diff->h }}h {{ $diff->i }}m</span>
                        </div>
                    @endif
                    @php $todayMode = \App\Enums\AttendanceMode::tryFromValue($todayAttendance->work_mode); @endphp
                    <div class="mt-2">
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold {{ $todayMode->chipClass() }}">
                            <flux:icon :name="$todayMode->icon()" class="size-3" /> {{ $todayMode->label() }}
                        </span>
                    </div>
                </div>

                @if($activeBreak)
                    <div class="rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/40 p-3 flex items-center gap-2">
                        <span class="w-2 h-2 bg-amber-500 rounded-full animate-pulse shrink-0"></span>
                        <span class="text-amber-700 dark:text-amber-400 text-xs font-bold">
                            Break since {{ \Carbon\Carbon::parse($activeBreak['break_start'])->format('h:i A') }}
                        </span>
                    </div>
                @endif

                @if($todayAttendance->excess_break_flag)
                    <div class="rounded-xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200 p-3 flex items-center gap-2 text-rose-700 dark:text-rose-400 text-xs font-bold">
                        <flux:icon.exclamation-triangle class="size-4 shrink-0" /> Excess break flagged
                    </div>
                @endif
            @else
                {{-- Work Mode selector --}}
                <div>
                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-2">Work Mode</div>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach(\App\Enums\AttendanceMode::cases() as $mode)
                            <button wire:click="$set('workMode', '{{ $mode->value }}')"
                                class="py-2 rounded-xl border text-xs font-bold transition-all {{ $workMode === $mode->value ? 'bg-brand-600 border-brand-600 text-white' : 'bg-zinc-50 dark:bg-zinc-800 border-zinc-200 dark:border-zinc-700 text-zinc-500 hover:border-zinc-300' }}">
                                <flux:icon :name="$mode->icon()" class="size-4 mx-auto mb-1" />
                                {{ $mode->shortLabel() }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Clock actions live in the hero card at the top --}}
            @if(!$todayAttendance)
                <p class="text-center text-[11px] text-zinc-400">Pick a mode, then use <span class="font-bold text-brand-600">Clock In</span> at the top of the page.</p>
            @endif

            {{-- Shift Info --}}
            @if($shift)
                <div class="pt-3 border-t border-zinc-100 dark:border-zinc-800 grid grid-cols-3 gap-2 text-center">
                    <div>
                        <div class="text-[9px] text-zinc-400 uppercase tracking-wider">Start</div>
                        <div class="text-xs font-bold text-zinc-700 dark:text-zinc-200">{{ \Carbon\Carbon::parse($shift->start_time)->format('h:i A') }}</div>
                    </div>
                    <div>
                        <div class="text-[9px] text-zinc-400 uppercase tracking-wider">Grace</div>
                        <div class="text-xs font-bold text-zinc-700 dark:text-zinc-200">{{ $shift->grace_minutes ?? 5 }}m</div>
                    </div>
                    <div>
                        <div class="text-[9px] text-zinc-400 uppercase tracking-wider">End</div>
                        <div class="text-xs font-bold text-zinc-700 dark:text-zinc-200">{{ \Carbon\Carbon::parse($shift->end_time)->format('h:i A') }}</div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- ── CENTRE: Calendar ── --}}
    <div class="lg:col-span-5 bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ $calendarMonth->format('F Y') }}</h3>
            <div class="flex gap-1">
                <button wire:click="previousMonth" class="p-1.5 border border-zinc-200 dark:border-zinc-700 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                    <flux:icon.chevron-left class="size-3.5 text-zinc-500" />
                </button>
                <button wire:click="nextMonth" class="p-1.5 border border-zinc-200 dark:border-zinc-700 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                    <flux:icon.chevron-right class="size-3.5 text-zinc-500" />
                </button>
            </div>
        </div>

        {{-- Day Headers --}}
        <div class="grid grid-cols-7 gap-1 mb-2">
            @foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d)
                <div class="text-center text-[9px] font-bold text-zinc-400 uppercase tracking-widest pb-1">{{ substr($d,0,1) }}</div>
            @endforeach
        </div>

        {{-- Calendar Grid --}}
        <div class="grid grid-cols-7 gap-1">
            @foreach($calendarDays as $day)
                @php
                    $dayDate = \Carbon\Carbon::parse($day['date']);
                    if ($day['is_today']) {
                        $cellClass = 'bg-brand-600 border-brand-600 text-white shadow-md shadow-brand-500/20 scale-105 z-10';
                        $numClass = 'text-white';
                        $dotColor = '';
                    } elseif (!$day['in_month']) {
                        $cellClass = 'opacity-0 pointer-events-none border-transparent';
                        $numClass = 'text-zinc-300';
                        $dotColor = '';
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

                        // Colour attended days by their attendance mode.
                        if ($day['status'] === 'present' && ! empty($day['mode'])) {
                            $dotColor = \App\Enums\AttendanceMode::tryFromValue($day['mode'])->dotClass();
                        }
                    }
                @endphp
                <div class="aspect-square rounded-xl border flex flex-col items-center justify-center transition-all {{ $cellClass }}">
                    <span class="text-xs font-bold {{ $numClass }} leading-none">{{ $day['day'] }}</span>
                    @if($dotColor && !$day['is_today'])
                        <div class="w-1.5 h-1.5 rounded-full {{ $dotColor }} mt-1"></div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Legend --}}
        <div class="mt-4 pt-4 border-t border-zinc-100 dark:border-zinc-800 flex flex-wrap gap-3">
            @foreach([['bg-amber-500','Late'],['bg-rose-400','Absent'],['bg-violet-500','Leave'],['bg-blue-500','Holiday'],['bg-brand-600','Today']] as [$c,$l])
                <div class="flex items-center gap-1.5 text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">
                    <div class="w-2 h-2 rounded-full {{ $c }}"></div> {{ $l }}
                </div>
            @endforeach
        </div>
        {{-- Attendance-mode legend --}}
        <div class="mt-2 flex flex-wrap gap-3">
            @foreach(\App\Enums\AttendanceMode::cases() as $mode)
                <div class="flex items-center gap-1.5 text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">
                    <div class="w-2 h-2 rounded-full {{ $mode->dotClass() }}"></div> {{ $mode->shortLabel() }}
                </div>
            @endforeach
        </div>
    </div>

    {{-- ── RIGHT: Attendance Log + Info ── --}}
    <div class="lg:col-span-4 flex flex-col gap-4">

        {{-- Attendance Log --}}
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden flex-1">
            <div class="flex items-center justify-between px-5 py-3.5 border-b border-zinc-100 dark:border-zinc-800">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                    <flux:icon.clock class="size-4 text-brand-500" /> Attendance Log
                </h3>
                <span class="text-[10px] text-zinc-400 uppercase tracking-widest">{{ $calendarMonth->format('M Y') }}</span>
            </div>
            <div class="divide-y divide-zinc-50 dark:divide-zinc-800/60 overflow-y-auto" style="max-height: 320px;">
                @forelse($history as $item)
                    @php
                        $sc = match($item->status) { 'on_time' => 'text-emerald-600', 'late' => 'text-amber-600', default => 'text-zinc-400' };
                        $bg = match($item->status) { 'on_time' => 'bg-emerald-500', 'late' => 'bg-amber-500', default => 'bg-zinc-300 dark:bg-zinc-600' };
                        $sl = match($item->status) { 'on_time' => 'On Time', 'late' => 'Late', default => ucfirst($item->status) };
                    @endphp
                    <div class="flex items-center gap-3 px-5 py-3 hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30 transition-colors">
                        {{-- Date badge --}}
                        <div class="w-10 text-center shrink-0">
                            <div class="text-sm font-black text-zinc-900 dark:text-white leading-none">{{ $item->date->format('d') }}</div>
                            <div class="text-[9px] font-bold text-zinc-400 uppercase">{{ $item->date->format('D') }}</div>
                        </div>
                        {{-- Status dot --}}
                        <div class="w-2 h-2 rounded-full {{ $bg }} shrink-0"></div>
                        {{-- Times --}}
                        <div class="flex-1 min-w-0">
                            <div class="text-xs font-mono text-zinc-700 dark:text-zinc-300">
                                {{ $item->check_in->format('H:i') }}
                                →
                                @if($item->check_out) {{ $item->check_out->format('H:i') }}
                                @elseif($item->date->isToday()) <span class="text-brand-600 animate-pulse font-bold">live</span>
                                @else <span class="text-rose-400">--:--</span>
                                @endif
                            </div>
                            @php $rowMode = \App\Enums\AttendanceMode::tryFromValue($item->work_mode); @endphp
                            <div class="flex items-center gap-1.5">
                                <span class="text-[9px] font-bold uppercase tracking-wider {{ $sc }}">{{ $sl }}</span>
                                <span class="inline-flex items-center rounded px-1 py-px text-[8px] font-bold uppercase tracking-wide {{ $rowMode->chipClass() }}">{{ $rowMode->shortLabel() }}</span>
                            </div>
                        </div>
                        {{-- Hours --}}
                        <div class="text-right shrink-0">
                            @if($item->check_out)
                                @php $d = $item->check_in->diff($item->check_out); @endphp
                                <div class="text-xs font-bold {{ ($d->h + $d->d*24) >= 9 ? 'text-emerald-600' : 'text-zinc-700 dark:text-white' }}">{{ $d->h + $d->d*24 }}h {{ $d->i }}m</div>
                            @elseif($item->date->isToday())
                                @php $d = now()->diff($item->check_in); @endphp
                                <div class="text-xs font-bold text-brand-600 animate-pulse">{{ $d->h }}h {{ $d->i }}m</div>
                            @endif
                            @if((!$item->check_out && !$item->date->isToday()) || $item->missing_checkout)
                                <button wire:click="openRegularisation('{{ $item->date->toDateString() }}')"
                                    class="text-[9px] font-bold text-brand-600 hover:underline">Fix →</button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="py-10 text-center text-zinc-400 text-sm">
                        <flux:icon.clock class="size-8 mx-auto mb-2 opacity-30" />
                        No records for {{ $calendarMonth->format('F Y') }}
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Holidays this month --}}
        @if(count($monthHolidays) > 0)
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm p-4">
                <h3 class="text-xs font-bold text-zinc-500 uppercase tracking-widest mb-3 flex items-center gap-2">
                    <flux:icon.sparkles class="size-3.5 text-blue-500" /> Holidays · {{ $calendarMonth->format('M Y') }}
                </h3>
                <div class="space-y-2">
                    @foreach($monthHolidays as $h)
                        <div class="flex items-center gap-3">
                            <div class="w-9 text-center bg-blue-50 dark:bg-blue-950/40 rounded-lg p-1 shrink-0">
                                <div class="text-sm font-black text-blue-700 dark:text-blue-400 leading-none">{{ \Carbon\Carbon::parse($h->date)->format('j') }}</div>
                                <div class="text-[8px] font-bold text-blue-400 uppercase">{{ \Carbon\Carbon::parse($h->date)->format('M') }}</div>
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

</div>{{-- end main --}}

{{-- ═══════════════════════════════════════════════
     REGULARISATION MODAL
═══════════════════════════════════════════════ --}}
<flux:modal name="regularisation-modal" class="max-w-md">
    <div class="space-y-5">
        <div>
            <flux:heading size="lg">Regularisation Request</flux:heading>
            <flux:subheading>Request correction for past attendance</flux:subheading>
        </div>
        <div class="space-y-4">
            <flux:input wire:model="regDate" label="Date of Work" type="date" />
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="regCheckIn" label="Correct Check-in" type="time" />
                <flux:input wire:model="regCheckOut" label="Correct Check-out" type="time" />
            </div>
            <flux:textarea wire:model="regReason" label="Reason" placeholder="e.g. Forgot to clock in, system glitch..." rows="3" />
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <flux:button @click="$flux.modal('regularisation-modal').close()">Cancel</flux:button>
            <flux:button wire:click="submitRegularisation" variant="primary">Submit Request</flux:button>
        </div>
    </div>
</flux:modal>

</flux:main>
