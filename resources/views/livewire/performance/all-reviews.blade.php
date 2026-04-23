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
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $review->employee->user->name }}</div>
                                <div class="text-[10px] text-zinc-500">{{ $review->employee->jobTitle->name }}</div>
                            </td>
                            <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-300">
                                {{ $review->cycle->name }}
                            </td>
                            <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-300">
                                {{ $review->reviewer?->user->name ?: 'N/A' }}
                            </td>
                            <td class="py-3 pr-4">
                                @if($review->status !== 'draft')
                                    <div class="flex">
                                        @for($i=1; $i<=5; $i++)
                                            <flux:icon.star variant="{{ $i <= $review->overall_rating ? 'solid' : 'outline' }}" class="size-3 {{ $i <= $review->overall_rating ? 'text-amber-400' : 'text-zinc-300' }}" />
                                        @endfor
                                    </div>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </td>
                            <td class="py-3 pr-4">
                                <span class="badge-{{ $review->statusColor() }}">{{ strtoupper($review->statusLabel()) }}</span>
                            </td>
                            <td class="py-3 pr-6 text-right space-x-1">
                                @if($review->status === 'manager_reviewed')
                                    <flux:button wire:click="lockReview({{ $review->id }})" size="xs" variant="primary" icon="lock-closed">Lock</flux:button>
                                @endif
                                <flux:button size="xs" variant="ghost" icon="eye">View</flux:button>
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
</flux:main>
