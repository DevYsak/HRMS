<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">My Disciplinary Records</h1>
            <p class="pulse-page-subtitle">View and acknowledge warnings or disciplinary actions</p>
        </div>
    </div>

    <div class="space-y-6">
        @forelse($warnings as $warning)
            <div class="pulse-card relative overflow-hidden group">
                <div class="relative z-10 p-6">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <div class="flex items-center gap-3 mb-1">
                                <span class="badge-{{ $warning->warningTypeColor() }}">{{ $warning->warningTypeLabel() }}</span>
                                <span class="text-xs text-zinc-500 font-medium">Issued: {{ \Carbon\Carbon::parse($warning->issue_date)->format('M d, Y') }}</span>
                            </div>
                            <h3 class="text-lg font-bold text-zinc-900 dark:text-white">{{ $warning->reason }}</h3>
                        </div>
                        <div>
                            <span class="badge-{{ $warning->statusColor() }} px-3 py-1 text-xs">{{ strtoupper($warning->statusLabel()) }}</span>
                        </div>
                    </div>
                    
                    @if($warning->description)
                        <p class="text-sm text-zinc-600 dark:text-zinc-400 line-clamp-2 mb-4">{{ $warning->description }}</p>
                    @endif
                    
                    <div class="flex justify-end border-t border-zinc-100 dark:border-zinc-800 pt-4 mt-2">
                        <flux:button wire:click="viewWarning({{ $warning->id }})" variant="{{ $warning->status === 'issued' ? 'primary' : 'ghost' }}" size="sm">
                            {{ $warning->status === 'issued' ? 'Review & Acknowledge' : 'View Details' }}
                        </flux:button>
                    </div>
                </div>
            </div>
        @empty
            <div class="pulse-card p-12 text-center">
                <div class="bg-green-50 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 dark:bg-green-900/20">
                    <flux:icon.shield-check class="size-8 text-green-600 dark:text-green-400" />
                </div>
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-1">Clean Record</h3>
                <p class="text-zinc-500 text-sm">You have no disciplinary warnings or actions on your record.</p>
            </div>
        @endforelse
    </div>

    {{-- View / Acknowledge Warning Modal --}}
    @if($showViewModal && $activeWarning)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showViewModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showViewModal', false)"></div>
            <div class="relative w-full max-w-2xl bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showViewModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

                <div class="flex items-start justify-between mb-6">
                    <div>
                        <flux:heading size="lg">Warning Letter Details</flux:heading>
                        <flux:subheading>Issued on {{ \Carbon\Carbon::parse($activeWarning->issue_date)->format('M d, Y') }}</flux:subheading>
                    </div>
                    <span class="badge-{{ $activeWarning->statusColor() }} px-2 py-1">{{ strtoupper($activeWarning->statusLabel()) }}</span>
                </div>

                <div class="space-y-6">
                    <div class="grid grid-cols-2 gap-4 text-sm bg-zinc-50 dark:bg-zinc-900 p-4 rounded-xl">
                        <div>
                            <span class="text-[10px] font-bold text-zinc-400 uppercase block">Warning Type</span>
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $activeWarning->warningTypeLabel() }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-zinc-400 uppercase block">Issued By</span>
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $activeWarning->issuedBy?->name ?? 'Management' }}</span>
                        </div>
                        @if($activeWarning->next_review_date)
                            <div class="col-span-2">
                                <span class="text-[10px] font-bold text-zinc-400 uppercase block">Next Review / Check-in</span>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ \Carbon\Carbon::parse($activeWarning->next_review_date)->format('M d, Y') }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="space-y-4">
                        <div>
                            <span class="text-xs font-bold text-zinc-500 uppercase block mb-1">Reason for Action</span>
                            <p class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">{{ $activeWarning->reason }}</p>
                        </div>
                        @if($activeWarning->description)
                            <div>
                                <span class="text-xs font-bold text-zinc-500 uppercase block mb-1">Detailed Description</span>
                                <div class="bg-white dark:bg-zinc-950 border border-zinc-100 dark:border-zinc-800 p-4 rounded-lg text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $activeWarning->description }}</div>
                            </div>
                        @endif
                    </div>

                    @if($activeWarning->status === 'issued')
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 space-y-4 dark:bg-amber-900/10 dark:border-amber-800/30">
                            <h4 class="font-bold text-amber-800 dark:text-amber-400">Acknowledgement Required</h4>
                            <p class="text-sm text-amber-700 dark:text-amber-500">By acknowledging this warning, you are confirming receipt and understanding of the contents. Acknowledgement does not necessarily imply agreement.</p>
                            
                            <flux:field>
                                <flux:label>Optional Comments / Rebuttal</flux:label>
                                <flux:textarea wire:model="acknowledgementText" rows="2" placeholder="You may provide comments regarding this warning..." />
                                <flux:error name="acknowledgementText" />
                            </flux:field>
                            
                            <div class="flex justify-end">
                                <flux:button wire:click="acknowledgeWarning" variant="primary">I Acknowledge Receipt</flux:button>
                            </div>
                        </div>
                    @elseif($activeWarning->isAcknowledged())
                        <div class="bg-zinc-50 border border-zinc-200 rounded-xl p-4 dark:bg-zinc-900 dark:border-zinc-700">
                            @php $ack = $activeWarning->acknowledgements->first(); @endphp
                            <h4 class="text-xs font-bold text-zinc-500 uppercase tracking-wider mb-2">Your Acknowledgement</h4>
                            <p class="text-xs text-zinc-600 dark:text-zinc-400 mb-2">Acknowledged on {{ $ack?->acknowledged_at?->format('M d, Y H:i') }}</p>
                            @if($ack?->acknowledgement_text)
                                <div class="bg-white dark:bg-zinc-800 p-3 rounded-lg text-sm italic text-zinc-700 dark:text-zinc-300">
                                    "{{ $ack->acknowledgement_text }}"
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</flux:main>
