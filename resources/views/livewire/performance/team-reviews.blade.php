<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Team Performance Reviews</h1>
            <p class="pulse-page-subtitle">Evaluate and provide feedback for your direct reports</p>
        </div>
    </div>

    <div class="pulse-card">
        <div class="px-6 pt-4 pb-3 border-b border-zinc-100 dark:border-zinc-800">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by employee name..." icon="magnifying-glass" class="max-w-xs" />
        </div>
        <div class="overflow-x-auto -mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-zinc-50/50 dark:bg-zinc-900/50 border-b border-zinc-100 dark:border-zinc-800">
                        <th class="py-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Employee</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Cycle</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                        <th class="py-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($teamReviews as $review)
                        <tr class="hover:bg-zinc-50/30 transition-colors">
                            <td class="py-4 pl-6 pr-4">
                                <div class="flex items-center gap-3">
                                    <flux:avatar :src="$review->employee->user->avatarUrl()" :initials="$review->employee->user->initials()" size="sm" />
                                    <div>
                                        <div class="font-medium text-zinc-900 dark:text-white">{{ $review->employee->user->name }}</div>
                                        <div class="text-xs text-zinc-500">{{ $review->employee->jobTitle->name }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-4 pr-4">
                                <div class="font-medium text-zinc-700 dark:text-zinc-300">{{ $review->cycle->name }}</div>
                            </td>
                            <td class="py-4 pr-4">
                                <span class="badge-{{ $review->statusColor() }}">{{ strtoupper($review->statusLabel()) }}</span>
                            </td>
                            <td class="py-4 pr-6 text-right">
                                @if($review->status === 'submitted')
                                    <flux:button wire:click="openManagerReview({{ $review->id }})" size="sm" variant="primary">Review</flux:button>
                                @else
                                    <flux:button wire:click="openManagerReview({{ $review->id }})" size="sm" variant="ghost">View</flux:button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-8 text-center text-zinc-500">No team reviews pending.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Manager Review Form Modal --}}
    @if($showReviewModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showReviewModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showReviewModal', false)"></div>
            <div class="relative w-full max-w-4xl bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showReviewModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

        <div class="space-y-6">
            @if($activeReview)
                <div>
                    <flux:heading size="lg">{{ $activeReview->status === 'submitted' ? 'Review Assessment' : 'Performance Review Details' }}</flux:heading>
                    <flux:subheading>{{ $activeReview->employee->user->name }} â€¢ {{ $activeReview->cycle->name }}</flux:subheading>
                </div>

                <div class="max-h-[70vh] overflow-y-auto px-1 space-y-8">
                    {{-- KPI Assessment --}}
                    <div class="space-y-4">
                        <h4 class="font-bold text-zinc-900 dark:text-white border-b pb-2 dark:border-zinc-800">KPI / Goal Evaluation</h4>
                        @foreach($activeReview->goals as $goal)
                            <div class="bg-zinc-50 p-4 rounded-xl border border-zinc-100 dark:bg-zinc-900 dark:border-zinc-800 space-y-4">
                                <div class="flex justify-between">
                                    <div>
                                        <div class="font-bold text-sm text-zinc-900 dark:text-white">{{ $goal->title }}</div>
                                        <div class="text-xs text-zinc-500">{{ $goal->description }}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-[10px] font-bold text-zinc-400 uppercase">Self Rating</div>
                                        <div class="font-bold text-lg text-zinc-700 dark:text-zinc-300">{{ $goal->self_rating }}/5</div>
                                    </div>
                                </div>
                                
                                <div class="bg-white p-3 rounded-lg border border-zinc-100 dark:bg-zinc-800 dark:border-zinc-700">
                                    <span class="text-[10px] font-bold text-zinc-400 uppercase">Employee Comments</span>
                                    <p class="text-sm italic text-zinc-600 dark:text-zinc-400">"{{ $goal->self_comment ?: 'No comments provided.' }}"</p>
                                </div>

                                @if($activeReview->status === 'submitted')
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <flux:field>
                                            <flux:label>Your Rating</flux:label>
                                            <flux:select wire:model="goalRatings.{{ $goal->id }}">
                                                <option value="0">Select Rating</option>
                                                <option value="1">1 - Poor</option>
                                                <option value="2">2 - Fair</option>
                                                <option value="3">3 - Good</option>
                                                <option value="4">4 - Very Good</option>
                                                <option value="5">5 - Excellent</option>
                                            </flux:select>
                                        </flux:field>
                                        <flux:textarea wire:model="goalComments.{{ $goal->id }}" label="Your Feedback" placeholder="Add specific feedback for this KPI..." rows="1" />
                                    </div>
                                @else
                                    <div class="bg-brand-50 p-3 rounded-lg border border-brand-100 dark:bg-brand-900/10 dark:border-brand-800/30">
                                        <span class="text-[10px] font-bold text-brand-600 uppercase">Your Rating: {{ $goal->manager_rating }}/5</span>
                                        <p class="text-sm text-brand-900 dark:text-brand-300">{{ $goal->manager_comment }}</p>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    {{-- Overall Feedback --}}
                    <div class="space-y-4">
                        <h4 class="font-bold text-zinc-900 dark:text-white border-b pb-2 dark:border-zinc-800">Overall Assessment</h4>
                        
                        <div class="bg-zinc-50 p-6 rounded-xl dark:bg-zinc-900">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div class="space-y-4">
                                    <h5 class="text-xs font-bold text-zinc-400 uppercase">Employee Reflection</h5>
                                    <div>
                                        <span class="text-sm font-bold block">Overall Self Rating</span>
                                        <div class="text-lg font-bold">{{ $activeReview->overall_rating }}/5</div>
                                    </div>
                                    <div>
                                        <span class="text-sm font-bold block">Strengths</span>
                                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $activeReview->strengths }}</p>
                                    </div>
                                    <div>
                                        <span class="text-sm font-bold block">Improvements</span>
                                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $activeReview->improvements }}</p>
                                    </div>
                                </div>

                                <div class="space-y-6">
                                    <h5 class="text-xs font-bold text-brand-600 uppercase">Manager Decision</h5>
                                    
                                    @if($activeReview->status === 'submitted')
                                        <flux:field>
                                            <flux:label>Final Performance Rating (1-5)</flux:label>
                                            <div class="flex gap-4 items-center">
                                                <input type="range" wire:model.live="rating" min="1" max="5" class="w-full accent-brand-600" />
                                                <span class="font-bold text-xl text-brand-600">{{ $rating }}</span>
                                            </div>
                                        </flux:field>

                                        <flux:textarea wire:model="manager_feedback" label="Overall Performance Summary" placeholder="Summarize the employee's performance for this cycle..." rows="4" />
                                        
                                        <flux:field>
                                            <div class="flex items-center gap-2">
                                                <flux:checkbox wire:model="promotion_recommended" />
                                                <flux:label>Recommend for Promotion</flux:label>
                                            </div>
                                        </flux:field>
                                    @else
                                        <div class="space-y-4">
                                            <div>
                                                <span class="text-sm font-bold block">Overall Rating</span>
                                                <div class="text-lg font-bold text-brand-600">{{ $activeReview->overall_rating }}/5</div>
                                            </div>
                                            <div>
                                                <span class="text-sm font-bold block">Feedback Summary</span>
                                                <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $activeReview->manager_feedback }}</p>
                                            </div>
                                            @if($activeReview->promotion_recommended)
                                                <div class="flex items-center gap-2 text-green-600 font-bold text-sm bg-green-50 p-2 rounded-lg dark:bg-green-900/20">
                                                    <flux:icon.check-badge class="size-4" />
                                                    <span>Promotion Recommended</span>
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                    <button type="button" @click="$wire.set('showReviewModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">Close</button>
                    @if($activeReview->status === 'submitted')
                        <flux:button wire:click="submitManagerReview" variant="primary">Submit Review</flux:button>
                    @endif
                </div>
            @endif
        </div>
    
            </div>
        </div>
    @endif
</flux:main>
