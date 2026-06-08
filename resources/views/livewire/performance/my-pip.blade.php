<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">My Performance Improvement Plan</h1>
            <p class="pulse-page-subtitle">Track your PIP goals, progress, and outcomes</p>
        </div>
    </div>

    <div class="space-y-6">
        @forelse($records as $record)
            <div class="pulse-card relative overflow-hidden group">
                <div class="relative z-10 p-6">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <div class="flex items-center gap-3 mb-1">
                                <span class="text-xs text-zinc-500 font-medium">
                                    {{ $record->start_date->format('M d, Y') }} &mdash; {{ $record->end_date->format('M d, Y') }}
                                </span>
                            </div>
                            <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Improvement Plan with {{ $record->manager?->name ?? 'Management' }}</h3>
                        </div>
                        <span class="badge-{{ $record->statusColor() }} px-3 py-1 text-xs">{{ strtoupper($record->statusLabel()) }}</span>
                    </div>

                    <div class="flex items-center gap-4 mb-4">
                        <div class="flex-1 h-2 bg-zinc-100 dark:bg-zinc-800 rounded-full overflow-hidden">
                            <div class="h-full bg-blue-500 rounded-full" style="width: {{ $record->overallProgress() }}%"></div>
                        </div>
                        <span class="text-xs font-bold text-zinc-600 dark:text-zinc-400">{{ $record->overallProgress() }}% complete</span>
                        @if($record->status === 'active')
                            <span class="text-xs text-zinc-400">{{ $record->daysRemaining() }} days remaining</span>
                        @endif
                    </div>

                    <div class="flex justify-end border-t border-zinc-100 dark:border-zinc-800 pt-4 mt-2">
                        <flux:button wire:click="viewRecord({{ $record->id }})" variant="ghost" size="sm">
                            View Details
                        </flux:button>
                    </div>
                </div>
            </div>
        @empty
            <div class="pulse-card p-12 text-center">
                <div class="bg-green-50 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 dark:bg-green-900/20">
                    <flux:icon.shield-check class="size-8 text-green-600 dark:text-green-400" />
                </div>
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-1">No Improvement Plans</h3>
                <p class="text-zinc-500 text-sm">You currently have no performance improvement plans.</p>
            </div>
        @endforelse
    </div>

    {{-- View PIP Modal --}}
    @if($showViewModal && $activeRecord)
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
                        <flux:heading size="lg">Performance Improvement Plan</flux:heading>
                        <flux:subheading>{{ $activeRecord->start_date->format('M d, Y') }} &mdash; {{ $activeRecord->end_date->format('M d, Y') }}</flux:subheading>
                    </div>
                    <span class="badge-{{ $activeRecord->statusColor() }} px-2 py-1">{{ strtoupper($activeRecord->statusLabel()) }}</span>
                </div>

                <div class="space-y-6">
                    @if($activeRecord->action_plan)
                        <div>
                            <span class="text-xs font-bold text-zinc-500 uppercase block mb-1">Action Plan</span>
                            <div class="bg-white dark:bg-zinc-950 border border-zinc-100 dark:border-zinc-800 p-4 rounded-lg text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $activeRecord->action_plan }}</div>
                        </div>
                    @endif

                    @if($activeRecord->success_criteria)
                        <div>
                            <span class="text-xs font-bold text-zinc-500 uppercase block mb-1">Success Criteria</span>
                            <div class="bg-white dark:bg-zinc-950 border border-zinc-100 dark:border-zinc-800 p-4 rounded-lg text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $activeRecord->success_criteria }}</div>
                        </div>
                    @endif

                    @if($activeRecord->goals->isNotEmpty())
                        <div>
                            <span class="text-xs font-bold text-zinc-500 uppercase block mb-2">Milestones &amp; Goals</span>
                            <div class="space-y-3">
                                @foreach($activeRecord->goals as $goal)
                                    <div class="bg-zinc-50 dark:bg-zinc-900 border border-zinc-100 dark:border-zinc-800 rounded-xl p-4">
                                        <div class="flex items-start justify-between mb-2">
                                            <div>
                                                <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $goal->title }}</p>
                                                @if($goal->description)
                                                    <p class="text-xs text-zinc-500 mt-1">{{ $goal->description }}</p>
                                                @endif
                                            </div>
                                            <span class="badge-{{ $goal->statusColor() }} px-2 py-0.5 text-[10px]">{{ strtoupper(str_replace('_', ' ', $goal->status)) }}</span>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <div class="flex-1 h-1.5 bg-zinc-200 dark:bg-zinc-800 rounded-full overflow-hidden">
                                                <div class="h-full bg-blue-500 rounded-full" style="width: {{ $goal->progress_percent }}%"></div>
                                            </div>
                                            <span class="text-xs font-bold text-zinc-600 dark:text-zinc-400">{{ $goal->progress_percent }}%</span>
                                        </div>
                                        @if($goal->target_date)
                                            <p class="text-[10px] text-zinc-400 mt-2">Target: {{ $goal->target_date->format('M d, Y') }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Documents --}}
                    <div class="bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-xl p-4">
                        <h4 class="text-xs font-bold text-zinc-600 dark:text-zinc-400 uppercase tracking-wider mb-2">Documents</h4>
                        @forelse($activeRecord->documents as $document)
                            <div class="flex items-center justify-between text-sm py-1.5 border-b border-zinc-200 dark:border-zinc-700 last:border-0">
                                <span class="text-zinc-700 dark:text-zinc-300">{{ $document->title }} <span class="text-xs text-zinc-400">v{{ $document->version }}</span></span>
                                <a href="{{ URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $document->id]) }}" class="text-xs text-blue-600 hover:underline">Download</a>
                            </div>
                        @empty
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">No documents have been shared for this plan yet.</p>
                        @endforelse
                    </div>

                    @if($activeRecord->outcome)
                        <div class="bg-zinc-50 border border-zinc-200 rounded-xl p-4 dark:bg-zinc-900 dark:border-zinc-700">
                            <span class="text-xs font-bold text-zinc-500 uppercase block mb-1">Outcome</span>
                            <p class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">{{ ucfirst($activeRecord->outcome) }} &mdash; {{ $activeRecord->outcome_date?->format('M d, Y') }}</p>
                        </div>
                    @endif

                    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 space-y-4 dark:bg-amber-900/10 dark:border-amber-800/30">
                        <h4 class="font-bold text-amber-800 dark:text-amber-400">Your Comments</h4>
                        <p class="text-sm text-amber-700 dark:text-amber-500">Share your perspective, progress notes, or concerns regarding this improvement plan.</p>

                        <flux:field>
                            <flux:textarea wire:model="employee_comments" rows="3" placeholder="Add your comments..." />
                            <flux:error name="employee_comments" />
                        </flux:field>

                        <div class="flex justify-end">
                            <flux:button wire:click="submitComments" variant="primary">Save Comments</flux:button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</flux:main>
