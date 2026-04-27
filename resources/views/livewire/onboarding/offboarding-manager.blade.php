<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Offboarding Management</h1>
            <p class="pulse-page-subtitle">Process employee exits, asset returns, and offboarding checklists.</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {{-- Employee Selection --}}
        <div class="lg:col-span-4 space-y-6">
            <div class="pulse-card">
                <flux:input wire:model.live="search" placeholder="Search employee..." icon="magnifying-glass" class="mb-4" />
                
                <div class="space-y-2 max-h-[600px] overflow-y-auto">
                    @forelse($employees as $emp)
                        <div 
                            wire:click="selectEmployee({{ $emp->id }})"
                            class="p-3 rounded-xl border {{ $selectedEmployeeId == $emp->id ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20' : 'border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900' }} cursor-pointer hover:border-brand-300 transition-colors"
                        >
                            <div class="font-bold text-sm text-zinc-900 dark:text-white">{{ $emp->user->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $emp->department->name ?? 'N/A' }}</div>
                        </div>
                    @empty
                        <div class="text-center text-zinc-500 text-sm py-4">No active employees found.</div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Offboarding Details --}}
        <div class="lg:col-span-8 space-y-6">
            @if($selectedEmployee)
                {{-- Exit Record Form --}}
                <div class="pulse-card">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-6">Exit Details</h3>
                    
                    <form wire:submit.prevent="processOffboarding" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <flux:input wire:model="lastWorkingDay" type="date" label="Last Working Day" required />
                            
                            <flux:select wire:model="exitType" label="Exit Type">
                                <option value="resignation">Resignation</option>
                                <option value="termination">Termination</option>
                                <option value="retirement">Retirement</option>
                                <option value="other">Other</option>
                            </flux:select>
                        </div>
                        
                        <flux:textarea wire:model="exitReason" label="Exit Reason" rows="2" />
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <flux:input wire:model="finalSettlementAmount" type="number" label="Final Settlement Amount" step="0.01" icon="banknotes" />
                            <div class="flex items-end pb-2">
                                <flux:checkbox wire:model="finalSettlementDone" label="Final Settlement Completed?" />
                            </div>
                        </div>

                        <flux:textarea wire:model="interviewNotes" label="Exit Interview Notes (Optional)" rows="3" />
                        
                        <div class="flex justify-end pt-4 border-t border-zinc-100 dark:border-zinc-800">
                            <flux:button type="submit" variant="primary">Save Exit Record</flux:button>
                        </div>
                    </form>
                </div>

                {{-- Asset Returns --}}
                <div class="pulse-card">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-6">Equipment Return</h3>
                    
                    <div class="space-y-4">
                        @forelse($selectedEmployee->assets as $asset)
                            @if($asset->status->value === 'assigned')
                                <div class="p-4 border border-zinc-200 dark:border-zinc-800 rounded-xl bg-zinc-50 dark:bg-zinc-900/50 flex flex-col md:flex-row md:items-center justify-between gap-4">
                                    <div>
                                        <div class="font-bold text-sm text-zinc-900 dark:text-white">{{ $asset->name }} ({{ $asset->type }})</div>
                                        <div class="text-xs text-zinc-500">SN: {{ $asset->serial_number }}</div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <flux:input wire:model="assetConditions.{{ $asset->id }}" placeholder="Condition (e.g. Good, Damaged)" size="sm" class="w-48" />
                                        <flux:button wire:click="returnAsset({{ $asset->id }})" size="sm" variant="primary">Mark Returned</flux:button>
                                    </div>
                                </div>
                            @elseif($asset->status->value === 'available')
                                <div class="p-4 border border-green-200 dark:border-green-900/50 rounded-xl bg-green-50 dark:bg-green-900/20 flex items-center justify-between">
                                    <div>
                                        <div class="font-bold text-sm text-green-900 dark:text-green-400">{{ $asset->name }}</div>
                                        <div class="text-xs text-green-700 dark:text-green-600">Returned on {{ $asset->returned_date?->format('M d, Y') }} | Condition: {{ $asset->condition_on_return }}</div>
                                    </div>
                                    <flux:icon.check-circle class="size-6 text-green-500" />
                                </div>
                            @endif
                        @empty
                            <div class="text-center text-zinc-500 text-sm py-4">No assets currently assigned to this employee.</div>
                        @endforelse
                    </div>
                </div>

                {{-- Offboarding Checklist Link --}}
                <div class="pulse-card flex items-center justify-between bg-brand-50 dark:bg-brand-900/20 border border-brand-200 dark:border-brand-900/50">
                    <div>
                        <h3 class="text-md font-bold text-brand-900 dark:text-brand-400">Offboarding Checklist</h3>
                        <p class="text-sm text-brand-700 dark:text-brand-500 mt-1">Manage departmental clearance tasks</p>
                    </div>
                    <flux:button href="{{ route('employees.offboarding', $selectedEmployee->id) }}" variant="primary">View Tasks</flux:button>
                </div>

                {{-- Experience Letter --}}
                @if($selectedEmployee->exitRecord && $selectedEmployee->exitRecord->last_working_day)
                    <div class="pulse-card">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Experience Letter</h3>
                                <p class="text-sm text-zinc-500 mt-1">Generate PDF for the offboarded employee</p>
                            </div>
                            <flux:button href="{{ route('documents.experience-letter', $selectedEmployee->id) }}" variant="ghost" icon="document-arrow-down" target="_blank">Generate PDF</flux:button>
                        </div>
                    </div>
                @endif
            @else
                <div class="pulse-card py-20 text-center flex flex-col items-center justify-center space-y-4">
                    <div class="size-16 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                        <flux:icon.user-minus class="size-8 text-zinc-400" />
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Select an Employee</h3>
                        <p class="text-zinc-500">Choose an employee from the list to manage their offboarding process.</p>
                    </div>
                </div>
            @endif
        </div>
    </div>
</flux:main>
