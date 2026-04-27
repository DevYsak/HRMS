<flux:main class="bg-zinc-50 dark:bg-zinc-950 p-6 lg:p-10 space-y-8 font-['DM_Sans'] min-h-screen">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;700;900&display=swap');
        
        .admin-card {
            border-radius: 20px;
            padding: 24px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .donut-chart {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .donut-inner {
            width: 95px;
            height: 95px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 2;
        }

        .badge-today { background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.15); }
        .badge-active { background: rgba(59, 130, 246, 0.1); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.15); }
        .badge-req { background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.15); }
        .badge-cycle { background: rgba(139, 92, 246, 0.1); color: #8b5cf6; border: 1px solid rgba(139, 92, 246, 0.15); }

        .status-box {
            width: 16px;
            height: 16px;
            border-radius: 4px;
        }

        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
    </style>

    {{-- Header --}}
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-3xl font-black tracking-tight text-zinc-900 dark:text-white">Admin Dashboard</h1>
            <p class="text-zinc-500 dark:text-zinc-400 text-sm mt-1">PulseHR Enterprise · Workforce Intelligence Overview</p>
        </div>
        <div class="flex items-center gap-3">
            <div class="px-4 py-2 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl text-xs font-bold text-zinc-500 dark:text-zinc-400">
                {{ now()->format('D, j M Y') }}
            </div>
            <flux:button variant="primary" icon="plus" size="sm" :href="route('employees.create')" class="!bg-brand-500 !text-white !font-bold rounded-xl border-none hover:!bg-brand-600 transition-all active:scale-95 shadow-lg shadow-brand-500/20">Add Employee</flux:button>
        </div>
    </div>

    {{-- Stats Row --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {{-- Attendance Rate --}}
        <div class="admin-card bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 shadow-sm">
            <div class="flex justify-between items-start mb-6">
                <div class="size-10 rounded-xl bg-zinc-50 dark:bg-zinc-800 flex items-center justify-center text-zinc-400 border border-zinc-100 dark:border-zinc-700/30">
                    <flux:icon.user class="size-5" />
                </div>
                <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest badge-today">Today</span>
            </div>
            <div class="text-4xl font-black mb-1 text-zinc-900 dark:text-white">{{ $attendancePercent }}%</div>
            <div class="text-[10px] text-zinc-500 dark:text-zinc-400 font-bold uppercase tracking-widest mb-4">Attendance Rate</div>
            <div class="text-[10px] text-zinc-400">
                <span class="text-emerald-500 font-bold">{{ $presentToday }}/{{ $totalActive }}</span> employees present
            </div>
        </div>

        {{-- On Leave Today --}}
        <div class="admin-card bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 shadow-sm">
            <div class="flex justify-between items-start mb-6">
                <div class="size-10 rounded-xl bg-zinc-50 dark:bg-zinc-800 flex items-center justify-center text-zinc-400 border border-zinc-100 dark:border-zinc-700/30">
                    <flux:icon.calendar-days class="size-5" />
                </div>
                <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest badge-active">Active</span>
            </div>
            <div class="text-4xl font-black mb-1 text-zinc-900 dark:text-white">{{ $onLeaveTodayCount }}</div>
            <div class="text-[10px] text-zinc-500 dark:text-zinc-400 font-bold uppercase tracking-widest mb-4">On Leave Today</div>
            <div class="text-[10px] text-zinc-400">
                All leaves approved
            </div>
        </div>

        {{-- Pending Approvals --}}
        <div class="admin-card bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 shadow-sm">
            <div class="flex justify-between items-start mb-6">
                <div class="size-10 rounded-xl bg-zinc-50 dark:bg-zinc-800 flex items-center justify-center text-zinc-400 border border-zinc-100 dark:border-zinc-700/30">
                    <flux:icon.clock class="size-5" />
                </div>
                @php $totalPending = $pendingLeavesCount + $pendingOtCount; @endphp
                <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest badge-req">{{ $totalPending }} REQ</span>
            </div>
            <div class="text-4xl font-black mb-1 text-zinc-900 dark:text-white">{{ $totalPending }}</div>
            <div class="text-[10px] text-zinc-500 dark:text-zinc-400 font-bold uppercase tracking-widest mb-4">Pending Approvals</div>
            <div class="text-[10px] text-rose-500 font-bold">
                {{ $pendingLeavesCount }} leaves <span class="text-zinc-400 font-normal">awaiting action</span>
            </div>
        </div>

        {{-- Payroll Status --}}
        <div class="admin-card bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 shadow-sm">
            <div class="flex justify-between items-start mb-6">
                <div class="size-10 rounded-xl bg-zinc-50 dark:bg-zinc-800 flex items-center justify-center text-zinc-400 border border-zinc-100 dark:border-zinc-700/30">
                    <flux:icon.chat-bubble-bottom-center-text class="size-5" />
                </div>
                <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest badge-cycle">Cycle</span>
            </div>
            <div class="text-4xl font-black mb-1 text-zinc-900 dark:text-white">{{ $activePayrolls->count() > 0 ? 'Draft' : 'Final' }}</div>
            <div class="text-[10px] text-zinc-500 dark:text-zinc-400 font-bold uppercase tracking-widest mb-4">Payroll Status</div>
            <div class="text-[10px] text-blue-500 font-bold">
                {{ $activePayrolls->count() }} active <span class="text-zinc-400 font-normal">payroll cycles</span>
            </div>
        </div>
    </div>

    {{-- Middle Row: Heatmap & Workforce --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Heatmap --}}
        <div class="lg:col-span-2 admin-card bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden">
            <div class="flex justify-between items-center mb-8">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Team Attendance Heatmap</h3>
                <div class="flex items-center gap-4 text-[10px] font-bold text-zinc-500 uppercase tracking-widest">
                    <div class="flex items-center gap-1.5"><div class="size-2.5 rounded-[2px] bg-emerald-500"></div> Present</div>
                    <div class="flex items-center gap-1.5"><div class="size-2.5 rounded-[2px] bg-amber-500"></div> Late</div>
                    <div class="flex items-center gap-1.5"><div class="size-2.5 rounded-[2px] bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700"></div> Absent</div>
                    <flux:link class="text-brand-500 ml-4 font-bold !no-underline hover:text-brand-600 transition-colors">Full Report →</flux:link>
                </div>
            </div>

            <div class="overflow-x-auto scrollbar-hide">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest border-b border-zinc-50 dark:border-zinc-800/50">
                            <th class="pb-4 pr-6">Employee</th>
                            @foreach($days as $day)
                                <th class="pb-4 px-2 text-center">{{ $day->format('D j') }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                        @foreach($heatmapData->take(8) as $row)
                            <tr class="group">
                                <td class="py-3 pr-6">
                                    <div class="flex items-center gap-3">
                                        <div class="flex size-7 shrink-0 items-center justify-center rounded-full bg-zinc-50 dark:bg-zinc-800 text-[10px] font-black text-brand-500 border border-zinc-100 dark:border-zinc-700">
                                            {{ $row['initials'] }}
                                        </div>
                                        <div class="text-xs font-bold text-zinc-700 dark:text-zinc-300 group-hover:text-brand-600 transition truncate max-w-[120px]">
                                            {{ $row['name'] }}
                                        </div>
                                    </div>
                                </td>
                                @foreach($row['days'] as $status)
                                    <td class="py-3 px-2 text-center">
                                        <div @class([
                                            'status-box mx-auto transition-all duration-300 group-hover:scale-110',
                                            'bg-emerald-500 shadow-[0_0_12px_rgba(16,185,129,0.2)]' => $status === 'present',
                                            'bg-amber-500 shadow-[0_0_12px_rgba(245,158,11,0.2)]' => $status === 'late',
                                            'bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700' => $status === 'absent' || $status === 'weekend',
                                            'bg-zinc-50 dark:bg-zinc-800/30' => $status === 'future',
                                        ])></div>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Workforce Composition --}}
        <div class="admin-card bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 shadow-sm flex flex-col">
            <div class="flex justify-between items-center mb-10">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Workforce Composition</h3>
                <flux:link class="text-brand-500 text-[10px] font-bold uppercase tracking-widest !no-underline hover:text-brand-600">Details →</flux:link>
            </div>

            <div class="flex flex-col items-center flex-1 justify-center">
                @php
                    $total = $workforceComposition->sum('count');
                    $currentPercentage = 0;
                    $gradientSteps = [];
                    foreach($workforceComposition as $dept) {
                        $percentage = ($dept['count'] / $total) * 100;
                        $gradientSteps[] = "{$dept['color']} {$currentPercentage}% " . ($currentPercentage + $percentage) . "%";
                        $currentPercentage += $percentage;
                    }
                    $conicGradient = implode(', ', $gradientSteps);
                @endphp
                
                <div class="donut-chart mb-10" style="background: conic-gradient({{ $conicGradient }})">
                    <div class="donut-inner bg-white dark:bg-zinc-900 border border-zinc-100 dark:border-zinc-800/50 shadow-inner">
                        <span class="text-3xl font-black text-zinc-900 dark:text-white leading-none tracking-tighter">{{ $total }}</span>
                        <span class="text-[10px] font-bold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest mt-1">Total</span>
                    </div>
                </div>

                <div class="w-full space-y-4">
                    @foreach($workforceComposition as $dept)
                        <div class="flex items-center justify-between group">
                            <div class="flex items-center gap-3">
                                <div class="size-2 rounded-sm transition-transform group-hover:scale-125 shadow-sm" style="background: {{ $dept['color'] }}"></div>
                                <span class="text-xs font-bold text-zinc-600 dark:text-zinc-400 group-hover:text-zinc-900 dark:group-hover:text-zinc-200 transition-colors">{{ $dept['name'] }}</span>
                            </div>
                            <span class="text-sm font-black text-zinc-900 dark:text-white">{{ $dept['count'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Bottom Row: Activity, Requests, Compliance --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Recent Activity --}}
        <div class="admin-card bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 shadow-sm">
            <div class="flex justify-between items-center mb-8">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Recent Activity</h3>
                <flux:link class="text-brand-500 text-[10px] font-bold uppercase tracking-widest !no-underline hover:text-brand-600">View all →</flux:link>
            </div>

            <div class="space-y-6 relative before:absolute before:left-4 before:top-2 before:bottom-2 before:w-[1px] before:bg-zinc-100 dark:before:bg-zinc-800">
                @foreach($recentAuditLogs->take(4) as $log)
                    <div class="flex gap-4 relative z-10">
                        <div class="size-8 rounded-full bg-white dark:bg-zinc-900 border border-zinc-100 dark:border-zinc-800 flex items-center justify-center shrink-0 shadow-sm">
                            <flux:icon.user class="size-4 text-brand-500" />
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs text-zinc-700 dark:text-zinc-300 leading-tight">
                                <span class="font-black text-zinc-900 dark:text-white hover:text-brand-500 transition-colors cursor-pointer">{{ $log->user?->name ?? 'System' }}</span> 
                                <span class="text-zinc-500 font-medium">{{ $log->display_action }}</span>
                            </p>
                            <p class="text-[9px] text-zinc-400 dark:text-zinc-500 mt-1 uppercase font-black tracking-widest">{{ $log->created_at->diffForHumans() }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Pending Requests --}}
        <div class="admin-card bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 shadow-sm">
            <div class="flex justify-between items-center mb-8">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Pending Requests</h3>
                <span class="px-2 py-1 bg-rose-500/10 text-rose-500 text-[8px] font-black uppercase tracking-widest border border-rose-500/20 rounded-md shadow-sm">10 Action Required</span>
            </div>

            <div class="space-y-3">
                @forelse(\App\Models\LeaveRequest::with('employee.user', 'leaveType')->where('status', 'pending')->latest()->take(3)->get() as $leave)
                    <div class="flex items-center justify-between p-4 bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-100 dark:border-zinc-700/50 rounded-xl hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors group">
                        <div class="flex items-center gap-3">
                            <div class="size-9 rounded-full bg-white dark:bg-zinc-900 flex items-center justify-center text-[10px] font-black text-amber-500 border border-zinc-100 dark:border-zinc-700/50 shadow-inner group-hover:border-amber-500/30 transition-colors">
                                {{ collect(explode(' ', $leave->employee->user->name))->map(fn($n)=>$n[0])->take(2)->join('') }}
                            </div>
                            <div class="min-w-0">
                                <h4 class="text-xs font-bold text-zinc-900 dark:text-white truncate">{{ $leave->employee->user->name }}</h4>
                                <p class="text-[10px] text-zinc-500 mt-0.5">{{ $leave->leaveType->name }} · {{ \Carbon\Carbon::parse($leave->start_date)->format('d M') }}</p>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button class="size-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-500 hover:bg-emerald-500 hover:text-white transition-all active:scale-90">
                                <flux:icon.check class="size-4" />
                            </button>
                            <button class="size-8 rounded-lg bg-rose-500/10 border border-rose-500/20 flex items-center justify-center text-rose-500 hover:bg-rose-500 hover:text-white transition-all active:scale-90">
                                <flux:icon.x-mark class="size-4" />
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center py-10">
                        <flux:icon.clipboard class="size-8 text-zinc-200 dark:text-zinc-800 mb-2" />
                        <p class="text-zinc-400 text-xs italic font-medium">No pending requests</p>
                    </div>
                @endforelse
            </div>
            
            <div class="mt-6 pt-4 border-t border-zinc-50 dark:border-zinc-800">
                <flux:link class="text-brand-500 text-[10px] font-bold uppercase tracking-widest !no-underline hover:text-brand-600">Manage All Requests →</flux:link>
            </div>
        </div>

        {{-- Compliance & Alerts --}}
        <div class="admin-card bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 shadow-sm">
            <div class="flex justify-between items-center mb-8">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Compliance & Alerts</h3>
                <flux:link class="text-brand-500 text-[10px] font-bold uppercase tracking-widest !no-underline hover:text-brand-600">View All →</flux:link>
            </div>

            <div class="space-y-6">
                <div class="flex items-center justify-between group cursor-pointer">
                    <div class="flex items-center gap-3">
                        <div class="size-1.5 rounded-full bg-emerald-500 shadow-sm"></div>
                        <span class="text-xs font-bold text-zinc-600 dark:text-zinc-400 group-hover:text-zinc-900 dark:group-hover:text-zinc-200 transition-colors">PF / ESI filings current</span>
                    </div>
                    <span class="px-2 py-0.5 bg-emerald-500/10 text-emerald-500 text-[8px] font-black uppercase tracking-widest border border-emerald-500/20 rounded shadow-sm">Compliant</span>
                </div>
                <div class="flex items-center justify-between group cursor-pointer">
                    <div class="flex items-center gap-3">
                        <div class="size-1.5 rounded-full bg-amber-500 shadow-sm"></div>
                        <span class="text-xs font-bold text-zinc-600 dark:text-zinc-400 group-hover:text-zinc-900 dark:group-hover:text-zinc-200 transition-colors">3 employees — contract renewal due</span>
                    </div>
                    <span class="px-2 py-0.5 bg-amber-500/10 text-amber-500 text-[8px] font-black uppercase tracking-widest border border-amber-500/20 rounded shadow-sm">Due Soon</span>
                </div>
                <div class="flex items-center justify-between group cursor-pointer">
                    <div class="flex items-center gap-3">
                        <div class="size-1.5 rounded-full bg-blue-500 shadow-sm"></div>
                        <span class="text-xs font-bold text-zinc-600 dark:text-zinc-400 group-hover:text-zinc-900 dark:group-hover:text-zinc-200 transition-colors">Quarterly payroll audit scheduled</span>
                    </div>
                    <span class="px-2 py-0.5 bg-blue-500/10 text-blue-500 text-[8px] font-black uppercase tracking-widest border border-blue-500/20 rounded shadow-sm">Upcoming</span>
                </div>
                <div class="flex items-center justify-between group cursor-pointer">
                    <div class="flex items-center gap-3">
                        <div class="size-1.5 rounded-full bg-emerald-500 shadow-sm"></div>
                        <span class="text-xs font-bold text-zinc-600 dark:text-zinc-400 group-hover:text-zinc-900 dark:group-hover:text-zinc-200 transition-colors">Leave policies acknowledged by all</span>
                    </div>
                    <span class="px-2 py-0.5 bg-emerald-500/10 text-emerald-500 text-[8px] font-black uppercase tracking-widest border border-emerald-500/20 rounded shadow-sm">Complete</span>
                </div>
                <div class="flex items-center justify-between group cursor-pointer">
                    <div class="flex items-center gap-3">
                        <div class="size-1.5 rounded-full bg-emerald-500 shadow-sm"></div>
                        <span class="text-xs font-bold text-zinc-600 dark:text-zinc-400 group-hover:text-zinc-900 dark:group-hover:text-zinc-200 transition-colors">TDS deductions verified for Apr</span>
                    </div>
                    <span class="px-2 py-0.5 bg-emerald-500/10 text-emerald-500 text-[8px] font-black uppercase tracking-widest border border-emerald-500/20 rounded shadow-sm">Verified</span>
                </div>
            </div>
        </div>
    </div>
</flux:main>
