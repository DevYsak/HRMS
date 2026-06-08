<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">My Promotions &amp; Rewards</h1>
            <p class="pulse-page-subtitle">Track recommendations for promotions, raises, and bonuses</p>
        </div>
    </div>

    <div class="space-y-6">
        @forelse($recommendations as $recommendation)
            <div class="pulse-card relative overflow-hidden group">
                <div class="relative z-10 p-6">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <div class="flex items-center gap-3 mb-1">
                                <span class="badge-onboarding">{{ $recommendation->recommendationTypeLabel() }}</span>
                                <span class="text-xs text-zinc-500 font-medium">Recommended {{ $recommendation->created_at->format('M d, Y') }}</span>
                            </div>
                            <h3 class="text-lg font-bold text-zinc-900 dark:text-white">
                                {{ $recommendation->proposed_role ?? $recommendation->recommendationTypeLabel() }}
                            </h3>
                        </div>
                        <span class="badge-{{ $recommendation->statusColor() }} px-3 py-1 text-xs">{{ strtoupper($recommendation->statusLabel()) }}</span>
                    </div>

                    @if($recommendation->incrementPercent())
                        <p class="text-sm text-zinc-600 dark:text-zinc-400 mb-4">Proposed salary increment: <span class="font-semibold text-emerald-600 dark:text-emerald-400">+{{ $recommendation->incrementPercent() }}%</span></p>
                    @endif

                    <div class="flex justify-end border-t border-zinc-100 dark:border-zinc-800 pt-4 mt-2">
                        <flux:button wire:click="viewRecommendation({{ $recommendation->id }})" variant="ghost" size="sm">
                            View Details
                        </flux:button>
                    </div>
                </div>
            </div>
        @empty
            <div class="pulse-card p-12 text-center">
                <div class="bg-blue-50 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 dark:bg-blue-900/20">
                    <flux:icon.sparkles class="size-8 text-blue-600 dark:text-blue-400" />
                </div>
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-1">No Recommendations Yet</h3>
                <p class="text-zinc-500 text-sm">You currently have no promotion or reward recommendations on record.</p>
            </div>
        @endforelse
    </div>

    {{-- View Recommendation Modal --}}
    @if($showViewModal && $activeRecommendation)
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
                        <flux:heading size="lg">{{ $activeRecommendation->recommendationTypeLabel() }}</flux:heading>
                        <flux:subheading>Recommended on {{ $activeRecommendation->created_at->format('M d, Y') }}</flux:subheading>
                    </div>
                    <span class="badge-{{ $activeRecommendation->statusColor() }} px-2 py-1">{{ strtoupper($activeRecommendation->statusLabel()) }}</span>
                </div>

                <div class="space-y-6">
                    <div class="grid grid-cols-2 gap-4 text-sm bg-zinc-50 dark:bg-zinc-900 p-4 rounded-xl">
                        @if($activeRecommendation->current_role || $activeRecommendation->proposed_role)
                            <div>
                                <span class="text-[10px] font-bold text-zinc-400 uppercase block">Current Role</span>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $activeRecommendation->current_role ?? '—' }}</span>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold text-zinc-400 uppercase block">Proposed Role</span>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $activeRecommendation->proposed_role ?? '—' }}</span>
                            </div>
                        @endif
                        @if($activeRecommendation->current_salary || $activeRecommendation->proposed_salary)
                            <div>
                                <span class="text-[10px] font-bold text-zinc-400 uppercase block">Current Salary</span>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $activeRecommendation->current_salary ? number_format($activeRecommendation->current_salary, 2) : '—' }}</span>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold text-zinc-400 uppercase block">Proposed Salary</span>
                                <span class="font-medium text-zinc-900 dark:text-white">
                                    {{ $activeRecommendation->proposed_salary ? number_format($activeRecommendation->proposed_salary, 2) : '—' }}
                                    @if($activeRecommendation->incrementPercent())
                                        <span class="text-emerald-600 dark:text-emerald-400">(+{{ $activeRecommendation->incrementPercent() }}%)</span>
                                    @endif
                                </span>
                            </div>
                        @endif
                        @if($activeRecommendation->bonus_amount)
                            <div>
                                <span class="text-[10px] font-bold text-zinc-400 uppercase block">Bonus Amount</span>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($activeRecommendation->bonus_amount, 2) }}</span>
                            </div>
                        @endif
                        @if($activeRecommendation->targetDepartment)
                            <div>
                                <span class="text-[10px] font-bold text-zinc-400 uppercase block">Target Department</span>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $activeRecommendation->targetDepartment->name }}</span>
                            </div>
                        @endif
                        @if($activeRecommendation->effective_date)
                            <div>
                                <span class="text-[10px] font-bold text-zinc-400 uppercase block">Effective Date</span>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $activeRecommendation->effective_date->format('M d, Y') }}</span>
                            </div>
                        @endif
                    </div>

                    <div>
                        <span class="text-xs font-bold text-zinc-500 uppercase block mb-1">Justification</span>
                        <div class="bg-white dark:bg-zinc-950 border border-zinc-100 dark:border-zinc-800 p-4 rounded-lg text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $activeRecommendation->justification }}</div>
                    </div>

                    {{-- Documents --}}
                    <div class="bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-xl p-4">
                        <h4 class="text-xs font-bold text-zinc-600 dark:text-zinc-400 uppercase tracking-wider mb-2">Documents</h4>
                        @forelse($activeRecommendation->documents as $document)
                            <div class="flex items-center justify-between text-sm py-1.5 border-b border-zinc-200 dark:border-zinc-700 last:border-0">
                                <span class="text-zinc-700 dark:text-zinc-300">{{ $document->title }} <span class="text-xs text-zinc-400">v{{ $document->version }}</span></span>
                                <a href="{{ URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $document->id]) }}" class="text-xs text-blue-600 hover:underline">Download</a>
                            </div>
                        @empty
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">No documents have been shared for this recommendation yet.</p>
                        @endforelse
                    </div>

                    @if($activeRecommendation->status === 'rejected')
                        <div class="bg-red-50 border border-red-100 rounded-xl p-4 dark:bg-red-900/10 dark:border-red-800/30">
                            <h4 class="text-xs font-bold text-red-600 uppercase tracking-wider mb-2">Not Approved</h4>
                            <p class="text-sm text-red-800 dark:text-red-300">{{ $activeRecommendation->hr_comments ?? $activeRecommendation->dept_head_comments ?? $activeRecommendation->super_admin_comments ?? 'No further details provided.' }}</p>
                        </div>
                    @elseif($activeRecommendation->status === 'approved')
                        <div class="bg-green-50 border border-green-100 rounded-xl p-4 dark:bg-green-900/20 dark:border-green-800/30">
                            <h4 class="text-xs font-bold text-green-600 uppercase tracking-wider mb-2">Approved</h4>
                            <p class="text-sm text-green-800 dark:text-green-300">Effective {{ $activeRecommendation->effective_date?->format('M d, Y') ?? 'date to be confirmed' }}.</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</flux:main>
