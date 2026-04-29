<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Run Payroll</h1>
            <p class="pulse-page-subtitle">Generate and verify monthly salary payments</p>
        </div>
        
        <div class="flex gap-4 items-center">
             <div class="flex gap-2">
                <flux:select wire:model.live="month" size="sm" class="w-32">
                    @foreach(['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'] as $m)
                        <option value="{{ $m }}">{{ $m }}</option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="year" size="sm" class="w-24">
                    <option value="2026">2026</option>
                    <option value="2025">2025</option>
                </flux:select>
             </div>
             
             @if(!$currentPayroll || $currentPayroll->status === 'draft')
                <flux:button wire:click="startProcessing" variant="primary" icon="play">
                    {{ $currentPayroll ? 'Re-generate Draft' : 'Generate Draft' }}
                </flux:button>
             @endif
        </div>
    </div>

    @if($currentPayroll)
        {{-- Stats Header --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div class="pulse-card">
                <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Gross Payout</div>
                <div class="text-2xl font-black mt-1 text-zinc-900 dark:text-white">
                    IDR {{ number_format($currentPayroll->total_payout, 0) }}
                </div>
            </div>
            <div class="pulse-card">
                <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Employee Count</div>
                <div class="text-2xl font-black mt-1 text-zinc-900 dark:text-white">
                    {{ $currentPayroll->payslips->count() }}
                </div>
            </div>
            <div class="pulse-card">
                <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Status</div>
                <div class="mt-2">
                    @php
                        $statusBadge = match($currentPayroll->status) {
                            'finalized' => 'on_time',
                            'pending_finance' => 'manager',
                            'draft' => 'rejected',
                            default => 'rejected'
                        };
                    @endphp
                    <span class="badge-{{ $statusBadge }}">
                        {{ strtoupper(str_replace('_', ' ', $currentPayroll->status)) }}
                    </span>
                </div>
            </div>
            <div class="pulse-card">
                <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Last Processed</div>
                <div class="text-lg font-bold mt-1 text-zinc-500">
                    {{ $currentPayroll->processed_at ? $currentPayroll->processed_at->format('M d, H:i') : 'Never' }}
                </div>
            </div>
        </div>

        {{-- Draft List --}}
        <div class="pulse-card overflow-hidden">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Draft Payslips</h3>
                @if($currentPayroll->status === 'draft')
                    <flux:button wire:click="submitToFinance" icon="paper-airplane" class="bg-amber-500 hover:bg-amber-600 text-white border-amber-500">Submit for Finance Approval</flux:button>
                @endif
                @if(in_array($currentPayroll->status, ['draft', 'pending_finance']))
                    <flux:button wire:click="finalize" wire:confirm="Finalize payroll? This will mark all payslips as paid and notify employees." variant="primary" icon="check-circle">Finalize &amp; Pay</flux:button>
                @endif
            </div>

            <div class="-mx-6 border-t border-zinc-100 dark:border-zinc-800">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-zinc-50/50 dark:bg-zinc-900/50 border-b border-zinc-100 dark:border-zinc-800">
                            <th class="py-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Employee</th>
                            <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Gross</th>
                            <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Deductions</th>
                            <th class="py-3 pr-4 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Net Payable</th>
                            <th class="py-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                        @foreach($currentPayroll->payslips as $slip)
                            <tr class="hover:bg-zinc-50/30 transition-colors">
                                <td class="py-4 pl-6 pr-4">
                                    <div class="font-bold text-zinc-900 dark:text-white">{{ $slip->employee->user->name }}</div>
                                    <div class="text-xs text-zinc-500">{{ $slip->employee->jobTitle?->name ?? 'No Job Title' }}</div>
                                </td>
                                <td class="py-4 pr-4 font-medium text-zinc-700 dark:text-zinc-300">
                                    IDR {{ number_format($slip->gross_salary, 0) }}
                                </td>
                                <td class="py-4 pr-4 font-medium text-red-600">
                                    - IDR {{ number_format($slip->total_deductions, 0) }}
                                </td>
                                <td class="py-4 pr-4 text-right text-lg font-black text-zinc-900 dark:text-white">
                                    IDR {{ number_format($slip->net_salary, 0) }}
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
    @else
        <div class="pulse-card py-24 flex flex-col items-center justify-center text-center">
            <div class="size-20 bg-brand-50 rounded-full flex items-center justify-center mb-6 dark:bg-brand-900/20">
                <flux:icon.banknotes class="size-10 text-brand-600" />
            </div>
            <h2 class="text-xl font-black text-zinc-900 dark:text-white italic">Ready to process {{ $month }}'s Payroll?</h2>
            <p class="text-sm text-zinc-500 max-w-md mt-2">Generating the draft will calculate earnings and deductions for all active employees based on their latest salary profiles.</p>
            
            <div class="mt-8">
                <flux:button wire:click="startProcessing" variant="primary" icon="play">Start Current Month Payroll</flux:button>
            </div>
        </div>
    @endif
</flux:main>
