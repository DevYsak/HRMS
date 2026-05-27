<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Employee Leave Master</h1>
            <p class="pulse-page-subtitle">Track and audit all leave requests company-wide</p>
        </div>
    </div>

    <div class="pulse-card">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div class="flex flex-wrap items-center gap-2">
                {{-- Search --}}
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search employee..." class="h-9 rounded-lg border border-zinc-200 bg-white pl-9 pr-3 text-sm text-zinc-800 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-brand-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100" />
                </div>
                
                {{-- Filters --}}
                <select wire:model.live="status" class="h-9 rounded-lg border border-zinc-200 bg-white px-3 text-sm text-zinc-600 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="cancelled">Cancelled</option>
                </select>

                <select wire:model.live="leave_type_id" class="h-9 rounded-lg border border-zinc-200 bg-white px-3 text-sm text-zinc-600 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                    <option value="">All Types</option>
                    @foreach($leaveTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="overflow-x-auto -mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Employee</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Leave Type</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Dates</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Days</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                        <th class="pb-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($requests as $req)
                        <tr class="group hover:bg-zinc-50/70 dark:hover:bg-zinc-800/30 transition-colors">
                            <td class="py-4 pl-6 pr-4 font-medium text-zinc-900 dark:text-white">
                                {{ $req->employee->user->name }}
                            </td>
                            <td class="py-4 pr-4">
                                <div class="flex items-center gap-2">
                                    <div class="size-2 rounded-full" style="background-color: {{ $req->leaveType->color }}"></div>
                                    <span class="text-zinc-600 dark:text-zinc-300">{{ $req->leaveType->name }}</span>
                                </div>
                            </td>
                            <td class="py-4 pr-4 text-xs text-zinc-500">
                                {{ $req->start_date->format('M d') }} - {{ $req->end_date->format('M d, Y') }}
                            </td>
                            <td class="py-4 pr-4 font-semibold text-zinc-700 dark:text-zinc-300">
                                {{ (float)$req->days }}
                            </td>
                            <td class="py-4 pr-4">
                                <span class="badge-{{ $req->status }}">{{ strtoupper($req->status) }}</span>
                            </td>
                            <td class="py-4 pr-6 text-right">
                                <flux:button wire:click="manageRequest({{ $req->id }})" variant="ghost" size="sm" icon="adjustments-horizontal" class="text-zinc-400 hover:text-brand-600" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-20 text-center text-zinc-400 italic">No leave requests found matching your filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 border-t border-zinc-50 pt-3 dark:border-zinc-800">
            {{ $requests->links() }}
        </div>
    </div>
    {{-- Management Modal --}}
    <flux:modal wire:model.self="showManageModal" class="w-full max-w-lg">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Manage Leave Request</flux:heading>
                <flux:subheading>Update request details and sync balances.</flux:subheading>
            </div>

            {{-- Super Admin lock warning --}}
            @if($superAdminLocked)
                <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950/30">
                    <svg class="mt-0.5 size-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <div>
                        <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Approved by Super Admin ({{ $lockedByName }})</p>
                        <p class="text-xs text-amber-700 dark:text-amber-400 mt-0.5">To reject or cancel this leave, a mandatory comment is required explaining the reason for override.</p>
                    </div>
                </div>
            @endif

            @error('form.leave_type_id')
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/30 dark:text-red-400">
                    {{ $message }}
                </div>
            @enderror

            <form wire:submit="saveManage" class="space-y-4">
                <flux:select wire:model="form.status" label="Request Status">
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="cancelled">Cancelled</option>
                </flux:select>

                <flux:select wire:model="form.leave_type_id" label="Leave Type">
                    @foreach($leaveTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }} ({{ $type->is_paid ? 'Paid' : 'Unpaid' }})</option>
                    @endforeach
                </flux:select>

                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="form.start_date" type="date" label="Start Date" required />
                    <flux:input wire:model="form.end_date" type="date" label="End Date" required />
                </div>

                <flux:textarea wire:model="form.reason" label="Reason" rows="2" />

                <div>
                    <flux:textarea
                        wire:model="form.reviewer_comment"
                        label="Reviewer Comment{{ $superAdminLocked ? ' (Required for override)' : '' }}"
                        rows="2"
                        placeholder="{{ $superAdminLocked ? 'Explain why you are overriding the Super Admin approvalâ€¦' : 'Optional commentâ€¦' }}"
                    />
                    @error('form.reviewer_comment')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">Update Request</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</flux:main>
