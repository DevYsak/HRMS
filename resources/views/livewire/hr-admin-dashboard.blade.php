<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">

    {{-- Header --}}
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">HR Admin Dashboard</h1>
            <p class="pulse-page-subtitle">{{ now()->format('l, jS F Y') }} — Company Overview</p>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('employees.create')" wire:navigate variant="primary" icon="plus">Add Employee</flux:button>
            <flux:button :href="route('payroll.process')" wire:navigate variant="ghost" icon="banknotes">Run Payroll</flux:button>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="pulse-card text-center p-5">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Active Staff</div>
            <div class="text-3xl font-black text-zinc-900 dark:text-white mt-2">{{ $totalActive }}</div>
            <div class="text-[10px] text-zinc-400 mt-1">{{ $onboarding }} onboarding · {{ $probation }} probation</div>
        </div>
        <div class="pulse-card text-center p-5">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">New Hires (Month)</div>
            <div class="text-3xl font-black text-brand-600 mt-2">{{ $newThisMonth }}</div>
            <div class="text-[10px] text-zinc-400 mt-1">Joined this month</div>
        </div>
        <div class="pulse-card text-center p-5">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Pending Leaves</div>
            <div class="text-3xl font-black text-amber-500 mt-2">{{ $pendingLeaves }}</div>
            <div class="text-[10px] text-red-500 mt-1">{{ $escalatedLeaves }} escalated</div>
        </div>
        <div class="pulse-card text-center p-5">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Regularisations</div>
            <div class="text-3xl font-black text-purple-500 mt-2">{{ $pendingReg }}</div>
            <div class="text-[10px] text-zinc-400 mt-1">Pending correction requests</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Attendance Exceptions --}}
        <div class="pulse-card p-6">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                <flux:icon.clock class="size-4 text-amber-500" /> Attendance Exceptions (Today)
            </h3>
            <div class="space-y-3">
                <div class="flex items-center justify-between p-3 bg-amber-50 rounded-xl dark:bg-amber-900/10">
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Missing Checkout</span>
                    <span class="text-lg font-black text-amber-600">{{ $missingCheckout }}</span>
                </div>
                <div class="flex items-center justify-between p-3 bg-red-50 rounded-xl dark:bg-red-900/10">
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Late Arrivals</span>
                    <span class="text-lg font-black text-red-600">{{ $lateToday }}</span>
                </div>
            </div>
            <div class="mt-4">
                <flux:button :href="route('attendance.employees')" wire:navigate variant="ghost" size="sm" class="w-full">View All Attendance →</flux:button>
            </div>
        </div>

        {{-- Payroll Run Status --}}
        <div class="pulse-card p-6">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                <flux:icon.banknotes class="size-4 text-green-500" /> Payroll Status (This Month)
            </h3>
            <div class="space-y-3">
                @foreach([
                    ['Cycle A (1st–31st)', $cycleARun],
                    ['Cycle B (21st–20th)', $cycleBRun],
                ] as [$label, $payroll])
                <div class="flex items-center justify-between p-3 bg-zinc-50 rounded-xl dark:bg-zinc-900">
                    <div>
                        <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $label }}</div>
                        <div class="text-xs text-zinc-400">
                            {{ $payroll ? '₹'.number_format($payroll->total_payout, 0) : 'Not run yet' }}
                        </div>
                    </div>
                    <span class="text-xs font-bold px-2 py-1 rounded-full
                        {{ $payroll?->status === 'finalized' ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400' : ($payroll ? 'bg-amber-100 text-amber-700' : 'bg-zinc-200 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400') }}">
                        {{ $payroll ? strtoupper($payroll->status) : 'PENDING' }}
                    </span>
                </div>
                @endforeach
            </div>
            <div class="mt-4">
                <flux:button :href="route('payroll.process')" wire:navigate variant="primary" size="sm" class="w-full">Go to Payroll Run →</flux:button>
            </div>
        </div>

        {{-- Quick Links --}}
        <div class="pulse-card p-6">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                <flux:icon.bolt class="size-4 text-blue-500" /> Quick Actions
            </h3>
            <div class="space-y-2">
                @foreach([
                    [route('employees.index'), 'users', 'Employee Directory'],
                    [route('employees.create'), 'plus-circle', 'Add New Employee'],
                    [route('time-off.employees'), 'calendar-days', 'All Leave Requests'],
                    [route('attendance.employees'), 'clock', 'All Attendance Records'],
                    [route('payroll.components'), 'banknotes', 'Salary Components'],
                    [route('documents.index'), 'document-text', 'Document Manager'],
                ] as [$url, $icon, $label])
                <a href="{{ $url }}" wire:navigate class="flex items-center gap-3 p-2.5 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors text-sm text-zinc-700 dark:text-zinc-300">
                    <flux:icon :name="$icon" class="size-4 text-zinc-400 shrink-0" />
                    {{ $label }}
                </a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Headcount by Department --}}
    <div class="pulse-card p-6">
        <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
            <flux:icon.building-office class="size-4 text-blue-500" /> Headcount by Department
        </h3>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
            @forelse($deptBreakdown as $dept)
                <div class="p-4 bg-zinc-50 dark:bg-zinc-900 rounded-xl text-center">
                    <div class="text-2xl font-black text-zinc-900 dark:text-white">{{ $dept->active_count }}</div>
                    <div class="text-[11px] text-zinc-500 mt-1 truncate" title="{{ $dept->name }}">{{ $dept->name }}</div>
                </div>
            @empty
                <div class="col-span-5 text-center py-8 text-zinc-400">No departments found.</div>
            @endforelse
        </div>
    </div>

    {{-- Recent Audit Log --}}
    <div class="pulse-card p-6">
        <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
            <flux:icon.shield-check class="size-4 text-zinc-500" /> Recent Audit Log
        </h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <th class="pb-2 pl-2 text-left text-xs font-semibold uppercase text-zinc-400">Time</th>
                        <th class="pb-2 pr-4 text-left text-xs font-semibold uppercase text-zinc-400">User</th>
                        <th class="pb-2 pr-4 text-left text-xs font-semibold uppercase text-zinc-400">Action</th>
                        <th class="pb-2 pr-2 text-left text-xs font-semibold uppercase text-zinc-400">Record</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800">
                    @forelse($recentAudit as $log)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                            <td class="py-2.5 pl-2 text-xs text-zinc-400">{{ $log->created_at ? \Illuminate\Support\Carbon::parse($log->created_at)->diffForHumans() : '—' }}</td>
                            <td class="py-2.5 pr-4 font-medium text-zinc-700 dark:text-zinc-300">{{ $log->user?->name ?? 'System' }}</td>
                            <td class="py-2.5 pr-4">
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full
                                    {{ match($log->action) {
                                        'created' => 'bg-green-100 text-green-700',
                                        'updated' => 'bg-blue-100 text-blue-700',
                                        'deleted' => 'bg-red-100 text-red-700',
                                        default   => 'bg-zinc-100 text-zinc-600',
                                    } }}">
                                    {{ strtoupper($log->action) }}
                                </span>
                            </td>
                            <td class="py-2.5 pr-2 text-xs text-zinc-500">{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-8 text-center text-zinc-400">No audit records yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</flux:main>
