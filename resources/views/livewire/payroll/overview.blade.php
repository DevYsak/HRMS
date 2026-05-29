@php
    $changeAmt  = $lastMonthPayout > 0 ? $totalMonthlyPayout - $lastMonthPayout : 0;
    $changePct  = $lastMonthPayout > 0 ? round(($changeAmt / $lastMonthPayout) * 100, 1) : 0;
    $activeComponents = \App\Models\SalaryComponent::where('is_active', true)->count();
    $pendingCount     = $statusCounts['draft'] ?? 0;
@endphp

<flux:main class="bg-[#F7F8FA] min-h-screen dark:bg-zinc-950">

    {{-- ── Page Header ────────────────────────────────────────────────── --}}
    <div class="bg-white dark:bg-zinc-900 border-b border-zinc-200/70 dark:border-zinc-800 px-8 py-5">
        <div class="flex items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-1.5 text-[11px] text-zinc-400 mb-1 font-medium">
                    <span>Payroll</span>
                    <flux:icon.chevron-right class="size-3" />
                    <span class="text-zinc-600 dark:text-zinc-300">Overview</span>
                </div>
                <h1 class="text-2xl font-black text-zinc-900 dark:text-white tracking-tight">Payroll Overview</h1>
                <p class="text-sm text-zinc-500 mt-0.5">Summary of company-wide salary disbursements</p>
            </div>
            <div class="flex items-center gap-2.5 shrink-0">
                <a href="{{ route('reports.payroll-summary', ['month' => now()->month, 'year' => now()->year]) }}"
                   target="_blank"
                   class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">
                    <flux:icon.arrow-down-tray class="size-4" />
                    Export PDF
                </a>
                <a href="{{ route('payroll.process') }}"
                   wire:navigate
                   class="inline-flex items-center gap-2 px-5 py-2 text-sm font-bold text-white bg-brand-600 hover:bg-brand-700 rounded-xl transition-colors shadow-sm shadow-orange-200 dark:shadow-none">
                    <flux:icon.play class="size-4" />
                    Process Payroll
                </a>
                <a href="{{ route('payroll.components') }}"
                   wire:navigate
                   class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">
                    <flux:icon.cog-6-tooth class="size-4" />
                    Settings
                </a>
            </div>
        </div>
    </div>

    <div class="p-8 space-y-6">

        {{-- ── KPI Cards ───────────────────────────────────────────────── --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">

            {{-- Expected Payout --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-6 shadow-sm relative overflow-hidden">
                <div class="absolute -right-6 -top-6 size-28 rounded-full bg-violet-50 dark:bg-violet-950/20 pointer-events-none"></div>
                <div class="relative">
                    <div class="flex items-start justify-between mb-5">
                        <div class="size-11 rounded-xl bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center shrink-0">
                            <flux:icon.banknotes class="size-5 text-violet-600 dark:text-violet-400" />
                        </div>
                        <span class="text-[10px] font-bold text-violet-600 dark:text-violet-400 bg-violet-50 dark:bg-violet-950/40 border border-violet-200 dark:border-violet-800/40 px-2.5 py-1 rounded-full tracking-wide uppercase">
                            {{ now()->format('M Y') }}
                        </span>
                    </div>
                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-1.5">Expected Payout</div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white tracking-tight tabular-nums">
                        ₹{{ number_format($totalMonthlyPayout, 2) }}
                    </div>
                    <div class="mt-3 flex items-center gap-1.5 text-xs font-semibold {{ $changePct >= 0 ? 'text-emerald-600' : 'text-red-500' }}">
                        <flux:icon :name="$changePct >= 0 ? 'arrow-trending-up' : 'arrow-trending-down'" class="size-3.5" />
                        {{ abs($changePct) }}% vs last month
                    </div>
                </div>
            </div>

            {{-- Active Components --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-6 shadow-sm relative overflow-hidden">
                <div class="absolute -right-6 -top-6 size-28 rounded-full bg-blue-50 dark:bg-blue-950/20 pointer-events-none"></div>
                <div class="relative">
                    <div class="flex items-start justify-between mb-5">
                        <div class="size-11 rounded-xl bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center shrink-0">
                            <flux:icon.puzzle-piece class="size-5 text-blue-600 dark:text-blue-400" />
                        </div>
                        <span class="text-[10px] font-bold text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-800/40 px-2.5 py-1 rounded-full tracking-wide uppercase">
                            Components
                        </span>
                    </div>
                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-1.5">Active Components</div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white tracking-tight">
                        {{ $activeComponents }}
                    </div>
                    <div class="mt-3 text-xs text-zinc-500 font-medium">Earnings &amp; Deductions combined</div>
                </div>
            </div>

            {{-- Pending Processes --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-6 shadow-sm relative overflow-hidden">
                <div class="absolute -right-6 -top-6 size-28 rounded-full bg-orange-50 dark:bg-orange-950/20 pointer-events-none"></div>
                <div class="relative">
                    <div class="flex items-start justify-between mb-5">
                        <div class="size-11 rounded-xl bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center shrink-0">
                            <flux:icon.clock class="size-5 text-orange-500 dark:text-orange-400" />
                        </div>
                        <span class="text-[10px] font-bold text-orange-500 dark:text-orange-400 bg-orange-50 dark:bg-orange-950/40 border border-orange-200 dark:border-orange-800/40 px-2.5 py-1 rounded-full tracking-wide uppercase">
                            Pending
                        </span>
                    </div>
                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-1.5">Pending Processes</div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white tracking-tight">
                        {{ $pendingCount }}
                    </div>
                    <div class="mt-3 text-xs text-zinc-500 font-medium">Drafts awaiting finalization</div>
                </div>
            </div>
        </div>

        {{-- ── YTD Banner ──────────────────────────────────────────────── --}}
        <div class="bg-gradient-to-r from-violet-600 to-violet-700 dark:from-violet-800 dark:to-violet-900 rounded-2xl p-5 flex items-center justify-between shadow-sm shadow-brand-200 dark:shadow-none">
            <div class="flex items-center gap-4">
                <div class="size-11 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
                    <flux:icon.chart-bar class="size-5 text-white" />
                </div>
                <div>
                    <div class="text-[10px] font-bold text-violet-200 uppercase tracking-widest mb-0.5">Year to Date — {{ now()->year }}</div>
                    <div class="text-2xl font-black text-white tracking-tight tabular-nums">₹{{ number_format($ytdPayout, 2) }}</div>
                </div>
            </div>
            <div class="text-right hidden sm:block">
                <div class="text-[10px] font-bold text-violet-200 uppercase tracking-widest mb-0.5">Total Cycles Run</div>
                <div class="text-2xl font-black text-white">{{ $recentPayrolls->count() }}</div>
            </div>
        </div>

        {{-- ── Recent Payroll Cycles ────────────────────────────────────── --}}
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center justify-between gap-4">
                <div>
                    <h3 class="text-base font-bold text-zinc-900 dark:text-white">Payroll Cycles</h3>
                    <p class="text-xs text-zinc-500 mt-0.5">{{ $recentPayrolls->count() }} runs{{ $filterYear ? ' in ' . $filterYear : '' }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <select wire:model.live="filterYear"
                        class="text-xs font-semibold text-zinc-700 dark:text-zinc-300 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-brand-400/30">
                        <option value="">All Years</option>
                        @foreach(range(now()->year, now()->year - 3) as $y)
                            <option value="{{ $y }}">{{ $y }}</option>
                        @endforeach
                    </select>
                    <a href="{{ route('payroll.process') }}" wire:navigate
                       class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:text-brand-700 transition-colors">
                        View all <flux:icon.arrow-right class="size-3.5" />
                    </a>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-zinc-50/70 dark:bg-zinc-950/40 border-b border-zinc-100 dark:border-zinc-800">
                            <th class="py-3 pl-6 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Cycle Period</th>
                            <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Total Disbursement</th>
                            <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Status</th>
                            <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Employees</th>
                            <th class="py-3 pr-6 text-right text-[10px] font-bold uppercase tracking-widest text-zinc-400">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                        @forelse($recentPayrolls as $p)
                            @php
                                [$statusClass, $statusLabel] = match($p->status) {
                                    'finalized'       => ['bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-400 dark:border-emerald-800/40', 'Finalized'],
                                    'pending_finance' => ['bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/30 dark:text-blue-400 dark:border-blue-800/40', 'Pending Finance'],
                                    'draft'           => ['bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/30 dark:text-amber-400 dark:border-amber-800/40', 'Draft'],
                                    default           => ['bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:border-zinc-700', ucfirst($p->status)],
                                };
                                $cycleLabel = strtoupper(str_replace('_', ' ', $p->cycle ?? 'cycle_a'));
                                $empCount   = $p->payslips()->count();
                            @endphp
                            <tr class="hover:bg-violet-50/20 dark:hover:bg-violet-950/10 transition-colors group">
                                <td class="py-4 pl-6 pr-4">
                                    <div class="font-bold text-zinc-900 dark:text-white">{{ $p->month }} {{ $p->year }}</div>
                                    <div class="text-[10px] text-zinc-400 mt-0.5 font-medium">{{ $cycleLabel }}</div>
                                </td>
                                <td class="py-4 pr-4">
                                    <div class="font-black text-zinc-900 dark:text-white tabular-nums">
                                        ₹{{ number_format($p->total_payout, 2) }}
                                    </div>
                                    <div class="text-[10px] text-zinc-400 mt-0.5">gross payout</div>
                                </td>
                                <td class="py-4 pr-4">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg border text-[10px] font-bold {{ $statusClass }}">
                                        {{ strtoupper($statusLabel) }}
                                    </span>
                                </td>
                                <td class="py-4 pr-4">
                                    <div class="flex items-center gap-1.5">
                                        <div class="size-5 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center shrink-0">
                                            <flux:icon.users class="size-3 text-zinc-500" />
                                        </div>
                                        <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $empCount }}</span>
                                        <span class="text-[10px] text-zinc-400">paid</span>
                                    </div>
                                </td>
                                <td class="py-4 pr-6 text-right">
                                    <flux:button variant="ghost" size="sm" icon="eye"
                                        class="opacity-0 group-hover:opacity-100 transition-opacity" />
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-16 text-center">
                                    <div class="size-14 rounded-2xl bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center mx-auto mb-3">
                                        <flux:icon.document-text class="size-7 text-zinc-400" />
                                    </div>
                                    <p class="text-sm font-bold text-zinc-600 dark:text-zinc-300">No payroll cycles yet</p>
                                    <p class="text-xs text-zinc-400 mt-1">Run your first payroll to see history here</p>
                                    <a href="{{ route('payroll.process') }}" wire:navigate
                                       class="mt-4 inline-flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-bold rounded-xl transition-colors">
                                        <flux:icon.play class="size-4" /> Process Payroll
                                    </a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</flux:main>
