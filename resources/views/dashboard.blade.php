@php $authUser = auth()->user(); @endphp
@if($authUser->isHrAdmin() && ! $authUser->isSuperAdmin())
    {{-- HR Admin gets the dedicated HR operations content (same route / layout / sidebar). --}}
    @include('dashboard.hr')
@else
<flux:main class="min-h-screen space-y-6 bg-[#FFFDF8] dark:bg-[#0B1220] p-4 font-['Inter'] md:p-6">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
    </style>

    @php
        $r = fn ($n) => \Illuminate\Support\Facades\Route::has($n) ? route($n) : '#';
        $hour = now()->hour;
        $greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
        $firstName = \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->first();
        $isSuper = auth()->user()->isSuperAdmin();
        $scope = $isSuper ? 'Company' : 'HR';

        // Tone helpers (normalise legacy rose/emerald/violet → cream palette keys).
        $tone = fn ($t) => ['rose' => 'red', 'emerald' => 'green', 'violet' => 'blue'][$t] ?? $t;
        $toneSoft = [
            'orange' => 'bg-orange-50 text-orange-500', 'green' => 'bg-green-50 text-green-600',
            'amber' => 'bg-amber-50 text-amber-600', 'blue' => 'bg-blue-50 text-blue-600', 'red' => 'bg-red-50 text-red-600',
        ];
        $toneText = [
            'orange' => 'text-orange-500', 'green' => 'text-green-600', 'amber' => 'text-amber-600',
            'blue' => 'text-blue-600', 'red' => 'text-red-600',
        ];

        // Synthetic sparkline (visual only — no per-metric history table).
        $spark = function (float $end, float $swing = 0.16) {
            $pts = [];
            $base = max($end, 1);
            for ($i = 6; $i >= 0; $i--) {
                $pts[] = round($base * (1 - ($i / 6) * $swing + (($i % 2) ? -0.04 : 0.03)), 1);
            }
            $pts[6] = $end;
            return $pts;
        };

        $complianceScore = max(40, 100 - (($expiringDocuments->count() + $upcomingProbations->count() + $activeWarnings) * 5));
        $totalPending = $pendingLeavesCount + $pendingOtCount + $pendingRegularisations + $pendingEncashments;
        $presentPct = $totalActive > 0 ? round($presentToday / $totalActive * 100) : 0;
        $readiness = max(20, min(98, round($attendancePercent * 0.5 + $complianceScore * 0.4 + (10 - min($totalPending, 10)))));

        // ── AI insights (data-driven) ──
        $insights = [];
        if ($expiringDocuments->count() > 0) {
            $insights[] = ['tone' => 'red', 'icon' => 'document-text', 'title' => 'Compliance risk', 'text' => $expiringDocuments->count().' document(s) expiring within 30 days.'];
        }
        if ($totalPending >= 5) {
            $insights[] = ['tone' => 'amber', 'icon' => 'inbox-stack', 'title' => 'Approval backlog', 'text' => $totalPending.' requests awaiting review — clear to protect SLAs.'];
        }
        if ($onPipCount > 0) {
            $insights[] = ['tone' => 'amber', 'icon' => 'chart-bar', 'title' => 'Performance watch', 'text' => $onPipCount.' employee(s) on an active improvement plan.'];
        }
        if ($attendancePercent < 80) {
            $insights[] = ['tone' => 'red', 'icon' => 'arrow-trending-down', 'title' => 'Attendance dip', 'text' => 'Attendance at '.$attendancePercent.'% today — below the 80% target.'];
        }
        if ($newEmployeesCount > 0) {
            $insights[] = ['tone' => 'green', 'icon' => 'user-plus', 'title' => 'Onboarding focus', 'text' => $newEmployeesCount.' new hire(s) this month — keep onboarding on track.'];
        }
        if (empty($insights)) {
            $insights[] = ['tone' => 'green', 'icon' => 'check-circle', 'title' => 'All clear', 'text' => 'No workforce risks detected. Operations are healthy.'];
        }

        // ── KPI cards (8) ──
        $kpis = [
            ['label' => 'Total Employees', 'value' => $totalActive, 'icon' => 'users', 'accent' => 'orange', 'delta' => 0, 'dir' => 'flat', 'compare' => 'active headcount', 'spark' => $spark($totalActive)],
            ['label' => 'Present Today', 'value' => $presentToday, 'icon' => 'check-circle', 'accent' => 'green', 'delta' => 0, 'dir' => 'flat', 'compare' => $presentPct.'% of '.$totalActive.' active', 'spark' => $spark(max($presentToday, 1))],
            ['label' => 'On Leave', 'value' => $onLeaveTodayCount, 'icon' => 'calendar-days', 'accent' => 'blue', 'delta' => 0, 'dir' => 'flat', 'compare' => 'out today', 'spark' => $spark(max($onLeaveTodayCount, 1))],
            ['label' => 'On Probation', 'value' => $probation, 'icon' => 'academic-cap', 'accent' => 'amber', 'delta' => 0, 'dir' => 'flat', 'compare' => 'under review', 'spark' => $spark(max($probation, 1))],
            ['label' => 'Open Approvals', 'value' => $totalPending, 'icon' => 'inbox-stack', 'accent' => 'red', 'delta' => 0, 'dir' => 'flat', 'compare' => $pendingLeavesCount.' leave · '.$pendingOtCount.' OT', 'spark' => $spark(max($totalPending, 1))],
            ['label' => 'Open PIP', 'value' => $onPipCount, 'icon' => 'chart-bar', 'accent' => 'red', 'delta' => 0, 'dir' => 'flat', 'compare' => $activeWarnings.' active warnings', 'spark' => $spark(max($onPipCount, 1))],
            ['label' => 'New Hires', 'value' => $newEmployeesCount, 'icon' => 'user-plus', 'accent' => 'green', 'delta' => 0, 'dir' => 'flat', 'compare' => 'this month', 'spark' => $spark(max($newEmployeesCount, 1))],
            ['label' => 'Compliance', 'value' => $complianceScore.'%', 'icon' => 'shield-check', 'accent' => 'green', 'delta' => 0, 'dir' => 'flat', 'compare' => 'docs + reviews', 'spark' => $spark($complianceScore, 0.08)],
        ];

        // ── Chart styling helpers ──
        $axisStyle = ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '12px', 'fontFamily' => 'Inter']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false]];
        $gridStyle = ['borderColor' => '#F3E8DD', 'strokeDashArray' => 5, 'padding' => ['left' => 6, 'right' => 6]];

        // Attendance analytics (last 6 months, from $attendanceTrend)
        $attChart = [
            'chart' => ['type' => 'area', 'height' => 300, 'toolbar' => ['show' => false], 'fontFamily' => 'Inter', 'animations' => ['enabled' => true, 'speed' => 800]],
            'colors' => ['#F97316'],
            'dataLabels' => ['enabled' => false],
            'stroke' => ['curve' => 'smooth', 'width' => 3],
            'fill' => ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.35, 'opacityTo' => 0.0, 'stops' => [0, 90, 100]]],
            'grid' => $gridStyle,
            'xaxis' => array_merge(['categories' => $attendanceTrend->pluck('month')->all()], $axisStyle),
            'yaxis' => ['max' => 100, 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '12px']]],
            'tooltip' => ['theme' => 'light'],
            'series' => [['name' => 'Attendance %', 'data' => $attendanceTrend->pluck('rate')->map(fn ($v) => (float) $v)->all()]],
        ];

        // Compliance radial gauge
        $gauge = [
            'chart' => ['type' => 'radialBar', 'height' => 280, 'fontFamily' => 'Inter'],
            'colors' => ['#F97316'],
            'plotOptions' => ['radialBar' => [
                'hollow' => ['size' => '64%'],
                'track' => ['background' => '#F3E8DD'],
                'dataLabels' => [
                    'name' => ['show' => true, 'color' => '#6B7280', 'fontSize' => '13px', 'offsetY' => 22],
                    'value' => ['show' => true, 'color' => '#F97316', 'fontSize' => '34px', 'fontWeight' => 800, 'offsetY' => -8],
                ],
            ]],
            'fill' => ['type' => 'gradient', 'gradient' => ['shade' => 'light', 'type' => 'horizontal', 'gradientToColors' => ['#FB923C'], 'stops' => [0, 100]]],
            'stroke' => ['lineCap' => 'round'],
            'labels' => ['HR Health'],
            'series' => [$complianceScore],
        ];

        // Department distribution donut
        $deptDist = $workforceComposition->sortByDesc('count')->take(7)->values();
        $donut = [
            'chart' => ['type' => 'donut', 'height' => 280, 'fontFamily' => 'Inter'],
            'labels' => $deptDist->pluck('name')->all(),
            'series' => $deptDist->pluck('count')->map(fn ($c) => (int) $c)->all(),
            'colors' => ['#F97316', '#FB923C', '#FDBA74', '#3B82F6', '#22C55E', '#8B5CF6', '#F59E0B'],
            'dataLabels' => ['enabled' => false],
            'legend' => ['position' => 'bottom', 'fontFamily' => 'Inter', 'labels' => ['colors' => '#6B7280'], 'markers' => ['radius' => 12]],
            'stroke' => ['width' => 0],
            'plotOptions' => ['pie' => ['donut' => ['size' => '72%', 'labels' => ['show' => true, 'total' => ['show' => true, 'label' => 'Total', 'color' => '#6B7280']]]]],
        ];

        // Bottom mini metrics
        $hexBy = ['orange' => '#F97316', 'blue' => '#3B82F6', 'amber' => '#F59E0B', 'green' => '#22C55E', 'red' => '#EF4444'];
        $bottomMetrics = [
            ['label' => 'Attendance %', 'value' => $attendancePercent.'%', 'accent' => 'orange', 'series' => $spark(max($attendancePercent, 1), 0.1)],
            ['label' => 'Leave Trend', 'value' => $onLeaveTodayCount, 'accent' => 'blue', 'series' => $spark(max($onLeaveTodayCount, 1))],
            ['label' => 'OT Trend', 'value' => $pendingOtCount, 'accent' => 'amber', 'series' => $spark(max($pendingOtCount, 1))],
            ['label' => 'Performance', 'value' => $readiness, 'accent' => 'green', 'series' => $spark($readiness, 0.1)],
            ['label' => 'Payroll', 'value' => $activePayrolls->count(), 'accent' => 'red', 'series' => $spark(max($activePayrolls->count(), 1))],
            ['label' => 'Headcount', 'value' => $totalActive, 'accent' => 'orange', 'series' => $spark(max($totalActive, 1), 0.2)],
        ];
    @endphp

    {{-- ══ HERO BANNER ══ --}}
    <div class="dash-rise relative overflow-hidden rounded-2xl border border-[#F3E8DD] dark:border-white/10 bg-gradient-to-r from-[#FFF8F1] via-white to-[#FFF2E8] dark:from-[#0F172A] dark:via-[#111827] dark:to-[#1E293B] p-6 shadow-sm md:p-7">
        <div class="pointer-events-none absolute -right-10 -top-16 size-64 rounded-full bg-orange-500/10 blur-3xl"></div>
        <div class="relative flex flex-col items-start justify-between gap-6 lg:flex-row lg:items-center">
            <div>
                <div class="mb-2 inline-flex items-center gap-2 rounded-full border border-[#F3E8DD] dark:border-white/10 bg-white dark:bg-zinc-900 px-3 py-1 text-[11px] font-semibold text-[#6B7280] dark:text-zinc-400">
                    <flux:icon.sun class="size-3.5 text-orange-500" /> {{ now()->format('l, d M Y') }} · {{ $scope }} Overview
                </div>
                <h1 class="text-[28px] font-extrabold tracking-tight text-[#111827] dark:text-white">{{ $greeting }}, {{ $firstName }} 👋</h1>
                <p class="mt-1 text-sm text-[#6B7280] dark:text-zinc-400">Everything looks healthy today — {{ $totalPending }} approvals need your attention.</p>
            </div>
            <div class="flex items-center gap-3">
                @foreach([['Readiness', $readiness.'%', 'shield-check'], ['Present', $presentPct.'%', 'check-circle']] as [$l, $v, $i])
                    <div class="flex items-center gap-3 rounded-2xl border border-white/70 bg-white/70 dark:border-white/10 dark:bg-white/5 px-4 py-3 shadow-sm backdrop-blur">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-400 text-white"><flux:icon :name="$i" class="size-5" /></div>
                        <div><div class="text-lg font-extrabold leading-none text-[#111827] dark:text-white" x-data="countUp(@js($v))" x-text="display">{{ $v }}</div><div class="text-[11px] font-medium text-[#6B7280] dark:text-zinc-400">{{ $l }}</div></div>
                    </div>
                @endforeach
                <a href="{{ $r('employees.create') }}" wire:navigate class="hidden items-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-orange-400 px-4 py-3 text-sm font-semibold text-white shadow-md shadow-orange-500/25 transition hover:shadow-lg sm:inline-flex">
                    <flux:icon.plus class="size-4" /> Add Employee
                </a>
            </div>
        </div>
    </div>

    {{-- ══ KPI GRID (8) ══ --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        @foreach($kpis as $i => $k)
            <x-dashboard.kpi-card class="dash-rise" style="animation-delay: {{ $i * 55 }}ms"
                :label="$k['label']" :value="$k['value']" :icon="$k['icon']" :accent="$k['accent']"
                :delta="$k['delta']" :dir="$k['dir']" :compare="$k['compare']" :spark="$k['spark']" />
        @endforeach
    </div>

    {{-- ══ ATTENDANCE ANALYTICS (8) + COMPLIANCE GAUGE (4) ══ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <div class="dash-rise rounded-2xl border border-[#F3E8DD] dark:border-white/10 bg-white dark:bg-zinc-900 p-6 shadow-sm transition hover:shadow-lg lg:col-span-8">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold tracking-tight text-[#111827] dark:text-white">Attendance Analytics</h3>
                    <p class="text-sm text-[#6B7280] dark:text-zinc-400">Monthly attendance rate · last 6 months</p>
                </div>
                <span class="rounded-full bg-orange-50 px-3 py-1 text-xs font-bold text-orange-500">{{ $attendancePercent }}% today</span>
            </div>
            <x-dashboard.chart :options="$attChart" />
        </div>
        <div class="dash-rise flex flex-col rounded-2xl border border-[#F3E8DD] dark:border-white/10 bg-white dark:bg-zinc-900 p-6 shadow-sm transition hover:shadow-lg lg:col-span-4">
            <h3 class="text-lg font-bold tracking-tight text-[#111827] dark:text-white">Compliance Score</h3>
            <p class="text-sm text-[#6B7280] dark:text-zinc-400">HR health &amp; risk meter</p>
            <x-dashboard.chart :options="$gauge" class="-mt-1" />
            <div class="mt-auto grid grid-cols-2 gap-3">
                <div class="rounded-xl bg-green-50 p-3 text-center"><div class="text-base font-extrabold text-green-600">{{ $complianceScore >= 80 ? 'Low' : ($complianceScore >= 60 ? 'Medium' : 'High') }}</div><div class="text-[11px] font-medium text-[#6B7280] dark:text-zinc-400">Risk level</div></div>
                <div class="rounded-xl bg-orange-50 p-3 text-center"><div class="text-base font-extrabold text-orange-600">{{ $complianceScore >= 80 ? 'Excellent' : 'Watch' }}</div><div class="text-[11px] font-medium text-[#6B7280] dark:text-zinc-400">HR health</div></div>
            </div>
        </div>
    </div>

    {{-- ══ APPROVAL CENTER (8) + AI INSIGHTS / EVENTS (4) ══ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <div class="dash-rise lg:col-span-8">
            <livewire:approval-center />
        </div>
        <div class="dash-rise space-y-6 lg:col-span-4">
            {{-- Pending Approvals breakdown --}}
            <div class="rounded-2xl border border-[#F3E8DD] dark:border-white/10 bg-white dark:bg-zinc-900 p-6 shadow-sm">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-base font-bold tracking-tight text-[#111827] dark:text-white">Pending Approvals</h3>
                    <span class="rounded-full bg-orange-50 px-2.5 py-0.5 text-[11px] font-bold text-orange-500">{{ $totalPending }} total</span>
                </div>
                <div class="space-y-2">
                    @foreach($pendingApprovals as $pa)
                        <a href="{{ $pa['href'] }}" wire:navigate class="flex items-center justify-between rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 px-3 py-2.5 transition hover:border-orange-200 hover:bg-[#FFF2E8]/60">
                            <span class="text-sm font-medium text-[#374151] dark:text-zinc-300">{{ $pa['label'] }}</span>
                            <span class="text-sm font-extrabold {{ $pa['count'] > 0 ? 'text-orange-500' : 'text-[#D8CCBE] dark:text-zinc-600' }}">{{ $pa['count'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- AI Insights --}}
            <div class="overflow-hidden rounded-2xl border border-orange-200/70 bg-gradient-to-br from-[#FFF8F1] to-white p-6 shadow-sm">
                <div class="mb-3 flex items-center gap-2">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-gradient-to-br from-orange-500 to-orange-400 text-white"><flux:icon.sparkles class="size-4" /></span>
                    <h3 class="text-base font-bold tracking-tight text-[#111827] dark:text-white">AI Insights</h3>
                    <span class="ml-auto rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-bold text-orange-600">{{ count($insights) }}</span>
                </div>
                <div class="space-y-2.5">
                    @foreach(array_slice($insights, 0, 4) as $in)
                        <div class="flex items-start gap-3 rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-white dark:bg-zinc-900 p-3">
                            <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg {{ $toneSoft[$in['tone']] }}"><flux:icon :name="$in['icon']" class="size-4" /></span>
                            <div class="min-w-0"><div class="text-[13px] font-bold text-[#111827] dark:text-white">{{ $in['title'] }}</div><p class="text-[11px] leading-relaxed text-[#6B7280] dark:text-zinc-400">{{ $in['text'] }}</p></div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Upcoming Events --}}
            <div class="rounded-2xl border border-[#F3E8DD] dark:border-white/10 bg-white dark:bg-zinc-900 p-6 shadow-sm">
                <h3 class="mb-4 text-base font-bold tracking-tight text-[#111827] dark:text-white">Upcoming Events</h3>
                <div class="space-y-3">
                    @forelse($upcomingBirthdays->take(4) as $b)
                        <div class="flex items-center gap-3 rounded-xl p-2 transition hover:bg-[#FFF2E8]/60">
                            <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-[11px] font-bold text-orange-600">{{ \Illuminate\Support\Str::of($b['name'])->explode(' ')->take(2)->map(fn ($p) => $p[0] ?? '')->implode('') }}</div>
                            <div class="min-w-0 flex-1"><div class="truncate text-sm font-semibold text-[#111827] dark:text-white">{{ $b['name'] }}</div><div class="truncate text-[11px] text-[#6B7280] dark:text-zinc-400">🎂 {{ $b['dept'] ?: 'Birthday' }}</div></div>
                            <span class="shrink-0 rounded-lg bg-[#FFFDF8] dark:bg-white/5 px-2 py-1 text-[11px] font-bold text-orange-500">{{ $b['is_today'] ? 'Today' : 'in '.$b['days'].'d' }}</span>
                        </div>
                    @empty
                        <p class="py-4 text-center text-sm text-[#9CA3AF] dark:text-zinc-500">No birthdays in the next 30 days.</p>
                    @endforelse
                    <div class="flex items-center gap-3 rounded-xl p-2">
                        <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-500"><flux:icon.banknotes class="size-[18px]" /></div>
                        <div class="min-w-0 flex-1"><div class="text-sm font-semibold text-[#111827] dark:text-white">Payroll run</div><div class="text-[11px] text-[#6B7280] dark:text-zinc-400">Month-end cycle</div></div>
                        <span class="shrink-0 rounded-lg bg-[#FFFDF8] dark:bg-white/5 px-2 py-1 text-[11px] font-bold text-red-500">{{ now()->endOfMonth()->format('d M') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ══ DEPARTMENT DISTRIBUTION (4) + ATTENDANCE HEATMAP (8) ══ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <div class="dash-rise rounded-2xl border border-[#F3E8DD] dark:border-white/10 bg-white dark:bg-zinc-900 p-6 shadow-sm transition hover:shadow-lg lg:col-span-4">
            <h3 class="mb-1 text-lg font-bold tracking-tight text-[#111827] dark:text-white">Department Distribution</h3>
            <p class="text-sm text-[#6B7280] dark:text-zinc-400">Active employees by team</p>
            @if($deptDist->isNotEmpty())<x-dashboard.chart :options="$donut" />@else<p class="py-16 text-center text-sm text-[#9CA3AF] dark:text-zinc-500">No department data.</p>@endif
        </div>

        <div class="dash-rise overflow-hidden rounded-2xl border border-[#F3E8DD] dark:border-white/10 bg-white dark:bg-zinc-900 p-6 shadow-sm lg:col-span-8">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-lg font-bold tracking-tight text-[#111827] dark:text-white">Team Attendance Heatmap</h3>
                <div class="flex flex-wrap items-center gap-3 text-[11px] font-semibold text-[#6B7280] dark:text-zinc-400">
                    @foreach(['Present' => 'bg-green-500', 'Late' => 'bg-amber-500', 'Absent' => 'bg-red-400', 'Off' => 'bg-[#EFE4D7] dark:bg-white/10'] as $l => $c)
                        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded {{ $c }}"></span>{{ $l }}</span>
                    @endforeach
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="text-[11px] font-bold uppercase tracking-wide text-[#9CA3AF] dark:text-zinc-500">
                            <th class="pb-3 pr-4 text-left">Employee</th>
                            @foreach($days as $d)
                                <th class="px-2 pb-3 text-center {{ $d->isWeekend() ? 'text-[#D8CCBE] dark:text-zinc-600' : '' }}"><div>{{ $d->format('D') }}</div><div class="text-[10px] font-medium {{ $d->isToday() ? 'text-orange-500' : '' }}">{{ $d->format('j') }}</div></th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($heatmapData->take(8) as $row)
                            <tr class="group">
                                <td class="py-1.5 pr-4">
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex size-7 items-center justify-center rounded-full bg-[#FFFDF8] dark:bg-white/5 text-[10px] font-bold text-orange-500 ring-1 ring-[#F3E8DD] dark:ring-white/10">{{ $row['initials'] }}</span>
                                        <span class="truncate text-xs font-semibold text-[#111827] dark:text-white">{{ $row['name'] }}</span>
                                    </div>
                                </td>
                                @foreach($row['days'] as $st)
                                    <td class="px-2 py-1.5 text-center">
                                        <div @class([
                                            'mx-auto size-6 rounded-md transition-transform duration-200 group-hover:scale-110',
                                            'bg-green-500' => $st === 'present',
                                            'bg-amber-500' => $st === 'late',
                                            'border border-red-200 bg-red-400' => $st === 'absent',
                                            'bg-[#EFE4D7] dark:bg-white/10' => $st === 'off',
                                            'bg-white ring-2 ring-orange-400' => $st === 'today',
                                            'bg-[#FBF5EE] dark:bg-white/5' => $st === 'future',
                                        ])></div>
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-8 text-center text-sm text-[#9CA3AF] dark:text-zinc-500">No attendance data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ══ RECENT ACTIVITY (7) + COMPLIANCE ALERTS (5) ══ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <div class="dash-rise rounded-2xl border border-[#F3E8DD] dark:border-white/10 bg-white dark:bg-zinc-900 p-6 shadow-sm lg:col-span-7">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-lg font-bold tracking-tight text-[#111827] dark:text-white">Recent Activity</h3>
                <a href="{{ $r('notifications.index') }}" wire:navigate class="text-xs font-bold text-orange-500 transition hover:text-orange-600">View all →</a>
            </div>
            <div class="max-h-[320px] space-y-3 overflow-y-auto pr-1">
                @forelse($recentAuditLogs->take(8) as $log)
                    @php $dot = ['created' => 'bg-green-500', 'updated' => 'bg-blue-500', 'deleted' => 'bg-red-500'][$log->action] ?? 'bg-orange-500'; @endphp
                    <div class="flex items-center gap-3 rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-3">
                        <span class="size-2.5 shrink-0 rounded-full {{ $dot }}"></span>
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-white text-[10px] font-bold text-orange-500 dark:bg-zinc-800 ring-1 ring-[#F3E8DD] dark:ring-white/10">{{ \Illuminate\Support\Str::of($log->user?->name ?? 'System')->explode(' ')->take(2)->map(fn ($p) => $p[0] ?? '')->implode('') }}</span>
                        <p class="min-w-0 flex-1 truncate text-sm text-[#6B7280] dark:text-zinc-400"><span class="font-semibold text-[#111827] dark:text-white">{{ $log->user?->name ?? 'System' }}</span> {{ $log->display_action }}</p>
                        <span class="shrink-0 text-[11px] font-medium text-[#9CA3AF] dark:text-zinc-500">{{ $log->created_at?->diffForHumans(null, true) }}</span>
                    </div>
                @empty
                    <p class="py-8 text-center text-sm text-[#9CA3AF] dark:text-zinc-500">No recent activity.</p>
                @endforelse
            </div>
        </div>

        <div class="dash-rise space-y-6 lg:col-span-5">
            <div class="rounded-2xl border border-[#F3E8DD] dark:border-white/10 bg-white dark:bg-zinc-900 p-6 shadow-sm">
                <h3 class="mb-4 text-lg font-bold tracking-tight text-[#111827] dark:text-white">Compliance Alerts</h3>
                <div class="grid grid-cols-2 gap-3">
                    @foreach($complianceAlerts as $al)
                        @php $t = $tone($al['tone']); @endphp
                        <a href="{{ $al['href'] }}" wire:navigate class="rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-4 transition hover:-translate-y-0.5 hover:shadow-md">
                            <div class="flex items-center justify-between">
                                <span class="rounded-lg {{ $toneSoft[$t] }} px-2 py-0.5 text-[10px] font-bold uppercase">{{ $al['status'] }}</span>
                                <span class="text-2xl font-extrabold {{ $al['count'] > 0 ? $toneText[$t] : 'text-[#D8CCBE] dark:text-zinc-600' }}">{{ $al['count'] }}</span>
                            </div>
                            <div class="mt-2 text-xs font-semibold leading-tight text-[#6B7280] dark:text-zinc-400">{{ $al['label'] }}</div>
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- Quick Actions --}}
            <div class="rounded-2xl border border-[#F3E8DD] dark:border-white/10 bg-white dark:bg-zinc-900 p-6 shadow-sm">
                <h3 class="mb-4 text-lg font-bold tracking-tight text-[#111827] dark:text-white">Quick Actions</h3>
                <div class="grid grid-cols-2 gap-3">
                    @foreach([
                        ['Add Employee', 'user-plus', 'employees.create', 'green'],
                        ['Run Payroll', 'banknotes', 'payroll.process', 'blue'],
                        ['Leave Requests', 'calendar-days', 'time-off.employees', 'orange'],
                        ['Attendance', 'clock', 'attendance.employees', 'amber'],
                    ] as [$l, $i, $route, $t])
                        <a href="{{ $r($route) }}" wire:navigate class="group flex items-center gap-3 rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-3 transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-md">
                            <div class="flex size-9 items-center justify-center rounded-lg {{ $toneSoft[$t] }} transition group-hover:scale-110"><flux:icon :name="$i" class="size-[18px]" /></div>
                            <span class="text-xs font-semibold text-[#111827] dark:text-white">{{ $l }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- ══ BOTTOM METRICS — 6 mini area charts ══ --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-6">
        @foreach($bottomMetrics as $m)
            @php
                $mini = [
                    'chart' => ['type' => 'area', 'height' => 54, 'sparkline' => ['enabled' => true], 'animations' => ['enabled' => true, 'speed' => 700]],
                    'colors' => [$hexBy[$m['accent']] ?? '#F97316'],
                    'stroke' => ['curve' => 'smooth', 'width' => 2],
                    'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.35, 'opacityTo' => 0.0, 'stops' => [0, 100]]],
                    'tooltip' => ['enabled' => false],
                    'series' => [['name' => $m['label'], 'data' => array_map('floatval', $m['series'])]],
                ];
            @endphp
            <div class="dash-rise rounded-2xl border border-[#F3E8DD] dark:border-white/10 bg-white dark:bg-zinc-900 p-4 shadow-sm transition hover:-translate-y-1 hover:shadow-lg">
                <div class="text-[11px] font-semibold uppercase tracking-wide text-[#9CA3AF] dark:text-zinc-500">{{ $m['label'] }}</div>
                <div class="mt-1 text-xl font-extrabold text-[#111827] dark:text-white" x-data="countUp(@js((string) $m['value']))" x-text="display">{{ $m['value'] }}</div>
                <x-dashboard.chart :options="$mini" class="mt-1" />
            </div>
        @endforeach
    </div>
</flux:main>
@endif
