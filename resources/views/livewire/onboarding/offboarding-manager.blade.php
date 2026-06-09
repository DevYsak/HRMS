<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Offboarding Management</h1>
            <p class="pulse-page-subtitle">Process employee exits, asset returns, and offboarding checklists.</p>
        </div>
    </div>

    <div class="flex items-center gap-4 border-b border-zinc-200 dark:border-zinc-800 mb-6">
        <button 
            wire:click="$set('activeTab', 'offboarding')"
            class="pb-3 px-1 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'offboarding' ? 'border-brand-500 text-brand-600 dark:text-brand-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
        >
            <div class="flex items-center gap-2">
                <flux:icon.user-minus class="size-4" /> Employee Exits
            </div>
        </button>
        <button 
            wire:click="$set('activeTab', 'analytics')"
            class="pb-3 px-1 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'analytics' ? 'border-brand-500 text-brand-600 dark:text-brand-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
        >
            <div class="flex items-center gap-2">
                <flux:icon.chart-pie class="size-4" /> Progress Tracking
            </div>
        </button>
    </div>

    @if($activeTab === 'offboarding')
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
                            @if($taskStats)
                                <div class="mt-3 flex items-center gap-2">
                                    <div class="w-48 h-2 bg-brand-100 dark:bg-brand-900/30 rounded-full overflow-hidden">
                                        <div class="h-full bg-brand-500 transition-all duration-500" style="width: {{ $taskStats['total'] > 0 ? ($taskStats['completed'] / $taskStats['total'] * 100) : 0 }}%"></div>
                                    </div>
                                    <span class="text-xs font-bold text-brand-700 dark:text-brand-400">{{ $taskStats['completed'] }} / {{ $taskStats['total'] }} tasks</span>
                                </div>
                            @endif
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
    @elseif($activeTab === 'analytics')
        @if($analytics)
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <div class="pulse-card border-l-4 border-l-brand-500">
                    <div class="text-zinc-500 text-sm font-medium mb-1">Total Offboarding Tasks</div>
                    <div class="text-3xl font-bold text-zinc-900 dark:text-white">{{ $analytics->total ?? 0 }}</div>
                </div>
                
                <div class="pulse-card border-l-4 border-l-green-500">
                    <div class="text-zinc-500 text-sm font-medium mb-1">Completed Tasks</div>
                    <div class="text-3xl font-bold text-zinc-900 dark:text-white">{{ $analytics->completed ?? 0 }}</div>
                    @php 
                        $overallProgress = ($analytics->total ?? 0) > 0 ? round((($analytics->completed ?? 0) / $analytics->total) * 100) : 0; 
                    @endphp
                    <div class="w-full h-1.5 bg-zinc-100 dark:bg-zinc-800 rounded-full mt-3 overflow-hidden">
                        <div class="h-full bg-green-500" style="width: {{ $overallProgress }}%"></div>
                    </div>
                    <div class="text-xs text-zinc-500 mt-1 text-right">{{ $overallProgress }}%</div>
                </div>
                
                <div class="pulse-card border-l-4 border-l-red-500">
                    <div class="text-zinc-500 text-sm font-medium mb-1">Overdue Tasks</div>
                    <div class="text-3xl font-bold text-red-600">{{ $analytics->overdue ?? 0 }}</div>
                </div>
                
                <div class="pulse-card border-l-4 border-l-orange-500">
                    <div class="text-zinc-500 text-sm font-medium mb-1">Blocked/In Progress</div>
                    <div class="text-3xl font-bold text-orange-600">{{ ($analytics->blocked ?? 0) + ($analytics->in_progress ?? 0) }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="pulse-card">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-6">Department Progress</h3>
                    
                    <div class="space-y-4">
                        @forelse($departmentBreakdown as $dept)
                            @php 
                                $deptProgress = $dept->total > 0 ? round(($dept->completed / $dept->total) * 100) : 0; 
                            @endphp
                            <div>
                                <div class="flex items-center justify-between text-sm mb-2">
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $dept->department_name ?? 'Unassigned' }}</span>
                                    <span class="text-zinc-500">{{ $dept->completed }} / {{ $dept->total }} ({{ $deptProgress }}%)</span>
                                </div>
                                <div class="w-full h-2 bg-zinc-100 dark:bg-zinc-800 rounded-full overflow-hidden">
                                    <div class="h-full bg-blue-500 transition-all duration-500" style="width: {{ $deptProgress }}%"></div>
                                </div>
                            </div>
                        @empty
                            <div class="text-center text-zinc-500 text-sm py-4">No offboarding data per department available.</div>
                        @endforelse
                    </div>
                </div>

                <div class="pulse-card">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-6">Progress By Task Owner</h3>
                    
                    <div class="space-y-4">
                        @forelse($ownerBreakdown as $owner)
                            @php 
                                $ownerProgress = $owner->total > 0 ? round(($owner->completed / $owner->total) * 100) : 0; 
                            @endphp
                            <div>
                                <div class="flex items-center justify-between text-sm mb-2">
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100 capitalize">{{ str_replace('_', ' ', $owner->owner_role) }}</span>
                                    <span class="text-zinc-500">{{ $owner->completed }} / {{ $owner->total }} ({{ $ownerProgress }}%)</span>
                                </div>
                                <div class="w-full h-2 bg-zinc-100 dark:bg-zinc-800 rounded-full overflow-hidden">
                                    <div class="h-full bg-purple-500 transition-all duration-500" style="width: {{ $ownerProgress }}%"></div>
                                </div>
                            </div>
                        @empty
                            <div class="text-center text-zinc-500 text-sm py-4">No offboarding data per owner available.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    @endif
</flux:main>
