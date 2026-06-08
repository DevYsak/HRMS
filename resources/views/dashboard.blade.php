<flux:main class="min-h-screen space-y-8 bg-zinc-50 p-6 font-['DM_Sans'] dark:bg-zinc-950 lg:p-10">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;700;900&display=swap');

        .admin-card {
            position: relative;
            overflow: hidden;
            border-radius: 20px;
            padding: 24px;
            transition: all 0.3s ease;
        }

        .donut-chart {
            position: relative;
            display: flex;
            height: 140px;
            width: 140px;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .donut-inner {
            z-index: 2;
            display: flex;
            height: 95px;
            width: 95px;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .badge-today {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.15);
        }

        .badge-active {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.15);
        }

        .badge-req {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.15);
        }

        .badge-cycle {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
            border: 1px solid rgba(139, 92, 246, 0.15);
        }

        .status-box {
            height: 16px;
            width: 16px;
            border-radius: 4px;
        }

        .hero-mesh {
            background:
                radial-gradient(circle at top left, rgba(255, 123, 67, 0.18), transparent 30%),
                radial-gradient(circle at top right, rgba(125, 205, 255, 0.28), transparent 34%),
                linear-gradient(135deg, rgba(245, 250, 255, 0.98), rgba(255, 244, 236, 0.96));
        }

        .dark .hero-mesh {
            background:
                radial-gradient(circle at top left, rgba(255, 123, 67, 0.16), transparent 32%),
                radial-gradient(circle at top right, rgba(125, 205, 255, 0.18), transparent 28%),
                linear-gradient(135deg, rgba(24, 24, 27, 0.96), rgba(39, 39, 42, 0.94));
        }

        .mini-stat {
            backdrop-filter: blur(10px);
        }

        .glass-panel {
            backdrop-filter: blur(18px);
        }

        .frost-panel {
            border: 1px solid rgba(255, 255, 255, 0.78);
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.82), rgba(241, 249, 255, 0.72));
            box-shadow: 0 18px 40px rgba(123, 170, 214, 0.10);
        }

        .dark .frost-panel {
            border-color: rgba(63, 63, 70, 0.8);
            background: linear-gradient(180deg, rgba(39, 39, 42, 0.82), rgba(24, 24, 27, 0.74));
            box-shadow: none;
        }

        .orbital-ring {
            position: relative;
            display: flex;
            height: 220px;
            width: 220px;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            box-shadow: 0 30px 60px rgba(15, 23, 42, 0.12);
        }

        .orbital-ring::before {
            position: absolute;
            inset: 20px;
            border-radius: 9999px;
            background: rgba(255, 255, 255, 0.92);
            content: "";
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }

        .dark .orbital-ring::before {
            background: rgba(24, 24, 27, 0.92);
        }

        .orbital-core {
            position: relative;
            z-index: 2;
            text-align: center;
        }

        .signal-dot {
            box-shadow: 0 0 0 6px rgba(255, 255, 255, 0.75);
        }

        .dark .signal-dot {
            box-shadow: 0 0 0 6px rgba(24, 24, 27, 0.82);
        }

        .metric-track {
            overflow: hidden;
            border-radius: 9999px;
            background: rgba(227, 239, 250, 0.78);
        }

        .dark .metric-track {
            background: rgba(39, 39, 42, 0.85);
        }

        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }

        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
    </style>

    @php
        $hour = now()->hour;
        $greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
        $firstName = \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->first();
        $timeContext = $hour < 12 ? 'New day — your HRMS snapshot is ready.' : ($hour < 17 ? 'Afternoon — operations overview below.' : 'End of day — final admin summary.');
        $totalPending = $pendingLeavesCount + $pendingOtCount;
        $readinessScore = max(12, min(96, round(
            ($attendancePercent * 0.55)
            + ((max($totalActive - $totalPending, 0) / max($totalActive, 1)) * 30)
            + ($expiringDocuments->isEmpty() ? 11 : 4)
        )));
        $approvalPressure = min(100, $totalPending * 8);
        $leaveLoad = $totalActive > 0 ? min(100, round(($onLeaveTodayCount / $totalActive) * 100)) : 0;
        $payrollProgress = $activePayrolls->count() > 0 ? 68 : 100;
        $topDepartments = $workforceComposition->sortByDesc('count')->take(3)->values();
        $latestMoments = $recentAuditLogs->take(3);
    @endphp

    <div class="flex flex-col items-start justify-between gap-4 md:flex-row md:items-center">
        <div>
            <div class="flex items-center gap-2 mb-2">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-orange-50 dark:bg-orange-950/30 border border-orange-200 dark:border-orange-800/50 rounded-full text-[10px] font-bold uppercase tracking-wider text-orange-600 dark:text-orange-400">
                    @if($hour < 12)
                        <flux:icon.sun class="size-3" /> Morning
                    @elseif($hour < 17)
                        <flux:icon.sun class="size-3" /> Afternoon
                    @else
                        <flux:icon.moon class="size-3" /> Evening
                    @endif
                </span>
                <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ now()->format('l, d F Y') }}</span>
            </div>
            <h1 class="text-3xl font-black tracking-tight text-zinc-900 dark:text-white">
                {{ $greeting }}, {{ $firstName }}
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $timeContext }}</p>
        </div>
        <div class="flex items-center gap-3">
            <div
                class="rounded-xl border border-zinc-200 bg-white px-4 py-2 text-xs font-bold text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">
                {{ now()->format('D, j M Y') }}
            </div>
            <flux:button variant="primary" icon="plus" size="sm" :href="route('employees.create')"
                class="rounded-xl border-none !bg-brand-600 !font-bold !text-white shadow-lg shadow-orange-500/20 transition-all hover:!bg-brand-600 active:scale-95">
                Add Employee
            </flux:button>
        </div>
    </div>

    <section
        class="hero-mesh relative overflow-hidden rounded-[32px] border border-sky-100/80 p-6 shadow-sm dark:border-zinc-800 lg:p-8">
        <div class="absolute -left-10 top-10 h-32 w-32 rounded-full bg-orange-200/35 blur-3xl"></div>
        <div class="absolute right-10 top-0 h-40 w-40 rounded-full bg-sky-200/45 blur-3xl"></div>
        <div class="absolute bottom-0 right-1/4 h-24 w-24 rounded-full bg-cyan-200/20 blur-2xl"></div>
        <div
            class="absolute inset-y-0 right-[22%] hidden w-px bg-gradient-to-b from-transparent via-sky-200/70 to-transparent xl:block">
        </div>

        <div class="relative grid gap-6 xl:grid-cols-[1.08fr_0.92fr] xl:items-start">
            <div class="space-y-5">
                <div class="flex flex-wrap items-center gap-3">
                    <span
                        class="inline-flex items-center rounded-full border border-orange-200/70 bg-white/85 px-3 py-1 text-[10px] font-black uppercase tracking-[0.24em] text-orange-500 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/60">
                        HRMS Snapshot
                    </span>
                    <span
                        class="inline-flex items-center rounded-full border border-zinc-200/70 bg-white/80 px-3 py-1 text-[10px] font-black uppercase tracking-[0.22em] text-zinc-500 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/60 dark:text-zinc-300">
                        {{ now()->format('l, d M Y') }}
                    </span>
                </div>

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div class="glass-panel frost-panel rounded-2xl p-4 dark:bg-zinc-900/65">
                        <div class="text-[10px] font-black uppercase tracking-[0.24em] text-zinc-400">Active Workforce
                        </div>
                        <div class="mt-2 text-4xl font-black text-zinc-900 dark:text-white">{{ $totalActive }}</div>
                        <div class="mt-2 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                            <span class="signal-dot size-2 rounded-full bg-sky-400"></span>
                            {{ $presentToday }} checked in today
                        </div>
                    </div>
                    <div class="glass-panel frost-panel rounded-2xl p-4 dark:bg-zinc-900/65">
                        <div class="text-[10px] font-black uppercase tracking-[0.24em] text-zinc-400">Approvals Queue
                        </div>
                        <div class="mt-2 text-4xl font-black text-zinc-900 dark:text-white">{{ $totalPending }}</div>
                        <div class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $pendingLeavesCount }} leave, {{ $pendingOtCount }} OT requests
                        </div>
                    </div>
                    <div class="glass-panel frost-panel rounded-2xl p-4 dark:bg-zinc-900/65">
                        <div class="text-[10px] font-black uppercase tracking-[0.24em] text-zinc-400">People Movement
                        </div>
                        <div class="mt-2 text-4xl font-black text-zinc-900 dark:text-white">
                            {{ $onboarding + $probation }}
                        </div>
                        <div class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $onboarding }} onboarding, {{ $probation }} probation
                        </div>
                    </div>
                    <div class="glass-panel frost-panel rounded-2xl p-4 dark:bg-zinc-900/65">
                        <div class="text-[10px] font-black uppercase tracking-[0.24em] text-zinc-400">Compliance Watch
                        </div>
                        <div class="mt-2 text-4xl font-black text-zinc-900 dark:text-white">
                            {{ $expiringDocuments->count() + $upcomingProbations->count() }}
                        </div>
                        <div class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $expiringDocuments->count() }} documents, {{ $upcomingProbations->count() }} reviews due
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 ">
                    <div class="glass-panel frost-panel rounded-3xl p-5 dark:bg-zinc-900/65">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-[10px] font-black uppercase tracking-[0.24em] text-zinc-400">Live
                                    Priorities</div>
                                <div class="mt-2 text-lg font-black text-zinc-900 dark:text-white">Current workload
                                    split</div>
                            </div>
                            <div
                                class="rounded-2xl bg-zinc-950 px-3 py-2 text-lg font-black text-white dark:bg-white dark:text-zinc-950">
                                {{ $actionRequiredCount }}
                            </div>
                        </div>
                        <div class="mt-4 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-2xl bg-gradient-to-br from-sky-50 to-white p-4 dark:bg-zinc-800/60">
                                <div class="text-[10px] font-black uppercase tracking-[0.22em] text-zinc-400">Leaves
                                </div>
                                <div class="mt-2 text-2xl font-black text-zinc-900 dark:text-white">
                                    {{ $pendingLeavesCount }}
                                </div>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Awaiting approval</p>
                            </div>
                            <div class="rounded-2xl bg-gradient-to-br from-orange-50 to-white p-4 dark:bg-zinc-800/60">
                                <div class="text-[10px] font-black uppercase tracking-[0.22em] text-zinc-400">Overtime
                                </div>
                                <div class="mt-2 text-2xl font-black text-zinc-900 dark:text-white">
                                    {{ $pendingOtCount }}
                                </div>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Pending review</p>
                            </div>
                            <div
                                class="rounded-2xl bg-gradient-to-br from-sky-50 via-orange-50/40 to-white p-4 dark:bg-zinc-800/60">
                                <div class="text-[10px] font-black uppercase tracking-[0.22em] text-zinc-400">Payroll
                                </div>
                                <div class="mt-2 text-2xl font-black text-zinc-900 dark:text-white">
                                    {{ $activePayrolls->count() }}
                                </div>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Draft cycles open</p>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="flex flex-wrap gap-3">
                    <flux:button :href="route('time-off.employees')" wire:navigate variant="primary"
                        class="rounded-xl border-none !bg-zinc-950 !text-white shadow-lg shadow-zinc-950/10 dark:!bg-white dark:!text-zinc-950">
                        Review Requests
                    </flux:button>
                    <flux:button :href="route('employees.index')" wire:navigate variant="ghost"
                        class="rounded-xl border border-zinc-200 bg-white/75 !text-zinc-700 hover:!text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900/60 dark:!text-zinc-200">
                        Open People Hub
                    </flux:button>
                    <flux:button :href="route('payroll.overview')" wire:navigate variant="ghost"
                        class="rounded-xl border border-zinc-200 bg-white/75 !text-zinc-700 hover:!text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900/60 dark:!text-zinc-200">
                        Open Payroll
                    </flux:button>
                </div>
            </div>

            <div class="grid gap-4 xl:grid-cols-1">
                <div
                    class="glass-panel rounded-[28px] border border-white/80 bg-white/72 p-5 shadow-[0_20px_50px_rgba(255,255,255,0.2)] dark:border-zinc-700/70 dark:bg-zinc-900/68">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="text-[10px] font-black uppercase tracking-[0.28em] text-zinc-400">Readiness
                                Index</div>
                            <h3 class="mt-2 text-lg font-black text-zinc-900 dark:text-white">Operations at a glance
                            </h3>
                        </div>
                        <span
                            class="rounded-full border border-zinc-200 bg-white/80 px-3 py-1 text-[10px] font-black uppercase tracking-[0.22em] text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/70 dark:text-zinc-300">
                            Live
                        </span>
                    </div>

                    <div
                        class="mt-6 flex flex-col items-center gap-6 lg:flex-row lg:items-center lg:justify-between xl:flex-col">
                        <div class="orbital-ring"
                            style="background: conic-gradient(#ff7a45 0% {{ round($readinessScore * 0.45) }}%, #8fd3ff {{ round($readinessScore * 0.45) }}% {{ $readinessScore }}%, rgba(255,255,255,0.52) {{ $readinessScore }}% 100%);">
                            <div class="orbital-core">
                                <div class="text-[10px] font-black uppercase tracking-[0.28em] text-zinc-400">Score
                                </div>
                                <div class="mt-2 text-5xl font-black tracking-tighter text-zinc-900 dark:text-white">
                                    {{ $readinessScore }}
                                </div>
                                <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Workforce readiness</div>
                            </div>
                        </div>

                        <div class="w-full space-y-4 lg:max-w-[260px] xl:max-w-none">
                            <div class="space-y-2">
                                <div
                                    class="flex items-center justify-between text-[11px] font-bold uppercase tracking-[0.2em] text-zinc-500">
                                    <span>Attendance Energy</span>
                                    <span>{{ $attendancePercent }}%</span>
                                </div>
                                <div class="metric-track h-2.5">
                                    <div class="h-full rounded-full bg-gradient-to-r from-sky-300 via-sky-400 to-cyan-400"
                                        style="width: {{ $attendancePercent }}%"></div>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <div
                                    class="flex items-center justify-between text-[11px] font-bold uppercase tracking-[0.2em] text-zinc-500">
                                    <span>Approval Pressure</span>
                                    <span>{{ $approvalPressure }}%</span>
                                </div>
                                <div class="metric-track h-2.5">
                                    <div class="h-full rounded-full bg-gradient-to-r from-orange-300 via-orange-400 to-red-400"
                                        style="width: {{ $approvalPressure }}%"></div>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <div
                                    class="flex items-center justify-between text-[11px] font-bold uppercase tracking-[0.2em] text-zinc-500">
                                    <span>Leave Load</span>
                                    <span>{{ $leaveLoad }}%</span>
                                </div>
                                <div class="metric-track h-2.5">
                                    <div class="h-full rounded-full bg-gradient-to-r from-orange-200 via-orange-300 to-sky-400"
                                        style="width: {{ $leaveLoad }}%"></div>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <div
                                    class="flex items-center justify-between text-[11px] font-bold uppercase tracking-[0.2em] text-zinc-500">
                                    <span>Payroll Completion</span>
                                    <span>{{ $payrollProgress }}%</span>
                                </div>
                                <div class="metric-track h-2.5">
                                    <div class="h-full rounded-full bg-gradient-to-r from-sky-200 via-sky-300 to-orange-400"
                                        style="width: {{ $payrollProgress }}%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div
            class="admin-card overflow-hidden border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 lg:col-span-2">
            <div class="mb-8 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Team Attendance Heatmap</h3>
                <div class="flex flex-wrap items-center gap-3 text-[10px] font-bold uppercase tracking-widest text-zinc-500">
                    <div class="flex items-center gap-1.5">
                        <div class="size-2.5 rounded-[2px] bg-emerald-500"></div> Present
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="size-2.5 rounded-[2px] bg-amber-500"></div> Late
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="size-2.5 rounded-[2px] border border-zinc-300 bg-zinc-200 dark:border-zinc-600 dark:bg-zinc-700"></div> Absent
                    </div>
                    <div class="flex items-center gap-1.5 opacity-50">
                        <div class="size-2.5 rounded-[2px] border border-dashed border-zinc-300 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800/50"></div> Off (Sat/Sun)
                    </div>
                    <flux:link :href="route('reports.attendance-summary', ['month' => now()->month, 'year' => now()->year])"
                        class="font-bold text-brand-500 !no-underline transition-colors hover:text-brand-600 ml-auto">
                        Full Report →
                    </flux:link>
                </div>
            </div>

            <div class="scrollbar-hide overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-zinc-50 text-[10px] font-bold uppercase tracking-widest text-zinc-400 dark:border-zinc-800/50">
                            <th class="pb-4 pr-6">Employee</th>
                            @foreach($days as $day)
                                @php $isWeekend = $day->isSaturday() || $day->isSunday(); @endphp
                                <th class="px-2 pb-4 text-center {{ $isWeekend ? 'text-zinc-300 dark:text-zinc-600' : '' }}">
                                    <div>{{ $day->format('D') }}</div>
                                    <div class="text-[9px] font-normal {{ $day->isToday() ? 'text-brand-500 font-bold' : '' }}">{{ $day->format('j') }}</div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                        @foreach($heatmapData->take(8) as $row)
                            <tr class="group">
                                <td class="py-3 pr-6">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="flex size-7 shrink-0 items-center justify-center rounded-full border border-zinc-100 bg-zinc-50 text-[10px] font-black text-brand-500 dark:border-zinc-700 dark:bg-zinc-800">
                                            {{ $row['initials'] }}
                                        </div>
                                        <div
                                            class="max-w-[120px] truncate text-xs font-bold text-zinc-700 transition group-hover:text-brand-600 dark:text-zinc-300">
                                            {{ $row['name'] }}
                                        </div>
                                    </div>
                                </td>
                                @foreach($row['days'] as $i => $status)
                                    @php
                                        $day = $days[$i];
                                        $isWeekend = $day->isSaturday() || $day->isSunday();
                                    @endphp
                                    <td class="px-2 py-3 text-center {{ $isWeekend && $status === 'off' ? 'opacity-40' : '' }}">
                                        <div @class([
                                            'status-box mx-auto transition-all duration-300 group-hover:scale-110',
                                            'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.25)]' => $status === 'present',
                                            'bg-amber-500 shadow-[0_0_8px_rgba(245,158,11,0.25)]'   => $status === 'late',
                                            'border border-zinc-300 bg-zinc-200 dark:border-zinc-600 dark:bg-zinc-700' => $status === 'absent',
                                            // Sat/Sun off — diagonal stripes via background
                                            'border border-dashed border-zinc-300 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800/50' => $status === 'off',
                                            // Today — brand ring
                                            'border-2 border-brand-400 bg-brand-50 dark:bg-brand-950/30' => $status === 'today',
                                            // Future — very faint
                                            'border border-zinc-100 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900' => $status === 'future',
                                        ])></div>
                                        @if($status === 'off' && $isWeekend)
                                            <div class="text-[7px] text-zinc-300 dark:text-zinc-600 mt-0.5 leading-none">off</div>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div
            class="admin-card flex flex-col border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-10 flex items-center justify-between">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Workforce Composition</h3>
                <flux:link :href="route('employees.index')" wire:navigate
                    class="text-[10px] font-bold uppercase tracking-widest text-brand-500 !no-underline hover:text-brand-600">
                    Details →
                </flux:link>
            </div>

            <div class="flex flex-1 flex-col items-center justify-center">
                @php
                    $total = $workforceComposition->sum('count');
                    $currentPercentage = 0;
                    $gradientSteps = [];
                    foreach ($workforceComposition as $dept) {
                        $percentage = $total > 0 ? ($dept['count'] / $total) * 100 : 0;
                        $gradientSteps[] = "{$dept['color']} {$currentPercentage}% " . ($currentPercentage + $percentage) . "%";
                        $currentPercentage += $percentage;
                    }
                    $conicGradient = implode(', ', $gradientSteps);
                @endphp

                <div class="donut-chart mb-10" style="background: conic-gradient({{ $conicGradient }})">
                    <div
                        class="donut-inner border border-zinc-100 bg-white shadow-inner dark:border-zinc-800/50 dark:bg-zinc-900">
                        <span
                            class="leading-none tracking-tighter text-3xl font-black text-zinc-900 dark:text-white">{{ $total }}</span>
                        <span
                            class="mt-1 text-[10px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">Total</span>
                    </div>
                </div>

                <div class="w-full space-y-4">
                    @foreach($workforceComposition as $dept)
                        <div class="flex items-center justify-between group">
                            <div class="flex items-center gap-3">
                                <div class="size-2 rounded-sm shadow-sm transition-transform group-hover:scale-125"
                                    style="background: {{ $dept['color'] }}"></div>
                                <span
                                    class="text-xs font-bold text-zinc-600 transition-colors group-hover:text-zinc-900 dark:text-zinc-400 dark:group-hover:text-zinc-200">{{ $dept['name'] }}</span>
                            </div>
                            <span class="text-sm font-black text-zinc-900 dark:text-white">{{ $dept['count'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="admin-card border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-8 flex items-center justify-between">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Recent Activity</h3>
                <flux:link :href="route('notifications.index')" wire:navigate
                    class="text-[10px] font-bold uppercase tracking-widest text-brand-500 !no-underline hover:text-brand-600">
                    View all →
                </flux:link>
            </div>

            <div
                class="relative space-y-6 before:absolute before:bottom-2 before:left-4 before:top-2 before:w-[1px] before:bg-zinc-100 dark:before:bg-zinc-800">
                @foreach($recentAuditLogs->take(4) as $log)
                    <div class="relative z-10 flex gap-4">
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-full border border-zinc-100 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                            <flux:icon.user class="size-4 text-brand-500" />
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs leading-tight text-zinc-700 dark:text-zinc-300">
                                <span
                                    class="cursor-pointer font-black text-zinc-900 transition-colors hover:text-brand-500 dark:text-white">{{ $log->user?->name ?? 'System' }}</span>
                                <span class="font-medium text-zinc-500">{{ $log->display_action }}</span>
                            </p>
                            <p
                                class="mt-1 text-[9px] font-black uppercase tracking-widest text-zinc-400 dark:text-zinc-500">
                                {{ $log->created_at->diffForHumans() }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="admin-card border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-8 flex items-center justify-between">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Pending Requests</h3>
                <span
                    class="rounded-md border border-rose-500/20 bg-rose-500/10 px-2 py-1 text-[8px] font-black uppercase tracking-widest text-rose-500 shadow-sm">
                    {{ $pendingLeavesCount + $pendingOtCount }} Action Required
                </span>
            </div>

            <div class="space-y-3">
                @forelse($pendingLeaveRequests as $leave)
                    <div
                        class="group flex items-center justify-between rounded-xl border border-zinc-100 bg-zinc-50 p-4 transition-colors hover:bg-zinc-100 dark:border-zinc-700/50 dark:bg-zinc-800/50 dark:hover:bg-zinc-800">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex size-9 items-center justify-center rounded-full border border-zinc-100 bg-white text-[10px] font-black text-amber-500 shadow-inner transition-colors group-hover:border-amber-500/30 dark:border-zinc-700/50 dark:bg-zinc-900">
                                {{ collect(explode(' ', $leave->employee->user->name))->map(fn($n) => $n[0])->take(2)->join('') }}
                            </div>
                            <div class="min-w-0">
                                <h4 class="truncate text-xs font-bold text-zinc-900 dark:text-white">
                                    {{ $leave->employee->user->name }}
                                </h4>
                                <p class="mt-0.5 text-[10px] text-zinc-500">{{ $leave->leaveType->name }} ·
                                    {{ \Carbon\Carbon::parse($leave->start_date)->format('d M') }}
                                </p>
                            </div>
                        </div>
                        <a href="{{ route('time-off.employees') }}" wire:navigate
                            class="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-brand-50 dark:bg-brand-950/30 text-brand-600 dark:text-brand-400 text-[10px] font-bold hover:bg-brand-100 transition-colors">
                            Review <flux:icon.chevron-right class="size-3" />
                        </a>
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center py-10">
                        <flux:icon.clipboard class="mb-2 size-8 text-zinc-200 dark:text-zinc-800" />
                        <p class="text-xs font-medium italic text-zinc-400">No pending requests</p>
                    </div>
                @endforelse
            </div>

            <div class="mt-6 border-t border-zinc-50 pt-4 dark:border-zinc-800">
                <flux:link :href="route('time-off.employees')" wire:navigate
                    class="text-[10px] font-bold uppercase tracking-widest text-brand-500 !no-underline hover:text-brand-600">
                    Manage all requests →
                </flux:link>
            </div>
        </div>

        <div class="admin-card border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-8 flex items-center justify-between">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Compliance & Alerts</h3>
                <flux:link :href="route('documents.index')" wire:navigate
                    class="text-[10px] font-bold uppercase tracking-widest text-brand-500 !no-underline hover:text-brand-600">
                    Open tracker →
                </flux:link>
            </div>

            <div class="space-y-4">
                @foreach($complianceAlerts as $alert)
                    <a href="{{ $alert['href'] }}" wire:navigate
                        class="flex items-center justify-between gap-4 rounded-xl border border-transparent px-2 py-1 transition hover:border-zinc-200 hover:bg-zinc-50 dark:hover:border-zinc-800 dark:hover:bg-zinc-800/40">
                        <div class="flex min-w-0 items-center gap-3">
                            <div @class([
                                'size-1.5 rounded-full shadow-sm',
                                'bg-emerald-500' => $alert['tone'] === 'emerald',
                                'bg-amber-500' => $alert['tone'] === 'amber',
                                'bg-rose-500' => $alert['tone'] === 'rose',
                                'bg-blue-500' => $alert['tone'] === 'blue',
                                'bg-violet-500' => $alert['tone'] === 'violet',
                            ])>
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-zinc-600 dark:text-zinc-400">{{ $alert['label'] }}</p>
                                <p class="mt-1 text-[10px] uppercase tracking-widest text-zinc-400">{{ $alert['count'] }}
                                    items</p>
                            </div>
                        </div>
                        <span @class([
                            'shrink-0 rounded border px-2 py-0.5 text-[8px] font-black uppercase tracking-widest shadow-sm',
                            'border-emerald-500/20 bg-emerald-500/10 text-emerald-500' => $alert['tone'] === 'emerald',
                            'border-amber-500/20 bg-amber-500/10 text-amber-500' => $alert['tone'] === 'amber',
                            'border-rose-500/20 bg-rose-500/10 text-rose-500' => $alert['tone'] === 'rose',
                            'border-blue-500/20 bg-blue-500/10 text-blue-500' => $alert['tone'] === 'blue',
                            'border-violet-500/20 bg-violet-500/10 text-violet-500' => $alert['tone'] === 'violet',
                        ])>{{ $alert['status'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════
         BOTTOM ROW: Attendance Trend + Birthdays + Live Check-ins
    ══════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3 mt-5">

        {{-- ── Attendance Trend (6 months bar chart) ── --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-5 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Attendance Trend</h3>
                    <p class="mt-0.5 text-[11px] text-zinc-400">Monthly attendance rate — last 6 months</p>
                </div>
                <div class="flex size-8 items-center justify-center rounded-xl bg-indigo-50 dark:bg-indigo-950/30">
                    <flux:icon.chart-bar class="size-4 text-indigo-600 dark:text-indigo-400" />
                </div>
            </div>

            @php
                $maxRate = max($attendanceTrend->pluck('rate')->max(), 1);
            @endphp

            {{-- Bar Chart --}}
            <div class="flex items-end justify-between gap-2 h-32 mb-3">
                @foreach($attendanceTrend as $t)
                    @php
                        $barH = max(4, round(($t['rate'] / 100) * 100));
                        $barColor = $t['rate'] >= 80 ? 'bg-indigo-500' : ($t['rate'] >= 60 ? 'bg-amber-400' : 'bg-rose-400');
                        $isCurrentMonth = $t['month'] === now()->format('M');
                    @endphp
                    <div class="flex flex-1 flex-col items-center gap-1.5">
                        <span class="text-[9px] font-bold text-zinc-500">{{ $t['rate'] > 0 ? $t['rate'].'%' : '' }}</span>
                        <div class="w-full rounded-t-lg {{ $barColor }} transition-all duration-700 relative {{ $isCurrentMonth ? 'ring-2 ring-offset-1 ring-indigo-400' : '' }}"
                            style="height: {{ $barH }}%;">
                        </div>
                        <span class="text-[10px] font-bold {{ $isCurrentMonth ? 'text-indigo-600 dark:text-indigo-400' : 'text-zinc-400' }} uppercase">
                            {{ $t['month'] }}
                        </span>
                    </div>
                @endforeach
            </div>

            {{-- Summary --}}
            <div class="pt-3 border-t border-zinc-100 dark:border-zinc-800 grid grid-cols-3 gap-3 text-center">
                @php
                    $avgRate = $attendanceTrend->avg('rate');
                    $bestMonth = $attendanceTrend->sortByDesc('rate')->first();
                    $totalPresent = $attendanceTrend->sum('present');
                @endphp
                <div>
                    <div class="text-sm font-black text-zinc-900 dark:text-white">{{ round($avgRate) }}%</div>
                    <div class="text-[9px] text-zinc-400 uppercase tracking-wider">Avg Rate</div>
                </div>
                <div>
                    <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $bestMonth['month'] }}</div>
                    <div class="text-[9px] text-zinc-400 uppercase tracking-wider">Best Month</div>
                </div>
                <div>
                    <div class="text-sm font-black text-zinc-900 dark:text-white">{{ number_format($totalPresent) }}</div>
                    <div class="text-[9px] text-zinc-400 uppercase tracking-wider">Check-ins</div>
                </div>
            </div>
        </div>

        {{-- ── Upcoming Birthdays ── --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-5 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Upcoming Birthdays</h3>
                    <p class="mt-0.5 text-[11px] text-zinc-400">Next 30 days</p>
                </div>
                <div class="flex size-8 items-center justify-center rounded-xl bg-rose-50 dark:bg-rose-950/30">
                    <flux:icon.cake class="size-4 text-rose-500" />
                </div>
            </div>

            @if($upcomingBirthdays->isEmpty())
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <flux:icon.cake class="size-9 mb-2 text-zinc-200 dark:text-zinc-700" />
                    <p class="text-xs text-zinc-400">No birthdays in the next 30 days</p>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($upcomingBirthdays as $b)
                        @php
                            $initials = collect(explode(' ', $b['name']))->map(fn($n) => $n[0] ?? '')->take(2)->join('');
                            $colors = ['bg-rose-100 text-rose-700','bg-violet-100 text-orange-700','bg-blue-100 text-blue-700','bg-amber-100 text-amber-700','bg-emerald-100 text-emerald-700','bg-orange-100 text-orange-700'];
                            $color = $colors[$loop->index % count($colors)];
                        @endphp
                        <div class="flex items-center gap-3 p-2.5 rounded-xl {{ $b['is_today'] ? 'bg-rose-50 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-900/40' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/40' }} transition-colors">
                            <div class="size-9 rounded-xl {{ $color }} flex items-center justify-center text-[11px] font-black shrink-0">
                                {{ $initials }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-xs font-bold text-zinc-900 dark:text-white truncate">{{ $b['name'] }}</div>
                                <div class="text-[10px] text-zinc-400">{{ $b['dept'] ?: 'No department' }}</div>
                            </div>
                            <div class="text-right shrink-0">
                                @if($b['is_today'])
                                    <span class="inline-flex items-center px-2 py-0.5 bg-rose-500 text-white text-[9px] font-black rounded-full">TODAY</span>
                                @else
                                    <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">{{ $b['dob_fmt'] }}</span>
                                    <div class="text-[9px] text-zinc-400">in {{ $b['days'] }}d</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                    <a href="{{ route('employees.index') }}" wire:navigate
                        class="text-[10px] font-bold uppercase tracking-widest text-brand-500 hover:text-brand-600">
                        View all employees →
                    </a>
                </div>
            @endif
        </div>

        {{-- ── Today's Live Check-ins ── --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-5 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Live Check-ins</h3>
                    <p class="mt-0.5 text-[11px] text-zinc-400">Today · {{ now()->format('d M Y') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="flex items-center gap-1.5 text-[10px] font-bold text-emerald-600 dark:text-emerald-400">
                        <span class="size-1.5 rounded-full bg-emerald-500 animate-pulse inline-block"></span>
                        LIVE
                    </span>
                    <div class="flex size-8 items-center justify-center rounded-xl bg-emerald-50 dark:bg-emerald-950/30">
                        <flux:icon.users class="size-4 text-emerald-600 dark:text-emerald-400" />
                    </div>
                </div>
            </div>

            @if($liveCheckins->isEmpty())
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <flux:icon.clock class="size-9 mb-2 text-zinc-200 dark:text-zinc-700" />
                    <p class="text-xs text-zinc-400">No check-ins yet today</p>
                </div>
            @else
                <div class="space-y-2.5">
                    @foreach($liveCheckins as $ci)
                        @php
                            $initials = collect(explode(' ', $ci->employee->user->name))->map(fn($n) => $n[0] ?? '')->take(2)->join('');
                            $isLive   = is_null($ci->check_out);
                        @endphp
                        <div class="flex items-center gap-2.5 p-2 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-800/40 transition-colors">
                            <div class="relative size-8 shrink-0">
                                <div class="size-8 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-[10px] font-black text-zinc-600 dark:text-zinc-300">
                                    {{ $initials }}
                                </div>
                                @if($isLive)
                                    <span class="absolute -bottom-0.5 -right-0.5 size-2.5 rounded-full bg-emerald-500 border-2 border-white dark:border-zinc-900"></span>
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-xs font-bold text-zinc-900 dark:text-white truncate">{{ $ci->employee->user->name }}</div>
                                <div class="text-[10px] font-mono text-zinc-400">
                                    In: {{ $ci->check_in->format('H:i') }}
                                    @if($ci->check_out)
                                        · Out: {{ $ci->check_out->format('H:i') }}
                                    @endif
                                </div>
                            </div>
                            <div class="shrink-0">
                                @if($ci->is_late)
                                    <span class="px-1.5 py-0.5 bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 text-[9px] font-black rounded">LATE</span>
                                @elseif($isLive)
                                    <span class="px-1.5 py-0.5 bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400 text-[9px] font-black rounded">LIVE</span>
                                @else
                                    <span class="px-1.5 py-0.5 bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400 text-[9px] font-black rounded">DONE</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                    <a href="{{ route('attendance.employees') }}" wire:navigate
                        class="text-[10px] font-bold uppercase tracking-widest text-brand-500 hover:text-brand-600">
                        Full attendance report →
                    </a>
                </div>
            @endif
        </div>

    </div>{{-- end bottom row --}}

</flux:main>