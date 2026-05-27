<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Team Time Off</h1>
            <p class="pulse-page-subtitle">Review and manage leave requests for your direct reports</p>
        </div>
    </div>

    {{-- Pending Requests Section --}}
    @if($pendingRequests->count() > 0)
        <div class="space-y-4">
            <h3 class="text-sm font-bold text-zinc-400 uppercase tracking-wider">Pending Action ({{ $pendingRequests->count() }})</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($pendingRequests as $req)
                    <div class="pulse-card flex flex-col gap-4">
                        <div class="flex items-center gap-3">
                            <div class="size-10 rounded-full bg-brand-600 flex items-center justify-center font-bold text-white text-sm">
                                {{ strtoupper(substr($req->employee->user->name, 0, 1)) }}
                            </div>
                            <div>
                                <div class="font-bold text-zinc-900 dark:text-white">{{ $req->employee->user->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $req->leaveType->name }}</div>
                            </div>
                        </div>
                        
                        <div class="bg-zinc-50 p-3 rounded-lg dark:bg-zinc-800/50">
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-zinc-400 font-medium">Requested Period</span>
                                <span class="text-zinc-900 font-bold dark:text-zinc-100">{{ (float)$req->days }} Days</span>
                            </div>
                            <div class="text-[13px] text-zinc-700 dark:text-zinc-300">
                                {{ $req->start_date->format('M d') }} - {{ $req->end_date->format('M d, Y') }}
                            </div>
                        </div>

                        <div class="text-xs text-zinc-500 italic line-clamp-2">
                            "{{ $req->reason }}"
                        </div>

                        <div class="flex gap-2 mt-2">
                            <flux:button wire:click="selectRequest({{ $req->id }})" variant="primary" class="flex-1">Review</flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="pulse-card py-12 flex flex-col items-center justify-center text-center opacity-60">
            <flux:icon.check-circle class="size-10 text-brand-500 mb-2" />
            <h4 class="font-bold text-zinc-900 dark:text-white">All caught up!</h4>
            <p class="text-sm text-zinc-500 max-w-xs">There are no pending leave requests from your team right now.</p>
        </div>
    @endif

    {{-- Team History --}}
    <div class="pulse-card">
        <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-6">Recent Team Activity</h3>
        
        <div class="overflow-x-auto -mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Employee</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Leave Type</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Dates</th>
                        <th class="pb-3 pr-4 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Days</th>
                        <th class="pb-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($history as $item)
                        <tr class="group hover:bg-zinc-50/70 dark:hover:bg-zinc-800/30 transition-colors">
                            <td class="py-4 pl-6 pr-4 font-medium text-zinc-900 dark:text-white">
                                {{ $item->employee->user->name }}
                            </td>
                            <td class="py-4 pr-4 text-zinc-600 dark:text-zinc-300">
                                {{ $item->leaveType->name }}
                            </td>
                            <td class="py-4 pr-4">
                                <span class="badge-{{ $item->status }}">{{ strtoupper($item->status) }}</span>
                            </td>
                            <td class="py-4 pr-4 text-xs text-zinc-500">
                                {{ $item->start_date->format('d M') }} - {{ $item->end_date->format('d M, Y') }}
                            </td>
                            <td class="py-4 pr-4 text-right font-bold text-zinc-900 dark:text-white">
                                {{ (float)$item->days }}
                            </td>
                            <td class="py-4 pr-6 text-right">
                                <flux:button wire:click="selectRequest({{ $item->id }})" variant="ghost" size="sm" icon="pencil-square" class="text-zinc-400 hover:text-brand-600" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center text-zinc-400">No team leave history found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4 border-t border-zinc-50 pt-3 dark:border-zinc-800">
            {{ $history->links() }}
        </div>
    </div>

    {{-- Review Modal --}}
    <flux:modal name="review-modal" wire:model.self="showReviewModal" class="w-full max-w-lg">
        @if($selectedRequestId && $selectedRequest)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">Review Leave Request</flux:heading>
                    <flux:subheading>From {{ $selectedRequest->employee->user->name }}</flux:subheading>
                </div>

                {{-- Request summary card --}}
                <div class="rounded-xl bg-zinc-50 p-4 space-y-2.5 dark:bg-zinc-900">
                    <div class="flex justify-between text-sm">
                        <span class="text-zinc-500">Leave Type</span>
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $selectedRequest->leaveType->name }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-zinc-500">Duration</span>
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ (float)$selectedRequest->days }} {{ $selectedRequest->is_half_day ? 'Half Day' : 'Day(s)' }}
                        </span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-zinc-500">Dates</span>
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ $selectedRequest->start_date->format('d M Y') }}
                            @if(!$selectedRequest->start_date->equalTo($selectedRequest->end_date))
                                – {{ $selectedRequest->end_date->format('d M Y') }}
                            @endif
                        </span>
                    </div>
                    <div class="pt-2 border-t border-zinc-200 dark:border-zinc-700">
                        <p class="text-xs text-zinc-400 mb-1">Employee's reason:</p>
                        <p class="text-sm text-zinc-700 italic dark:text-zinc-300">"{{ $selectedRequest->reason }}"</p>
                    </div>
                </div>

                {{-- Error from service (e.g. insufficient balance on re-approve) --}}
                @error('form.leave_type_id')
                    <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700 dark:bg-red-950/30 dark:border-red-800 dark:text-red-400">
                        {{ $message }}
                    </div>
                @enderror

                {{-- Manager comment --}}
                <div class="space-y-1.5">
                    <flux:textarea
                        wire:model="reviewer_comment"
                        label="Manager Comment"
                        placeholder="Add a comment (required to reject)…"
                        rows="2"
                    />
                    @error('reviewer_comment')
                        <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex gap-3 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                    <flux:button
                        wire:click="reject"
                        wire:loading.attr="disabled"
                        wire:target="reject"
                        variant="ghost"
                        class="flex-1 !text-red-600 hover:!bg-red-50 dark:hover:!bg-red-950/30"
                    >
                        <span wire:loading.remove wire:target="reject">Reject</span>
                        <span wire:loading wire:target="reject">Rejecting…</span>
                    </flux:button>
                    <flux:button
                        wire:click="approve"
                        wire:loading.attr="disabled"
                        wire:target="approve"
                        variant="primary"
                        class="flex-1"
                    >
                        <span wire:loading.remove wire:target="approve">Approve</span>
                        <span wire:loading wire:target="approve">Approving…</span>
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</flux:main>
