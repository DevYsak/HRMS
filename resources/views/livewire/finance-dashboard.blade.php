<flux:main class="min-h-screen bg-[#FAFAFA] font-['DM_Sans'] dark:bg-[#0B1220]">

    @php
        $hour = now()->hour;
        $greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
        $firstName = \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->first();
        $timeContext = $hour < 12 ? 'Morning finance brief — payroll window open.' : ($hour < 17 ? 'Afternoon — review and verify payroll.' : 'End of day — financial wrap-up.');
    @endphp
    {{-- Premium Header --}}
    <div class="p-4 pb-0 md:p-6 md:pb-0">
        <x-pulse.dashboard-header :title="$greeting.', '.$firstName" :subtitle="$timeContext">
            <x-slot:actions>
                <flux:input wire:model.live="month" type="month" size="sm" class="rounded-xl" />
                <a href="{{ route('payroll.finance-approve') }}" wire:navigate
                   class="inline-flex items-center gap-2 rounded-xl bg-brand-500 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-brand-600">
                    <flux:icon.check-circle class="size-4" /> Finance Approval
                </a>
            </x-slot:actions>
        </x-pulse.dashboard-header>
    </div>

    <div class="p-4 md:p-6 space-y-5">
    {{-- Legacy header placeholder for downstream code compat --}}
    <div>
    </div>

    {{-- Payroll/compensation widgets temporarily hidden (functionality untouched). --}}
    @php $showPayroll = false; @endphp
    @if($showPayroll)
    {{-- KPI Bar --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="pulse-card p-5 text-center">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Cycle A Payout</div>
            <div class="text-2xl font-black text-green-600 mt-2">₹{{ $cycleARun ? number_format($cycleARun->total_payout, 0) : '—' }}</div>
            <div class="text-[10px] mt-1 {{ $cycleARun?->status === 'finalized' ? 'text-green-500' : 'text-amber-500' }}">{{ $cycleARun ? strtoupper($cycleARun->status) : 'NOT RUN' }}</div>
        </div>
        <div class="pulse-card p-5 text-center">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Cycle B Payout</div>
            <div class="text-2xl font-black text-blue-600 mt-2">₹{{ $cycleBRun ? number_format($cycleBRun->total_payout, 0) : '—' }}</div>
            <div class="text-[10px] mt-1 {{ $cycleBRun?->status === 'finalized' ? 'text-green-500' : 'text-amber-500' }}">{{ $cycleBRun ? strtoupper($cycleBRun->status) : 'NOT RUN' }}</div>
        </div>
        <div class="pulse-card p-5 text-center">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Approved Incentives</div>
            <div class="text-2xl font-black text-purple-600 mt-2">₹{{ number_format($approvedIncentivesTotal, 0) }}</div>
            <div class="text-[10px] text-amber-500 mt-1">{{ $pendingIncentives }} pending approval</div>
        </div>
        <div class="pulse-card p-5 text-center">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Reimbursements</div>
            <div class="text-2xl font-black text-orange-500 mt-2">₹{{ number_format($approvedReimbursementsTotal, 0) }}</div>
            <div class="text-[10px] text-amber-500 mt-1">{{ $pendingReimbursements }} pending · {{ $pendingEncashments }} encashments</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Cycle A Sign-off --}}
        <div class="pulse-card p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                    <flux:icon.banknotes class="size-4 text-green-500" /> Cycle A — {{ now()->format('F Y') }}
                </h3>
                @if($cycleARun && $cycleARun->status === 'pending_finance')
                    <flux:button :href="route('payroll.finance-approve')" wire:navigate variant="primary" size="sm" icon="check">Sign Off</flux:button>
                @elseif($cycleARun?->status === 'finalized')
                    <span class="text-xs font-bold px-2 py-1 rounded-full bg-green-100 text-green-700">FINALIZED</span>
                @endif
            </div>
            @if($cycleARun)
                <div class="space-y-2">
                    <div class="flex justify-between text-sm"><span class="text-zinc-500">Total Payout</span><span class="font-bold text-zinc-900 dark:text-white">₹{{ number_format($cycleARun->total_payout, 0) }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-zinc-500">Employees</span><span class="font-bold text-zinc-900 dark:text-white">{{ $cycleARun->payslips->count() }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-zinc-500">Processed</span><span class="font-bold text-zinc-900 dark:text-white">{{ $cycleARun->processed_at?->format('d M H:i') ?? '—' }}</span></div>
                </div>
            @else
                <p class="text-sm text-zinc-400 py-4 text-center">Cycle A payroll not yet generated for this month.</p>
                <flux:button :href="route('payroll.process')" wire:navigate variant="ghost" size="sm" class="w-full">Go to Payroll Run →</flux:button>
            @endif
        </div>

        {{-- Cycle B Sign-off --}}
        <div class="pulse-card p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                    <flux:icon.banknotes class="size-4 text-blue-500" /> Cycle B — {{ now()->format('F Y') }}
                </h3>
                @if($cycleBRun && $cycleBRun->status === 'pending_finance')
                    <flux:button :href="route('payroll.finance-approve')" wire:navigate variant="primary" size="sm" icon="check">Sign Off</flux:button>
                @elseif($cycleBRun?->status === 'finalized')
                    <span class="text-xs font-bold px-2 py-1 rounded-full bg-green-100 text-green-700">FINALIZED</span>
                @endif
            </div>
            @if($cycleBRun)
                <div class="space-y-2">
                    <div class="flex justify-between text-sm"><span class="text-zinc-500">Total Payout</span><span class="font-bold text-zinc-900 dark:text-white">₹{{ number_format($cycleBRun->total_payout, 0) }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-zinc-500">Employees</span><span class="font-bold text-zinc-900 dark:text-white">{{ $cycleBRun->payslips->count() }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-zinc-500">Processed</span><span class="font-bold text-zinc-900 dark:text-white">{{ $cycleBRun->processed_at?->format('d M H:i') ?? '—' }}</span></div>
                </div>
            @else
                <p class="text-sm text-zinc-400 py-4 text-center">Cycle B payroll not yet generated for this month.</p>
                <flux:button :href="route('payroll.process')" wire:navigate variant="ghost" size="sm" class="w-full">Go to Payroll Run →</flux:button>
            @endif
        </div>
    </div>

    {{-- OT + Adjustments Row --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="pulse-card p-5 text-center">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">OT Payout (Month)</div>
            <div class="text-3xl font-black text-amber-500 mt-2">₹{{ number_format($monthlyOtAmount, 0) }}</div>
            <div class="text-xs text-zinc-400 mt-1">@ ₹100/hr · Approved OT</div>
        </div>
        <div class="pulse-card p-5 text-center">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Pending Incentives</div>
            <div class="text-3xl font-black text-purple-500 mt-2">{{ $pendingIncentives }}</div>
            <div class="text-xs text-zinc-400 mt-1">Awaiting Finance approval</div>
            <flux:button :href="route('payroll.incentives')" wire:navigate size="sm" variant="ghost" class="mt-2">Review →</flux:button>
        </div>
        <div class="pulse-card p-5 text-center">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Pending Encashments</div>
            <div class="text-3xl font-black text-orange-500 mt-2">{{ $pendingEncashments }}</div>
            <div class="text-xs text-zinc-400 mt-1">Leave encashment requests</div>
            <flux:button :href="route('payroll.reimbursements')" wire:navigate size="sm" variant="ghost" class="mt-2">Review →</flux:button>
        </div>
    </div>

    {{-- Payslip Breakdown per Cycle --}}
    @foreach([['label' => 'Cycle A', 'run' => $cycleARun], ['label' => 'Cycle B', 'run' => $cycleBRun]] as $cycleData)
        @if($cycleData['run'])
        <div class="pulse-card">
            <div class="flex items-center justify-between p-6 border-b border-zinc-100 dark:border-zinc-800">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ $cycleData['label'] }} — Payslip Breakdown</h3>
                <span class="text-xs text-zinc-400">{{ $cycleData['run']->payslips->count() }} employees</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-zinc-800">
                            <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Employee</th>
                            <th class="pb-3 pr-4 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400">Gross</th>
                            <th class="pb-3 pr-4 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400">Deductions</th>
                            <th class="pb-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400">Net</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800">
                        @foreach($cycleData['run']->payslips as $slip)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                            <td class="py-3 pl-6 pr-4">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $slip->employee->user->name ?? '—' }}</div>
                                <div class="text-xs text-zinc-400">{{ $slip->employee->department?->name ?? '' }}</div>
                            </td>
                            <td class="py-3 pr-4 text-right text-zinc-600 dark:text-zinc-300">₹{{ number_format($slip->gross_salary, 0) }}</td>
                            <td class="py-3 pr-4 text-right text-red-500">−₹{{ number_format($slip->total_deductions, 0) }}</td>
                            <td class="py-3 pr-6 text-right font-bold text-zinc-900 dark:text-white">₹{{ number_format($slip->net_salary, 0) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/50">
                            <td class="py-3 pl-6 pr-4 font-bold text-zinc-900 dark:text-white">Total</td>
                            <td class="py-3 pr-4 text-right font-bold text-zinc-900 dark:text-white">₹{{ number_format($cycleData['run']->payslips->sum('gross_salary'), 0) }}</td>
                            <td class="py-3 pr-4 text-right font-bold text-red-500">−₹{{ number_format($cycleData['run']->payslips->sum('total_deductions'), 0) }}</td>
                            <td class="py-3 pr-6 text-right font-black text-brand-600">₹{{ number_format($cycleData['run']->total_payout, 0) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        @endif
    @endforeach

    {{-- OT Summary Breakdown --}}
    @if($otSummary->isNotEmpty())
    <div class="pulse-card">
        <div class="flex items-center justify-between p-6 border-b border-zinc-100 dark:border-zinc-800">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Approved Overtime Summary</h3>
            <span class="text-xs text-zinc-400">Rate: ₹100/hr</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Employee</th>
                        <th class="pb-3 pr-4 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400">OT Hours</th>
                        <th class="pb-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400">OT Payout</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800">
                    @foreach($otSummary as $ot)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                        <td class="py-3 pl-6 pr-4">
                            <div class="font-medium text-zinc-900 dark:text-white">{{ $ot->employee->user->name ?? '—' }}</div>
                            <div class="text-xs text-zinc-400">{{ $ot->employee->department?->name ?? '' }}</div>
                        </td>
                        <td class="py-3 pr-4 text-right text-zinc-600 dark:text-zinc-300">{{ number_format($ot->total_hours, 1) }}h</td>
                        <td class="py-3 pr-6 text-right font-bold text-zinc-900 dark:text-white">₹{{ number_format($ot->total_amount, 0) }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/30">
                        <td class="py-3 pl-6 pr-4 font-bold text-zinc-900 dark:text-white">Total</td>
                        <td class="py-3 pr-4 text-right font-bold text-zinc-900 dark:text-white">{{ number_format($otSummary->sum('total_hours'), 1) }}h</td>
                        <td class="py-3 pr-6 text-right font-black text-amber-600">₹{{ number_format($otSummary->sum('total_amount'), 0) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    @endif

    @else
        <div class="pulse-card text-center py-16">
            <flux:icon.banknotes class="mx-auto size-10 text-zinc-300 dark:text-zinc-600" />
            <p class="mt-3 text-sm font-semibold text-zinc-500 dark:text-zinc-400">Payroll &amp; compensation widgets are temporarily hidden.</p>
            <p class="mt-1 text-xs text-zinc-400">Payroll functionality remains available in the Payroll module.</p>
        </div>
    @endif

    </div>{{-- end p-4 md:p-6 --}}

</flux:main>
