<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Team Attendance</h1>
            <p class="pulse-page-subtitle">Monitoring real-time presence of your direct reports</p>
        </div>
    </div>

    {{-- Currently In Section --}}
    <div class="space-y-4">
        <h3 class="text-sm font-bold text-zinc-400 uppercase tracking-wider">Present Now ({{ $currentlyIn->count() }})</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            @forelse($currentlyIn as $item)
                <div class="pulse-card flex items-center justify-between group">
                    <div class="flex items-center gap-3">
                        <div class="size-10 rounded-full bg-brand-600 flex items-center justify-center font-bold text-white text-sm">
                            {{ strtoupper(substr($item->employee->user->name, 0, 1)) }}
                        </div>
                        <div>
                            <div class="font-bold text-zinc-900 dark:text-white">{{ $item->employee->user->name }}</div>
                            <div class="text-xs text-zinc-500">In at {{ $item->check_in->format('H:i') }}</div>
                        </div>
                    </div>
                    <div class="size-2 rounded-full bg-green-500 animate-pulse"></div>
                </div>
            @empty
                <div class="col-span-full pulse-card py-8 text-center text-zinc-400">
                    No team members are currently clocked in.
                </div>
            @endforelse
        </div>
    </div>

    {{-- Pending Regularisation Requests --}}
    @if($pendingRegularisations->isNotEmpty())
        <div class="pulse-card border-brand-200 dark:border-brand-900/50 relative overflow-hidden">
            <div class="absolute top-0 left-0 w-1 h-full bg-brand-500"></div>
            <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-6">Pending Regularisation Requests</h3>
            <div class="overflow-x-auto -mx-6">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-zinc-800">
                            <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Employee</th>
                            <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Date</th>
                            <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Requested Time</th>
                            <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Reason</th>
                            <th class="pb-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                        @foreach($pendingRegularisations as $req)
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/20 transition-colors">
                                <td class="py-3 pl-6 pr-4 font-medium text-zinc-900 dark:text-white">
                                    {{ $req->employee->user->name }}
                                </td>
                                <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-300">
                                    {{ \Carbon\Carbon::parse($req->work_date)->format('M d, Y') }}
                                </td>
                                <td class="py-3 pr-4">
                                    {{ \Carbon\Carbon::parse($req->requested_check_in)->format('H:i') }} - {{ \Carbon\Carbon::parse($req->requested_check_out)->format('H:i') }}
                                </td>
                                <td class="py-3 pr-4 text-zinc-500 truncate max-w-xs" title="{{ $req->reason }}">
                                    {{ $req->reason }}
                                </td>
                                <td class="py-3 pr-6 text-right">
                                    <flux:button wire:click="openReviewModal({{ $req->id }})" size="xs" variant="primary">Review</flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Activity Feed --}}
    <div class="pulse-card">
        <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-6">Recent Activity</h3>
        
        <div class="overflow-x-auto -mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Employee</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Date</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Check In</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Check Out</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                        <th class="pb-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Total Hours</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($recentLogs as $log)
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/20 transition-colors">
                            <td class="py-4 pl-6 pr-4 font-medium text-zinc-900 dark:text-white">
                                {{ $log->employee->user->name }}
                            </td>
                            <td class="py-4 pr-4 text-zinc-600 dark:text-zinc-300">
                                {{ $log->date->format('M d, Y') }}
                            </td>
                            <td class="py-4 pr-4">
                                {{ $log->check_in->format('H:i') }}
                            </td>
                            <td class="py-4 pr-4">
                                {{ $log->check_out ? $log->check_out->format('H:i') : '--:--' }}
                            </td>
                            <td class="py-4 pr-4">
                                <span class="badge-{{ $log->status === 'on_time' ? $log->status : ($log->status === 'late' ? 'rejected' : 'manager') }}">
                                    {{ strtoupper($log->status) }}
                                </span>
                            </td>
                            <td class="py-4 pr-6 text-right font-bold text-zinc-900 dark:text-white">
                                {{ (float)$log->total_hours }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-zinc-400">No activity logs found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4 border-t border-zinc-50 pt-3 dark:border-zinc-800">
            {{ $recentLogs->links() }}
        </div>
    </div>

    {{-- Review Modal --}}
    @if($showReviewModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showReviewModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" wire:click="$set('showReviewModal', false)"></div>
            <div class="relative w-full max-w-md bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" wire:click="$set('showReviewModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Review Regularisation Request</flux:heading>
                <flux:subheading>Evaluate the attendance correction</flux:subheading>
            </div>

            @if($activeRequest)
                <div class="bg-zinc-50 p-4 rounded-xl dark:bg-zinc-900 text-sm space-y-3">
                    <div class="flex justify-between">
                        <span class="text-zinc-500">Employee</span>
                        <span class="font-bold">{{ $activeRequest->employee->user->name }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-zinc-500">Date</span>
                        <span class="font-bold">{{ \Carbon\Carbon::parse($activeRequest->work_date)->format('M d, Y') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-zinc-500">Requested Time</span>
                        <span class="font-bold text-brand-600">{{ \Carbon\Carbon::parse($activeRequest->requested_check_in)->format('H:i') }} - {{ \Carbon\Carbon::parse($activeRequest->requested_check_out)->format('H:i') }}</span>
                    </div>
                    <div>
                        <span class="text-zinc-500 block mb-1">Reason provided</span>
                        <p class="text-zinc-700 dark:text-zinc-300 italic">"{{ $activeRequest->reason }}"</p>
                    </div>
                </div>

                <flux:textarea wire:model="reviewComment" label="Manager Comment (Required for Rejection)" rows="2" />

                <div class="flex gap-2 justify-end pt-4">
                    <button type="button" wire:click="$set('showReviewModal\', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">Cancel</button>
                    <flux:button wire:click="rejectRegularisation" variant="ghost" class="text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/30">Reject</flux:button>
                    <flux:button wire:click="approveRegularisation" variant="primary">Approve</flux:button>
                </div>
            @endif
        </div>
    
            </div>
        </div>
    @endif
</flux:main>
