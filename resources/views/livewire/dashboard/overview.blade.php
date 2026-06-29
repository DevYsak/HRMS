<div class="space-y-6">
    @php
        $r = fn ($n) => \Illuminate\Support\Facades\Route::has($n) ? route($n) : '#';
        $toneText = ['orange' => 'text-orange-500', 'green' => 'text-green-500', 'amber' => 'text-amber-500', 'blue' => 'text-blue-500', 'red' => 'text-red-500'];
        $toneSoft = ['orange' => 'bg-orange-50 text-orange-500', 'green' => 'bg-green-50 text-green-500', 'amber' => 'bg-amber-50 text-amber-500', 'blue' => 'bg-blue-50 text-blue-500', 'red' => 'bg-red-50 text-red-500'];
    @endphp

    {{-- ══ BREADCRUMB + QUICK ACTIONS ══ --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-2 text-sm">
            <span class="font-medium text-[#9CA3AF]">Workspace</span>
            <flux:icon.chevron-right class="size-3.5 text-[#D1C7BD]" />
            <span class="font-semibold text-[#1F2937]">Dashboard</span>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ $r('employees.create') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-orange-400 px-4 py-2.5 text-sm font-semibold text-white shadow-md shadow-orange-500/25 transition hover:shadow-lg hover:shadow-orange-500/30">
                <flux:icon.plus class="size-4" /> Add Employee
            </a>
            @foreach(['Import' => 'arrow-down-tray', 'Export' => 'arrow-up-tray', 'Generate Report' => 'document-chart-bar'] as $label => $icon)
                <button class="inline-flex items-center gap-2 rounded-xl border border-[#F1E7DD] bg-white px-4 py-2.5 text-sm font-semibold text-[#6B7280] transition hover:bg-[#FFF1E5] hover:text-orange-500">
                    <flux:icon :name="$icon" class="size-4" /> {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- ══ WELCOME BANNER ══ --}}
    <div class="relative overflow-hidden rounded-2xl border border-[#F1E7DD] bg-gradient-to-r from-[#FFF8F2] via-white to-[#FFF1E5] p-7 shadow-sm">
        <div class="pointer-events-none absolute -right-10 -top-16 size-64 rounded-full bg-orange-500/10 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-20 right-40 size-52 rounded-full bg-orange-400/10 blur-3xl"></div>
        <div class="relative flex flex-col items-start justify-between gap-6 lg:flex-row lg:items-center">
            <div>
                <div class="mb-2 inline-flex items-center gap-2 rounded-full border border-[#F1E7DD] bg-white px-3 py-1 text-[11px] font-semibold text-[#6B7280]">
                    <flux:icon.sun class="size-3.5 text-orange-500" /> {{ now()->format('l, d M Y') }} · 28°C Sunny
                </div>
                <h1 class="text-[28px] font-extrabold tracking-tight text-[#1F2937]">{{ $greeting }}, {{ $firstName }} 👋</h1>
                <p class="mt-1 text-sm text-[#6B7280]">Everything looks healthy today — {{ $openApprovals }} approvals need your attention.</p>
            </div>

            {{-- Floating glass KPIs --}}
            <div class="flex items-center gap-3">
                @foreach([['Readiness', $complianceScore.'%', 'shield-check'], ['Present', $attendance['present'] ? array_sum($attendance['present']) > 0 ? end($attendance['present']) : 0 : 0, 'check-circle']] as [$l, $v, $i])
                    <div class="flex items-center gap-3 rounded-2xl border border-white/60 bg-white/70 px-4 py-3 shadow-sm backdrop-blur">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-400 text-white">
                            <flux:icon :name="$i" class="size-5" />
                        </div>
                        <div>
                            <div class="text-lg font-extrabold leading-none text-[#1F2937]">{{ $v }}</div>
                            <div class="text-[11px] font-medium text-[#6B7280]">{{ $l }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ══ KPI GRID (8) ══ --}}
    <div class="grid grid-cols-2 gap-6 lg:grid-cols-4">
        @foreach($kpis as $k)
            <x-dashboard.kpi-card class="dash-rise" style="animation-delay: {{ $loop->index * 55 }}ms"
                :label="$k['label']" :value="$k['value']" :icon="$k['icon']" :accent="$k['accent']"
                :delta="$k['delta']" :dir="$k['dir']" :compare="$k['compare']" :spark="$k['spark']" />
        @endforeach
    </div>

    {{-- ══ ROW 1 — Attendance Analytics (8) + Compliance Gauge (4) ══ --}}
    @php
        $attChart = [
            'chart' => ['type' => 'area', 'height' => 320, 'toolbar' => ['show' => false], 'fontFamily' => 'Inter', 'animations' => ['enabled' => true, 'speed' => 800]],
            'colors' => ['#F97316', '#F59E0B', '#EF4444'],
            'dataLabels' => ['enabled' => false],
            'stroke' => ['curve' => 'smooth', 'width' => 3],
            'fill' => ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.3, 'opacityTo' => 0.0, 'stops' => [0, 90, 100]]],
            'grid' => ['borderColor' => '#F1E7DD', 'strokeDashArray' => 5, 'xaxis' => ['lines' => ['show' => false]], 'padding' => ['left' => 8, 'right' => 8]],
            'xaxis' => ['categories' => $attendance['cats'], 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '12px']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false]],
            'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '12px']]],
            'legend' => ['show' => true, 'position' => 'top', 'horizontalAlign' => 'right', 'fontFamily' => 'Inter', 'labels' => ['colors' => '#6B7280'], 'markers' => ['radius' => 12]],
            'tooltip' => ['theme' => 'light'],
            'series' => [
                ['name' => 'Present', 'data' => $attendance['present']],
                ['name' => 'Late', 'data' => $attendance['late']],
                ['name' => 'Absent', 'data' => $attendance['absent']],
            ],
        ];
        $gauge = [
            'chart' => ['type' => 'radialBar', 'height' => 290, 'fontFamily' => 'Inter'],
            'colors' => ['#F97316'],
            'plotOptions' => ['radialBar' => [
                'hollow' => ['size' => '64%'],
                'track' => ['background' => '#F1E7DD', 'strokeWidth' => '100%'],
                'dataLabels' => [
                    'name' => ['show' => true, 'color' => '#6B7280', 'fontSize' => '13px', 'fontFamily' => 'Inter', 'offsetY' => 24],
                    'value' => ['show' => true, 'color' => '#1F2937', 'fontSize' => '36px', 'fontWeight' => 800, 'fontFamily' => 'Inter', 'offsetY' => -10],
                ],
            ]],
            'fill' => ['type' => 'gradient', 'gradient' => ['shade' => 'light', 'type' => 'horizontal', 'gradientToColors' => ['#FB923C'], 'stops' => [0, 100]]],
            'stroke' => ['lineCap' => 'round'],
            'labels' => ['HR Health'],
            'series' => [$complianceScore],
        ];
    @endphp
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <div class="rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm transition hover:shadow-lg lg:col-span-8"
             x-data="{
                 period: 'weekly',
                 sets: @js($attendanceSets),
                 apply(p) {
                     this.period = p;
                     const s = this.sets[p];
                     window.dispatchEvent(new CustomEvent('apex-update', { detail: {
                         id: 'attendance',
                         categories: s.cats,
                         series: [
                             { name: 'Present', data: s.present },
                             { name: 'Late', data: s.late },
                             { name: 'Absent', data: s.absent },
                         ],
                     }}));
                 },
             }">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h3 class="text-[20px] font-bold tracking-tight text-[#1F2937]">Attendance Analytics</h3>
                    <p class="text-sm text-[#6B7280]">Present · Late · Absent
                        <span x-text="{ weekly: '— last 7 days', monthly: '— last 4 weeks', yearly: '— last 6 months' }[period]"></span>
                    </p>
                </div>
                <div class="flex items-center gap-1 rounded-xl border border-[#F1E7DD] bg-[#FFFDF8] p-1 text-xs font-semibold">
                    @foreach(['weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $k => $f)
                        <button type="button" @click="apply('{{ $k }}')"
                            class="cursor-pointer rounded-lg px-3 py-1.5 transition"
                            :class="period === '{{ $k }}' ? 'bg-white text-orange-500 shadow-sm' : 'text-[#6B7280] hover:text-[#1F2937]'">{{ $f }}</button>
                    @endforeach
                </div>
            </div>
            <x-dashboard.chart id="attendance" :options="$attChart" />
        </div>

        <div class="flex flex-col rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm transition hover:shadow-lg lg:col-span-4">
            <h3 class="text-[20px] font-bold tracking-tight text-[#1F2937]">Compliance Score</h3>
            <p class="text-sm text-[#6B7280]">HR health &amp; risk meter</p>
            <x-dashboard.chart :options="$gauge" class="-mt-2" />
            <div class="mt-auto grid grid-cols-2 gap-3">
                <div class="rounded-xl bg-green-50 p-3 text-center">
                    <div class="text-lg font-extrabold text-green-600">{{ $complianceScore >= 80 ? 'Low' : ($complianceScore >= 60 ? 'Medium' : 'High') }}</div>
                    <div class="text-[11px] font-medium text-[#6B7280]">Risk level</div>
                </div>
                <div class="rounded-xl bg-orange-50 p-3 text-center">
                    <div class="text-lg font-extrabold text-orange-600">{{ $complianceScore >= 80 ? 'Excellent' : 'Watch' }}</div>
                    <div class="text-[11px] font-medium text-[#6B7280]">HR health</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ══ MAIN ANALYTICS — executive trend charts ══ --}}
    @php
        $axisStyle = ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '12px', 'fontFamily' => 'Inter']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false]];
        $gridStyle = ['borderColor' => '#F1E7DD', 'strokeDashArray' => 5, 'padding' => ['left' => 4, 'right' => 4]];

        $deptBar = [
            'chart' => ['type' => 'bar', 'height' => 280, 'toolbar' => ['show' => false], 'fontFamily' => 'Inter', 'animations' => ['enabled' => true, 'speed' => 800]],
            'colors' => ['#F97316'],
            'plotOptions' => ['bar' => ['borderRadius' => 8, 'columnWidth' => '45%', 'distributed' => false, 'dataLabels' => ['position' => 'top']]],
            'dataLabels' => ['enabled' => false],
            'fill' => ['type' => 'gradient', 'gradient' => ['shade' => 'light', 'type' => 'vertical', 'gradientToColors' => ['#FDBA74'], 'opacityFrom' => 1, 'opacityTo' => 0.85, 'stops' => [0, 100]]],
            'grid' => $gridStyle,
            'xaxis' => array_merge(['categories' => $deptPerformance->pluck('name')->all()], $axisStyle),
            'yaxis' => ['max' => 100, 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '12px'], 'formatter' => null]],
            'tooltip' => ['theme' => 'light', 'y' => ['title' => ['formatter' => null]]],
            'series' => [['name' => 'Attendance %', 'data' => $deptPerformance->pluck('rate')->all()]],
        ];

        $growthLine = [
            'chart' => ['type' => 'line', 'height' => 280, 'toolbar' => ['show' => false], 'fontFamily' => 'Inter', 'animations' => ['enabled' => true, 'speed' => 900]],
            'colors' => ['#3B82F6'],
            'stroke' => ['curve' => 'smooth', 'width' => 4],
            'markers' => ['size' => 5, 'colors' => ['#fff'], 'strokeColors' => '#3B82F6', 'strokeWidth' => 3, 'hover' => ['size' => 7]],
            'dataLabels' => ['enabled' => false],
            'grid' => $gridStyle,
            'xaxis' => array_merge(['categories' => $trends['cats']], $axisStyle),
            'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '12px']]],
            'tooltip' => ['theme' => 'light'],
            'series' => [['name' => 'Headcount', 'data' => $trends['employeeGrowth']]],
        ];

        $payrollArea = [
            'chart' => ['type' => 'area', 'height' => 230, 'toolbar' => ['show' => false], 'fontFamily' => 'Inter', 'animations' => ['enabled' => true, 'speed' => 800]],
            'colors' => ['#22C55E'],
            'stroke' => ['curve' => 'smooth', 'width' => 3],
            'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.35, 'opacityTo' => 0.0, 'stops' => [0, 100]]],
            'dataLabels' => ['enabled' => false],
            'grid' => $gridStyle,
            'xaxis' => array_merge(['categories' => $trends['cats']], $axisStyle),
            'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '11px']]],
            'tooltip' => ['theme' => 'light', 'y' => ['formatter' => null]],
            'series' => [['name' => '₹ Lakh', 'data' => $trends['payroll']]],
        ];

        $hiringCol = [
            'chart' => ['type' => 'bar', 'height' => 230, 'toolbar' => ['show' => false], 'fontFamily' => 'Inter', 'animations' => ['enabled' => true, 'speed' => 800]],
            'colors' => ['#F97316'],
            'plotOptions' => ['bar' => ['borderRadius' => 6, 'columnWidth' => '55%']],
            'dataLabels' => ['enabled' => false],
            'fill' => ['type' => 'gradient', 'gradient' => ['shade' => 'light', 'type' => 'vertical', 'gradientToColors' => ['#FDBA74'], 'opacityFrom' => 1, 'opacityTo' => 0.8]],
            'grid' => $gridStyle,
            'xaxis' => array_merge(['categories' => $trends['cats']], $axisStyle),
            'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '11px']]],
            'tooltip' => ['theme' => 'light'],
            'series' => [['name' => 'Hires', 'data' => $trends['hiring']]],
        ];

        $attritionLine = [
            'chart' => ['type' => 'line', 'height' => 230, 'toolbar' => ['show' => false], 'fontFamily' => 'Inter', 'animations' => ['enabled' => true, 'speed' => 900]],
            'colors' => ['#EF4444'],
            'stroke' => ['curve' => 'smooth', 'width' => 3],
            'markers' => ['size' => 4, 'colors' => ['#fff'], 'strokeColors' => '#EF4444', 'strokeWidth' => 2, 'hover' => ['size' => 6]],
            'dataLabels' => ['enabled' => false],
            'grid' => $gridStyle,
            'xaxis' => array_merge(['categories' => $trends['cats']], $axisStyle),
            'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '11px']]],
            'tooltip' => ['theme' => 'light'],
            'series' => [['name' => 'Exits', 'data' => $trends['attrition']]],
        ];
    @endphp
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm transition hover:shadow-lg">
            <h3 class="text-[20px] font-bold tracking-tight text-[#111827]">Department Performance</h3>
            <p class="mb-2 text-sm text-[#6B7280]">Attendance rate by department · this month</p>
            @if($deptPerformance->isNotEmpty())<x-dashboard.chart :options="$deptBar" />@else<p class="py-16 text-center text-sm text-[#9CA3AF]">No department data.</p>@endif
        </div>
        <div class="rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm transition hover:shadow-lg">
            <h3 class="text-[20px] font-bold tracking-tight text-[#111827]">Employee Growth</h3>
            <p class="mb-2 text-sm text-[#6B7280]">Cumulative headcount · last 6 months</p>
            <x-dashboard.chart :options="$growthLine" />
        </div>
    </div>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm transition hover:shadow-lg">
            <h3 class="text-[17px] font-bold tracking-tight text-[#111827]">Payroll Trend</h3>
            <p class="mb-1 text-xs text-[#6B7280]">Monthly payout (₹ Lakh)</p>
            <x-dashboard.chart :options="$payrollArea" />
        </div>
        <div class="rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm transition hover:shadow-lg">
            <h3 class="text-[17px] font-bold tracking-tight text-[#111827]">Hiring Trend</h3>
            <p class="mb-1 text-xs text-[#6B7280]">New joiners per month</p>
            <x-dashboard.chart :options="$hiringCol" />
        </div>
        <div class="rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm transition hover:shadow-lg">
            <h3 class="text-[17px] font-bold tracking-tight text-[#111827]">Attrition Trend</h3>
            <p class="mb-1 text-xs text-[#6B7280]">Exits per month</p>
            <x-dashboard.chart :options="$attritionLine" />
        </div>
    </div>

    {{-- ══ ROW 2 — Approval Command Center (8) + Upcoming Events (4) ══ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <div class="rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm lg:col-span-8">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h3 class="text-[20px] font-bold tracking-tight text-[#1F2937]">Approval Command Center</h3>
                    <p class="text-sm text-[#6B7280]">Act on requests without leaving the dashboard</p>
                </div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-orange-50 px-3 py-1 text-xs font-bold text-orange-500">{{ $openApprovals }} pending</span>
            </div>
            <div class="max-h-[360px] space-y-3 overflow-y-auto pr-1">
                @forelse($approvals as $a)
                    <div class="flex flex-col gap-3 rounded-xl border border-[#F1E7DD] bg-[#FFFDF8] p-4 transition hover:border-orange-200 hover:bg-[#FFF1E5]/40 sm:flex-row sm:items-center">
                        <div class="flex flex-1 items-center gap-3">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-white text-sm font-bold text-orange-500 shadow-sm ring-1 ring-[#F1E7DD]">
                                {{ \Illuminate\Support\Str::of($a['name'])->explode(' ')->take(2)->map(fn ($p) => $p[0] ?? '')->implode('') }}
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="truncate text-sm font-semibold text-[#1F2937]">{{ $a['name'] }}</span>
                                    <span class="rounded-full {{ $toneSoft[$a['tone']] }} px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide">{{ $a['kind'] }}</span>
                                </div>
                                <div class="mt-0.5 truncate text-xs text-[#6B7280]">{{ $a['detail'] }} · {{ $a['when'] }}</div>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <a href="{{ $a['href'] }}" wire:navigate class="inline-flex items-center gap-1 rounded-lg bg-green-500 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-green-600">
                                <flux:icon.check class="size-3.5" /> Approve
                            </a>
                            <a href="{{ $a['href'] }}" wire:navigate class="inline-flex items-center gap-1 rounded-lg border border-[#F1E7DD] bg-white px-3 py-1.5 text-xs font-bold text-red-500 transition hover:bg-red-50">
                                <flux:icon.x-mark class="size-3.5" /> Reject
                            </a>
                            <a href="{{ $a['href'] }}" wire:navigate class="rounded-lg p-1.5 text-[#9CA3AF] transition hover:bg-white hover:text-orange-500"><flux:icon.arrow-up-right class="size-4" /></a>
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <div class="flex size-12 items-center justify-center rounded-full bg-green-50"><flux:icon.check-badge class="size-6 text-green-500" /></div>
                        <p class="mt-3 text-sm font-medium text-[#6B7280]">All caught up — no pending approvals.</p>
                    </div>
                @endforelse
            </div>
        </div>

        <div class="space-y-6 lg:col-span-4">
            {{-- AI Insights --}}
            <div class="overflow-hidden rounded-2xl border border-orange-200/70 bg-gradient-to-br from-[#FFF8F1] to-white p-6 shadow-sm">
                <div class="mb-3 flex items-center gap-2">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-gradient-to-br from-orange-500 to-orange-400 text-white"><flux:icon.sparkles class="size-4" /></span>
                    <h3 class="text-[17px] font-bold tracking-tight text-[#111827]">AI Insights</h3>
                    <span class="ml-auto rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-bold text-orange-600">{{ count($aiInsights) }}</span>
                </div>
                <div class="space-y-2.5">
                    @foreach(array_slice($aiInsights, 0, 4) as $in)
                        <div class="flex items-start gap-3 rounded-xl border border-[#F3E8DD] bg-white p-3">
                            <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg {{ $toneSoft[$in['tone']] }}"><flux:icon :name="$in['icon']" class="size-4" /></span>
                            <div class="min-w-0">
                                <div class="text-[13px] font-bold text-[#111827]">{{ $in['title'] }}</div>
                                <p class="text-[11px] leading-relaxed text-[#6B7280]">{{ $in['text'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Upcoming Events --}}
            <div class="rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm">
                <h3 class="mb-4 text-[17px] font-bold tracking-tight text-[#111827]">Upcoming Events</h3>
                <div class="space-y-3">
                    @forelse($events as $e)
                        <div class="flex items-center gap-3 rounded-xl p-2.5 transition hover:bg-[#FFF1E5]/50">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl {{ $toneSoft[$e['tone']] }}">
                                <flux:icon :name="$e['icon']" class="size-5" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold text-[#1F2937]">{{ $e['title'] }}</div>
                                <div class="truncate text-[11px] text-[#6B7280]">{{ $e['sub'] }}</div>
                            </div>
                            <span class="shrink-0 rounded-lg bg-[#FFFDF8] px-2 py-1 text-[11px] font-bold {{ $toneText[$e['tone']] }}">{{ $e['meta'] }}</span>
                        </div>
                    @empty
                        <p class="py-8 text-center text-sm text-[#9CA3AF]">No upcoming events.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- ══ ROW 3 — Workforce Analytics (3 cards) ══ --}}
    @php
        $deptDist = $workforce['deptDistribution'];
        $donut = [
            'chart' => ['type' => 'donut', 'height' => 260, 'fontFamily' => 'Inter'],
            'labels' => $deptDist->pluck('name')->all(),
            'series' => $deptDist->pluck('count')->map(fn ($c) => (int) $c)->all(),
            'colors' => ['#F97316', '#FB923C', '#F59E0B', '#3B82F6', '#22C55E', '#8B5CF6'],
            'dataLabels' => ['enabled' => false],
            'legend' => ['position' => 'bottom', 'fontFamily' => 'Inter', 'labels' => ['colors' => '#6B7280'], 'markers' => ['radius' => 12]],
            'stroke' => ['width' => 0],
            'plotOptions' => ['pie' => ['donut' => ['size' => '72%', 'labels' => ['show' => true, 'total' => ['show' => true, 'label' => 'Total', 'color' => '#6B7280', 'fontFamily' => 'Inter']]]]],
        ];
        $genderTotal = max(array_sum($workforce['gender']), 1);
    @endphp
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- A: key workforce stats --}}
        <div class="rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm transition hover:shadow-lg">
            <h3 class="mb-4 text-[20px] font-bold tracking-tight text-[#1F2937]">Workforce Insights</h3>
            <div class="grid grid-cols-2 gap-3">
                @foreach([
                    ['New Joiners', $workforce['newJoiners'], 'user-plus', 'green'],
                    ['Attrition', $workforce['attrition'].'%', 'arrow-trending-down', 'red'],
                    ['Departments', $workforce['departments'], 'building-office-2', 'blue'],
                    ['Avg Experience', $workforce['avgExperience'].'y', 'briefcase', 'orange'],
                ] as [$l, $v, $i, $t])
                    <div class="rounded-xl border border-[#F1E7DD] bg-[#FFFDF8] p-4">
                        <div class="flex size-9 items-center justify-center rounded-lg {{ $toneSoft[$t] }}"><flux:icon :name="$i" class="size-[18px]" /></div>
                        <div class="mt-3 text-2xl font-extrabold text-[#1F2937]">{{ $v }}</div>
                        <div class="text-[11px] font-medium text-[#6B7280]">{{ $l }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- B: department donut --}}
        <div class="rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm transition hover:shadow-lg">
            <h3 class="mb-2 text-[20px] font-bold tracking-tight text-[#1F2937]">Department Distribution</h3>
            @if($deptDist->isNotEmpty())
                <x-dashboard.chart :options="$donut" />
            @else
                <p class="py-16 text-center text-sm text-[#9CA3AF]">No department data.</p>
            @endif
        </div>

        {{-- C: gender + employment type --}}
        <div class="rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm transition hover:shadow-lg">
            <h3 class="mb-4 text-[20px] font-bold tracking-tight text-[#1F2937]">Composition</h3>
            <div class="space-y-4">
                <div>
                    <div class="mb-2 text-[11px] font-bold uppercase tracking-wide text-[#9CA3AF]">Gender</div>
                    <div class="flex h-3 overflow-hidden rounded-full bg-[#F1E7DD]">
                        <div class="bg-orange-500" style="width: {{ round($workforce['gender']['male'] / $genderTotal * 100) }}%"></div>
                        <div class="bg-orange-300" style="width: {{ round($workforce['gender']['female'] / $genderTotal * 100) }}%"></div>
                        <div class="bg-blue-400" style="width: {{ round($workforce['gender']['other'] / $genderTotal * 100) }}%"></div>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] font-medium text-[#6B7280]">
                        <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-orange-500"></span>Male {{ $workforce['gender']['male'] }}</span>
                        <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-orange-300"></span>Female {{ $workforce['gender']['female'] }}</span>
                        <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-blue-400"></span>Other {{ $workforce['gender']['other'] }}</span>
                    </div>
                </div>
                <div>
                    <div class="mb-2 text-[11px] font-bold uppercase tracking-wide text-[#9CA3AF]">Employment Type</div>
                    <div class="space-y-2.5">
                        @php $etMax = max($workforce['employmentTypes']->max('count') ?? 1, 1); @endphp
                        @forelse($workforce['employmentTypes']->take(4) as $et)
                            <div>
                                <div class="mb-1 flex items-center justify-between text-xs">
                                    <span class="font-medium text-[#1F2937]">{{ $et['label'] }}</span>
                                    <span class="font-bold text-[#6B7280]">{{ $et['count'] }}</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-[#F1E7DD]">
                                    <div class="h-full rounded-full bg-gradient-to-r from-orange-500 to-orange-400" style="width: {{ round($et['count'] / $etMax * 100) }}%"></div>
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-[#9CA3AF]">No data.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ══ ROW 4 — Heatmap (8) + Quick Actions (4) ══ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <div class="overflow-hidden rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm lg:col-span-8">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-[20px] font-bold tracking-tight text-[#1F2937]">Team Attendance Heatmap</h3>
                <div class="flex flex-wrap items-center gap-3 text-[11px] font-semibold text-[#6B7280]">
                    @foreach(['Present' => 'bg-green-500', 'Late' => 'bg-amber-500', 'Absent' => 'bg-red-400', 'Weekend' => 'bg-[#E7DDD2]'] as $l => $c)
                        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded {{ $c }}"></span>{{ $l }}</span>
                    @endforeach
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="text-[11px] font-bold uppercase tracking-wide text-[#9CA3AF]">
                            <th class="pb-3 pr-4 text-left">Employee</th>
                            @foreach($days as $d)
                                <th class="px-2 pb-3 text-center {{ $d->isWeekend() ? 'text-[#D1C7BD]' : '' }}">
                                    <div>{{ $d->format('D') }}</div>
                                    <div class="text-[10px] font-medium {{ $d->isToday() ? 'text-orange-500' : '' }}">{{ $d->format('j') }}</div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($heatmap as $row)
                            <tr class="group">
                                <td class="py-1.5 pr-4">
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex size-7 items-center justify-center rounded-full bg-[#FFFDF8] text-[10px] font-bold text-orange-500 ring-1 ring-[#F1E7DD]">{{ $row['initials'] }}</span>
                                        <span class="truncate text-xs font-semibold text-[#1F2937]">{{ $row['name'] }}</span>
                                    </div>
                                </td>
                                @foreach($row['days'] as $st)
                                    <td class="px-2 py-1.5 text-center">
                                        <div @class([
                                            'mx-auto size-6 rounded-md transition-transform duration-200 group-hover:scale-110',
                                            'bg-green-500' => $st === 'present',
                                            'bg-amber-500' => $st === 'late',
                                            'bg-red-400' => $st === 'absent',
                                            'bg-[#E7DDD2]' => $st === 'weekend',
                                            'bg-white ring-2 ring-orange-400' => $st === 'today',
                                            'bg-[#F8F2EB]' => $st === 'future',
                                        ])></div>
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-8 text-center text-sm text-[#9CA3AF]">No attendance data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Quick actions floating cards --}}
        <div class="lg:col-span-4">
            <div class="rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm">
                <h3 class="mb-4 text-[20px] font-bold tracking-tight text-[#1F2937]">Quick Actions</h3>
                <div class="grid grid-cols-1 gap-3">
                    @foreach([
                        ['Apply Leave', 'calendar-days', 'time-off.my', 'orange'],
                        ['Request OT', 'clock', 'overtime.my', 'amber'],
                        ['Add Employee', 'user-plus', 'employees.create', 'green'],
                        ['Generate Payslip', 'banknotes', 'payroll.payslips', 'blue'],
                        ['Attendance Correction', 'pencil-square', 'attendance.my', 'red'],
                    ] as [$l, $i, $route, $t])
                        <a href="{{ $r($route) }}" wire:navigate class="group flex items-center gap-3 rounded-xl border border-[#F1E7DD] bg-[#FFFDF8] p-3.5 transition hover:-translate-y-0.5 hover:border-orange-200 hover:bg-[#FFF1E5]/50 hover:shadow-md">
                            <div class="flex size-10 items-center justify-center rounded-xl {{ $toneSoft[$t] }} transition group-hover:scale-110"><flux:icon :name="$i" class="size-5" /></div>
                            <span class="text-sm font-semibold text-[#1F2937]">{{ $l }}</span>
                            <flux:icon.chevron-right class="ml-auto size-4 text-[#D1C7BD] transition group-hover:text-orange-500" />
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- ══ BOTTOM — Recent Activity (7) + Compliance Alerts (5) ══ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <div class="rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm lg:col-span-7">
            <h3 class="mb-4 text-[20px] font-bold tracking-tight text-[#1F2937]">Recent Activity</h3>
            <div class="relative max-h-[320px] space-y-4 overflow-y-auto pl-2">
                @forelse($activity as $log)
                    @php $dot = ['created' => 'bg-green-500', 'updated' => 'bg-blue-500', 'deleted' => 'bg-red-500'][$log->action] ?? 'bg-orange-500'; @endphp
                    <div class="flex items-start gap-3">
                        <div class="relative mt-1 flex flex-col items-center">
                            <span class="size-2.5 rounded-full {{ $dot }} ring-4 ring-white"></span>
                        </div>
                        <div class="flex flex-1 items-center gap-3 rounded-xl border border-[#F1E7DD] bg-[#FFFDF8] p-3">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-white text-[10px] font-bold text-orange-500 ring-1 ring-[#F1E7DD]">
                                {{ \Illuminate\Support\Str::of($log->user?->name ?? 'System')->explode(' ')->take(2)->map(fn ($p) => $p[0] ?? '')->implode('') }}
                            </span>
                            <p class="min-w-0 flex-1 truncate text-sm text-[#6B7280]">
                                <span class="font-semibold text-[#1F2937]">{{ $log->user?->name ?? 'System' }}</span> {{ $log->display_action }}
                            </p>
                            <span class="shrink-0 text-[11px] font-medium text-[#9CA3AF]">{{ $log->created_at?->diffForHumans(null, true) }}</span>
                        </div>
                    </div>
                @empty
                    <p class="py-8 text-center text-sm text-[#9CA3AF]">No recent activity.</p>
                @endforelse
            </div>
        </div>

        <div class="space-y-6 lg:col-span-5">
            <div class="rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm">
                <h3 class="mb-4 text-[20px] font-bold tracking-tight text-[#111827]">Compliance Alerts</h3>
                <div class="grid grid-cols-2 gap-3">
                    @foreach($alerts as $al)
                        <div class="rounded-xl border border-[#F1E7DD] bg-[#FFFDF8] p-4 transition hover:shadow-md">
                            <div class="flex items-center justify-between">
                                <div class="flex size-9 items-center justify-center rounded-lg {{ $toneSoft[$al['tone']] }}"><flux:icon :name="$al['icon']" class="size-[18px]" /></div>
                                <span class="text-2xl font-extrabold {{ $al['count'] > 0 ? $toneText[$al['tone']] : 'text-[#D1C7BD]' }}">{{ $al['count'] }}</span>
                            </div>
                            <div class="mt-2 text-xs font-semibold text-[#6B7280]">{{ $al['label'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Payroll Status --}}
            <div class="rounded-2xl border border-[#F1E7DD] bg-white p-6 shadow-sm">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-[20px] font-bold tracking-tight text-[#111827]">Payroll Status</h3>
                    <a href="{{ $r('payroll.process') }}" wire:navigate class="text-xs font-bold text-orange-500 transition hover:text-orange-600">Run payroll →</a>
                </div>
                <div class="space-y-3">
                    @foreach($payrollStatus as $ps)
                        <div class="flex items-center justify-between rounded-xl border border-[#F1E7DD] bg-[#FFFDF8] p-4">
                            <div class="flex items-center gap-3">
                                <span class="flex size-9 items-center justify-center rounded-lg {{ $toneSoft[$ps['tone']] }}"><flux:icon.banknotes class="size-[18px]" /></span>
                                <div>
                                    <div class="text-sm font-semibold text-[#111827]">{{ $ps['label'] }}</div>
                                    <div class="text-xs text-[#6B7280]">{{ $ps['amount'] }}</div>
                                </div>
                            </div>
                            <span class="rounded-full {{ $toneSoft[$ps['tone']] }} px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide">{{ $ps['status'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- ══ BOTTOM METRICS — 6 mini area charts ══ --}}
    @php
        $hexBy = ['orange' => '#F97316', 'blue' => '#3B82F6', 'amber' => '#F59E0B', 'green' => '#22C55E', 'red' => '#EF4444'];
    @endphp
    <div class="grid grid-cols-2 gap-6 lg:grid-cols-6">
        @foreach($bottomMetrics as $m)
            @php
                $mini = [
                    'chart' => ['type' => 'area', 'height' => 56, 'sparkline' => ['enabled' => true], 'animations' => ['enabled' => true, 'speed' => 700]],
                    'colors' => [$hexBy[$m['accent']] ?? '#F97316'],
                    'stroke' => ['curve' => 'smooth', 'width' => 2],
                    'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.35, 'opacityTo' => 0.0, 'stops' => [0, 100]]],
                    'tooltip' => ['enabled' => false],
                    'series' => [['name' => $m['label'], 'data' => array_map('floatval', $m['series'])]],
                ];
            @endphp
            <div class="rounded-2xl border border-[#F1E7DD] bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-lg">
                <div class="text-[11px] font-semibold uppercase tracking-wide text-[#9CA3AF]">{{ $m['label'] }}</div>
                <div class="mt-1 text-2xl font-extrabold text-[#1F2937]">{{ $m['value'] }}</div>
                <x-dashboard.chart :options="$mini" class="mt-2" />
            </div>
        @endforeach
    </div>
</div>
