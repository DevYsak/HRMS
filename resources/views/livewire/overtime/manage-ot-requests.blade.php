<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Manage OT Requests</h1>
            <p class="pulse-page-subtitle">Review, approve or reject overtime pre-approval requests</p>
        </div>
        <flux:button href="{{ route('reports.ot-records', ['month' => now()->month, 'year' => now()->year]) }}" variant="ghost" icon="arrow-down-tray" target="_blank">Export CSV</flux:button>
    </div>

    {{-- Filters --}}
    <div class="pulse-card">
        <div class="flex flex-wrap items-center gap-3">
            <flux:input
                wire:model.live="filterSearch"
                placeholder="Search employee..."
                icon="magnifying-glass"
                class="w-56"
                size="sm"
            />
            <flux:select wire:model.live="filterStatus" class="w-36" size="sm">
                <option value="">All Status</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
            </flux:select>
        </div>
    </div>

    {{-- Requests Table --}}
    <div class="pulse-card">
        <div class="overflow-x-auto -mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Employee</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Work Date</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Time Window</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Hrs</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Reason</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Status</th>
                        <th class="pb-3 pr-6 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($requests as $req)
                        <tr class="group hover:bg-zinc-50/70 dark:hover:bg-zinc-800/30 transition-colors" wire:key="mng-ot-{{ $req->id }}">
                            <td class="py-4 pl-6 pr-4">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $req->employee->user->name }}</div>
                                <div class="text-xs text-zinc-400">{{ $req->employee->department?->name }}</div>
                            </td>
                            <td class="py-4 pr-4 text-zinc-700 dark:text-zinc-300">
                                {{ $req->work_date->format('M d, Y') }}
                            </td>
                            <td class="py-4 pr-4 text-zinc-600 dark:text-zinc-400 text-xs">
                                {{ $req->start_time }} – {{ $req->end_time }}
                            </td>
                            <td class="py-4 pr-4 font-semibold text-zinc-900 dark:text-white">
                                {{ number_format($req->requested_hours, 2) }}
                            </td>
                            <td class="py-4 pr-4 text-zinc-500 max-w-xs truncate">
                                <flux:tooltip :content="$req->reason">
                                    <span class="truncate block max-w-[180px]">{{ $req->reason }}</span>
                                </flux:tooltip>
                            </td>
                            <td class="py-4 pr-4">
                                <span class="badge-{{ $req->status }}">{{ strtoupper($req->status) }}</span>
                            </td>
                            <td class="py-4 pr-6">
                                @if($req->status === 'pending')
                                    <div class="flex items-center gap-2">
                                        <flux:button
                                            wire:click="openReview({{ $req->id }}, 'approve')"
                                            variant="primary"
                                            size="xs"
                                            icon="check"
                                        >Approve</flux:button>
                                        <flux:button
                                            wire:click="openReview({{ $req->id }}, 'reject')"
                                            variant="danger"
                                            size="xs"
                                            icon="x-mark"
                                        >Reject</flux:button>
                                    </div>
                                @else
                                    <span class="text-xs text-zinc-400">
                                        {{ $req->reviewer?->name }} · {{ $req->reviewed_at?->diffForHumans() }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 text-center text-zinc-400">
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

    {{-- Review Modal --}}
    <flux:modal wire:model="showReviewModal" class="w-full max-w-md">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">
                    {{ $reviewAction === 'approve' ? 'Approve OT Request' : 'Reject OT Request' }}
                </flux:heading>
                <flux:subheading>
                    {{ $reviewAction === 'approve'
                        ? 'An overtime record will be created upon approval.'
                        : 'Please provide a reason for rejection.' }}
                </flux:subheading>
            </div>

            <form wire:submit="submitReview" class="space-y-4">
                <flux:textarea
                    wire:model="reviewComment"
                    label="{{ $reviewAction === 'reject' ? 'Rejection Reason *' : 'Comment (optional)' }}"
                    placeholder="{{ $reviewAction === 'reject' ? 'Explain why this OT request is rejected...' : 'Add a comment...' }}"
                    rows="3"
                />

                <div class="flex justify-end gap-3 pt-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button
                        type="submit"
                        variant="{{ $reviewAction === 'approve' ? 'primary' : 'danger' }}"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading.remove>{{ $reviewAction === 'approve' ? 'Approve' : 'Reject' }}</span>
                        <span wire:loading>Processing...</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</flux:main>
