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
                            <div class="font-bold text-zinc-900 dark:text-white">{{ $review->cycle->name }}</div>
                            <div class="text-xs text-zinc-500">Cycle ends: {{ $review->cycle->end_date->format('M d, Y') }}</div>
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
                            <div class="text-xs font-bold text-zinc-400 uppercase tracking-wider mb-1">{{ $cReview->cycle->name }}</div>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    @if($cReview->reviewer->user->avatar)
                                        <img src="{{ $cReview->reviewer->user->avatarUrl() }}" class="size-6 rounded-full" />
                                    @endif
                                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">by {{ $cReview->reviewer->user->name }}</span>
                                </div>
                                <span class="text-xs font-bold text-brand-600">{{ $cReview->overall_rating }}/5</span>
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
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" wire:click="$set('showSelfReviewModal', false)"></div>
            <div class="relative w-full max-w-3xl bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" wire:click="$set('showSelfReviewModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $activeReview?->status === 'draft' ? 'Self Assessment' : 'View Performance Review' }}</flux:heading>
                <flux:subheading>{{ $activeReview?->cycle->name }}</flux:subheading>
            </div>

            <div class="max-h-[70vh] overflow-y-auto px-1 space-y-8">
                {{-- KPI / Goal Assessment --}}
                <div class="space-y-4">
                    <h4 class="font-bold text-zinc-900 dark:text-white border-b pb-2 dark:border-zinc-800">KPI / Goal Assessment</h4>
                    @forelse($activeReview?->goals ?? [] as $goal)
                        <div class="bg-zinc-50 p-4 rounded-xl border border-zinc-100 dark:bg-zinc-900 dark:border-zinc-800 space-y-4">
                            <div>
                                <div class="font-bold text-sm text-zinc-900 dark:text-white">{{ $goal->title }}</div>
                                <div class="text-xs text-zinc-500">{{ $goal->description }}</div>
                            </div>
                            
                            @if($activeReview?->status === 'draft')
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <flux:field>
                                        <flux:label>Self Rating</flux:label>
                                        <flux:select wire:model="goalRatings.{{ $goal->id }}">
                                            <option value="0">Select Rating</option>
                                            <option value="1">1 - Poor</option>
                                            <option value="2">2 - Fair</option>
                                            <option value="3">3 - Good</option>
                                            <option value="4">4 - Very Good</option>
                                            <option value="5">5 - Excellent</option>
                                        </flux:select>
                                    </flux:field>
                                    <flux:textarea wire:model="goalComments.{{ $goal->id }}" label="Self Comment" placeholder="Describe your achievement..." rows="1" />
                                </div>
                            @else
                                <div class="grid grid-cols-2 gap-4 text-xs">
                                    <div class="space-y-1">
                                        <div class="font-bold text-zinc-400">SELF</div>
                                        <div class="text-zinc-700 dark:text-zinc-300">Rating: {{ $goal->self_rating }}/5</div>
                                        <div class="italic text-zinc-500">"{{ $goal->self_comment }}"</div>
                                    </div>
                                    @if($goal->manager_rating)
                                        <div class="space-y-1">
                                            <div class="font-bold text-brand-600">MANAGER</div>
                                            <div class="text-zinc-700 dark:text-zinc-300">Rating: {{ $goal->manager_rating }}/5</div>
                                            <div class="italic text-zinc-500">"{{ $goal->manager_comment }}"</div>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500 italic">No KPIs/Goals linked to this review cycle.</p>
                    @endforelse
                </div>

                {{-- Overall Assessment --}}
                <div class="space-y-4">
                    <h4 class="font-bold text-zinc-900 dark:text-white border-b pb-2 dark:border-zinc-800">Overall Reflection</h4>
                    
                    @if($activeReview?->status === 'draft')
                        <flux:field>
                            <flux:label>Overall Self Rating (1-5)</flux:label>
                            <div class="flex gap-4 items-center">
                                <input type="range" wire:model.live="rating" min="1" max="5" class="w-full accent-brand-600" />
                                <span class="font-bold text-xl text-brand-600">{{ $rating }}</span>
                            </div>
                        </flux:field>

                        <flux:textarea wire:model="strengths" label="Key Achievements & Strengths" rows="3" />
                        <flux:textarea wire:model="improvements" label="Areas for Improvement" rows="3" />
                        <flux:textarea wire:model="comments" label="Additional Comments" rows="2" />
                    @else
                        <div class="space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div class="space-y-4">
                                    <h5 class="text-xs font-bold text-zinc-400 uppercase tracking-tighter">Self Assessment</h5>
                                    <div>
                                        <span class="text-sm font-bold block">Overall Rating</span>
                                        <div class="flex mt-1">
                                            @for($i=1; $i<=5; $i++)
                                                <flux:icon.star variant="{{ $i <= $activeReview?->overall_rating ? 'solid' : 'outline' }}" class="size-4 {{ $i <= $activeReview?->overall_rating ? 'text-amber-400' : 'text-zinc-300' }}" />
                                            @endfor
                                        </div>
                                    </div>
                                    <div>
                                        <span class="text-sm font-bold block">Strengths</span>
                                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $activeReview?->strengths }}</p>
                                    </div>
                                    <div>
                                        <span class="text-sm font-bold block">Improvements</span>
                                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $activeReview?->improvements }}</p>
                                    </div>
                                </div>

                                @if($activeReview?->status !== 'submitted')
                                    <div class="space-y-4">
                                        <h5 class="text-xs font-bold text-brand-600 uppercase tracking-tighter">Manager Feedback</h5>
                                        <div>
                                            <span class="text-sm font-bold block">Overall Feedback</span>
                                            <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $activeReview?->manager_feedback ?: 'No feedback provided yet.' }}</p>
                                        </div>
                                        @if($activeReview?->promotion_recommended)
                                            <div class="flex items-center gap-2 text-green-600 font-bold text-sm">
                                                <flux:icon.check-badge class="size-4" />
                                                <span>Promotion Recommended</span>
                                            </div>
                                        @endif
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

            <div class="flex justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <button type="button" wire:click="$set('showSelfReviewModal\', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">Close</button>
                @if($activeReview?->status === 'draft')
                    <flux:button wire:click="submitSelfReview" variant="primary">Submit Review</flux:button>
                @endif
            </div>
        </div>
    
            </div>
        </div>
    @endif
</flux:main>
