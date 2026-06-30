<flux:main class="min-h-screen space-y-6 bg-[#FBF7F1] p-4 dark:bg-[#0B1220] md:p-6">

    @php
        // ── Today's status label ──────────────────────────────────────────
        $statusLabel = $todayAttendance && $todayAttendance->check_in
            ? ($todayAttendance->check_out ? 'Checked Out' : 'Present')
            : 'Absent';
        $statusTone = $todayAttendance && $todayAttendance->check_in ? 'emerald' : 'rose';

        $latestPayslip = $myPayslips->first();
        $pendingTotal = $pendingLeaveCount + $myOtRequests->where('status', 'pending')->count();

        // ── Quick actions (only routes that exist) ────────────────────────
        $qa = [];
        $add = function ($label, $icon, $name, $params = []) use (&$qa) {
            if (\Illuminate\Support\Facades\Route::has($name)) {
                $qa[] = ['label' => $label, 'icon' => $icon, 'href' => route($name, $params)];
            }
        };
        $add('Clock In/Out', 'clock', 'attendance.my');
        $add('Apply Leave', 'calendar-days', 'time-off.my');
        $add('Log Overtime', 'plus-circle', 'overtime.my');
        $add('My Payslips', 'banknotes', 'payroll.payslips');
        $add('Documents', 'document-text', 'documents.index');
        $add('Performance', 'chart-bar', 'performance.my');
        $add('WFH Request', 'home-modern', 'wfh.my');
        $add('Help Desk', 'lifebuoy', 'notifications.index');

        // ── Chart data ────────────────────────────────────────────────────
        $chartLabels = $dailyHours->pluck('label');
        $chartHours = $dailyHours->pluck('hours');

        $donutSeries = $leaveBalances->map(fn ($b) => max(0, (float) ($b->allocated_days ?? 0) - (float) ($b->used_days ?? 0)))->values();
        $donutLabels = $leaveBalances->map(fn ($b) => $b->leaveType?->name ?? 'Leave')->values();
        $donutHasData = $donutSeries->sum() > 0;

        // ── ApexCharts option arrays (rendered via the shared <x-dashboard.chart> / bundled apexChart helper) ──
        $attChart = [
            'chart' => ['type' => 'area', 'height' => 300, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'easing' => 'easeinout', 'speed' => 800]],
            'colors' => ['#F97316'],
            'dataLabels' => ['enabled' => false],
            'stroke' => ['curve' => 'smooth', 'width' => 3],
            'fill' => ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.4, 'opacityTo' => 0.02, 'stops' => [0, 90]]],
            'grid' => ['borderColor' => '#F1E7DD', 'strokeDashArray' => 4, 'padding' => ['left' => 8, 'right' => 8]],
            'xaxis' => ['categories' => $chartLabels->all(), 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '11px']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false], 'tickAmount' => 10],
            'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '11px']]],
            'tooltip' => ['theme' => 'light'],
            'series' => [['name' => 'Working hours', 'data' => $chartHours->all()]],
        ];

        $leaveDonut = [
            'chart' => ['type' => 'donut', 'height' => 190, 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'speed' => 700]],
            'colors' => ['#F97316', '#10B981', '#6366F1', '#F59E0B', '#06B6D4', '#EC4899'],
            'labels' => $donutLabels->all(),
            'legend' => ['show' => false],
            'dataLabels' => ['enabled' => false],
            'stroke' => ['width' => 0],
            'plotOptions' => ['pie' => ['donut' => ['size' => '74%']]],
            'tooltip' => ['theme' => 'light'],
            'series' => $donutSeries->map(fn ($v) => (float) $v)->all(),
        ];
    @endphp

    {{-- ═══════════ HERO ═══════════ --}}
    <x-employee.hero :employee="$employee" :attendance="$todayAttendance" :shift="$shift"
        :shiftName="$shiftName" :workedMinutes="$workedTodayMinutes" :quote="$quote"
        :designation="$designation" :department="$department" />

    {{-- ═══════════ KPI ROW ═══════════ --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-4 2xl:grid-cols-8">
        <x-employee.kpi-card label="Today" :value="$statusLabel" :tone="$statusTone" icon="check-badge" :sub="$todayAttendance?->check_in?->format('h:i A') ?? 'Not clocked in'" />
        <x-employee.kpi-card label="Attendance" :value="$attendancePct" suffix="%" tone="orange" icon="chart-pie" sub="this month" />
        <x-employee.kpi-card label="Leave Left" :value="(int) $totalLeaveRemaining" tone="amber" icon="calendar-days" :sub="(int) $totalLeaveUsed.' used'" :href="route('time-off.my')" />
        <x-employee.kpi-card label="Late" :value="$lateCount" tone="rose" icon="exclamation-triangle" sub="this month" />
        <x-employee.kpi-card label="Overtime" :value="rtrim(rtrim(number_format($otHours,1),'0'),'.')" suffix="h" tone="violet" icon="clock" sub="this month" />
        <x-employee.kpi-card label="Performance" :value="$performanceScore ?? '—'" tone="blue" icon="star" :sub="$latestCycle?->name ?? 'rating'" />
        <x-employee.kpi-card label="Pending" :value="$pendingTotal" tone="cyan" icon="paper-airplane" sub="requests" />
        <x-employee.kpi-card label="Salary" :value="$latestPayslip ? ucfirst($latestPayslip->status) : '—'" tone="emerald" icon="banknotes" :sub="$latestPayslip?->payroll?->month ?? 'latest'" />
    </div>

    {{-- ═══════════ ATTENDANCE CHART + LEAVE ═══════════ --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <x-employee.section-card class="lg:col-span-2" title="Monthly Attendance Overview" icon="chart-bar" :href="route('attendance.my')" cta="Details">
            @if($chartHours->sum() > 0)
                <x-dashboard.chart :options="$attChart" id="emp-attendance" class="-mb-2 flex-1" />
            @else
                <div class="flex flex-1 flex-col items-center justify-center py-16 text-zinc-400">
                    <flux:icon.chart-bar class="mb-2 size-10 opacity-30" />
                    <p class="text-xs">No attendance recorded this month yet.</p>
                </div>
            @endif
        </x-employee.section-card>

        <x-employee.section-card title="Leave Summary" icon="calendar-days">
            <x-employee.leave-summary :balances="$leaveBalances" :remaining="$totalLeaveRemaining" :allocated="$totalLeaveAllocated" :used="$totalLeaveUsed" :donutOptions="$leaveDonut" :hasData="$donutHasData" />
        </x-employee.section-card>
    </div>

    {{-- ═══════════ TIMELINE + PAYROLL + PERFORMANCE ═══════════ --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <x-employee.section-card title="Today's Timeline" icon="clock">
            <x-employee.timeline :attendance="$todayAttendance" :shift="$shift" :workedMinutes="$workedTodayMinutes" />
        </x-employee.section-card>

        {{-- Payroll --}}
        <x-employee.section-card title="Payroll" icon="banknotes" :href="route('payroll.payslips')">
            @if($latestPayslip)
                <div class="rounded-2xl bg-gradient-to-br from-emerald-50 to-white p-4 dark:from-emerald-900/10 dark:to-zinc-900">
                    <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Net Salary · {{ $latestPayslip->payroll?->month }} {{ $latestPayslip->payroll?->year }}</div>
                    <div class="mt-1 text-3xl font-black text-zinc-900 dark:text-white">₹{{ number_format($latestPayslip->net_salary, 0) }}</div>
                    <span class="mt-2 inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-bold {{ $latestPayslip->status === 'paid' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' }}">
                        {{ strtoupper($latestPayslip->status) }}
                    </span>
                </div>
                <a href="{{ route('payroll.payslips') }}" wire:navigate class="mt-auto inline-flex w-full items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-2 text-sm font-bold text-zinc-700 transition hover:bg-zinc-50 dark:border-white/10 dark:bg-white/5 dark:text-zinc-200">
                    <flux:icon.arrow-down-tray class="size-4" /> Download Payslip
                </a>
            @else
                <div class="flex flex-1 flex-col items-center justify-center py-8 text-zinc-400">
                    <flux:icon.banknotes class="mb-2 size-8 opacity-30" />
                    <p class="text-xs">No payslip generated yet.</p>
                </div>
            @endif
        </x-employee.section-card>

        {{-- Performance --}}
        <x-employee.section-card title="Performance" icon="trophy" :href="route('performance.my')">
            @php $score = is_numeric($performanceScore) ? (float) $performanceScore : null; @endphp
            <div class="flex items-center gap-4">
                <div class="relative grid size-24 place-items-center">
                    <svg class="size-24 -rotate-90" viewBox="0 0 36 36">
                        <circle cx="18" cy="18" r="15.9" fill="none" class="stroke-zinc-100 dark:stroke-zinc-800" stroke-width="3.4"></circle>
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="#f97316" stroke-width="3.4" stroke-linecap="round"
                                stroke-dasharray="{{ $score !== null ? min(100, $score) : 0 }}, 100"></circle>
                    </svg>
                    <div class="absolute text-center">
                        <div class="text-xl font-black text-zinc-900 dark:text-white">{{ $performanceScore ?? '—' }}</div>
                        <div class="text-[9px] font-bold uppercase tracking-wide text-zinc-400">Score</div>
                    </div>
                </div>
                <div class="flex-1 space-y-1.5 text-sm">
                    <div class="flex items-center justify-between"><span class="text-zinc-500">Grade</span><span class="font-bold text-zinc-900 dark:text-white">{{ $performanceGrade ?? '—' }}</span></div>
                    <div class="flex items-center justify-between"><span class="text-zinc-500">Cycle</span><span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $latestCycle?->name ?? '—' }}</span></div>
                </div>
            </div>
            <a href="{{ route('performance.my') }}" wire:navigate class="mt-auto inline-flex w-full items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-2 text-sm font-bold text-zinc-700 transition hover:bg-zinc-50 dark:border-white/10 dark:bg-white/5 dark:text-zinc-200">
                View Details
            </a>
        </x-employee.section-card>
    </div>

    {{-- ═══════════ QUICK ACTIONS + COMPANY FEED ═══════════ --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <x-employee.section-card class="lg:col-span-2" title="Quick Actions" icon="bolt">
            <x-employee.quick-actions :items="$qa" />
        </x-employee.section-card>

        <x-employee.section-card title="Announcements" icon="megaphone" :href="route('notifications.index')">
            <x-employee.company-feed :holidays="$upcomingHolidays" :notifications="$recentNotifications" />
        </x-employee.section-card>
    </div>

    {{-- ═══════════ RECENT ACTIVITY + DOCUMENTS + TEAM ═══════════ --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        {{-- Recent Activity --}}
        <x-employee.section-card title="Recent Activity" icon="sparkles">
            @php
                $activity = collect();
                foreach ($pendingLeaveRequests->take(3) as $lr) {
                    $activity->push(['icon' => 'calendar-days', 'tone' => 'text-violet-600 bg-violet-50 dark:bg-violet-900/20', 'title' => 'Leave request · '.($lr->leaveType?->name ?? 'Leave'), 'time' => $lr->created_at?->diffForHumans()]);
                }
                foreach ($myOtRequests->take(3) as $ot) {
                    $activity->push(['icon' => 'clock', 'tone' => 'text-amber-600 bg-amber-50 dark:bg-amber-900/20', 'title' => 'OT '.ucfirst($ot->status).' · '.$ot->requested_hours.'h', 'time' => $ot->created_at?->diffForHumans()]);
                }
                $activity = $activity->take(6);
            @endphp
            @if($activity->isEmpty())
                <div class="flex flex-1 flex-col items-center justify-center py-8 text-zinc-400"><flux:icon.sparkles class="mb-2 size-8 opacity-30" /><p class="text-xs">No recent activity.</p></div>
            @else
                <div class="space-y-2">
                    @foreach($activity as $a)
                        <div class="flex items-center gap-3 rounded-xl p-2.5 transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg {{ $a['tone'] }}"><flux:icon :name="$a['icon']" class="size-4" /></span>
                            <span class="flex-1 truncate text-sm text-zinc-700 dark:text-zinc-200">{{ $a['title'] }}</span>
                            <span class="shrink-0 text-[10px] text-zinc-400">{{ $a['time'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-employee.section-card>

        {{-- My Documents --}}
        <x-employee.section-card title="My Documents" icon="folder" :href="route('documents.index')">
            @if($myDocuments->isEmpty())
                <div class="flex flex-1 flex-col items-center justify-center py-8 text-zinc-400"><flux:icon.folder class="mb-2 size-8 opacity-30" /><p class="text-xs">No documents yet.</p></div>
            @else
                <div class="space-y-2">
                    @foreach($myDocuments as $doc)
                        <a href="{{ route('documents.index') }}" wire:navigate class="flex items-center gap-3 rounded-xl bg-zinc-50 p-2.5 transition hover:bg-zinc-100 dark:bg-zinc-800/50 dark:hover:bg-zinc-800">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-orange-50 text-orange-600 dark:bg-orange-900/20"><flux:icon.document-text class="size-4" /></span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $doc->title }}</div>
                                <div class="text-[10px] uppercase tracking-wide text-zinc-400">{{ $doc->category ?? 'Document' }}</div>
                            </div>
                            <flux:icon.arrow-down-tray class="size-4 shrink-0 text-zinc-400" />
                        </a>
                    @endforeach
                </div>
            @endif
        </x-employee.section-card>

        {{-- Team --}}
        <x-employee.section-card title="My Team" icon="users">
            <div class="space-y-3">
                <div class="rounded-xl border border-zinc-100 p-3 dark:border-white/5">
                    <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Reporting Manager</div>
                    @if($manager)
                        <div class="mt-2 flex items-center gap-3">
                            <div class="flex size-9 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-amber-400 text-sm font-bold text-white">
                                {{ \Illuminate\Support\Str::of($manager->name)->explode(' ')->map(fn ($n) => mb_substr($n, 0, 1))->take(2)->implode('') }}
                            </div>
                            <div class="min-w-0">
                                <div class="truncate text-sm font-bold text-zinc-900 dark:text-white">{{ $manager->name }}</div>
                                <div class="truncate text-[11px] text-zinc-400">{{ $manager->email }}</div>
                            </div>
                        </div>
                    @else
                        <p class="mt-2 text-xs text-zinc-400">No manager assigned.</p>
                    @endif
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/50">
                        <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Department</div>
                        <div class="mt-1 truncate text-sm font-bold text-zinc-900 dark:text-white">{{ $department ?? '—' }}</div>
                    </div>
                    <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/50">
                        <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Designation</div>
                        <div class="mt-1 truncate text-sm font-bold text-zinc-900 dark:text-white">{{ $designation ?? '—' }}</div>
                    </div>
                </div>
                @if(\Illuminate\Support\Facades\Route::has('employees.org-chart'))
                    <a href="{{ route('employees.org-chart') }}" wire:navigate class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-2 text-sm font-bold text-zinc-700 transition hover:bg-zinc-50 dark:border-white/10 dark:bg-white/5 dark:text-zinc-200">
                        <flux:icon.user-group class="size-4" /> View Org Chart
                    </a>
                @endif
            </div>
        </x-employee.section-card>
    </div>

</flux:main>
