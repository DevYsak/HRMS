<flux:main class="bg-zinc-50 dark:bg-zinc-950 min-h-screen">

    @php
        $hour = now()->hour;
        $greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
        $firstName = \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->first();
        $timeContext = $hour < 12 ? 'Here\'s your company at a glance.' : ($hour < 17 ? 'Afternoon executive overview.' : 'End of day — company snapshot.');
    @endphp
    {{-- ── HEADER ── --}}
    <div class="pulse-hero">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_bottom_left,_rgba(249,115,22,0.22),_transparent_65%)]"></div>
        <div class="pointer-events-none absolute -bottom-10 -left-10 size-64 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(249,115,22,0.30),transparent 70%)"></div>
        <div class="pointer-events-none absolute top-0 right-0 size-48 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(249,115,22,0.08),transparent 70%)"></div>
        <div class="relative">
            <div class="flex items-center gap-2.5 mb-3">
                <div class="inline-flex items-center gap-1.5 px-3 py-1 bg-white/10 border border-white/10 rounded-full">
                    @if($hour < 12)
                        <flux:icon.sun class="size-3 text-amber-300" />
                        <span class="text-[11px] font-semibold text-white/70">Morning</span>
                    @elseif($hour < 17)
                        <flux:icon.sun class="size-3 text-orange-300" />
                        <span class="text-[11px] font-semibold text-white/70">Afternoon</span>
                    @else
                        <flux:icon.moon class="size-3 text-slate-300" />
                        <span class="text-[11px] font-semibold text-white/70">Evening</span>
                    @endif
                </div>
                <span class="inline-flex items-center px-3 py-1 bg-amber-500/15 border border-amber-500/20 rounded-full text-[11px] font-bold text-amber-400 uppercase tracking-wide">
                    Executive
                </span>
                <span class="text-white/40 text-xs">{{ now()->format('l, d F Y') }}</span>
            </div>
            <h1 class="text-3xl font-black text-white tracking-tight">{{ $greeting }}, {{ $firstName }}</h1>
            <p class="text-white/55 text-sm mt-1.5">{{ $timeContext }}</p>
        </div>
    </div>

    <div class="p-4 md:p-6 space-y-5">

        {{-- ── KPI ROW ── --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            {{-- Headcount --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Headcount</span>
                    <div class="size-8 rounded-xl bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                        <flux:icon.users class="size-4 text-zinc-600 dark:text-zinc-300" />
                    </div>
                </div>
                <div class="text-4xl font-black text-zinc-900 dark:text-white">{{ $activeCount }}</div>
                <div class="flex gap-3 mt-2">
                    <span class="text-[10px] text-amber-600 font-bold">{{ $onboardingCount }} onboarding</span>
                    <span class="text-[10px] text-purple-600 font-bold">{{ $probationCount }} probation</span>
                </div>
            </div>

            {{-- Today's attendance --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">In Today</span>
                    <div class="size-8 rounded-xl bg-brand-50 dark:bg-brand-950/30 flex items-center justify-center">
                        <flux:icon.check-circle class="size-4 text-brand-600" />
                    </div>
                </div>
                <div class="text-4xl font-black text-brand-600">{{ $presentToday }}</div>
                @if($lateToday > 0)
                    <div class="text-[10px] text-rose-500 font-bold mt-2">{{ $lateToday }} arrived late</div>
                @else
                    <div class="text-[10px] text-zinc-400 mt-2">no late arrivals today</div>
                @endif
            </div>

            {{-- Pending Leaves --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Pending Leaves</span>
                    <div class="size-8 rounded-xl bg-amber-50 dark:bg-amber-950/30 flex items-center justify-center">
                        <flux:icon.calendar-days class="size-4 text-amber-500" />
                    </div>
                </div>
                <div class="text-4xl font-black text-amber-500">{{ $pendingLeaves }}</div>
                <div class="text-[10px] text-zinc-400 mt-2">awaiting manager approval</div>
            </div>

            {{-- Pending OT --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Pending OT</span>
                    <div class="size-8 rounded-xl bg-purple-50 dark:bg-purple-950/30 flex items-center justify-center">
                        <flux:icon.clock class="size-4 text-purple-500" />
                    </div>
                </div>
                <div class="text-4xl font-black text-purple-500">{{ $pendingOt }}</div>
                <div class="text-[10px] text-zinc-400 mt-2">awaiting approval</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

            {{-- ── PAYROLL STATUS ── --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center gap-2">
                    <flux:icon.banknotes class="size-4 text-brand-500" />
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Payroll Status</h3>
                    <span class="ml-auto text-[10px] text-zinc-400">{{ now()->format('M Y') }}</span>
                </div>
                <div class="p-5 space-y-3">
                    @foreach([['Cycle A', '1st–31st', $cycleAPayroll], ['Cycle B', '21st–20th', $cycleBPayroll]] as [$label, $period, $payroll])
                        <div class="p-4 rounded-xl {{ $payroll ? 'bg-zinc-50 dark:bg-zinc-950' : 'bg-zinc-50/50 dark:bg-zinc-950/50' }} border border-zinc-100 dark:border-zinc-800">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $label }}</div>
                                    <div class="text-[10px] text-zinc-400 mt-0.5">{{ $period }}</div>
                                </div>
                                @php
                                    $status = $payroll?->status ?? 'not_run';
                                    $badge = match($status) {
                                        'approved','finalized' => 'bg-emerald-100 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-400',
                                        'pending_finance','draft' => 'bg-amber-100 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400',
                                        default => 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500',
                                    };
                                @endphp
                                <span class="text-[9px] font-black px-2 py-0.5 rounded-full {{ $badge }}">
                                    {{ $payroll ? strtoupper(str_replace('_', ' ', $payroll->status)) : 'NOT RUN' }}
                                </span>
                            </div>
                            @if($payroll && $payroll->total_payout)
                                <div class="mt-2 text-lg font-black text-brand-600">₹{{ number_format($payroll->total_payout, 0) }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- ── PERFORMANCE ── --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center gap-2">
                    <flux:icon.chart-bar class="size-4 text-purple-500" />
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Performance</h3>
                </div>
                <div class="p-6 flex flex-col items-center justify-center min-h-[140px]">
                    @if($avgRating)
                        <div class="text-6xl font-black text-purple-600 leading-none">{{ number_format($avgRating, 1) }}</div>
                        <div class="text-xs text-zinc-400 mt-2">avg rating / 5.0</div>
                        <div class="flex gap-1 mt-3">
                            @for($i = 1; $i <= 5; $i++)
                                <div class="h-1.5 w-8 rounded-full {{ $i <= round($avgRating) ? 'bg-purple-500' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>
                            @endfor
                        </div>
                        <div class="text-[10px] text-zinc-400 mt-3">Based on submitted QBR reviews</div>
                    @else
                        <flux:icon.chart-bar class="size-10 text-zinc-300 dark:text-zinc-700 mb-2" />
                        <div class="text-sm text-zinc-400">No reviews submitted yet</div>
                    @endif
                </div>
            </div>

            {{-- ── DEPT HEADCOUNT ── --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center gap-2">
                    <flux:icon.building-office class="size-4 text-blue-500" />
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">By Department</h3>
                </div>
                <div class="p-4 space-y-2.5">
                    @php $maxCount = collect($byDepartment)->max() ?: 1; @endphp
                    @forelse($byDepartment as $dept => $count)
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-xs text-zinc-600 dark:text-zinc-300 font-medium truncate max-w-[130px]" title="{{ $dept ?? 'Unassigned' }}">{{ $dept ?? 'Unassigned' }}</span>
                                <span class="text-xs font-bold text-zinc-900 dark:text-white">{{ $count }}</span>
                            </div>
                            <div class="h-1.5 bg-zinc-100 dark:bg-zinc-800 rounded-full overflow-hidden">
                                <div class="h-full bg-brand-500 rounded-full transition-all" style="width: {{ ($count / $maxCount) * 100 }}%"></div>
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-zinc-400 text-sm">No data available</div>
                    @endforelse
                </div>
            </div>

        </div>
    </div>

</flux:main>
