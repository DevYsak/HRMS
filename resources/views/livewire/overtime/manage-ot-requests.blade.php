<flux:main class="space-y-6 bg-zinc-50 p-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Manage OT Requests</h1>
            <p class="pulse-page-subtitle">Review pre-approval requests with clear work windows, duration, and action context.</p>
        </div>
        <flux:button href="{{ route('reports.ot-records', ['month' => now()->month, 'year' => now()->year]) }}" variant="ghost" icon="arrow-down-tray" target="_blank">
            Export CSV
        </flux:button>
    </div>

    <div class="grid gap-4 xl:grid-cols-[0.95fr_2.05fr]">
        <div class="pulse-card space-y-4">
            <div>
                <div class="text-[10px] font-black uppercase tracking-[0.24em] text-zinc-400">Review Queue</div>
                <h3 class="mt-2 text-xl font-black text-zinc-900 dark:text-white">{{ $requests->total() }} OT request{{ $requests->total() === 1 ? '' : 's' }}</h3>
                <p class="mt-2 text-sm leading-6 text-zinc-500 dark:text-zinc-400">Use this queue to approve valid extra work windows or send back requests with a reason.</p>
            </div>

            <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-1">
                <div class="rounded-2xl bg-zinc-50 p-4 dark:bg-zinc-800/50">
                    <div class="text-[10px] font-black uppercase tracking-[0.22em] text-zinc-400">Pending</div>
                    <div class="mt-2 text-3xl font-black text-zinc-900 dark:text-white">{{ $requests->getCollection()->where('status', 'pending')->count() }}</div>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Needs decision now</p>
                </div>
                <div class="rounded-2xl bg-zinc-50 p-4 dark:bg-zinc-800/50">
                    <div class="text-[10px] font-black uppercase tracking-[0.22em] text-zinc-400">Showing</div>
                    <div class="mt-2 text-3xl font-black text-zinc-900 dark:text-white">{{ $requests->count() }}</div>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Filtered results</p>
                </div>
                <div class="rounded-2xl bg-zinc-50 p-4 dark:bg-zinc-800/50">
                    <div class="text-[10px] font-black uppercase tracking-[0.22em] text-zinc-400">Status Filter</div>
                    <div class="mt-2 text-lg font-black text-zinc-900 dark:text-white">{{ $filterStatus !== '' ? ucfirst($filterStatus) : 'All' }}</div>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Current review scope</p>
                </div>
            </div>
        </div>

        <div class="pulse-card">
            <div class="grid gap-3 md:grid-cols-[1fr_180px]">
                <flux:input
                    wire:model.live.debounce.300ms="filterSearch"
                    placeholder="Search employee..."
                    icon="magnifying-glass"
                    size="sm"
                />
                <flux:select wire:model.live="filterStatus" size="sm">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                </flux:select>
            </div>
        </div>
    </div>

    <div class="pulse-card">
        <div class="overflow-x-auto -mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 bg-zinc-50/50 dark:border-zinc-800 dark:bg-zinc-900/40">
                        <th class="pb-3 pl-6 pr-4 pt-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Employee</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Work Date</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">OT Window</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Duration</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Reason</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Reviewed</th>
                        <th class="py-3 pl-4 pr-6 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($requests as $req)
                        <tr class="group transition-colors hover:bg-zinc-50/70 dark:hover:bg-zinc-800/30" wire:key="mng-ot-{{ $req->id }}">
                            <td class="py-4 pl-6 pr-4 align-top">
                                <div class="font-semibold text-zinc-900 dark:text-white">{{ $req->employee->user->name }}</div>
                                <div class="mt-1 text-xs text-zinc-400">{{ $req->employee->department?->name ?? 'No department' }}</div>
                            </td>
                            <td class="px-4 py-4 align-top text-zinc-700 dark:text-zinc-300">
                                <div class="font-medium">{{ $req->work_date->format('M d, Y') }}</div>
                                <div class="mt-1 text-xs text-zinc-400">{{ $req->work_date->format('l') }}</div>
                            </td>
                            <td class="px-4 py-4 align-top">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $req->timeWindowLabel() }}</div>
                                <div class="mt-1 text-xs text-zinc-400">Requested overtime slot</div>
                            </td>
                            <td class="px-4 py-4 align-top">
                                <div class="font-semibold text-zinc-900 dark:text-white">{{ number_format($req->resolvedRequestedHours(), 2) }} hrs</div>
                                <div class="mt-1 text-xs text-zinc-400">{{ $req->totalRequestedMinutes() }} mins</div>
                            </td>
                            <td class="px-4 py-4 align-top text-zinc-500">
                                <flux:tooltip :content="$req->reason">
                                    <div class="max-w-[220px]">
                                        <div class="line-clamp-2 text-sm text-zinc-700 dark:text-zinc-300">{{ $req->reason }}</div>
                                    </div>
                                </flux:tooltip>
                            </td>
                            <td class="px-4 py-4 align-top">
                                <span class="badge-{{ $req->status }}">{{ strtoupper($req->status) }}</span>
                            </td>
                            <td class="px-4 py-4 align-top text-xs text-zinc-500">
                                @if($req->reviewed_at)
                                    <div class="font-medium text-zinc-700 dark:text-zinc-300">{{ $req->reviewer?->name ?? 'Reviewer' }}</div>
                                    <div class="mt-1 text-zinc-400">{{ $req->reviewed_at->diffForHumans() }}</div>
                                @else
                                    <span class="text-zinc-400">Pending review</span>
                                @endif
                            </td>
                            <td class="py-4 pl-4 pr-6 align-top">
                                @if($req->status === 'pending')
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:button
                                            wire:click="openReview({{ $req->id }}, 'approve')"
                                            wire:loading.attr="disabled"
                                            wire:target="openReview"
                                            variant="primary"
                                            size="xs"
                                            icon="check"
                                        >
                                            Review & Approve
                                        </flux:button>
                                        <flux:button
                                            wire:click="openReview({{ $req->id }}, 'reject')"
                                            wire:loading.attr="disabled"
                                            wire:target="openReview"
                                            variant="ghost"
                                            size="xs"
                                            class="!text-red-600 hover:!bg-red-50 dark:hover:!bg-red-950/30"
                                            icon="x-mark"
                                        >
                                            Reject
                                        </flux:button>
                                    </div>
                                @else
                                    <span class="text-xs text-zinc-400">No further action</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-12 text-center text-zinc-400">
                                No OT requests found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 border-t border-zinc-50 pt-3 dark:border-zinc-800">
            {{ $requests->links() }}
        </div>
    </div>

    <flux:modal wire:model="showReviewModal" class="w-full max-w-lg">
        @if($selectedRequest)
            <div class="space-y-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="lg">
                            {{ $reviewAction === 'approve' ? 'Approve OT Request' : 'Reject OT Request' }}
                        </flux:heading>
                        <flux:subheading>From {{ $selectedRequest->employee->user->name }}</flux:subheading>
                    </div>
                    <span class="badge-{{ $selectedRequest->status }}">{{ strtoupper($selectedRequest->status) }}</span>
                </div>

                <div class="space-y-3 rounded-2xl bg-zinc-50 p-4 dark:bg-zinc-900">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wide text-zinc-400">Work date</p>
                            <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $selectedRequest->work_date->format('d M Y') }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wide text-zinc-400">Department</p>
                            <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $selectedRequest->employee->department?->name ?? 'No department' }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wide text-zinc-400">OT window</p>
                            <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $selectedRequest->timeWindowLabel() }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wide text-zinc-400">Requested duration</p>
                            <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ number_format($selectedRequest->resolvedRequestedHours(), 2) }} hrs ({{ $selectedRequest->totalRequestedMinutes() }} mins)</p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-950/50">
                        <p class="text-[11px] font-bold uppercase tracking-wide text-zinc-400">Reason</p>
                        <p class="mt-2 text-sm leading-6 text-zinc-700 dark:text-zinc-300">{{ $selectedRequest->reason }}</p>
                    </div>

                    @if($selectedRequest->attendance)
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
                            Linked attendance found. Final OT record will be calculated against actual attendance hours on this date.
                        </div>
                    @else
                        <div class="rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm text-blue-700 dark:border-blue-500/20 dark:bg-blue-500/10 dark:text-blue-300">
                            No attendance record linked. Approval will use the requested time window shown above.
                        </div>
                    @endif
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
                            <span wire:loading wire:target="submitReview">Processing...</span>
                        </flux:button>
                    </div>
                </form>
            </div>
        @endif
    </flux:modal>
</flux:main>
