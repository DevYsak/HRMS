<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Employee Attendance Master</h1>
            <p class="pulse-page-subtitle">View and audit attendance across the organization</p>
        </div>
        <div class="flex gap-3">
             <flux:input wire:model.live="search" placeholder="Search employee..." icon="magnifying-glass" size="sm" class="w-64" />
             <flux:input wire:model.live="date" type="date" size="sm" class="w-48" />
             <flux:select wire:model.live="status" placeholder="Filter by status" size="sm" class="w-40">
                <option value="">All Status</option>
                <option value="on_time">On Time</option>
                <option value="late">Late</option>
                <option value="remote">Remote</option>
                <option value="absent">Absent</option>
             </flux:select>
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

    <div class="pulse-card">
        <div class="overflow-x-auto -mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Employee</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Date</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Office</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Clock In</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Clock Out</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                        <th class="pb-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Hours</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($attendances as $log)
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/20 transition-colors">
                            <td class="py-4 pl-6 pr-4">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $log->employee->user->name }}</div>
                                <div class="text-[10px] text-zinc-500">ID: {{ $log->employee->employee_id }}</div>
                            </td>
                            <td class="py-4 pr-4 text-zinc-600 dark:text-zinc-300">
                                {{ $log->date->format('M d, Y') }}
                            </td>
                            <td class="py-4 pr-4 text-zinc-500 truncate max-w-[120px]">
                                {{ $log->employee->office->name ?? 'N/A' }}
                            </td>
                            <td class="py-4 pr-4">
                                <div>{{ $log->check_in->format('H:i') }}</div>
                                @if($log->is_verified)
                                    <div class="text-[10px] text-green-500 flex items-center gap-1">
                                        <flux:icon.check-circle class="size-2.5" /> Verified
                                    </div>
                                @else
                                    <div class="text-[10px] text-zinc-400">Unverified</div>
                                @endif
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
                            <td colspan="7" class="py-12 text-center text-zinc-400">No attendance records found matching filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4 border-t border-zinc-50 pt-3 dark:border-zinc-800">
            {{ $attendances->links() }}
        </div>
    </div>

    {{-- Review Modal --}}
    <flux:modal wire:model="showReviewModal" class="w-full max-w-md">
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

                <flux:textarea wire:model="reviewComment" label="HR Comment (Required for Rejection)" rows="2" />

                <div class="flex gap-2 justify-end pt-4">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button wire:click="rejectRegularisation" variant="danger">Reject</flux:button>
                    <flux:button wire:click="approveRegularisation" variant="primary">Approve</flux:button>
                </div>
            @endif
        </div>
    </flux:modal>
</flux:main>
