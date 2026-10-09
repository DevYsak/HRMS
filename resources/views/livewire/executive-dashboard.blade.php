<flux:main>
    <div class="mx-auto w-full max-w-[1400px] space-y-5">

    @php
        $r = fn ($n) => \Illuminate\Support\Facades\Route::has($n) ? route($n) : '#';
        $u = auth()->user();
        $firstName = \Illuminate\Support\Str::of($u->name)->explode(' ')->first();
        $roleLabel = $u->role?->label() ?? 'Director';
        $hour = now()->hour;
        $greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
        $today = now();

        $toneSoft = ['orange' => 'bg-orange-50 text-orange-500', 'green' => 'bg-green-50 text-green-600', 'amber' => 'bg-amber-50 text-amber-600', 'blue' => 'bg-blue-50 text-blue-600', 'red' => 'bg-red-50 text-red-600', 'violet' => 'bg-violet-50 text-violet-600'];
        $toneText = ['orange' => 'text-orange-500', 'green' => 'text-green-600', 'amber' => 'text-amber-600', 'blue' => 'text-blue-600', 'red' => 'text-red-600', 'violet' => 'text-violet-600'];

        $spark = function (float $end, float $swing = 0.16) {
            $pts = [];
            $base = max($end, 1);
            for ($i = 6; $i >= 0; $i--) { $pts[] = round($base * (1 - ($i / 6) * $swing + (($i % 2) ? -0.04 : 0.03)), 1); }
            $pts[6] = $end;
            return $pts;
        };

        // ── Executive metrics ──
        // Payroll & Payslips switched off: no payroll figures or actions here.
        $payrollOn = app(\App\Services\ModuleFeatureService::class)->payrollEnabled();
        $payrollCost = (float) (($cycleAPayroll?->total_payout ?? 0) + ($cycleBPayroll?->total_payout ?? 0));
        $payrollPerEmp = $activeCount > 0 ? $payrollCost / $activeCount : 0;
        $openApprovals = $pendingLeaves + $pendingOt;
        $pendingCompliance = $expiringDocs + $probationDue + $activeWarnings + $overdueOnboarding;
        $perfScore = $avgRating ? (int) round($avgRating / 5 * 100) : (int) round(($perfTrend->last()['avg'] ?? 0));
        $perfScore = max(0, min(100, $perfScore));

        $fmtMoney = fn ($v) => $v >= 10000000 ? '₹'.round($v / 10000000, 2).'Cr' : ($v >= 100000 ? '₹'.round($v / 100000, 2).'L' : '₹'.number_format($v));

        // FY progress (Apr–Mar)
        $fyStartYear = $today->month >= 4 ? $today->year : $today->year - 1;
        $fyStart = \Carbon\Carbon::create($fyStartYear, 4, 1)->startOfDay();
        $fyEnd = \Carbon\Carbon::create($fyStartYear + 1, 3, 31)->endOfDay();
        $fyProgress = (int) round($fyStart->diffInDays($today) / max($fyStart->diffInDays($fyEnd), 1) * 100);
        $fyLabel = 'FY '.$fyStartYear.'-'.substr((string) ($fyStartYear + 1), 2);

        // ── 6-month analytics ──
        $cats = []; $growth = []; $hiring = []; $payrollSeries = []; $attrition = []; $attTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->subMonths($i);
            $me = $m->copy()->endOfMonth();
            $cats[] = $m->format('M');
            $growth[] = \App\Models\Employee::whereDate('joining_date', '<=', $me)->whereIn('status', ['active', 'probation', 'onboarding', 'resigned', 'terminated'])->count();
            $hiring[] = \App\Models\Employee::whereYear('joining_date', $m->year)->whereMonth('joining_date', $m->month)->count();
            $payrollSeries[] = round((float) \App\Models\Payroll::whereYear('created_at', $m->year)->whereMonth('created_at', $m->month)->sum('total_payout') / 100000, 2);
            $attrition[] = \App\Models\ExitRecord::whereYear('last_working_day', $m->year)->whereMonth('last_working_day', $m->month)->count();
            $present = \App\Models\Attendance::whereYear('date', $m->year)->whereMonth('date', $m->month)->whereNotNull('check_in')->count();
            $wd = max((int) $m->copy()->startOfMonth()->diffInDaysFiltered(fn ($d) => ! app(\App\Services\Attendance\WorkingDayResolver::class)->isWeeklyOff($d), $me), 1);
            $attTrend[] = $activeCount > 0 ? min(100, (int) round($present / ($activeCount * $wd) * 100)) : 0;
        }
        $growthPct = ($growth[0] ?? 0) > 0 ? round((end($growth) - $growth[0]) / $growth[0] * 100) : 0;

        // Department ranking
        $deptRanked = $deptHealth->sortByDesc('health')->values();
        $topTeams = $deptRanked->take(3);
        $lowTeams = $deptRanked->reverse()->take(3)->values();

        // Recent activity + AI insights
        $activity = \App\Models\AuditLog::with('user')->latest('id')->take(7)->get()->map(function ($log) {
            $model = strtolower(\Illuminate\Support\Str::afterLast((string) $log->auditable_type, '\\'));
            $log->display_action = match ($log->action) { 'created' => "created new {$model}", 'updated' => "updated {$model} details", 'deleted' => "removed a {$model}", default => (string) $log->action };
            return $log;
        });
        $aiInsights = [];
        if ($attritionRate >= 8) { $aiInsights[] = ['tone' => 'red', 'icon' => 'arrow-trending-down', 'title' => 'Attrition risk', 'text' => 'Attrition at '.$attritionRate.'% — review retention in low-health teams.']; }
        if ($pendingCompliance >= 5) { $aiInsights[] = ['tone' => 'amber', 'icon' => 'shield-exclamation', 'title' => 'Compliance load', 'text' => $pendingCompliance.' compliance items open across docs, probation & discipline.']; }
        if ($attendancePercent < 80) { $aiInsights[] = ['tone' => 'amber', 'icon' => 'clock', 'title' => 'Attendance dip', 'text' => 'Company attendance at '.$attendancePercent.'% today — below the 80% target.']; }
        if ($perfScore >= 80) { $aiInsights[] = ['tone' => 'green', 'icon' => 'sparkles', 'title' => 'Strong performance', 'text' => 'Overall performance index at '.$perfScore.' — momentum is healthy.']; }
        if (empty($aiInsights)) { $aiInsights[] = ['tone' => 'green', 'icon' => 'check-circle', 'title' => 'All clear', 'text' => 'No critical executive risks detected. Operations are healthy.']; }

        // ── KPI cards (8) ──
        $kpis = [
            ['label' => 'Total Employees', 'value' => $activeCount, 'icon' => 'users', 'accent' => 'orange', 'delta' => $growthPct, 'dir' => $growthPct >= 0 ? 'up' : 'down', 'compare' => '6-month growth', 'spark' => $spark(max($activeCount, 1))],
            ['label' => 'Company Attendance', 'value' => $attendancePercent.'%', 'icon' => 'check-circle', 'accent' => 'green', 'delta' => 0, 'dir' => 'flat', 'compare' => 'present today', 'spark' => $spark(max($attendancePercent, 1), 0.1)],
            ...($payrollOn ? [['label' => 'Payroll Cost', 'value' => $fmtMoney($payrollCost), 'icon' => 'banknotes', 'accent' => 'blue', 'delta' => 0, 'dir' => 'flat', 'compare' => 'this month', 'spark' => $spark(max($payrollCost / 100000, 1))]] : []),
            ...($payrollOn ? [['label' => 'Payroll / FTE', 'value' => $fmtMoney($payrollPerEmp), 'icon' => 'wallet', 'accent' => 'violet', 'delta' => 0, 'dir' => 'flat', 'compare' => 'avg per employee', 'spark' => $spark(max($payrollPerEmp / 1000, 1))]] : []),
            ['label' => 'Open Approvals', 'value' => $openApprovals, 'icon' => 'inbox-stack', 'accent' => 'amber', 'delta' => 0, 'dir' => 'flat', 'compare' => $pendingLeaves.' leave · '.$pendingOt.' OT', 'spark' => $spark(max($openApprovals, 1))],
            ['label' => 'Pending Compliance', 'value' => $pendingCompliance, 'icon' => 'shield-check', 'accent' => 'red', 'delta' => 0, 'dir' => 'flat', 'compare' => 'docs + reviews', 'spark' => $spark(max($pendingCompliance, 1))],
            ['label' => 'Performance', 'value' => $perfScore, 'icon' => 'chart-bar', 'accent' => 'orange', 'delta' => 0, 'dir' => 'flat', 'compare' => 'overall score', 'spark' => $spark(max($perfScore, 1), 0.1)],
        ];

        // ── Chart configs ──
        $axisStyle = ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '12px', 'fontFamily' => 'inherit']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false]];
        $gridStyle = ['borderColor' => '#F3E8DD', 'strokeDashArray' => 5, 'padding' => ['left' => 6, 'right' => 6]];
        $areaFill = ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.35, 'opacityTo' => 0.0, 'stops' => [0, 90, 100]]];

        $mkArea = fn ($name, $data, $color) => [
            'chart' => ['type' => 'area', 'height' => 260, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'speed' => 800]],
            'colors' => [$color], 'dataLabels' => ['enabled' => false], 'stroke' => ['curve' => 'smooth', 'width' => 3], 'fill' => $areaFill,
            'grid' => $gridStyle, 'xaxis' => array_merge(['categories' => $cats], $axisStyle), 'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '11px']]],
            'tooltip' => ['theme' => 'light'], 'series' => [['name' => $name, 'data' => $data]],
        ];
        $mkBar = fn ($name, $cats2, $data, $color, $to) => [
            'chart' => ['type' => 'bar', 'height' => 260, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'speed' => 800]],
            'colors' => [$color], 'plotOptions' => ['bar' => ['borderRadius' => 7, 'columnWidth' => '50%']], 'dataLabels' => ['enabled' => false],
            'fill' => ['type' => 'gradient', 'gradient' => ['shade' => 'light', 'type' => 'vertical', 'gradientToColors' => [$to], 'opacityFrom' => 1, 'opacityTo' => 0.85]],
            'grid' => $gridStyle, 'xaxis' => array_merge(['categories' => $cats2], $axisStyle), 'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '11px']]],
            'tooltip' => ['theme' => 'light'], 'series' => [['name' => $name, 'data' => $data]],
        ];
        $mkLine = fn ($name, $data, $color) => [
            'chart' => ['type' => 'line', 'height' => 260, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'speed' => 900]],
            'colors' => [$color], 'stroke' => ['curve' => 'smooth', 'width' => 4], 'dataLabels' => ['enabled' => false],
            'markers' => ['size' => 4, 'colors' => ['#fff'], 'strokeColors' => $color, 'strokeWidth' => 3, 'hover' => ['size' => 6]],
            'grid' => $gridStyle, 'xaxis' => array_merge(['categories' => $cats], $axisStyle), 'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '11px']]],
            'tooltip' => ['theme' => 'light'], 'series' => [['name' => $name, 'data' => $data]],
        ];

        $growthChart = $mkLine('Headcount', $growth, '#3B82F6');
        $attChart = $mkArea('Attendance %', $attTrend, '#F97316');
        $deptBar = $mkBar('Health %', $deptHealth->pluck('name')->all(), $deptHealth->pluck('health')->all(), '#F97316', '#FDBA74');
        $payrollChart = $mkArea('₹ Lakh', $payrollSeries, '#22C55E');
        $hiringChart = $mkBar('Joiners', $cats, $hiring, '#22C55E', '#86EFAC');
        $attritionChart = $mkLine('Exits', $attrition, '#EF4444');
        $perfBar = $mkBar('Avg score', $perfTrend->pluck('name')->all(), $perfTrend->pluck('avg')->all(), '#8B5CF6', '#C4B5FD');

        $deptDonut = [
            'chart' => ['type' => 'donut', 'height' => 260, 'fontFamily' => 'inherit'],
            'labels' => $deptHealth->pluck('name')->all(), 'series' => $deptHealth->pluck('headcount')->map(fn ($c) => (int) $c)->all(),
            'colors' => ['#F97316', '#FB923C', '#FDBA74', '#3B82F6', '#22C55E', '#8B5CF6', '#F59E0B'], 'dataLabels' => ['enabled' => false],
            'legend' => ['position' => 'bottom', 'fontFamily' => 'inherit', 'labels' => ['colors' => '#6B7280'], 'markers' => ['radius' => 12]], 'stroke' => ['width' => 0],
            'plotOptions' => ['pie' => ['donut' => ['size' => '72%', 'labels' => ['show' => true, 'total' => ['show' => true, 'label' => 'Total', 'color' => '#6B7280']]]]],
        ];

    @endphp

    {{-- ══ HEADER — same component as every other dashboard ══ --}}
    <x-pulse.dashboard-header title="Executive View" subtitle="Executive Summary · company overview, exceptions and strategic status at a glance.">
        <x-slot:actions>
            <a href="{{ $r('attendance.reports') }}" wire:navigate class="inline-flex h-9 items-center gap-2 rounded-lg bg-brand-600 px-3.5 text-sm font-semibold text-white transition hover:bg-brand-700"><flux:icon.document-chart-bar class="size-4" /> Company Report</a>
            @if($payrollOn)
                <a href="{{ $r('payroll.finance-approve') }}" wire:navigate class="inline-flex h-9 items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3.5 text-sm font-semibold text-zinc-600 transition hover:text-brand-600 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-300"><flux:icon.banknotes class="size-4" /> Approve Payroll</a>
            @endif
            <a href="{{ $r('performance.dashboard') }}" wire:navigate class="inline-flex h-9 items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3.5 text-sm font-semibold text-zinc-600 transition hover:text-brand-600 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-300"><flux:icon.chart-bar class="size-4" /> View Performance</a>
        </x-slot:actions>
        <div class="w-full max-w-xs">
            <div class="flex items-center justify-between text-xs font-semibold text-zinc-500"><span>{{ $fyLabel }}</span><span class="text-brand-600">{{ $fyProgress }}%</span></div>
            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full bg-brand-500" style="width: {{ $fyProgress }}%"></div></div>
        </div>
    </x-pulse.dashboard-header>

    {{-- ══ KPI GRID (8) ══ --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        @foreach($kpis as $i => $k)
            <x-dashboard.kpi-card class="dash-rise" style="animation-delay: {{ $i * 55 }}ms"
                :label="$k['label']" :value="$k['value']" :icon="$k['icon']" :accent="$k['accent']"
                :delta="$k['delta']" :dir="$k['dir']" :compare="$k['compare']" :spark="$k['spark']" />
        @endforeach
    </div>

    {{-- ══ ANALYTICS — Company Growth + Attendance ══ --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <x-dashboard.hr.section-card class="dash-rise" title="Company Growth" subtitle="Headcount · last 6 months" icon="arrow-trending-up" accent="blue"><x-dashboard.chart :options="$growthChart" /></x-dashboard.hr.section-card>
        <x-dashboard.hr.section-card class="dash-rise" title="Attendance Trend" subtitle="Company attendance %" icon="chart-bar"><x-dashboard.chart :options="$attChart" /></x-dashboard.hr.section-card>
    </div>

    {{-- ══ ANALYTICS — Department / Payroll / Attrition / Hiring ══ --}}
    <div class="grid grid-cols-1 gap-5 {{ $payrollOn ? 'lg:grid-cols-2' : 'lg:grid-cols-1' }}">
        <x-dashboard.hr.section-card class="dash-rise" title="Department Performance" subtitle="Attendance health by team" icon="building-office-2" accent="orange">
            @if($deptHealth->isNotEmpty())<x-dashboard.chart :options="$deptBar" />@else<p class="py-16 text-center text-sm text-[#9CA3AF] dark:text-zinc-500">No data.</p>@endif
        </x-dashboard.hr.section-card>
        @if($payrollOn)
        <x-dashboard.hr.section-card class="dash-rise" title="Payroll Analytics" subtitle="Monthly payout (₹ Lakh)" icon="banknotes" accent="green"><x-dashboard.chart :options="$payrollChart" /></x-dashboard.hr.section-card>
        @endif
    </div>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <x-dashboard.hr.section-card class="dash-rise" title="Hiring Trend" subtitle="Joiners / month" icon="user-plus" accent="green"><x-dashboard.chart :options="$hiringChart" /></x-dashboard.hr.section-card>
        <x-dashboard.hr.section-card class="dash-rise" title="Attrition Analysis" subtitle="Exits / month" icon="arrow-trending-down" accent="red"><x-dashboard.chart :options="$attritionChart" /></x-dashboard.hr.section-card>
        <x-dashboard.hr.section-card class="dash-rise" title="Quarterly Performance" subtitle="Avg score by cycle" icon="trophy" accent="violet">
            @if($perfTrend->isNotEmpty())<x-dashboard.chart :options="$perfBar" />@else<p class="py-16 text-center text-sm text-[#9CA3AF] dark:text-zinc-500">No performance cycles yet.</p>@endif
        </x-dashboard.hr.section-card>
    </div>

    {{-- ══ EXECUTIVE WIDGETS — Dept Ranking + Risk/AI ══ --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-12">
        <x-dashboard.hr.section-card class="dash-rise lg:col-span-6" title="Department Ranking" subtitle="By attendance health" icon="trophy" accent="amber">
            <div class="space-y-2.5">
                @forelse($deptRanked as $idx => $d)
                    <div class="flex items-center gap-3 rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-2.5">
                        <span class="flex size-7 items-center justify-center rounded-lg text-[11px] font-extrabold {{ $idx === 0 ? 'bg-orange-500 text-white' : 'bg-orange-50 text-orange-600' }}">{{ $idx + 1 }}</span>
                        <div class="min-w-0 flex-1"><div class="truncate text-sm font-semibold text-[#111827] dark:text-white">{{ $d['name'] }}</div><div class="text-[10px] text-[#6B7280] dark:text-zinc-400">{{ $d['headcount'] }} employees</div></div>
                        <div class="w-24"><div class="mb-0.5 flex justify-end text-[11px] font-bold {{ $d['health'] >= 75 ? 'text-green-600' : ($d['health'] >= 50 ? 'text-amber-600' : 'text-red-600') }}">{{ $d['health'] }}%</div><div class="h-1.5 overflow-hidden rounded-full bg-[#F3E8DD] dark:bg-white/10"><div class="h-full rounded-full {{ $d['health'] >= 75 ? 'bg-green-500' : ($d['health'] >= 50 ? 'bg-amber-500' : 'bg-red-500') }}" style="width: {{ $d['health'] }}%"></div></div></div>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-[#9CA3AF] dark:text-zinc-500">No department data.</p>
                @endforelse
            </div>
        </x-dashboard.hr.section-card>

        <div class="dash-rise space-y-6 lg:col-span-6">
            <x-dashboard.hr.section-card title="Risk Indicators" subtitle="Watch list" icon="exclamation-triangle" accent="red">
                <div class="grid grid-cols-2 gap-3">
                    @foreach([['Attrition', $attritionRate.'%', 'arrow-trending-down', $attritionRate >= 8 ? 'red' : 'green'], ['Active Warnings', $activeWarnings, 'exclamation-triangle', $activeWarnings > 0 ? 'amber' : 'green'], ['On PIP', $onPip, 'chart-bar', $onPip > 0 ? 'amber' : 'green'], ['Expiring Docs', $expiringDocs, 'document-text', $expiringDocs > 0 ? 'red' : 'green']] as [$l, $v, $i, $t])
                        <div class="rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-3">
                            <div class="flex items-center justify-between"><span class="flex size-8 items-center justify-center rounded-lg {{ $toneSoft[$t] }}"><flux:icon :name="$i" class="size-4" /></span><span class="text-xl font-extrabold {{ $toneText[$t] }}">{{ $v }}</span></div>
                            <div class="mt-1.5 text-[11px] font-semibold text-[#6B7280] dark:text-zinc-400">{{ $l }}</div>
                        </div>
                    @endforeach
                </div>
            </x-dashboard.hr.section-card>

            <x-dashboard.hr.section-card title="AI Insights" subtitle="Top executive signals" icon="sparkles">
                <div class="space-y-2.5">
                    @foreach(array_slice($aiInsights, 0, 3) as $in)
                        <div class="flex items-start gap-3 rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-3">
                            <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg {{ $toneSoft[$in['tone']] }}"><flux:icon :name="$in['icon']" class="size-4" /></span>
                            <div class="min-w-0"><div class="text-[13px] font-bold text-[#111827] dark:text-white">{{ $in['title'] }}</div><p class="text-[11px] leading-relaxed text-[#6B7280] dark:text-zinc-400">{{ $in['text'] }}</p></div>
                        </div>
                    @endforeach
                </div>
            </x-dashboard.hr.section-card>
        </div>
    </div>

    {{-- ══ APPROVAL CENTER + DEPARTMENT DISTRIBUTION ══ --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-12">
        <div class="dash-rise lg:col-span-8"><livewire:approval-center /></div>
        <x-dashboard.hr.section-card class="dash-rise lg:col-span-4" title="Headcount Split" subtitle="By department" icon="chart-pie" accent="blue">
            @if($deptHealth->isNotEmpty())<x-dashboard.chart :options="$deptDonut" />@else<p class="py-16 text-center text-sm text-[#9CA3AF] dark:text-zinc-500">No data.</p>@endif
        </x-dashboard.hr.section-card>
    </div>

    {{-- ══ RECENT ACTIVITY + QUICK ACTIONS ══ --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-12">
        <x-dashboard.hr.section-card class="dash-rise lg:col-span-7" title="Recent Activities" subtitle="Latest changes and alerts" icon="bell-alert">
            <x-slot:actions><a href="{{ $r('notifications.index') }}" wire:navigate class="text-xs font-bold text-orange-500 transition hover:text-orange-600">View all →</a></x-slot:actions>
            <div class="max-h-[320px] space-y-3 overflow-y-auto pr-1">
                @forelse($activity as $log)
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
        </x-dashboard.hr.section-card>

        <x-dashboard.hr.section-card class="dash-rise lg:col-span-5" title="Quick Actions" subtitle="Executive shortcuts" icon="bolt">
            <div class="grid grid-cols-2 gap-3">
                @foreach([
                    ['Generate Report', 'document-chart-bar', 'attendance.reports', 'orange'],
                    $payrollOn ? ['Approve Payroll', 'banknotes', 'payroll.finance-approve', 'green'] : ['Documents', 'document-text', 'documents.index', 'green'],
                    ['View Performance', 'chart-bar', 'performance.dashboard', 'violet'],
                    ['Employee Directory', 'users', 'employees.index', 'blue'],
                    ['All Approvals', 'inbox-stack', 'time-off.employees', 'amber'],
                    ['Documents', 'document-text', 'documents.index', 'red'],
                ] as [$l, $i, $route, $t])
                    <a href="{{ $r($route) }}" wire:navigate class="group flex items-center gap-3 rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-3 transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-md">
                        <div class="flex size-9 items-center justify-center rounded-lg {{ $toneSoft[$t] }} transition group-hover:scale-110"><flux:icon :name="$i" class="size-[18px]" /></div>
                        <span class="text-xs font-semibold text-[#111827] dark:text-white">{{ $l }}</span>
                    </a>
                @endforeach
            </div>
        </x-dashboard.hr.section-card>
    </div>
    </div>
</flux:main>
