<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Finance Approval</h1>
            <p class="pulse-page-subtitle">Review and finalize payroll drafts submitted by HR</p>
        </div>
    </div>

    <div class="space-y-6">
        @forelse($pendingPayrolls as $payroll)
            <div class="pulse-card !p-0 overflow-hidden shadow-sm hover:shadow-md transition-shadow">
                @php $currentStep = $payroll->approvalSteps->isNotEmpty() ? $payroll->currentApprovalStep() : null; @endphp
                <div class="bg-zinc-50 border-b border-zinc-100 p-6 flex justify-between items-center dark:bg-zinc-900 dark:border-zinc-800">
                    <div>
                        <div class="flex items-center gap-3">
                            <h3 class="text-xl font-black text-zinc-900 dark:text-white uppercase tracking-tighter">{{ $payroll->month }} {{ $payroll->year }}</h3>
                            <span class="badge-manager">PENDING FINANCE</span>
                        </div>
                        <p class="text-sm text-zinc-500 mt-1">Processed by {{ $payroll->processedBy->name }} on {{ $payroll->processed_at->format('M d, H:i') }}</p>
                    </div>
                    <div class="text-right flex items-center gap-6">
                        <div>
                            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Total Net Payout</div>
                            <div class="text-2xl font-black text-zinc-900 dark:text-white">IDR {{ number_format($payroll->computeTotal(), 0) }}</div>
                        </div>
                        <div class="flex gap-2">
                            @if($payroll->approvalSteps->isEmpty())
                                <flux:button wire:click="openReject({{ $payroll->id }})" variant="ghost" class="text-red-600 hover:text-red-700">Reject</flux:button>
                                <flux:button wire:click="approve({{ $payroll->id }})" variant="primary">Approve & Finalize</flux:button>
                            @elseif($currentStep && $currentStep->isEligible(auth()->user()))
                                <flux:button wire:click="openRejectStep({{ $currentStep->id }})" variant="ghost" class="text-red-600 hover:text-red-700">Reject</flux:button>
                                <flux:button wire:click="approveStep({{ $currentStep->id }})" variant="primary">Approve Step</flux:button>
                            @elseif($currentStep)
                                <flux:badge color="amber">Waiting on {{ $currentStep->label }}</flux:badge>
                            @endif
                        </div>
                    </div>
                </div>

                @if($payroll->approvalSteps->isNotEmpty())
                    <div class="px-6 py-4 border-b border-zinc-100 bg-zinc-50/50 dark:border-zinc-800 dark:bg-zinc-900/40">
                        <h4 class="text-xs font-bold text-zinc-400 uppercase tracking-widest mb-3">Approval Steps</h4>
                        <div class="flex flex-wrap items-center gap-3">
                            @foreach($payroll->approvalSteps as $step)
                                <div class="flex items-center gap-2 text-sm">
                                    @if($step->status === 'approved')
                                        <flux:icon.check-circle class="size-4 text-emerald-500" />
                                        <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $step->label }}</span>
                                        <span class="text-xs text-zinc-400">— {{ $step->approver?->name ?? 'approved' }}</span>
                                    @elseif($step->status === 'rejected')
                                        <flux:icon.x-circle class="size-4 text-rose-500" />
                                        <span class="font-semibold text-rose-600 dark:text-rose-400">{{ $step->label }} — rejected</span>
                                    @elseif($step->status === 'skipped')
                                        <flux:icon.minus-circle class="size-4 text-zinc-300" />
                                        <span class="text-zinc-400 line-through">{{ $step->label }}</span>
                                    @elseif($currentStep?->id === $step->id)
                                        <flux:icon.arrow-right-circle class="size-4 text-amber-500" />
                                        <span class="font-semibold text-amber-600 dark:text-amber-400">{{ $step->label }} — pending</span>
                                    @else
                                        <flux:icon.clock class="size-4 text-zinc-300" />
                                        <span class="text-zinc-400">{{ $step->label }}</span>
                                    @endif
                                </div>
                                @if(! $loop->last)
                                    <flux:icon.chevron-right class="size-3 text-zinc-300" />
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="p-6 grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div class="pulse-card bg-zinc-50/50 p-4 border-none dark:bg-zinc-900/50">
                        <div class="text-[10px] font-bold text-zinc-400 uppercase">Salaries</div>
                        <div class="text-lg font-bold text-zinc-900 dark:text-white">IDR {{ number_format($payroll->total_payout, 0) }}</div>
                    </div>
                    <div class="pulse-card bg-zinc-50/50 p-4 border-none dark:bg-zinc-900/50">
                        <div class="text-[10px] font-bold text-zinc-400 uppercase">Overtime</div>
                        <div class="text-lg font-bold text-zinc-900 dark:text-white">IDR {{ number_format($payroll->ot_amount, 0) }}</div>
                    </div>
                    <div class="pulse-card bg-zinc-50/50 p-4 border-none dark:bg-zinc-900/50">
                        <div class="text-[10px] font-bold text-zinc-400 uppercase">Incentives</div>
                        <div class="text-lg font-bold text-zinc-900 dark:text-white">IDR {{ number_format($payroll->incentives, 0) }}</div>
                    </div>
                    <div class="pulse-card bg-zinc-50/50 p-4 border-none dark:bg-zinc-900/50">
                        <div class="text-[10px] font-bold text-zinc-400 uppercase font-black text-red-500 uppercase">Deductions</div>
                        <div class="text-lg font-bold text-red-500">- IDR {{ number_format($payroll->deductions, 0) }}</div>
                    </div>
                </div>

                <div class="px-6 pb-6">
                    <h4 class="text-xs font-bold text-zinc-400 uppercase tracking-widest mb-4">Employee Breakdown</h4>
                    <div class="overflow-x-auto -mx-6">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-zinc-50/30 dark:bg-zinc-900/30">
                                    <th class="py-2 pl-6 text-left text-[10px] font-bold text-zinc-400 uppercase">Employee</th>
                                    <th class="py-2 text-left text-[10px] font-bold text-zinc-400 uppercase">Gross</th>
                                    <th class="py-2 text-left text-[10px] font-bold text-zinc-400 uppercase">Deductions</th>
                                    <th class="py-2 pr-6 text-right text-[10px] font-bold text-zinc-400 uppercase">Net Pay</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                                @foreach($payroll->payslips as $slp)
                                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/20">
                                        <td class="py-3 pl-6">
                                            <div class="font-bold text-zinc-900 dark:text-white">{{ $slp->employee?->user?->name ?? 'Unknown Employee' }}</div>
                                            <div class="text-[10px] text-zinc-400 uppercase">{{ $slp->employee?->job_title ?? 'Unknown Title' }}</div>
                                        </td>
                                        <td class="py-3 text-zinc-600 dark:text-zinc-300">IDR {{ number_format($slp->gross_salary, 0) }}</td>
                                        <td class="py-3 text-red-500">IDR {{ number_format($slp->total_deductions, 0) }}</td>
                                        <td class="py-3 pr-6 text-right font-black text-zinc-900 dark:text-white">IDR {{ number_format($slp->net_salary, 0) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @empty
            <div class="pulse-card py-20 text-center space-y-4">
                <flux:icon.check-circle class="size-12 text-zinc-200 mx-auto" />
                <div>
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">No Pending Approvals</h3>
                    <p class="text-sm text-zinc-500">All submitted payrolls have been processed.</p>
                </div>
            </div>
        @endforelse
    </div>

    <flux:modal name="reject-payroll" class="max-w-lg">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Reject payroll</flux:heading>
                <flux:subheading>Sends the payroll back to Draft so HR can correct it. The reason is included in the payroll audit trail.</flux:subheading>
            </div>

            <flux:textarea wire:model="rejectReason" label="Reason (optional)" placeholder="e.g. missing OT approvals for Engineering, incorrect LWP for 2 employees" rows="3" />

            <div class="flex justify-end gap-2">
                <flux:button @click="$flux.modal('reject-payroll').close()" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="confirmReject" variant="danger">Reject Payroll</flux:button>
            </div>
        </div>
    </flux:modal>
</flux:main>
