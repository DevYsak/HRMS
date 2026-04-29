<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Payroll Overview</h1>
            <p class="pulse-page-subtitle">Summary of company-wide salary disbursements</p>
        </div>
        <div class="flex gap-3">
            <flux:button href="{{ route('reports.payroll-summary', ['month' => now()->month, 'year' => now()->year]) }}" variant="ghost" icon="arrow-down-tray" target="_blank">Export PDF</flux:button>
            <flux:button href="{{ route('payroll.process') }}" variant="primary" icon="play" wire:navigate>Process Payroll</flux:button>
            <flux:button href="{{ route('payroll.components') }}" variant="ghost" icon="cog-6-tooth" wire:navigate>Settings</flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="pulse-card">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Expected Payout ({{ \Illuminate\Support\Carbon::now()->format('M') }})</div>
            <div class="text-3xl font-black mt-1 text-zinc-900 dark:text-white">
                ${{ number_format($totalMonthlyPayout, 2) }}
            </div>
            <div class="mt-4 flex items-center gap-1 text-xs text-green-600">
                <flux:icon.arrow-trending-up class="size-3" /> 2.4% vs last month
            </div>
        </div>
        <div class="pulse-card">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Active Components</div>
            <div class="text-3xl font-black mt-1 text-zinc-900 dark:text-white">
                {{ \App\Models\SalaryComponent::where('is_active', true)->count() }}
            </div>
            <div class="mt-4 text-xs text-zinc-500">Earnings & Deductions combined</div>
        </div>
        <div class="pulse-card">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Pending Processes</div>
            <div class="text-3xl font-black mt-1 text-zinc-900 dark:text-white">
                {{ \App\Models\Payroll::where('status', 'draft')->count() }}
            </div>
            <div class="mt-4 text-xs text-zinc-500">Drafts awaiting finalization</div>
        </div>
    </div>

    <div class="pulse-card">
        <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-6">Recent Payroll Cycles</h3>
        <div class="overflow-x-auto -mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Cycle Period</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Total Disbursement</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Employees</th>
                        <th class="pb-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @foreach($recentPayrolls as $p)
                        <tr class="hover:bg-zinc-50/50 transition-colors">
                            <td class="py-4 pl-6 pr-4 font-bold text-zinc-900 dark:text-white">
                                {{ $p->month }} {{ $p->year }}
                            </td>
                            <td class="py-4 pr-4 font-medium text-zinc-700 dark:text-zinc-300">
                                ${{ number_format($p->total_payout, 2) }}
                            </td>
                            <td class="py-4 pr-4">
                                <span class="badge-{{ $p->status === 'finalized' ? 'on_time' : 'manager' }}">
                                    {{ strtoupper($p->status) }}
                                </span>
                            </td>
                            <td class="py-4 pr-4 text-zinc-500">
                                {{ $p->payslips()->count() }} Paid
                            </td>
                            <td class="py-4 pr-6 text-right">
                                <flux:button variant="ghost" size="sm" icon="eye" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</flux:main>
