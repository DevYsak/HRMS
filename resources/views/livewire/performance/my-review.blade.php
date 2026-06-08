<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">My Performance Reviews</h1>
            <p class="pulse-page-subtitle">Reflect on your achievements and areas for growth</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        {{-- Left side - Active Reviews --}}
        <div class="col-span-2 space-y-6">
            <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Active Assessments</h3>
            
            @forelse($activeReviews as $review)
                <div class="pulse-card">
                    <div class="flex justify-between items-center mb-4">
                        <div>
                            <div class="font-bold text-zinc-900 dark:text-white">{{ $review->performanceCycle?->name ?? '—' }}</div>
                            <div class="text-xs text-zinc-500">Cycle ends: {{ $review->performanceCycle?->end_date?->format('M d, Y') ?? '—' }}</div>
                        </div>
                        <div>
                            <span class="badge-{{ $review->statusColor() }}">{{ strtoupper($review->statusLabel()) }}</span>
                        </div>
                    </div>

                    @if($review->status === 'draft')
                        <div class="bg-zinc-50 rounded-xl p-6 text-center border border-zinc-100 dark:bg-zinc-900 dark:border-zinc-800">
                            <flux:icon.document-text class="size-8 mx-auto text-zinc-400 mb-3" />
                            <h4 class="font-semibold text-zinc-900 dark:text-white">Action Required</h4>
                            <p class="text-sm text-zinc-500 mb-4 max-w-sm mx-auto">Please submit your self-reflection and goal assessments before the cycle closes.</p>
                            <flux:button wire:click="openReview({{ $review->id }})" variant="primary">Start Self-Assessment</flux:button>
                        </div>
                    @else
                        <div class="bg-zinc-50 rounded-xl p-6 text-center border border-zinc-100 dark:bg-zinc-900 dark:border-zinc-800">
                            <flux:icon.check-circle class="size-8 mx-auto text-green-500 mb-3" />
                            <h4 class="font-semibold text-zinc-900 dark:text-white">Review Submitted</h4>
                            <p class="text-sm text-zinc-500 mb-4 max-w-sm mx-auto">Your assessment is with your manager for review.</p>
                            <flux:button wire:click="openReview({{ $review->id }})" variant="ghost">View Details</flux:button>
                        </div>
                    @endif
                </div>
            @empty
                <div class="pulse-card py-12 text-center text-zinc-500">
                    You have no active performance reviews.
                </div>
            @endforelse
        </div>

        {{-- Right side - Feedback/Completed Reviews --}}
        <div class="space-y-6">
            <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Past Reviews</h3>
            
            <div class="space-y-4">
                @forelse($completedReviews as $cReview)
                    <div class="pulse-card relative overflow-hidden group cursor-pointer" wire:click="openReview({{ $cReview->id }})">
                        <div class="relative z-10">
                            <div class="text-xs font-bold text-zinc-400 uppercase tracking-wider mb-1">{{ $cReview->performanceCycle?->name ?? '—' }}</div>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    @if($cReview->reviewer?->user->avatar)
                                        <img src="{{ $cReview->reviewer->user->avatarUrl() }}" class="size-6 rounded-full" />
                                    @endif
                                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                        {{ $cReview->reviewer ? 'by '.$cReview->reviewer->user->name : 'Finalized' }}
                                    </span>
                                </div>
                                <span class="text-xs font-bold text-brand-600">{{ $cReview->gradeLabel() }}</span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-sm text-zinc-500 text-center py-8 border border-dashed border-zinc-200 rounded-xl dark:border-zinc-800">
                        No past reviews found.
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Self Review Form Modal --}}
    @if($showSelfReviewModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showSelfReviewModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showSelfReviewModal', false)"></div>
            <div class="relative w-full max-w-4xl bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showSelfReviewModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $activeReview?->status === 'draft' ? 'Self Assessment' : 'View Performance Review' }}</flux:heading>
                <flux:subheading>{{ $activeReview?->performanceCycle?->name ?? '—' }} (Template: {{ $activeReview?->template?->name }})</flux:subheading>
            </div>

            <div class="max-h-[70vh] overflow-y-auto px-1 space-y-8">
                {{-- KPI / Component Assessment --}}
                <div class="space-y-4">
                    <h4 class="font-bold text-zinc-900 dark:text-white border-b pb-2 dark:border-zinc-800">KPI / Component Assessment</h4>
                    @forelse($activeReview?->componentScores ?? [] as $scoreRow)
                        @php $component = $scoreRow->component; @endphp
                        <div class="bg-zinc-50 p-4 rounded-xl border border-zinc-100 dark:bg-zinc-900 dark:border-zinc-800 space-y-4">
                            <div class="flex justify-between items-start">
                                <div>
                                    <div class="font-bold text-sm text-zinc-900 dark:text-white">
                                        {{ $component->name }}
                                        @if($component->isAutoScored())
                                            <span class="ml-2 text-[10px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded-full dark:bg-blue-900/40 dark:text-blue-400 font-semibold tracking-wider uppercase">Auto-Scored</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-zinc-500 mt-1">{{ $component->description ?? 'No description.' }}</div>
                                </div>
                                <div class="text-xs font-medium text-zinc-400 shrink-0 text-right">
                                    Weight: {{ $component->weight_percent }}% <br>
                                    Max Score: {{ $component->max_score }}
                                </div>
                            </div>
                            
                            @if($activeReview?->status === 'draft')
                                @if($component->isAutoScored())
                                    <div class="bg-blue-50/50 p-3 rounded border border-blue-100 text-sm text-blue-700 dark:bg-blue-900/20 dark:border-blue-800/50 dark:text-blue-400">
                                        This component will be automatically scored based on system data at the end of the review cycle.
                                    </div>
                                @else
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <flux:field class="md:col-span-1">
                                            <flux:label>Self Score (out of {{ $component->max_score }})</flux:label>
                                            <flux:input type="number" wire:model="componentScores.{{ $component->id }}" min="0" max="{{ $component->max_score }}" step="0.5" />
                                            <flux:error name="componentScores.{{ $component->id }}" />
                                        </flux:field>
                                        <flux:field class="md:col-span-2">
                                            <flux:label>Self Comment</flux:label>
                                            <flux:textarea wire:model="componentComments.{{ $component->id }}" rows="1" placeholder="Describe your achievement..." />
                                            <flux:error name="componentComments.{{ $component->id }}" />
                                        </flux:field>
                                    </div>
                                @endif
                            @else
                                <div class="grid grid-cols-3 gap-4 text-xs bg-white dark:bg-zinc-950 p-3 rounded-lg border border-zinc-100 dark:border-zinc-800">
                                    <div class="space-y-1">
                                        <div class="font-bold text-zinc-400">SELF</div>
                                        @if($component->isAutoScored())
                                            <div class="text-zinc-500 italic">Auto-scored</div>
                                        @else
                                            <div class="text-zinc-700 dark:text-zinc-300">Score: <span class="font-semibold">{{ $scoreRow->self_score ?? '-' }}</span> / {{ $component->max_score }}</div>
                                            <div class="italic text-zinc-500">"{{ $scoreRow->self_comment ?? 'No comment' }}"</div>
                                        @endif
                                    </div>
                                    <div class="space-y-1 border-l border-zinc-100 dark:border-zinc-800 pl-4">
                                        <div class="font-bold text-brand-600">MANAGER</div>
                                        @if($component->isAutoScored())
                                            <div class="text-zinc-500 italic">Auto-scored</div>
                                        @else
                                            <div class="text-zinc-700 dark:text-zinc-300">Score: <span class="font-semibold">{{ $scoreRow->manager_score ?? '-' }}</span> / {{ $component->max_score }}</div>
                                            <div class="italic text-zinc-500">"{{ $scoreRow->manager_comment ?? 'No comment' }}"</div>
                                        @endif
                                    </div>
                                    <div class="space-y-1 border-l border-zinc-100 dark:border-zinc-800 pl-4">
                                        <div class="font-bold text-indigo-600 dark:text-indigo-400">HR / FINAL</div>
                                        @if($component->isAutoScored() && $scoreRow->final_score !== null)
                                            <div class="text-zinc-700 dark:text-zinc-300">Auto Score: <span class="font-semibold">{{ $scoreRow->final_score }}</span> / {{ $component->max_score }}</div>
                                            <div class="text-indigo-600 font-bold tracking-wider">{{ $scoreRow->weighted_score }}%</div>
                                        @else
                                            <div class="text-zinc-700 dark:text-zinc-300">Score: <span class="font-semibold">{{ $scoreRow->hr_score ?? $scoreRow->manager_score ?? '-' }}</span> / {{ $component->max_score }}</div>
                                            @if($scoreRow->weighted_score !== null)
                                                <div class="text-indigo-600 font-bold tracking-wider mt-1">{{ $scoreRow->weighted_score }}% (Weighted)</div>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500 italic">No KPIs/Components linked to this review cycle.</p>
                    @endforelse
                </div>

                {{-- Overall Reflection --}}
                <div class="space-y-4">
                    <h4 class="font-bold text-zinc-900 dark:text-white border-b pb-2 dark:border-zinc-800">Overall Reflection</h4>
                    
                    @if($activeReview?->status === 'draft')
                        <flux:textarea wire:model="strengths" label="Key Achievements & Strengths *" rows="3" />
                        <flux:textarea wire:model="improvements" label="Areas for Improvement *" rows="3" />
                        <flux:textarea wire:model="comments" label="Additional Comments" rows="2" />
                    @else
                        <div class="space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div class="space-y-4">
                                    <h5 class="text-xs font-bold text-zinc-400 uppercase tracking-tighter">Self Assessment</h5>
                                    <div>
                                        <span class="text-sm font-bold block">Strengths</span>
                                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $activeReview?->strengths ?? 'None' }}</p>
                                    </div>
                                    <div>
                                        <span class="text-sm font-bold block">Improvements</span>
                                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $activeReview?->improvements ?? 'None' }}</p>
                                    </div>
                                    <div>
                                        <span class="text-sm font-bold block">Comments</span>
                                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $activeReview?->comments ?? 'None' }}</p>
                                    </div>
                                </div>

                                @if($activeReview?->status !== 'submitted')
                                    <div class="space-y-4">
                                        <h5 class="text-xs font-bold text-brand-600 uppercase tracking-tighter">Manager Feedback</h5>
                                        <div>
                                            <span class="text-sm font-bold block">Overall Feedback</span>
                                            <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $activeReview?->manager_feedback ?: 'No feedback provided yet.' }}</p>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            @if($activeReview?->status === 'draft')
                <div class="bg-amber-50 text-amber-800 p-4 rounded-xl text-sm dark:bg-amber-900/20 dark:text-amber-300 mt-4">
                    <span class="font-bold">Note:</span> Once submitted, you cannot edit this assessment. It will be shared with your manager.
                </div>
            @endif

            {{-- Documents --}}
            <div class="bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-xl p-4 mt-4">
                <h4 class="text-xs font-bold text-zinc-600 dark:text-zinc-400 uppercase tracking-wider mb-2">Documents</h4>
                @forelse($activeReview->documents as $document)
                    <div class="flex items-center justify-between text-sm py-1.5 border-b border-zinc-200 dark:border-zinc-700 last:border-0">
                        <span class="text-zinc-700 dark:text-zinc-300">{{ $document->title }} <span class="text-xs text-zinc-400">v{{ $document->version }}</span></span>
                        <a href="{{ URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $document->id]) }}" class="text-xs text-blue-600 hover:underline">Download</a>
                    </div>
                @empty
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">No documents attached to this review yet.</p>
                @endforelse
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <button type="button" @click="$wire.set('showSelfReviewModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">Close</button>
                @if($activeReview?->status === 'draft')
                    <flux:button wire:click="submitSelfReview" variant="primary">Submit Review</flux:button>
                @endif
            </div>
        </div>
    
            </div>
        </div>
    @endif
</flux:main>
