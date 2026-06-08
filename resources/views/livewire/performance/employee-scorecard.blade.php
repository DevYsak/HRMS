<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <div class="flex items-center gap-3">
                <flux:button href="{{ route('performance.my-review') }}" wire:navigate variant="ghost" size="sm" icon="arrow-left" class="mr-2" />
                <h1 class="pulse-page-title">Performance Scorecard</h1>
            </div>
            <p class="pulse-page-subtitle">Finalized assessment results for {{ $review->performanceCycle?->name ?? '—' }}</p>
        </div>
        <flux:button variant="ghost" icon="printer" onclick="window.print()">Print Scorecard</flux:button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Left side - Employee Info & Overall Grade --}}
        <div class="space-y-6">
            {{-- Employee Card --}}
            <div class="pulse-card p-6">
                <div class="flex items-center gap-4 mb-6">
                    <flux:avatar :src="$review->employee->user->avatarUrl()" :initials="$review->employee->user->initials()" size="lg" />
                    <div>
                        <h2 class="text-lg font-bold text-zinc-900 dark:text-white">{{ $review->employee->user->name }}</h2>
                        <p class="text-sm text-zinc-500">{{ $review->employee->jobTitle->name ?? 'No Title' }}</p>
                    </div>
                </div>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between py-2 border-t border-zinc-100 dark:border-zinc-800">
                        <span class="text-zinc-500">Department</span>
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $review->employee->department->name ?? '—' }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-t border-zinc-100 dark:border-zinc-800">
                        <span class="text-zinc-500">Manager</span>
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $review->reviewer->user->name ?? '—' }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-t border-zinc-100 dark:border-zinc-800">
                        <span class="text-zinc-500">Review Cycle</span>
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $review->performanceCycle?->name ?? '—' }}</span>
                    </div>
                </div>
            </div>

            {{-- Final Score & Grade --}}
            <div class="pulse-card p-6 bg-gradient-to-br from-indigo-500 to-brand-600 text-white relative overflow-hidden">
                <div class="absolute inset-0 bg-white/10 opacity-20" style="background-image: radial-gradient(circle at 2px 2px, white 1px, transparent 0); background-size: 20px 20px;"></div>
                <div class="relative z-10 text-center space-y-2">
                    <h3 class="text-sm font-semibold text-white/80 uppercase tracking-widest">Final Performance Grade</h3>
                    <div class="text-5xl font-black">{{ $review->gradeLabel() }}</div>
                    <div class="text-2xl font-bold opacity-90">{{ $review->final_score }}<span class="text-lg opacity-70">%</span></div>
                </div>
            </div>

            {{-- HR / Promotion --}}
            @if($review->promotion_recommended)
                <div class="pulse-card p-4 bg-green-50 border border-green-200 dark:bg-green-900/20 dark:border-green-800/30 flex items-center gap-3">
                    <div class="bg-green-100 text-green-600 p-2 rounded-full dark:bg-green-800 dark:text-green-300">
                        <flux:icon.arrow-trending-up class="size-5" />
                    </div>
                    <div>
                        <h4 class="font-bold text-green-800 dark:text-green-300">Promotion Recommended</h4>
                        <p class="text-xs text-green-600 dark:text-green-400">Approved by Manager</p>
                    </div>
                </div>
            @endif
        </div>

        {{-- Right side - Component Details & Comments --}}
        <div class="col-span-2 space-y-6">
            {{-- Category Breakdown --}}
            <div class="pulse-card p-6 space-y-6">
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Performance Breakdown</h3>
                
                <div class="space-y-8">
                    @foreach($categories as $catData)
                        <div class="space-y-4">
                            <div class="flex justify-between items-end border-b border-zinc-200 dark:border-zinc-700 pb-2">
                                <h4 class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $catData['name'] }}</h4>
                                <div class="text-sm">
                                    <span class="font-bold text-indigo-600">{{ $catData['earned_weight'] }}</span>
                                    <span class="text-zinc-400">/ {{ $catData['total_weight'] }} pts</span>
                                </div>
                            </div>
                            
                            <div class="space-y-3">
                                @foreach($catData['scores'] as $scoreRow)
                                    @php $component = $scoreRow->component; @endphp
                                    <div class="bg-zinc-50 dark:bg-zinc-900 p-4 rounded-xl">
                                        <div class="flex justify-between items-start mb-2">
                                            <div>
                                                <div class="font-medium text-zinc-900 dark:text-white text-sm">
                                                    {{ $component->name }}
                                                    @if($component->isAutoScored())
                                                        <span class="ml-2 text-[9px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded-full uppercase tracking-wider font-semibold">Auto-Scored</span>
                                                    @endif
                                                </div>
                                                <div class="text-xs text-zinc-500 mt-0.5 max-w-lg">{{ $component->description }}</div>
                                            </div>
                                            <div class="text-right shrink-0 bg-white dark:bg-zinc-800 px-3 py-1.5 rounded-lg border border-zinc-100 dark:border-zinc-700 shadow-sm">
                                                <div class="text-[10px] text-zinc-400 font-bold uppercase mb-0.5">Weighted Score</div>
                                                <div class="font-bold text-brand-600">{{ $scoreRow->weighted_score }}<span class="text-xs text-zinc-400 font-normal"> / {{ $component->weight_percent }}</span></div>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-3 grid grid-cols-2 lg:grid-cols-3 gap-4 text-xs">
                                            <div class="space-y-1">
                                                <div class="font-bold text-zinc-400 uppercase tracking-wider text-[10px]">Self</div>
                                                @if($component->isAutoScored())
                                                    <span class="italic text-zinc-500">N/A</span>
                                                @else
                                                    <div class="text-zinc-800 dark:text-zinc-200">Score: <span class="font-semibold">{{ $scoreRow->self_score ?? '-' }}</span></div>
                                                    @if($scoreRow->self_comment)<div class="text-zinc-500 italic mt-0.5 line-clamp-2" title="{{ $scoreRow->self_comment }}">"{{ $scoreRow->self_comment }}"</div>@endif
                                                @endif
                                            </div>
                                            <div class="space-y-1">
                                                <div class="font-bold text-brand-500 uppercase tracking-wider text-[10px]">Manager</div>
                                                @if($component->isAutoScored())
                                                    <span class="italic text-zinc-500">N/A</span>
                                                @else
                                                    <div class="text-zinc-800 dark:text-zinc-200">Score: <span class="font-semibold">{{ $scoreRow->manager_score ?? '-' }}</span></div>
                                                    @if($scoreRow->manager_comment)<div class="text-zinc-500 italic mt-0.5 line-clamp-2" title="{{ $scoreRow->manager_comment }}">"{{ $scoreRow->manager_comment }}"</div>@endif
                                                @endif
                                            </div>
                                            <div class="space-y-1 lg:col-span-1 col-span-2">
                                                <div class="font-bold text-indigo-500 uppercase tracking-wider text-[10px]">Final ({{ $component->max_score }} max)</div>
                                                <div class="text-zinc-800 dark:text-zinc-200">Score: <span class="font-semibold">{{ $scoreRow->final_score ?? '-' }}</span></div>
                                                @if($scoreRow->hr_comment)<div class="text-zinc-500 italic mt-0.5 line-clamp-2" title="{{ $scoreRow->hr_comment }}">"{{ $scoreRow->hr_comment }}"</div>@endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Overall Comments --}}
            <div class="pulse-card p-6 space-y-6">
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Overall Remarks</h3>
                
                <div class="space-y-6">
                    <div class="space-y-2">
                        <h4 class="text-xs font-bold text-brand-600 uppercase tracking-wider">Manager Summary</h4>
                        <div class="bg-brand-50 dark:bg-brand-900/10 p-4 rounded-xl border border-brand-100 dark:border-brand-800/30">
                            <p class="text-sm text-brand-900 dark:text-brand-200">{{ $review->manager_feedback ?: 'No overall summary provided.' }}</p>
                        </div>
                    </div>

                    @if($review->hr_comments)
                        <div class="space-y-2">
                            <h4 class="text-xs font-bold text-indigo-600 uppercase tracking-wider">HR Final Comments</h4>
                            <div class="bg-indigo-50 dark:bg-indigo-900/10 p-4 rounded-xl border border-indigo-100 dark:border-indigo-800/30">
                                <p class="text-sm text-indigo-900 dark:text-indigo-200">{{ $review->hr_comments }}</p>
                            </div>
                        </div>
                    @endif

                    <div class="space-y-2">
                        <h4 class="text-xs font-bold text-zinc-500 uppercase tracking-wider">Employee Self-Reflection</h4>
                        <div class="bg-zinc-50 dark:bg-zinc-900 p-4 rounded-xl border border-zinc-100 dark:border-zinc-800 space-y-3">
                            @if($review->strengths)
                                <div><span class="text-xs font-bold text-zinc-400 block mb-1">Key Strengths</span><p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $review->strengths }}</p></div>
                            @endif
                            @if($review->improvements)
                                <div><span class="text-xs font-bold text-zinc-400 block mb-1">Areas for Improvement</span><p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $review->improvements }}</p></div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</flux:main>
