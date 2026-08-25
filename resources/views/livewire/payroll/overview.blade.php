<flux:main class="space-y-6 p-4 md:p-6">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">Payroll Overview</flux:heading>
            <flux:subheading>Run status, payout trends and upcoming cycles at a glance.</flux:subheading>
        </div>
        <div class="flex items-center gap-2">
            <x-clean-select model="filterYear" :live="true"
                :options="array_merge([['value' => '', 'label' => 'This year']], collect(range(now()->year, now()->year - 3))->map(fn ($y) => ['value' => (string) $y, 'label' => (string) $y])->all())" />
            <flux:button :href="route('reports.payroll-summary', ['month' => now()->month, 'year' => now()->year])" target="_blank" variant="ghost" icon="arrow-down-tray">Export PDF</flux:button>
            <flux:button :href="route('payroll.process')" wire:navigate variant="primary" icon="play">Run Payroll</flux:button>
        </div>
    </div>

    {{-- ══ KPI ROW ══ --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-7">
        <x-dashboard.hr.stat-tile label="Employees" :value="$totalEmployees" icon="users" accent="blue" />
        <x-dashboard.hr.stat-tile label="Processed" :value="$processedCount" icon="check-badge" accent="green" sub="finalized" />
        <x-dashboard.hr.stat-tile label="Pending" :value="$pendingCount" icon="clock" accent="amber" sub="awaiting finance" />
        <x-dashboard.hr.stat-tile label="Draft" :value="$draftCount" icon="pencil-square" accent="orange" sub="in progress" />
        <x-dashboard.hr.stat-tile label="Failed" :value="$failedCount" icon="exclamation-triangle" :accent="$failedCount > 0 ? 'red' : 'green'" sub="generation errors" />
        <div class="col-span-2 xl:col-span-2">
            <x-dashboard.kpi-card label="Total Salary Paid" :value="'₹'.number_format($totalSalaryPaid, 0)" icon="banknotes" accent="green" :compare="'vs ₹'.number_format($lastMonthPayout,0).' last month'" />
        </div>
    </div>

    {{-- ══ PAYOUT TREND + UPCOMING CYCLE ══ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-dashboard.hr.section-card class="lg:col-span-2" title="Payout Trend" subtitle="Total payroll payout, last 6 months (₹ Lakh)" icon="chart-bar" accent="orange">
            <x-dashboard.chart :options="$payoutChart" id="payroll-payout-trend" />
        </x-dashboard.hr.section-card>

        <x-dashboard.hr.section-card title="Upcoming Payroll Cycle" subtitle="Next pay date per active cycle" icon="calendar-days" accent="blue">
            @forelse($upcomingCycles as $cycle)
                <div class="mb-2 flex items-center justify-between rounded-xl border border-[#F3E8DD] bg-[#FFFDF8] p-3 dark:border-white/10 dark:bg-white/5">
                    <div>
                        <div class="text-sm font-bold text-[#111827] dark:text-white">{{ $cycle['name'] }}</div>
                        <div class="text-[11px] text-[#9CA3AF] dark:text-zinc-500">Pays on day {{ $cycle['pay_day'] }} of the month</div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm font-bold text-orange-600 dark:text-orange-400">{{ $cycle['next']->format('d M') }}</div>
                        <div class="text-[11px] text-[#9CA3AF] dark:text-zinc-500">{{ $cycle['days'] === 0 ? 'Today' : ($cycle['days'] === 1 ? 'Tomorrow' : $cycle['days'].' days') }}</div>
                    </div>
                </div>
            @empty
                <p class="py-8 text-center text-sm text-[#9CA3AF] dark:text-zinc-500">No active salary cycles configured.</p>
            @endforelse
        </x-dashboard.hr.section-card>
    </div>

    {{-- ══ RECENT ACTIVITY + CALENDAR ══ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-dashboard.hr.section-card class="lg:col-span-2" title="Recent Payroll Activity" subtitle="Latest payroll/payslip audit events" icon="sparkles" accent="violet">
            <x-slot:actions>
                <flux:button :href="route('payroll.audit-trail')" wire:navigate size="sm" variant="ghost">View all →</flux:button>
            </x-slot:actions>
            <div class="max-h-[320px] space-y-2.5 overflow-y-auto pr-1">
                @forelse($recentActivity as $log)
                    @php
                        $dot = match(true) {
                            in_array($log->action, ['created', 'approved', 'unlocked'], true) => 'bg-emerald-500',
                            in_array($log->action, ['rejected', 'deleted'], true) => 'bg-rose-500',
                            $log->action === 'locked' => 'bg-amber-500',
                            default => 'bg-blue-500',
                        };
                    @endphp
                    <div class="flex items-center gap-3 rounded-xl border border-[#F3E8DD] bg-[#FFFDF8] p-3 dark:border-white/10 dark:bg-white/5">
                        <span class="size-2.5 shrink-0 rounded-full {{ $dot }}"></span>
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-white text-[10px] font-bold text-orange-500 ring-1 ring-[#F3E8DD] dark:bg-zinc-800 dark:ring-white/10">
                            {{ \Illuminate\Support\Str::of($log->user?->name ?? 'System')->explode(' ')->take(2)->map(fn ($p) => $p[0] ?? '')->implode('') }}
                        </span>
                        <p class="min-w-0 flex-1 truncate text-sm text-[#6B7280] dark:text-zinc-400">
                            <span class="font-semibold text-[#111827] dark:text-white">{{ $log->user?->name ?? 'System' }}</span>
                            {{ ucfirst(str_replace('_', ' ', $log->action)) }} a {{ class_basename($log->auditable_type) }}
                        </p>
                        <span class="shrink-0 text-[11px] font-medium text-[#9CA3AF] dark:text-zinc-500">{{ $log->created_at?->diffForHumans(null, true) }}</span>
                    </div>
                @empty
                    <p class="py-8 text-center text-sm text-[#9CA3AF] dark:text-zinc-500">No payroll activity yet.</p>
                @endforelse
            </div>
        </x-dashboard.hr.section-card>

        <x-dashboard.hr.section-card title="Payroll Calendar" :subtitle="$calendarMonthLabel" icon="calendar" accent="amber">
            <div class="grid grid-cols-7 gap-1">
                @foreach($calendarDays as $d)
                    <div @class([
                        'relative flex aspect-square items-center justify-center rounded-lg text-[11px] font-semibold',
                        'bg-orange-500 text-white' => $d['is_today'],
                        'bg-[#FFFDF8] text-[#9CA3AF] dark:bg-white/5 dark:text-zinc-500' => ! $d['is_today'] && $d['is_weekend'],
                        'bg-white text-[#374151] dark:bg-zinc-800 dark:text-zinc-300' => ! $d['is_today'] && ! $d['is_weekend'],
                        'ring-2 ring-emerald-400' => ! empty($d['cycles']) && ! $d['is_today'],
                    ]) title="{{ ! empty($d['cycles']) ? implode(', ', $d['cycles']).' pay day' : '' }}">
                        {{ $d['day'] }}
                        @if(! empty($d['cycles']))
                            <span class="absolute -bottom-0.5 size-1.5 rounded-full {{ $d['is_today'] ? 'bg-white' : 'bg-emerald-500' }}"></span>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="mt-3 flex items-center gap-1.5 text-[11px] text-[#9CA3AF] dark:text-zinc-500">
                <span class="size-1.5 rounded-full bg-emerald-500"></span> Pay day
            </div>
        </x-dashboard.hr.section-card>
    </div>

    {{-- ══ RECENT PAYROLL CYCLES TABLE ══ --}}
    <x-dashboard.hr.section-card title="Recent Payroll Cycles" icon="banknotes" accent="green">
        <x-slot:actions>
            <flux:button :href="route('payroll.process')" wire:navigate size="sm" variant="ghost">View all →</flux:button>
        </x-slot:actions>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-[11px] font-bold uppercase tracking-wide text-[#9CA3AF] dark:text-zinc-500">
                        <th class="py-2 pr-4 text-left">Period</th>
                        <th class="py-2 pr-4 text-left">Cycle</th>
                        <th class="py-2 pr-4 text-right">Disbursement</th>
                        <th class="py-2 pr-4 text-left">Status</th>
                        <th class="py-2 pr-4 text-right">Employees</th>
                        <th class="py-2 pr-2 text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#F3E8DD] dark:divide-white/5">
                    @forelse($recentPayrolls as $p)
                        @php
                            $statusCls = match($p->status) {
                                'finalized' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
                                'pending_finance' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                                default => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                            };
                        @endphp
                        <tr class="hover:bg-[#FFFDF8] dark:hover:bg-white/5">
                            <td class="py-2.5 pr-4 font-semibold text-[#111827] dark:text-white">{{ $p->month }} {{ $p->year }}</td>
                            <td class="py-2.5 pr-4 text-[#6B7280] dark:text-zinc-400">{{ $p->cycle === 'cycle_a' ? 'Cycle A' : 'Cycle B' }}</td>
                            <td class="py-2.5 pr-4 text-right font-semibold text-[#111827] dark:text-white">₹{{ number_format($p->total_payout, 0) }}</td>
                            <td class="py-2.5 pr-4"><span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold {{ $statusCls }}">{{ ucfirst(str_replace('_', ' ', $p->status)) }}</span></td>
                            <td class="py-2.5 pr-4 text-right text-[#6B7280] dark:text-zinc-400">{{ $p->payslips_count }}</td>
                            <td class="py-2.5 pr-2 text-right">
                                <flux:button :href="route('payroll.process')" wire:navigate variant="ghost" size="sm" icon="eye" />
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-sm text-[#9CA3AF] dark:text-zinc-500">No payroll cycles yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-dashboard.hr.section-card>

</flux:main>
