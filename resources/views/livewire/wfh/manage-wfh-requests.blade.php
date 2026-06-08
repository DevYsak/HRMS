<flux:main class="space-y-6 bg-zinc-50 p-6 dark:bg-zinc-950">

    {{-- Page Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="pulse-page-title">Manage WFH Requests</h1>
            <p class="pulse-page-subtitle">Review work-from-home requests and approve or reject with a clear audit trail.</p>
        </div>
    </div>

    {{-- Overview + Filters row --}}
    <div class="grid gap-4 xl:grid-cols-[1fr_2fr]">

        {{-- Overview card --}}
        <div class="pulse-card space-y-4">
            <div>
                <div class="text-[10px] font-black uppercase tracking-widest text-indigo-500">Overview</div>
                <h3 class="mt-1 text-2xl font-black text-zinc-900 dark:text-white">
                    {{ $requests->total() }} WFH Request{{ $requests->total() === 1 ? '' : 's' }}
                </h3>
                <p class="mt-1.5 text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">
                    Use this queue to approve remote-work requests or send them back with a reason.
                </p>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 xl:grid-cols-1">
                {{-- Pending --}}
                <div class="flex items-center gap-3 rounded-2xl bg-orange-50 p-4 dark:bg-orange-950/20">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-orange-100 dark:bg-orange-900/40">
                        <flux:icon.home class="size-5 text-orange-500" />
                    </div>
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Pending</div>
                        <div class="text-2xl font-black text-zinc-900 dark:text-white">{{ $pendingCount }}</div>
                        <p class="text-[11px] text-zinc-400">Needs decision now</p>
                    </div>
                </div>

                {{-- Showing --}}
                <div class="flex items-center gap-3 rounded-2xl bg-blue-50 p-4 dark:bg-blue-950/20">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-blue-100 dark:bg-blue-900/40">
                        <flux:icon.eye class="size-5 text-blue-500" />
                    </div>
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Showing</div>
                        <div class="text-2xl font-black text-zinc-900 dark:text-white">{{ $requests->count() }}</div>
                        <p class="text-[11px] text-zinc-400">Filtered results</p>
                    </div>
                </div>

                {{-- Status filter --}}
                <div class="flex items-center gap-3 rounded-2xl bg-zinc-100 p-4 dark:bg-zinc-800/50">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-zinc-200 dark:bg-zinc-700">
                        <flux:icon.funnel class="size-5 text-zinc-500" />
                    </div>
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Status Filter</div>
                        <div class="text-lg font-black text-zinc-900 dark:text-white">{{ $filterStatus !== '' ? ucfirst($filterStatus) : 'All' }}</div>
                        <p class="text-[11px] text-zinc-400">Current review scope</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Search + filter card --}}
        <div class="pulse-card space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative flex-1 min-w-48">
                    <flux:icon.magnifying-glass class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                    <input
                        wire:model.live.debounce.300ms="filterSearch"
                        type="text"
                        placeholder="Search employees..."
                        class="w-full rounded-xl border border-zinc-200 bg-white py-2.5 pl-9 pr-9 text-sm text-zinc-900 placeholder-zinc-400 outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                    />
                    @if($filterSearch)
                        <button wire:click="$set('filterSearch', '')" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-600">
                            <flux:icon.x-mark class="size-4" />
                        </button>
                    @endif
                </div>
                <flux:select wire:model.live="filterStatus" class="w-36">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                </flux:select>
            </div>

            {{-- Active filter chips --}}
            @if($filterSearch || $filterStatus)
                <div class="flex items-center gap-2 text-sm">
                    <span class="text-zinc-500">{{ $requests->total() }} result{{ $requests->total() === 1 ? '' : 's' }} found</span>
                    <button
                        wire:click="clearFilters"
                        class="flex items-center gap-1 rounded-full bg-zinc-100 px-3 py-1 text-xs font-medium text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                    >
                        Clear all <flux:icon.x-mark class="size-3" />
                    </button>
                </div>
            @endif

            <div class="text-xs text-zinc-400">
                @if($requests->total() === 0)
                    No WFH requests match your filters.
                @else
                    Showing <strong class="text-zinc-700 dark:text-zinc-200">{{ $requests->firstItem() }}–{{ $requests->lastItem() }}</strong> of <strong class="text-zinc-700 dark:text-zinc-200">{{ $requests->total() }}</strong> requests
                @endif
            </div>
        </div>
    </div>

    {{-- Requests Table --}}
    <div class="pulse-card">
        <div class="-mx-6 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <th class="py-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Employee</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">
                            <button wire:click="sortBy('start_date')" class="flex items-center gap-1 hover:text-zinc-600">
                                Dates
                                <flux:icon.chevron-up-down class="size-3" />
                            </button>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Duration</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Reason</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Reviewed</th>
                        <th class="py-3 pl-4 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($requests as $req)
                        @php
                            $avatarColors = ['bg-violet-500','bg-indigo-500','bg-blue-500','bg-emerald-500','bg-brand-600','bg-rose-500','bg-amber-500','bg-teal-500'];
                            $avatarColor  = $avatarColors[crc32($req->employee->user->name) % count($avatarColors)];
                        @endphp
                        <tr class="group transition-colors hover:bg-zinc-50/70 dark:hover:bg-zinc-800/30" wire:key="mng-wfh-{{ $req->id }}">

                            {{-- Employee --}}
                            <td class="py-4 pl-6 pr-4 align-middle">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full {{ $avatarColor }} text-sm font-black text-white">
                                        {{ strtoupper(substr($req->employee->user->name, 0, 1)) }}
                                    </div>
                                    <div>
                                        <div class="font-semibold text-zinc-900 dark:text-white">{{ $req->employee->user->name }}</div>
                                        <div class="text-xs text-zinc-400">{{ $req->employee->department?->name ?? 'No department' }}</div>
                                    </div>
                                </div>
                            </td>

                            {{-- Dates --}}
                            <td class="px-4 py-4 align-middle">
                                <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $req->dateRangeLabel() }}</div>
                                <div class="text-xs text-zinc-400">{{ $req->start_date->format('l') }}</div>
                            </td>

                            {{-- Duration --}}
                            <td class="px-4 py-4 align-middle">
                                <div class="font-bold text-zinc-900 dark:text-zinc-100">
                                    {{ $req->is_half_day ? 'Half day' : number_format($req->daysCount(), 0).' day(s)' }}
                                </div>
                                @if($req->is_half_day)
                                    <div class="text-xs text-zinc-400">{{ $req->half_day_period === 'first_half' ? 'First half' : 'Second half' }}</div>
                                @endif
                            </td>

                            {{-- Reason --}}
                            <td class="px-4 py-4 align-middle">
                                <flux:tooltip :content="$req->reason">
                                    <div class="max-w-[180px] truncate text-sm text-zinc-600 dark:text-zinc-300">{{ $req->reason }}</div>
                                </flux:tooltip>
                            </td>

                            {{-- Status --}}
                            <td class="px-4 py-4 align-middle">
                                <span class="badge-{{ $req->status }}">{{ strtoupper($req->status) }}</span>
                            </td>

                            {{-- Reviewed --}}
                            <td class="px-4 py-4 align-middle text-xs">
                                @if($req->reviewed_at)
                                    <div class="font-medium text-zinc-700 dark:text-zinc-300">{{ $req->reviewer?->name ?? '—' }}</div>
                                    <div class="text-zinc-400">{{ $req->reviewed_at->diffForHumans() }}</div>
                                @else
                                    <span class="text-zinc-400">Pending review</span>
                                @endif
                            </td>

                            {{-- Actions (3-dot dropdown) — teleported to body to escape overflow clipping --}}
                            <td class="py-4 pl-4 pr-6 align-middle text-right">
                                <div
                                    x-data="{
                                        open: false,
                                        top: 0,
                                        left: 0,
                                        toggle() {
                                            if (!this.open) {
                                                const rect = this.$refs.trigger.getBoundingClientRect();
                                                this.top  = rect.bottom + 4;
                                                this.left = rect.right - 176;
                                            }
                                            this.open = !this.open;
                                        }
                                    }"
                                    @click.outside="open = false"
                                    class="inline-block"
                                >
                                    <button
                                        x-ref="trigger"
                                        @click="toggle()"
                                        class="flex size-8 items-center justify-center rounded-lg text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                                    >
                                        <flux:icon.ellipsis-vertical class="size-5" />
                                    </button>

                                    <template x-teleport="body">
                                        <div
                                            x-show="open"
                                            x-transition:enter="transition ease-out duration-100"
                                            x-transition:enter-start="opacity-0 scale-95"
                                            x-transition:enter-end="opacity-100 scale-100"
                                            x-transition:leave="transition ease-in duration-75"
                                            x-transition:leave-start="opacity-100 scale-100"
                                            x-transition:leave-end="opacity-0 scale-95"
                                            :style="`position:fixed; top:${top}px; left:${left}px; z-index:9999;`"
                                            class="w-44 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-800"
                                            style="display:none"
                                        >
                                            @if($req->status === 'pending')
                                                <button
                                                    type="button"
                                                    wire:click="openReview({{ $req->id }}, 'approve')"
                                                    @click="open = false"
                                                    class="flex w-full items-center gap-2.5 px-4 py-2.5 text-left text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700/60"
                                                >
                                                    <flux:icon.check class="size-4 text-emerald-500" />
                                                    Approve
                                                </button>
                                                <div class="border-t border-zinc-100 dark:border-zinc-700"></div>
                                                <button
                                                    type="button"
                                                    wire:click="openReview({{ $req->id }}, 'reject')"
                                                    @click="open = false"
                                                    class="flex w-full items-center gap-2.5 px-4 py-2.5 text-left text-sm font-medium text-red-500 hover:bg-zinc-50 dark:hover:bg-zinc-700/60"
                                                >
                                                    <flux:icon.x-circle class="size-4" />
                                                    Reject Request
                                                </button>
                                            @else
                                                <button
                                                    type="button"
                                                    wire:click="openView({{ $req->id }})"
                                                    @click="open = false"
                                                    class="flex w-full items-center gap-2.5 px-4 py-2.5 text-left text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700/60"
                                                >
                                                    <flux:icon.eye class="size-4 text-zinc-400" />
                                                    View Details
                                                </button>
                                            @endif
                                        </div>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-16 text-center">
                                <flux:icon.home class="mx-auto size-10 text-zinc-300 dark:text-zinc-600 mb-3" />
                                <p class="text-sm font-medium text-zinc-500">No WFH requests found</p>
                                <p class="mt-1 text-xs text-zinc-400">Try adjusting your search or filter.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="mt-4 flex items-center justify-between border-t border-zinc-100 pt-4 dark:border-zinc-800">
            <span class="text-xs text-zinc-400">
                @if($requests->total() > 0)
                    Showing {{ $requests->firstItem() }} to {{ $requests->lastItem() }} of {{ $requests->total() }} result{{ $requests->total() === 1 ? '' : 's' }}
                @else
                    No results
                @endif
            </span>
            {{ $requests->links() }}
        </div>
    </div>

    {{-- View Details Modal (read-only) --}}
    @if($showViewModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showViewModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showViewModal', false)"></div>
            <div class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showViewModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

        @if($selectedRequest)
            <div class="space-y-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="lg">WFH Request Details</flux:heading>
                        <flux:subheading>From {{ $selectedRequest->employee->user->name }}</flux:subheading>
                    </div>
                    <span class="badge-{{ $selectedRequest->status }}">{{ strtoupper($selectedRequest->status) }}</span>
                </div>

                <div class="space-y-3 rounded-2xl bg-zinc-50 p-4 dark:bg-zinc-900">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wide text-zinc-400">Dates</p>
                            <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $selectedRequest->dateRangeLabel() }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wide text-zinc-400">Department</p>
                            <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $selectedRequest->employee->department?->name ?? 'No department' }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wide text-zinc-400">Duration</p>
                            <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ $selectedRequest->is_half_day ? 'Half day ('.($selectedRequest->half_day_period === 'first_half' ? 'First half' : 'Second half').')' : number_format($selectedRequest->daysCount(), 0).' day(s)' }}
                            </p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-950/50">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-zinc-400">Reason</p>
                        <p class="mt-2 text-sm leading-6 text-zinc-700 dark:text-zinc-300">{{ $selectedRequest->reason }}</p>
                    </div>
                    @if($selectedRequest->reviewer_comment)
                        <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-950/50">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-zinc-400">Reviewer Comment</p>
                            <p class="mt-2 text-sm leading-6 text-zinc-700 dark:text-zinc-300">{{ $selectedRequest->reviewer_comment }}</p>
                        </div>
                    @endif
                </div>

                <div class="flex justify-end gap-3 border-t border-zinc-100 pt-2 dark:border-zinc-800">
                    @if($selectedRequest->status === 'pending')
                        <flux:button type="button" wire:click="openReview({{ $selectedRequest->id }}, 'reject')" variant="ghost" class="!text-red-600 hover:!bg-red-50">Reject</flux:button>
                        <flux:button type="button" wire:click="openReview({{ $selectedRequest->id }}, 'approve')" variant="primary">Approve</flux:button>
                    @else
                        <button type="button" @click="$wire.set('showViewModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">Close</button>
                    @endif
                </div>
            </div>
        @endif

            </div>
        </div>
    @endif

    {{-- Review Modal --}}
    @if($showReviewModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showReviewModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showReviewModal', false)"></div>
            <div class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showReviewModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

        @if($selectedRequest)
            <div class="space-y-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="lg">
                            {{ $reviewAction === 'approve' ? 'Approve WFH Request' : 'Reject WFH Request' }}
                        </flux:heading>
                        <flux:subheading>From {{ $selectedRequest->employee->user->name }}</flux:subheading>
                    </div>
                    <span class="badge-{{ $selectedRequest->status }}">{{ strtoupper($selectedRequest->status) }}</span>
                </div>

                <div class="space-y-3 rounded-2xl bg-zinc-50 p-4 dark:bg-zinc-900">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wide text-zinc-400">Dates</p>
                            <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $selectedRequest->dateRangeLabel() }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wide text-zinc-400">Department</p>
                            <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $selectedRequest->employee->department?->name ?? 'No department' }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wide text-zinc-400">Duration</p>
                            <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ $selectedRequest->is_half_day ? 'Half day ('.($selectedRequest->half_day_period === 'first_half' ? 'First half' : 'Second half').')' : number_format($selectedRequest->daysCount(), 0).' day(s)' }}
                            </p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-950/50">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-zinc-400">Reason</p>
                        <p class="mt-2 text-sm leading-6 text-zinc-700 dark:text-zinc-300">{{ $selectedRequest->reason }}</p>
                    </div>
                </div>

                <form wire:submit="submitReview" class="space-y-4">
                    <flux:textarea
                        wire:model="reviewComment"
                        label="{{ $reviewAction === 'reject' ? 'Rejection Reason *' : 'Comment (optional)' }}"
                        placeholder="{{ $reviewAction === 'reject' ? 'Explain clearly why this request is being rejected...' : 'Add optional approval notes...' }}"
                        rows="3"
                    />
                    @error('reviewComment')
                        <p class="text-xs text-red-500">{{ $message }}</p>
                    @enderror

                    <div class="flex justify-end gap-3 border-t border-zinc-100 pt-2 dark:border-zinc-800">
                        <flux:button type="button" wire:click="closeReviewModal" variant="ghost">Cancel</flux:button>
                        <flux:button
                            type="submit"
                            variant="{{ $reviewAction === 'approve' ? 'primary' : 'ghost' }}"
                            class="{{ $reviewAction === 'reject' ? '!text-red-600 hover:!bg-red-50 dark:hover:!bg-red-950/30' : '' }}"
                            wire:loading.attr="disabled"
                            wire:target="submitReview"
                        >
                            <span wire:loading.remove wire:target="submitReview">{{ $reviewAction === 'approve' ? 'Approve Request' : 'Reject Request' }}</span>
                            <span wire:loading wire:target="submitReview">Processing…</span>
                        </flux:button>
                    </div>
                </form>
            </div>
        @endif

            </div>
        </div>
    @endif

</flux:main>
