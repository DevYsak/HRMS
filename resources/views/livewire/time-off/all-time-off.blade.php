<flux:main class="bg-[#F7F8FA] min-h-screen dark:bg-zinc-950">

    {{-- ── Page Header ─────────────────────────────────────────────────────── --}}
    <div class="bg-white dark:bg-zinc-900 border-b border-zinc-200/70 dark:border-zinc-800 px-8 py-5">
        <div class="flex items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-1.5 text-[11px] text-zinc-400 mb-1 font-medium">
                    <span>Time Off</span>
                    <flux:icon.chevron-right class="size-3" />
                    <span class="text-zinc-600 dark:text-zinc-300">All Leave</span>
                </div>
                <h1 class="text-2xl font-black text-zinc-900 dark:text-white tracking-tight">Employee Leave Master</h1>
                <p class="text-sm text-zinc-500 mt-0.5">Track and audit all leave requests company-wide</p>
            </div>
            <div class="flex items-center gap-2.5 shrink-0">
                <button type="button"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">
                    <flux:icon.arrow-up-tray class="size-4" />
                    Export
                </button>
                <button type="button" wire:click="openNewModal"
                    class="inline-flex items-center gap-2 px-5 py-2 text-sm font-bold text-white bg-brand-600 hover:bg-brand-700 rounded-xl transition-colors shadow-sm shadow-brand-200 dark:shadow-none">
                    <flux:icon.plus class="size-4" />
                    New Leave Request
                </button>
            </div>
        </div>
    </div>

    <div class="p-8 space-y-6">

        {{-- ── KPI Cards ──────────────────────────────────────────────────── --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">

            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
                <div class="size-12 rounded-xl bg-brand-50 dark:bg-brand-900/20 flex items-center justify-center shrink-0">
                    <flux:icon.calendar-days class="size-6 text-brand-600" />
                </div>
                <div>
                    <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider mb-0.5">Total Leave Requests</div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white leading-none">{{ $kpi['total'] }}</div>
                    <div class="text-xs text-brand-600 font-semibold mt-1">This Month</div>
                </div>
            </div>

            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
                <div class="size-12 rounded-xl bg-brand-50 dark:bg-brand-900/20 flex items-center justify-center shrink-0">
                    <svg class="size-6 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider mb-0.5">Pending Approval</div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white leading-none">{{ $kpi['pending'] }}</div>
                    <div class="text-xs text-brand-600 font-semibold mt-1">This Month</div>
                </div>
            </div>

            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
                <div class="size-12 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center shrink-0">
                    <flux:icon.check-circle class="size-6 text-emerald-500" />
                </div>
                <div>
                    <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider mb-0.5">Approved</div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white leading-none">{{ $kpi['approved'] }}</div>
                    <div class="text-xs text-emerald-500 font-semibold mt-1">This Month</div>
                </div>
            </div>

            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
                <div class="size-12 rounded-xl bg-red-50 dark:bg-red-900/20 flex items-center justify-center shrink-0">
                    <flux:icon.x-circle class="size-6 text-red-500" />
                </div>
                <div>
                    <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider mb-0.5">Rejected</div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white leading-none">{{ $kpi['rejected'] }}</div>
                    <div class="text-xs text-red-500 font-semibold mt-1">This Month</div>
                </div>
            </div>

        </div>

        {{-- ── Main Content: Table + Detail Panel ─────────────────────────── --}}
        <div class="flex gap-6 items-start">

            {{-- Table Card --}}
            <div class="flex-1 min-w-0 bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-sm overflow-hidden">

                {{-- Filter bar --}}
                <div class="px-6 py-4 border-b border-zinc-100 dark:border-zinc-800 flex flex-wrap items-center gap-3">

                    {{-- Search --}}
                    <div class="relative flex-1 min-w-[200px]">
                        <flux:icon.magnifying-glass class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-zinc-400" />
                        <input
                            wire:model.live.debounce.300ms="search"
                            type="text"
                            placeholder="Search employee..."
                            class="w-full h-9 pl-9 pr-3 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-sm text-zinc-800 dark:text-zinc-200 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-brand-400/30 focus:border-brand-400 transition-colors"
                        />
                    </div>

                    {{-- Leave Type --}}
                    <select wire:model.live="leave_type_id"
                        class="h-9 px-3 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-sm text-zinc-600 dark:text-zinc-300 focus:outline-none focus:ring-2 focus:ring-brand-400/30 focus:border-brand-400 transition-colors">
                        <option value="">All Leave Types</option>
                        @foreach($leaveTypes as $lt)
                            <option value="{{ $lt->id }}">{{ $lt->name }}</option>
                        @endforeach
                    </select>

                    {{-- Status --}}
                    <select wire:model.live="status"
                        class="h-9 px-3 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-sm text-zinc-600 dark:text-zinc-300 focus:outline-none focus:ring-2 focus:ring-brand-400/30 focus:border-brand-400 transition-colors">
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="cancelled">Cancelled</option>
                    </select>

                    {{-- Date Range --}}
                    <div class="flex items-center gap-1.5 h-9 px-3 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-sm">
                        <flux:icon.calendar class="size-4 text-zinc-400 shrink-0" />
                        <input type="date" wire:model.live="dateFrom"
                            class="bg-transparent text-xs text-zinc-600 dark:text-zinc-300 border-0 outline-none focus:ring-0 p-0 w-28" />
                        <span class="text-zinc-300">–</span>
                        <input type="date" wire:model.live="dateTo"
                            class="bg-transparent text-xs text-zinc-600 dark:text-zinc-300 border-0 outline-none focus:ring-0 p-0 w-28" />
                    </div>

                    {{-- Filters label --}}
                    <div class="flex items-center gap-1.5 h-9 px-3 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm font-semibold text-zinc-600 dark:text-zinc-300">
                        <flux:icon.funnel class="size-4" />
                        Filters
                    </div>

                </div>

                {{-- Table --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-zinc-50/70 dark:bg-zinc-950/40 border-b border-zinc-100 dark:border-zinc-800">
                                <th class="py-3 pl-6 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Employee</th>
                                <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Leave Type</th>
                                <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Dates</th>
                                <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Days</th>
                                <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Status</th>
                                <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Applied On</th>
                                <th class="py-3 pr-6 text-right text-[10px] font-bold uppercase tracking-widest text-zinc-400">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                            @forelse($requests as $req)
                                @php
                                    $isViewing = $viewingId === $req->id;
                                    $avatarColors = ['bg-violet-500','bg-emerald-500','bg-amber-500','bg-rose-500','bg-sky-500','bg-indigo-500','bg-teal-500','bg-pink-500'];
                                    $avatarColor = $avatarColors[$req->employee->id % count($avatarColors)];
                                    [$statusClass, $statusLabel] = match($req->status) {
                                        'approved'  => ['bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-400 dark:border-emerald-800/40', 'Approved'],
                                        'rejected'  => ['bg-red-50 text-red-700 border-red-200 dark:bg-red-950/30 dark:text-red-400 dark:border-red-800/40', 'Rejected'],
                                        'cancelled' => ['bg-zinc-100 text-zinc-500 border-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:border-zinc-700', 'Cancelled'],
                                        default     => ['bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/30 dark:text-amber-400 dark:border-amber-800/40', 'Pending'],
                                    };
                                @endphp
                                <tr wire:key="row-{{ $req->id }}"
                                    class="transition-colors cursor-pointer {{ $isViewing ? 'bg-brand-50/50 dark:bg-brand-900/10' : 'hover:bg-zinc-50/70 dark:hover:bg-zinc-800/30' }}"
                                    wire:click="viewRequest({{ $req->id }})">
                                    <td class="py-3.5 pl-6 pr-4">
                                        <div class="flex items-center gap-3">
                                            <div class="size-8 rounded-full {{ $avatarColor }} flex items-center justify-center font-bold text-white text-xs shrink-0">
                                                {{ strtoupper(substr($req->employee->user->name, 0, 1)) }}
                                            </div>
                                            <div>
                                                <div class="font-semibold text-zinc-900 dark:text-white text-sm">{{ $req->employee->user->name }}</div>
                                                <div class="text-[11px] text-zinc-400">{{ $req->employee->department?->name ?? '—' }} Team</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3.5 pr-4">
                                        <div class="flex items-center gap-2">
                                            <div class="size-2 rounded-full shrink-0" style="background-color: {{ $req->leaveType->color ?? '#6b7280' }}"></div>
                                            <span class="text-zinc-700 dark:text-zinc-300">{{ $req->leaveType->name }}</span>
                                        </div>
                                    </td>
                                    <td class="py-3.5 pr-4 text-sm text-zinc-600 dark:text-zinc-300">
                                        {{ $req->start_date->format('M d') }} – {{ $req->end_date->format('M d, Y') }}
                                    </td>
                                    <td class="py-3.5 pr-4 font-bold text-zinc-800 dark:text-zinc-200">{{ (float)$req->days }}</td>
                                    <td class="py-3.5 pr-4">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg border text-[10px] font-bold {{ $statusClass }}">
                                            {{ strtoupper($statusLabel) }}
                                        </span>
                                    </td>
                                    <td class="py-3.5 pr-4">
                                        <div class="text-sm text-zinc-700 dark:text-zinc-300">{{ $req->created_at->format('M d, Y') }}</div>
                                        <div class="text-[11px] text-zinc-400">{{ $req->created_at->format('h:i A') }}</div>
                                    </td>
                                    <td class="py-3.5 pr-6 text-right" wire:click.stop>
                                        <div class="flex items-center justify-end gap-1">
                                            <button type="button" wire:click="viewRequest({{ $req->id }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-zinc-600 dark:text-zinc-300 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">
                                                <flux:icon.eye class="size-3.5" />
                                                View
                                            </button>
                                            <button type="button" wire:click="manageRequest({{ $req->id }})"
                                                class="p-1.5 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-700 rounded-lg transition-colors">
                                                <flux:icon.ellipsis-vertical class="size-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-16 text-center">
                                        <div class="size-12 rounded-2xl bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center mx-auto mb-3">
                                            <flux:icon.calendar-days class="size-6 text-zinc-400" />
                                        </div>
                                        <p class="text-sm font-bold text-zinc-600 dark:text-zinc-300">No leave requests found</p>
                                        <p class="text-xs text-zinc-400 mt-1">Try adjusting your filters</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Table footer --}}
                <div class="px-6 py-3 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between gap-4">
                    <div class="text-xs text-zinc-500">
                        Showing {{ $requests->firstItem() ?? 0 }} to {{ $requests->lastItem() ?? 0 }} of {{ $requests->total() }} results
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-zinc-400">Per page</span>
                            <select wire:model.live="perPage"
                                class="text-xs font-semibold text-zinc-700 dark:text-zinc-300 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-brand-400/30">
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                            </select>
                        </div>
                        {{ $requests->links('vendor.pagination.simple-tailwind') }}
                    </div>
                </div>
            </div>

            {{-- ── Detail Side Panel ──────────────────────────────────────── --}}
            @if($showDetailPanel && $viewingRequest)
                <div class="w-96 shrink-0 bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-sm overflow-hidden flex flex-col"
                     style="max-height: calc(100vh - 12rem);">

                    {{-- Panel Header --}}
                    <div class="px-5 py-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center justify-between shrink-0">
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Leave Request Details</h3>
                        <button type="button" wire:click="closeDetailPanel"
                            class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors p-1 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800">
                            <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    {{-- Scrollable content --}}
                    <div class="flex-1 overflow-y-auto p-5 space-y-5">

                        {{-- Employee info --}}
                        <div class="flex items-center gap-3">
                            @php
                                $panelAvatarColors = ['bg-violet-500','bg-emerald-500','bg-amber-500','bg-rose-500','bg-sky-500','bg-indigo-500','bg-teal-500','bg-pink-500'];
                                $panelColor = $panelAvatarColors[$viewingRequest->employee->id % count($panelAvatarColors)];
                            @endphp
                            <div class="size-12 rounded-full {{ $panelColor }} flex items-center justify-center font-bold text-white text-lg shrink-0">
                                {{ strtoupper(substr($viewingRequest->employee->user->name, 0, 1)) }}
                            </div>
                            <div>
                                <div class="font-bold text-zinc-900 dark:text-white">{{ $viewingRequest->employee->user->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $viewingRequest->employee->department?->name ?? '—' }} Team</div>
                                <div class="text-xs text-zinc-400">Employee ID: {{ $viewingRequest->employee->employee_id ?? 'N/A' }}</div>
                            </div>
                        </div>

                        {{-- Details --}}
                        <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-4 space-y-3">
                            <div class="flex items-center gap-3">
                                <flux:icon.tag class="size-4 text-zinc-400 shrink-0" />
                                <div class="flex-1">
                                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-wide">Leave Type</div>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <div class="size-2 rounded-full" style="background-color: {{ $viewingRequest->leaveType->color ?? '#6b7280' }}"></div>
                                        <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">{{ $viewingRequest->leaveType->name }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <flux:icon.calendar class="size-4 text-zinc-400 shrink-0" />
                                <div class="flex-1">
                                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-wide">Dates</div>
                                    <div class="text-sm font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">
                                        {{ $viewingRequest->start_date->format('M d') }} – {{ $viewingRequest->end_date->format('M d, Y') }}
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <flux:icon.clock class="size-4 text-zinc-400 shrink-0" />
                                <div class="flex-1">
                                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-wide">Total Days</div>
                                    <div class="text-sm font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">
                                        {{ (float)$viewingRequest->days }} {{ (float)$viewingRequest->days == 1 ? 'Day' : 'Days' }}
                                        @if($viewingRequest->is_half_day) <span class="text-xs text-zinc-400">(Half Day)</span> @endif
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <flux:icon.calendar-days class="size-4 text-zinc-400 shrink-0" />
                                <div class="flex-1">
                                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-wide">Applied On</div>
                                    <div class="text-sm font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">
                                        {{ $viewingRequest->created_at->format('M d, Y') }} ({{ $viewingRequest->created_at->format('h:i A') }})
                                    </div>
                                </div>
                            </div>
                            @if($viewingRequest->reason)
                                <div class="flex items-start gap-3">
                                    <flux:icon.document-text class="size-4 text-zinc-400 shrink-0 mt-0.5" />
                                    <div class="flex-1">
                                        <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-wide">Reason</div>
                                        <div class="text-sm text-zinc-700 dark:text-zinc-300 mt-0.5">{{ $viewingRequest->reason }}</div>
                                    </div>
                                </div>
                            @endif
                        </div>

                        {{-- Approval History --}}
                        <div>
                            <h4 class="text-sm font-bold text-zinc-900 dark:text-white mb-3">Approval History</h4>
                            <div class="space-y-3">
                                {{-- Request submitted --}}
                                <div class="flex items-start gap-3">
                                    <div class="size-2 rounded-full bg-brand-500 mt-1.5 shrink-0"></div>
                                    <div>
                                        <div class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">Pending</div>
                                        <div class="text-[11px] text-zinc-400">{{ $viewingRequest->created_at->format('M d, Y (h:i A)') }}</div>
                                        <div class="text-[11px] text-zinc-500 mt-0.5">Requested by {{ $viewingRequest->employee->user->name }}</div>
                                    </div>
                                </div>
                                {{-- Review decision --}}
                                @if($viewingRequest->reviewer)
                                    <div class="flex items-start gap-3">
                                        <div class="size-2 rounded-full {{ $viewingRequest->status === 'approved' ? 'bg-emerald-500' : 'bg-red-500' }} mt-1.5 shrink-0"></div>
                                        <div>
                                            <div class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ ucfirst($viewingRequest->status) }}</div>
                                            <div class="text-[11px] text-zinc-400">{{ $viewingRequest->updated_at->format('M d, Y (h:i A)') }}</div>
                                            <div class="text-[11px] text-zinc-500 mt-0.5">Reviewed by {{ $viewingRequest->reviewer->name }}</div>
                                            @if($viewingRequest->reviewer_comment)
                                                <div class="text-[11px] text-zinc-500 italic mt-0.5">"{{ $viewingRequest->reviewer_comment }}"</div>
                                            @endif
                                        </div>
                                    </div>
                                @else
                                    <div class="flex items-start gap-3">
                                        <div class="size-2 rounded-full bg-zinc-300 dark:bg-zinc-600 mt-1.5 shrink-0"></div>
                                        <div>
                                            <div class="text-xs font-semibold text-zinc-500">Pending</div>
                                            <div class="text-[11px] text-zinc-400 mt-0.5">Waiting for approval</div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Comment box (for approve/reject) --}}
                        @if(in_array($viewingRequest->status, ['pending']))
                            <div class="space-y-1.5">
                                <label class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Reviewer Comment</label>
                                <textarea
                                    wire:model="panelReviewComment"
                                    rows="2"
                                    placeholder="Add a comment (required to reject)…"
                                    class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 px-3 py-2 text-sm text-zinc-700 dark:text-zinc-300 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-brand-400/30 focus:border-brand-400 resize-none transition-colors"
                                ></textarea>
                                @error('panelReviewComment')
                                    <p class="text-xs text-red-500">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

                    </div>

                    {{-- Panel Footer: Approve / Reject --}}
                    @if(in_array($viewingRequest->status, ['pending']))
                        <div class="px-5 py-4 border-t border-zinc-100 dark:border-zinc-800 flex gap-3 shrink-0">
                            <button type="button" wire:click="quickApprove"
                                wire:loading.attr="disabled" wire:target="quickApprove"
                                class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-xl transition-colors disabled:opacity-60">
                                <flux:icon.check-circle class="size-4" />
                                <span wire:loading.remove wire:target="quickApprove">Approve</span>
                                <span wire:loading wire:target="quickApprove">Approving…</span>
                            </button>
                            <button type="button" wire:click="quickReject"
                                wire:loading.attr="disabled" wire:target="quickReject"
                                class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold text-white bg-red-500 hover:bg-red-600 rounded-xl transition-colors disabled:opacity-60">
                                <flux:icon.x-circle class="size-4" />
                                <span wire:loading.remove wire:target="quickReject">Reject</span>
                                <span wire:loading wire:target="quickReject">Rejecting…</span>
                            </button>
                        </div>
                    @else
                        <div class="px-5 py-4 border-t border-zinc-100 dark:border-zinc-800 shrink-0">
                            <button type="button" wire:click="manageRequest({{ $viewingRequest->id }})"
                                class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold text-white bg-brand-600 hover:bg-brand-700 rounded-xl transition-colors">
                                <flux:icon.pencil-square class="size-4" />
                                Edit Request
                            </button>
                        </div>
                    @endif
                </div>
            @endif

        </div>
    </div>

    {{-- ── Full Manage Modal (div-based) ──────────────────────────────────── --}}
    @if($showManageModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.closeManageModal()">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" wire:click="closeManageModal"></div>
            <div class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 space-y-5">

                <button type="button" wire:click="closeManageModal"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>

                <div>
                    <h2 class="text-lg font-bold text-zinc-900 dark:text-white">Manage Leave Request</h2>
                    <p class="text-sm text-zinc-500 mt-0.5">Update request details and sync balances.</p>
                </div>

                @if($superAdminLocked)
                    <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950/30">
                        <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-500" />
                        <div>
                            <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Approved by Super Admin ({{ $lockedByName }})</p>
                            <p class="text-xs text-amber-700 dark:text-amber-400 mt-0.5">A mandatory comment is required to override this approval.</p>
                        </div>
                    </div>
                @endif

                @error('form.leave_type_id')
                    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/30 dark:text-red-400">{{ $message }}</div>
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
                            placeholder="{{ $superAdminLocked ? 'Explain why you are overriding…' : 'Optional comment…' }}"
                        />
                        @error('form.reviewer_comment')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                        <button type="button" wire:click="closeManageModal"
                            class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 bg-white dark:bg-zinc-700 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 transition-colors">
                            Cancel
                        </button>
                        <flux:button type="submit" variant="primary">Update Request</flux:button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ── New Leave Request Modal (div-based) ────────────────────────────── --}}
    @if($showNewModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.closeNewModal()">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" wire:click="closeNewModal"></div>
            <div class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 space-y-5">

                <button type="button" wire:click="closeNewModal"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>

                <div>
                    <h2 class="text-lg font-bold text-zinc-900 dark:text-white">New Leave Request</h2>
                    <p class="text-sm text-zinc-500 mt-0.5">Submit a leave request on behalf of an employee.</p>
                </div>

                @error('newForm.leave_type_id')
                    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/30 dark:text-red-400">{{ $message }}</div>
                @enderror

                <form wire:submit="submitNewRequest" class="space-y-4">
                    <div>
                        <flux:select wire:model="newForm.employee_id" label="Employee">
                            <option value="">Select employee…</option>
                            @foreach($allEmployees as $emp)
                                <option value="{{ $emp->id }}">{{ $emp->user->name }} ({{ $emp->employee_id ?? $emp->id }})</option>
                            @endforeach
                        </flux:select>
                        @error('newForm.employee_id') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                    <flux:select wire:model="newForm.leave_type_id" label="Leave Type">
                        <option value="">Select type…</option>
                        @foreach($leaveTypes as $lt)
                            <option value="{{ $lt->id }}">{{ $lt->name }}</option>
                        @endforeach
                    </flux:select>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="newForm.start_date" type="date" label="Start Date" required />
                        <flux:input wire:model="newForm.end_date" type="date" label="End Date" required />
                    </div>

                    <flux:textarea wire:model="newForm.reason" label="Reason" rows="2" placeholder="Reason for leave…" required />

                    <div class="flex items-center gap-3 py-1">
                        <flux:checkbox wire:model="newForm.is_half_day" label="Half Day" />
                    </div>

                    <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                        <button type="button" wire:click="closeNewModal"
                            class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 bg-white dark:bg-zinc-700 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 transition-colors">
                            Cancel
                        </button>
                        <flux:button type="submit" variant="primary">Submit Request</flux:button>
                    </div>
                </form>
            </div>
        </div>
    @endif

</flux:main>
