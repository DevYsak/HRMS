<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Company Reviews</h1>
            <p class="pulse-page-subtitle">Track performance review progress across the organization</p>
        </div>
        <flux:button href="{{ route('performance.cycles') }}" wire:navigate variant="ghost" icon="calendar" class="bg-white">Manage Cycles</flux:button>
    </div>

    <div class="pulse-card">
        <div class="flex flex-wrap items-center gap-3 mb-5">
            <div class="relative flex-1 max-w-xs">
                <flux:icon.magnifying-glass class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-zinc-400" />
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search employee..." class="w-full h-9 rounded-lg border border-zinc-200 bg-white pl-9 pr-3 text-sm focus:ring-2 focus:ring-brand-600 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100" />
            </div>
            
            <flux:select wire:model.live="cycle_id" class="w-48 h-9 text-sm">
                <option value="">All Cycles</option>
                @foreach($cycles as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </flux:select>
            
            <flux:select wire:model.live="status" class="w-40 h-9 text-sm">
                <option value="">All Statuses</option>
                <option value="draft">Draft</option>
                <option value="submitted">Submitted</option>
                <option value="manager_reviewed">Manager Reviewed</option>
                <option value="hr_reviewed">HR Reviewed</option>
                <option value="locked">Locked</option>
            </flux:select>
        </div>

        <div class="overflow-x-auto -mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-zinc-50/50 dark:bg-zinc-900/50 border-b border-zinc-100 dark:border-zinc-800">
                        <th class="py-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Employee</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Cycle</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Manager</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Final Grade</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                        <th class="py-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($reviews as $review)
                        <tr class="hover:bg-zinc-50/30 transition-colors">
                            <td class="py-3 pl-6 pr-4">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $review->employee->user->name ?? 'Unknown' }}</div>
                                <div class="text-[10px] text-zinc-500">{{ $review->employee->jobTitle?->name ?? '—' }}</div>
                            </td>
                            <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-300">
                                {{ $review->performanceCycle?->name ?? '—' }}
                            </td>
                            <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-300">
                                {{ $review->reviewer?->user?->name ?? 'No Manager' }}
                            </td>
                            <td class="py-3 pr-4">
                                @if($review->status === 'locked' && $review->final_score !== null)
                                    <div class="font-bold text-brand-600">
                                        {{ $review->gradeLabel() }}
                                    </div>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>
                            <td class="py-3 pr-4">
                                <span class="badge-{{ $review->statusColor() }}">{{ strtoupper($review->statusLabel()) }}</span>
                            </td>
                            <td class="py-3 pr-6 text-right space-x-1">
                                @if($review->status === 'manager_reviewed')
                                    <flux:button wire:click="viewReview({{ $review->id }})" size="xs" variant="primary" icon="clipboard-document-check">HR Review</flux:button>
                                @else
                                    <flux:button wire:click="viewReview({{ $review->id }})" size="xs" variant="ghost" icon="eye">View</flux:button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-zinc-500">No performance reviews found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        <div class="mt-4">
            {{ $reviews->links() }}
        </div>
    </div>

    {{-- View / HR Review Modal --}}
    @if($showViewModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showViewModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showViewModal', false)"></div>
            <div class="relative w-full max-w-4xl bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showViewModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

        @if($viewingReview)
        <div class="space-y-5">
            <div class="flex items-start justify-between">
                <div>
                    <flux:heading size="lg">{{ $viewingReview->status === 'manager_reviewed' ? 'HR Validation' : 'Performance Review' }}</flux:heading>
                    <flux:subheading>{{ $viewingReview->employee->user?->name }} · {{ $viewingReview->performanceCycle?->name }}</flux:subheading>
                </div>
                <span class="badge-{{ $viewingReview->statusColor() }} text-xs px-2 py-1 rounded-full">{{ $viewingReview->statusLabel() }}</span>
            </div>

            <div class="max-h-[65vh] overflow-y-auto pr-2 space-y-6">
                {{-- Self Assessment Summary --}}
                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-900 p-4 space-y-3">
                    <p class="text-xs font-bold uppercase tracking-wider text-zinc-400">Self Assessment</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @if($viewingReview->strengths)
                            <div><p class="text-xs text-zinc-400 mb-0.5">Strengths</p><p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $viewingReview->strengths }}</p></div>
                        @endif
                        @if($viewingReview->improvements)
                            <div><p class="text-xs text-zinc-400 mb-0.5">Areas for Improvement</p><p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $viewingReview->improvements }}</p></div>
                        @endif
                    </div>
                </div>

                {{-- Manager Feedback Summary --}}
                <div class="rounded-xl bg-blue-50 dark:bg-blue-950/20 p-4 space-y-3 border border-blue-100 dark:border-blue-900/50">
                    <p class="text-xs font-bold uppercase tracking-wider text-blue-500">Manager Feedback · {{ $viewingReview->reviewer?->user?->name ?? 'N/A' }}</p>
                    @if($viewingReview->manager_feedback)
                        <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $viewingReview->manager_feedback }}</p>
                    @endif
                    @if($viewingReview->promotion_recommended)
                        <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700 dark:bg-green-900/30 dark:text-green-400">
                            ✓ Promotion Recommended
                        </span>
                    @endif
                </div>

                {{-- Components --}}
                <div class="space-y-3">
                    <p class="text-xs font-bold uppercase tracking-wider text-zinc-400">Component Scores</p>
                    @foreach($viewingReview->componentScores as $scoreRow)
                        @php $component = $scoreRow->component; @endphp
                        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
                            <div class="flex justify-between items-start">
                                <div>
                                    <div class="font-medium text-zinc-900 dark:text-white">
                                        {{ $component->name }}
                                        @if($component->isAutoScored())
                                            <span class="ml-2 text-[10px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded-full uppercase tracking-wider font-semibold">Auto-Scored</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-zinc-500 mt-1">Weight: {{ $component->weight_percent }}% | Max: {{ $component->max_score }}</div>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4 text-sm bg-zinc-50 dark:bg-zinc-900 p-3 rounded-md">
                                <div>
                                    <span class="text-[10px] font-bold text-zinc-400 uppercase block mb-1">Self Rating</span>
                                    @if($component->isAutoScored())
                                        <span class="text-xs italic text-zinc-500">N/A</span>
                                    @else
                                        <span class="font-semibold">{{ $scoreRow->self_score ?? '-' }}</span>
                                        @if($scoreRow->self_comment) <div class="text-xs italic text-zinc-600 dark:text-zinc-400 mt-1">"{{ $scoreRow->self_comment }}"</div> @endif
                                    @endif
                                </div>
                                <div>
                                    <span class="text-[10px] font-bold text-zinc-400 uppercase block mb-1">Manager Rating</span>
                                    @if($component->isAutoScored())
                                        <span class="text-xs italic text-zinc-500">N/A</span>
                                    @else
                                        <span class="font-semibold text-brand-600">{{ $scoreRow->manager_score ?? '-' }}</span>
                                        @if($scoreRow->manager_comment) <div class="text-xs italic text-zinc-600 dark:text-zinc-400 mt-1">"{{ $scoreRow->manager_comment }}"</div> @endif
                                    @endif
                                </div>
                            </div>

                            @if($viewingReview->status === 'manager_reviewed')
                                @if($component->isAutoScored())
                                    <div class="text-sm text-blue-600 bg-blue-50 p-2 rounded">This score will be calculated automatically upon locking.</div>
                                @else
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pt-2 border-t border-zinc-100 dark:border-zinc-700 mt-2">
                                        <flux:field class="md:col-span-1">
                                            <flux:label>HR Validated Score</flux:label>
                                            <flux:input type="number" wire:model="componentScores.{{ $component->id }}" min="0" max="{{ $component->max_score }}" step="0.5" />
                                            <flux:error name="componentScores.{{ $component->id }}" />
                                        </flux:field>
                                        <flux:field class="md:col-span-2">
                                            <flux:label>HR Comment (Optional)</flux:label>
                                            <flux:input wire:model="componentComments.{{ $component->id }}" placeholder="Reason for adjustment, if any" />
                                            <flux:error name="componentComments.{{ $component->id }}" />
                                        </flux:field>
                                    </div>
                                @endif
                            @else
                                @if($scoreRow->hr_score !== null || $scoreRow->hr_comment !== null || $component->isAutoScored())
                                    <div class="pt-2 border-t border-zinc-100 dark:border-zinc-700 mt-2">
                                        <span class="text-[10px] font-bold text-indigo-500 uppercase block mb-1">HR / Final Review</span>
                                        @if($component->isAutoScored())
                                            @if($scoreRow->final_score !== null)
                                                <span class="font-semibold text-indigo-600">Auto Score: {{ $scoreRow->final_score }}</span> 
                                                <span class="text-xs ml-2">(Weighted: {{ $scoreRow->weighted_score }}%)</span>
                                            @else
                                                <span class="text-xs italic text-zinc-500">Pending calculation</span>
                                            @endif
                                        @else
                                            <span class="font-semibold text-indigo-600">{{ $scoreRow->hr_score ?? $scoreRow->manager_score }}</span>
                                            @if($scoreRow->hr_comment) <div class="text-xs italic text-zinc-600 dark:text-zinc-400 mt-1">"{{ $scoreRow->hr_comment }}"</div> @endif
                                        @endif
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Final Details --}}
                @if($viewingReview->status === 'manager_reviewed')
                    <div class="bg-zinc-50 dark:bg-zinc-900 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <flux:textarea wire:model="hr_comments" label="HR Summary Comments *" placeholder="Enter final HR remarks before approving..." rows="3" />
                    </div>
                @elseif($viewingReview->hr_comments)
                    <div class="bg-zinc-50 dark:bg-zinc-900 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <p class="text-xs font-bold uppercase tracking-wider text-indigo-500 mb-2">HR Comments</p>
                        <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $viewingReview->hr_comments }}</p>
                    </div>
                @endif
                
                {{-- Documents --}}
                <div class="bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-xl p-4">
                    <h4 class="text-xs font-bold text-zinc-600 dark:text-zinc-400 uppercase tracking-wider mb-2">Documents</h4>
                    @forelse($viewingReview->documents as $document)
                        <div class="flex items-center justify-between text-sm py-1.5 border-b border-zinc-200 dark:border-zinc-700 last:border-0">
                            <span class="text-zinc-700 dark:text-zinc-300">{{ $document->title }} <span class="text-xs text-zinc-400">v{{ $document->version }}</span></span>
                            <a href="{{ URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $document->id]) }}" class="text-xs text-blue-600 hover:underline">Download</a>
                        </div>
                    @empty
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">No documents attached to this review yet.</p>
                    @endforelse
                </div>

                @if($viewingReview->status === 'locked')
                    <div class="bg-green-50 p-4 rounded-xl border border-green-100 flex justify-between items-center dark:bg-green-900/20 dark:border-green-800/30">
                        <div>
                            <p class="text-sm font-bold text-green-800 dark:text-green-300">Final Scorecard Generated</p>
                            <p class="text-xs text-green-600 dark:text-green-400">Total Score: {{ $viewingReview->final_score }}% | Grade: {{ $viewingReview->gradeLabel() }}</p>
                        </div>
                        <flux:button href="{{ route('performance.scorecard', $viewingReview->id) }}" wire:navigate variant="primary" size="sm">View Scorecard</flux:button>
                    </div>
                @endif
            </div>

            <div class="flex justify-between items-center border-t border-zinc-100 dark:border-zinc-800 pt-4">
                <div class="space-x-2">
                    @if($viewingReview->status === 'manager_reviewed')
                        <flux:button wire:click="submitHrReview" variant="primary" icon="check-circle">Approve & Mark HR Validated</flux:button>
                    @endif
                    @if(in_array($viewingReview->status, ['manager_reviewed', 'hr_reviewed']))
                        <flux:button wire:click="lockReview" variant="danger" icon="lock-closed">Lock & Generate Scorecard</flux:button>
                    @endif
                </div>
                <button type="button" @click="$wire.set('showViewModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">Close</button>
            </div>
        </div>
        @endif
    
            </div>
        </div>
    @endif
</flux:main>
