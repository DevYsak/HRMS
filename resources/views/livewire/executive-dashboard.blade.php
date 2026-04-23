<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Executive Dashboard</h1>
            <p class="pulse-page-subtitle">Company-wide overview — {{ now()->format('l, jS F Y') }}</p>
        </div>
    </div>

    {{-- KPI Bar --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="pulse-card p-5 text-center">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Active Employees</div>
            <div class="text-3xl font-black text-zinc-900 dark:text-white mt-2">{{ $activeCount }}</div>
            <div class="text-[10px] text-zinc-400 mt-1">{{ $onboardingCount }} onboarding · {{ $probationCount }} probation</div>
        </div>
        <div class="pulse-card p-5 text-center">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Present Today</div>
            <div class="text-3xl font-black text-brand-600 mt-2">{{ $presentToday }}</div>
            <div class="text-[10px] text-red-500 mt-1">{{ $lateToday }} arrived late</div>
        </div>
        <div class="pulse-card p-5 text-center">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Pending Leaves</div>
            <div class="text-3xl font-black text-amber-500 mt-2">{{ $pendingLeaves }}</div>
            <div class="text-[10px] text-zinc-400 mt-1">Awaiting manager approval</div>
        </div>
        <div class="pulse-card p-5 text-center">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Pending OT</div>
            <div class="text-3xl font-black text-purple-500 mt-2">{{ $pendingOt }}</div>
            <div class="text-[10px] text-zinc-400 mt-1">Awaiting approval</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Payroll Status --}}
        <div class="pulse-card p-6">
            <h3 class="text-base font-bold text-zinc-900 dark:text-white mb-4">
                <flux:icon.banknotes class="size-4 inline-block mr-1 text-green-500" /> Payroll Status (This Month)
            </h3>
            <div class="space-y-3">
                @foreach([['label' => 'Cycle A (1st–31st)', 'payroll' => $cycleAPayroll], ['label' => 'Cycle B (21st–20th)', 'payroll' => $cycleBPayroll]] as $cycleData)
                    <div class="flex items-center justify-between p-3 bg-zinc-50 rounded-xl dark:bg-zinc-900">
                        <div>
                            <div class="font-semibold text-sm text-zinc-900 dark:text-white">{{ $cycleData['label'] }}</div>
                            <div class="text-xs text-zinc-400">
                                @if($cycleData['payroll'])
                                    ₹{{ number_format($cycleData['payroll']->total_payout, 2) }} payout
                                @else
                                    Not processed yet
                                @endif
                            </div>
                        </div>
                        <span class="text-xs font-bold px-2 py-1 rounded-full
                            {{ $cycleData['payroll']?->status === 'finalized' ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400' : ($cycleData['payroll'] ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400' : 'bg-zinc-200 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400') }}">
                            {{ $cycleData['payroll'] ? strtoupper($cycleData['payroll']->status) : 'PENDING' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Avg Performance Rating --}}
        <div class="pulse-card p-6">
            <h3 class="text-base font-bold text-zinc-900 dark:text-white mb-4">
                <flux:icon.chart-bar class="size-4 inline-block mr-1 text-purple-500" /> Performance (This Year)
            </h3>
            <div class="flex items-center gap-6">
                <div class="text-5xl font-black text-purple-600">{{ $avgRating ? number_format($avgRating, 1) : '—' }}</div>
                <div class="text-sm text-zinc-500">Average rating out of 5 across all submitted reviews this year.</div>
            </div>
        </div>

        {{-- Department Breakdown --}}
        <div class="pulse-card p-6 lg:col-span-2">
            <h3 class="text-base font-bold text-zinc-900 dark:text-white mb-4">
                <flux:icon.building-office class="size-4 inline-block mr-1 text-blue-500" /> Headcount by Department
            </h3>
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                @forelse($byDepartment as $dept => $count)
                    <div class="p-4 bg-zinc-50 rounded-xl text-center dark:bg-zinc-900">
                        <div class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $count }}</div>
                        <div class="text-[11px] text-zinc-500 mt-1 truncate" title="{{ $dept ?? 'Unassigned' }}">{{ $dept ?? 'Unassigned' }}</div>
                    </div>
                @empty
                    <div class="col-span-4 text-center py-8 text-zinc-400">No department data available.</div>
                @endforelse
            </div>
        </div>
    </div>
</flux:main>
