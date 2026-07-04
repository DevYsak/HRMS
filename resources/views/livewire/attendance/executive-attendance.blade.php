<flux:main class="min-h-screen bg-[#FFF8F3] p-4 md:p-6">

{{-- ═══════════════ HEADER ═══════════════ --}}
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-black tracking-tight text-zinc-900">Executive Attendance</h1>
        <div class="mt-0.5 flex items-center gap-1.5 text-xs text-zinc-400">
            <span>Dashboard</span><flux:icon.chevron-right class="size-3" /><span>Attendance</span><flux:icon.chevron-right class="size-3" /><span class="font-semibold text-orange-500">Executive</span>
            <span class="ml-1">· {{ now()->format('F Y') }} · {{ $elapsedWorkingDays }}/{{ $monthWorkingDays }} working days</span>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('attendance.reports') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white px-3 py-2 text-xs font-bold text-zinc-600 shadow-sm transition hover:bg-orange-50"><flux:icon.document-chart-bar class="size-4 text-orange-500" /> Reports</a>
        <a href="{{ route('attendance.employees') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white px-3 py-2 text-xs font-bold text-zinc-600 shadow-sm transition hover:bg-orange-50"><flux:icon.users class="size-4 text-orange-500" /> All Attendance</a>
    </div>
</div>

{{-- ═══════════════ HERO KPIs ═══════════════ --}}
<div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
    {{-- Company Attendance ring --}}
    <div class="flex items-center gap-5 rounded-[18px] border border-orange-100/70 bg-gradient-to-br from-white to-orange-50/40 p-5 shadow-sm lg:col-span-4"
         x-data="{ p: 0 }" x-init="setTimeout(() => p = {{ $companyPct }}, 150)">
        <div class="relative grid size-28 shrink-0 place-items-center">
            <svg class="size-28 -rotate-90" viewBox="0 0 36 36">
                <circle cx="18" cy="18" r="15.9" fill="none" stroke="#FFEDD5" stroke-width="3.2" />
                <circle cx="18" cy="18" r="15.9" fill="none" stroke="#F97316" stroke-width="3.2" stroke-linecap="round"
                    class="transition-all duration-1000 ease-out" :stroke-dasharray="p + ', 100'" stroke-dasharray="0, 100" />
            </svg>
            <div class="absolute text-center">
                <div class="text-2xl font-black tabular-nums text-zinc-900">{{ $companyPct }}%</div>
                <div class="text-[8px] font-bold uppercase tracking-wider text-zinc-400">Company</div>
            </div>
        </div>
        <div>
            <div class="text-sm font-black text-zinc-900">Company Attendance</div>
            <div class="mt-1 text-xs text-zinc-500">{{ $activeCount }} active employees</div>
            <div class="mt-2 flex gap-3 text-xs">
                <span class="font-bold text-emerald-600">Score {{ $attendanceScore }}</span>
                <span class="font-bold text-orange-500">Forecast {{ $forecast }}%</span>
            </div>
        </div>
    </div>

    {{-- KPI tiles --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:col-span-8">
        @php
            $hero = [
                ['Workforce Availability', $availability.'%', 'users', '#10b981', $presentToday.' present now'],
                ['Late %', $latePct.'%', 'exclamation-triangle', $latePct > 10 ? '#f59e0b' : '#10b981', 'of present days'],
                ['Absent %', $absentPct.'%', 'x-circle', $absentPct > 10 ? '#ef4444' : '#10b981', 'month to date'],
                ['On WFH Today', $wfhToday, 'home', '#8b5cf6', 'remote / hybrid'],
                ['On Leave Today', $onLeaveToday, 'calendar-days', '#0ea5e9', 'approved leave'],
                ['Attendance Score', $attendanceScore.'/100', 'shield-check', '#F97316', 'composite index'],
                ['Month Forecast', $forecast.'%', 'sparkles', '#f59e0b', 'projected end'],
                ['Present Today', $presentToday, 'check-circle', '#10b981', 'of '.$activeCount],
            ];
        @endphp
        @foreach($hero as [$label, $value, $icon, $color, $sub])
            <div class="rounded-[18px] border border-orange-100/70 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                <span class="inline-flex size-8 items-center justify-center rounded-xl" style="background: {{ $color }}1a; color: {{ $color }};"><flux:icon :icon="$icon" class="size-4" /></span>
                <div class="mt-2 text-xl font-black tabular-nums text-zinc-900">{{ $value }}</div>
                <div class="text-[9px] font-bold uppercase tracking-wide text-zinc-400">{{ $label }}</div>
                <div class="truncate text-[9px] text-zinc-400">{{ $sub }}</div>
            </div>
        @endforeach
    </div>
</div>

{{-- ═══════════════ TREND + AI INSIGHTS ═══════════════ --}}
@php
    $trendChart = [
        'chart' => ['type' => 'area', 'height' => 220, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'speed' => 700]],
        'colors' => ['#F97316'], 'dataLabels' => ['enabled' => false], 'stroke' => ['curve' => 'smooth', 'width' => 3],
        'fill' => ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.35, 'opacityTo' => 0.02, 'stops' => [0, 90]]],
        'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'xaxis' => ['categories' => collect($trend)->pluck('label')->all(), 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false]],
        'yaxis' => ['max' => 100, 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]],
        'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Attendance %', 'data' => collect($trend)->pluck('pct')->all()]],
    ];
@endphp
<div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
    <div class="rounded-[18px] border border-orange-100/70 bg-white p-5 shadow-sm lg:col-span-7">
        <div class="mb-1 flex items-center justify-between">
            <div class="text-sm font-black text-zinc-900">Monthly Attendance Trend</div>
            <span class="rounded-full bg-orange-50 px-2 py-0.5 text-[10px] font-bold text-orange-500">6 months</span>
        </div>
        <x-dashboard.chart :options="$trendChart" id="exec-trend" wire:key="exec-trend" class="-mb-2" />
    </div>
    <div class="rounded-[18px] border border-orange-100/70 bg-white p-5 shadow-sm lg:col-span-5">
        <div class="mb-3 flex items-center gap-2">
            <span class="inline-flex size-8 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-amber-400 text-white shadow"><flux:icon.sparkles class="size-4" /></span>
            <div class="text-sm font-black text-zinc-900">AI Insights</div>
        </div>
        <div class="space-y-2">
            @foreach($insights as $ins)
                <div class="flex items-center gap-2 rounded-xl {{ $ins['good'] ? 'bg-emerald-50/60' : 'bg-amber-50/60' }} px-3 py-2 text-xs">
                    <flux:icon :icon="$ins['good'] ? 'check-circle' : 'exclamation-circle'" class="size-4 shrink-0 {{ $ins['good'] ? 'text-emerald-500' : 'text-amber-500' }}" />
                    <span class="font-semibold {{ $ins['good'] ? 'text-emerald-800' : 'text-amber-800' }}">{{ $ins['text'] }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ═══════════════ DEPARTMENT + BRANCH + RISK ═══════════════ --}}
<div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
    {{-- Top Departments --}}
    <div class="rounded-[18px] border border-orange-100/70 bg-white p-5 shadow-sm lg:col-span-5">
        <div class="mb-3 text-sm font-black text-zinc-900">Department Attendance</div>
        <div class="space-y-2.5">
            @forelse($departments as $d)
                <div>
                    <div class="mb-1 flex items-center justify-between text-xs">
                        <span class="font-bold text-zinc-700">{{ $d['name'] }} <span class="text-zinc-400">· {{ $d['headcount'] }}</span></span>
                        <span class="font-black tabular-nums {{ $d['pct'] >= 90 ? 'text-emerald-600' : ($d['pct'] >= 80 ? 'text-amber-600' : 'text-rose-500') }}">{{ $d['pct'] }}%</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-zinc-100">
                        <div class="h-full rounded-full transition-all {{ $d['pct'] >= 90 ? 'bg-emerald-500' : ($d['pct'] >= 80 ? 'bg-amber-500' : 'bg-rose-500') }}" style="width: {{ $d['pct'] }}%"></div>
                    </div>
                </div>
            @empty
                <p class="text-xs text-zinc-400">No department data.</p>
            @endforelse
        </div>
    </div>

    {{-- Branch Attendance --}}
    <div class="rounded-[18px] border border-orange-100/70 bg-white p-5 shadow-sm lg:col-span-4">
        <div class="mb-3 text-sm font-black text-zinc-900">Branch Attendance</div>
        <div class="space-y-2.5">
            @forelse($branches as $b)
                <div class="flex items-center justify-between rounded-xl bg-orange-50/40 px-3 py-2">
                    <div>
                        <div class="text-xs font-bold text-zinc-800">{{ $b['name'] }}</div>
                        <div class="text-[9px] text-zinc-400">{{ $b['headcount'] }} employees</div>
                    </div>
                    <span class="text-lg font-black tabular-nums {{ $b['pct'] >= 90 ? 'text-emerald-600' : ($b['pct'] >= 80 ? 'text-amber-600' : 'text-rose-500') }}">{{ $b['pct'] }}%</span>
                </div>
            @empty
                <p class="text-xs text-zinc-400">No branch data.</p>
            @endforelse
        </div>
    </div>

    {{-- Attendance Risk --}}
    <div class="rounded-[18px] border {{ count($risk) ? 'border-rose-200 bg-rose-50/30' : 'border-orange-100/70 bg-white' }} p-5 shadow-sm lg:col-span-3">
        <div class="mb-3 flex items-center gap-2">
            <flux:icon.shield-exclamation class="size-4 {{ count($risk) ? 'text-rose-500' : 'text-emerald-500' }}" />
            <div class="text-sm font-black text-zinc-900">Attendance Risk</div>
        </div>
        @forelse($risk as $r)
            <div class="mb-1.5 flex items-center justify-between rounded-lg bg-white px-2.5 py-1.5 text-xs shadow-sm">
                <span class="truncate font-bold text-zinc-700">{{ $r['name'] }}</span>
                <span class="font-black text-rose-500">{{ $r['pct'] }}%</span>
            </div>
        @empty
            <div class="flex h-24 flex-col items-center justify-center text-center text-emerald-600">
                <flux:icon.check-badge class="mb-1 size-8" />
                <p class="text-xs font-bold">All departments ≥ 80%</p>
            </div>
        @endforelse
    </div>
</div>

{{-- ═══════════════ TOP + BOTTOM PERFORMERS ═══════════════ --}}
<div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
    <div class="rounded-[18px] border border-orange-100/70 bg-white p-5 shadow-sm">
        <div class="mb-3 flex items-center gap-2"><flux:icon.trophy class="size-4 text-emerald-500" /><div class="text-sm font-black text-zinc-900">Top Performers</div></div>
        <div class="space-y-1.5">
            @foreach($topPerformers as $i => $p)
                <div class="flex items-center gap-3 rounded-xl bg-emerald-50/40 px-3 py-2">
                    <span class="flex size-6 items-center justify-center rounded-lg bg-emerald-500 text-[10px] font-black text-white">{{ $i + 1 }}</span>
                    <div class="min-w-0 flex-1"><div class="truncate text-xs font-bold text-zinc-800">{{ $p['name'] }}</div><div class="text-[9px] text-zinc-400">{{ $p['dept'] }}</div></div>
                    <span class="text-sm font-black tabular-nums text-emerald-600">{{ $p['pct'] }}%</span>
                </div>
            @endforeach
        </div>
    </div>
    <div class="rounded-[18px] border border-orange-100/70 bg-white p-5 shadow-sm">
        <div class="mb-3 flex items-center gap-2"><flux:icon.arrow-trending-down class="size-4 text-rose-500" /><div class="text-sm font-black text-zinc-900">Needs Attention</div></div>
        <div class="space-y-1.5">
            @foreach($bottomPerformers as $p)
                <div class="flex items-center gap-3 rounded-xl bg-rose-50/40 px-3 py-2">
                    <span class="flex size-6 items-center justify-center rounded-lg bg-rose-400 text-white"><flux:icon.exclamation-triangle class="size-3" /></span>
                    <div class="min-w-0 flex-1"><div class="truncate text-xs font-bold text-zinc-800">{{ $p['name'] }}</div><div class="text-[9px] text-zinc-400">{{ $p['dept'] }}</div></div>
                    <span class="text-sm font-black tabular-nums {{ $p['pct'] >= 80 ? 'text-amber-600' : 'text-rose-500' }}">{{ $p['pct'] }}%</span>
                </div>
            @endforeach
        </div>
    </div>
</div>

</flux:main>
