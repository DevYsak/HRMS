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
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Rating</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                        <th class="py-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($reviews as $review)
                        <tr class="hover:bg-zinc-50/30 transition-colors">
                            <td class="py-3 pl-6 pr-4">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $review->employee->user->name ?? 'Unknown' }}</div>
                                <div class="text-[10px] text-zinc-500">{{ $review->employee->jobTitle?->name ?? 'â€”' }}</div>
                            </td>
                            <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-300">
                                {{ $review->cycle?->name ?? 'â€”' }}
                            </td>
                            <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-300">
                                {{ $review->reviewer?->user?->name ?? 'No Manager' }}
                            </td>
                            <td class="py-3 pr-4">
                                @if($review->status !== 'draft' && $review->overall_rating)
                                    <div class="flex items-center gap-0.5">
                                        @for($i = 1; $i <= 5; $i++)
                                            <flux:icon.star variant="{{ $i <= $review->overall_rating ? 'solid' : 'outline' }}" class="size-3 {{ $i <= $review->overall_rating ? 'text-amber-400' : 'text-zinc-300' }}" />
                                        @endfor
                                        <span class="ml-1 text-[10px] text-zinc-400">({{ $review->overall_rating }}/5)</span>
                                    </div>
                                @else
                                    <span class="text-zinc-400">â€”</span>
                                @endif
                            </td>
                            <td class="py-3 pr-4">
                                <span class="badge-{{ $review->statusColor() }}">{{ strtoupper($review->statusLabel()) }}</span>
                            </td>
                            <td class="py-3 pr-6 text-right space-x-1">
                                @if($review->status === 'manager_reviewed')
                                    <flux:button wire:click="lockReview({{ $review->id }})" size="xs" variant="primary" icon="lock-closed">Lock</flux:button>
                                @endif
                                <flux:button wire:click="viewReview({{ $review->id }})" size="xs" variant="ghost" icon="eye">View</flux:button>
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

    {{-- View Review Modal --}}
    @if($showViewModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showViewModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" wire:click="$set('showViewModal', false)"></div>
            <div class="relative w-full max-w-2xl bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" wire:click="$set('showViewModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

        @if($viewingReview)
        <div class="space-y-5">
            <div class="flex items-start justify-between">
                <div>
                    <flux:heading size="lg">Performance Review</flux:heading>
                    <flux:subheading>{{ $viewingReview->employee->user?->name }} Â· {{ $viewingReview->cycle?->name }}</flux:subheading>
                </div>
                <span class="badge-{{ $viewingReview->statusColor() }} text-xs px-2 py-1 rounded-full">{{ $viewingReview->statusLabel() }}</span>
            </div>

            {{-- Self Assessment --}}
            <div class="rounded-xl bg-zinc-50 dark:bg-zinc-900 p-4 space-y-3">
                <p class="text-xs font-bold uppercase tracking-wider text-zinc-400">Self Assessment</p>
                @if($viewingReview->overall_rating)
                    <div class="flex items-center gap-1">
                        @for($i=1;$i<=5;$i++)
                            <flux:icon.star variant="{{ $i <= $viewingReview->overall_rating ? 'solid' : 'outline' }}" class="size-4 {{ $i <= $viewingReview->overall_rating ? 'text-amber-400' : 'text-zinc-300' }}" />
                        @endfor
                        <span class="ml-1 text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $viewingReview->overall_rating }}/5</span>
                    </div>
                @endif
                @if($viewingReview->strengths)
                    <div><p class="text-xs text-zinc-400 mb-0.5">Strengths</p><p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $viewingReview->strengths }}</p></div>
                @endif
                @if($viewingReview->improvements)
                    <div><p class="text-xs text-zinc-400 mb-0.5">Areas for Improvement</p><p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $viewingReview->improvements }}</p></div>
                @endif
                @if($viewingReview->comments)
                    <div><p class="text-xs text-zinc-400 mb-0.5">Additional Comments</p><p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $viewingReview->comments }}</p></div>
                @endif
                @if(!$viewingReview->overall_rating && !$viewingReview->strengths)
                    <p class="text-sm text-zinc-400 italic">Self-assessment not submitted yet.</p>
                @endif
            </div>

            {{-- Manager Feedback --}}
            @if($viewingReview->manager_feedback || $viewingReview->status === 'manager_reviewed' || $viewingReview->status === 'locked')
            <div class="rounded-xl bg-blue-50 dark:bg-blue-950/20 p-4 space-y-3">
                <p class="text-xs font-bold uppercase tracking-wider text-blue-400">Manager Feedback Â· {{ $viewingReview->reviewer?->user?->name ?? 'N/A' }}</p>
                @if($viewingReview->manager_feedback)
                    <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $viewingReview->manager_feedback }}</p>
                @endif
                @if($viewingReview->promotion_recommended)
                    <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700">
                        âœ“ Promotion Recommended
                    </span>
                @endif
                @if(!$viewingReview->manager_feedback)
                    <p class="text-sm text-zinc-400 italic">Manager review not submitted yet.</p>
                @endif
            </div>
            @endif

            {{-- Goals --}}
            @if($viewingReview->goals->count())
            <div class="space-y-2">
                <p class="text-xs font-bold uppercase tracking-wider text-zinc-400">Goals ({{ $viewingReview->goals->count() }})</p>
                @foreach($viewingReview->goals as $goal)
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3">
                    <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $goal->description }}</p>
                    <div class="mt-2 grid grid-cols-2 gap-3 text-xs text-zinc-500">
                        <div>
                            <span class="font-semibold">Self: </span>
                            {{ $goal->self_rating ? $goal->self_rating.'/5' : 'â€”' }}
                            @if($goal->self_comment) Â· "{{ $goal->self_comment }}" @endif
                        </div>
                        <div>
                            <span class="font-semibold">Manager: </span>
                            {{ $goal->manager_rating ? $goal->manager_rating.'/5' : 'â€”' }}
                            @if($goal->manager_comment) Â· "{{ $goal->manager_comment }}" @endif
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @endif

            <div class="flex justify-between items-center border-t border-zinc-100 dark:border-zinc-800 pt-4">
                @if($viewingReview->status === 'manager_reviewed')
                    <flux:button wire:click="lockReview({{ $viewingReview->id }})" variant="primary" icon="lock-closed">Lock Review</flux:button>
                @else
                    <div></div>
                @endif
                <button type="button" wire:click="$set('showViewModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">Close</button>
            </div>
        </div>
        @endif
    
            </div>
        </div>
    @endif
</flux:main>
