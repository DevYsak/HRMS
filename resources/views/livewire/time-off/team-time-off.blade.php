<flux:main class="bg-[#F7F8FA] min-h-screen dark:bg-zinc-950">

    {{-- ── Page Header ────────────────────────────────────────────────────── --}}
    <div class="pulse-hero shadow-xl">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_bottom_left,_rgba(249,115,22,0.22),_transparent_65%)]"></div>
        <div class="pointer-events-none absolute -bottom-10 -left-10 size-64 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(249,115,22,0.30),transparent 70%)"></div>
        <div class="pointer-events-none absolute top-0 right-0 size-48 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(249,115,22,0.08),transparent 70%)"></div>

        <div class="relative flex flex-col sm:flex-row sm:items-center justify-between gap-6">
            <div>
                <div class="mb-3 inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3 py-1 backdrop-blur-sm">
                    <div class="size-1.5 animate-pulse rounded-full bg-orange-200"></div>
                    <span class="text-[11px] font-bold uppercase tracking-[0.18em] text-orange-100">Leave Management</span>
                </div>
                <h1 class="text-3xl font-black tracking-tight text-white">Team Time Off</h1>
                <p class="mt-1.5 text-sm font-medium text-violet-200/80">Review and manage leave requests for your direct reports.</p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-3">
                <a href="{{ route('time-off.my') }}" wire:navigate
                    class="inline-flex cursor-pointer items-center gap-2 rounded-xl bg-white px-5 py-2.5 text-sm font-black text-orange-700 shadow-lg shadow-black/20 transition-all hover:bg-violet-50">
                    <flux:icon.plus class="size-4 shrink-0" />
                    <span>New Leave Request</span>
                </a>
            </div>
        </div>
    </div>

    <div class="p-4 md:p-6 space-y-5">

        {{-- ── KPI Cards ──────────────────────────────────────────────────── --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">

            {{-- Pending Action --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
                <div class="size-12 rounded-xl bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center shrink-0">
                    <flux:icon.clock class="size-6 text-amber-500" />
                </div>
                <div>
                    <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider mb-0.5">Pending Action</div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white leading-none">{{ $pendingRequests->count() }}</div>
                    <div class="text-xs text-amber-500 font-semibold mt-1">
                        @if($pendingRequests->count() > 0)
                            Requests need review
                        @else
                            All caught up!
                        @endif
                    </div>
                </div>
            </div>

            {{-- Approved This Month --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
                <div class="size-12 rounded-xl bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center shrink-0">
                    <flux:icon.check-circle class="size-6 text-emerald-500" />
                </div>
                <div>
                    <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider mb-0.5">Approved</div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white leading-none">{{ $approvedThisMonth }}</div>
                    <div class="text-xs text-emerald-500 font-semibold mt-1">This Month</div>
                </div>
            </div>

            {{-- Total Days Approved --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
                <div class="size-12 rounded-xl bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center shrink-0">
                    <flux:icon.calendar-days class="size-6 text-violet-500" />
                </div>
                <div>
                    <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider mb-0.5">Total Days Approved</div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white leading-none">{{ (float)$totalDaysThisMonth }}</div>
                    <div class="text-xs text-violet-500 font-semibold mt-1">This Month</div>
                </div>
            </div>

            {{-- Team Members --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
                <div class="size-12 rounded-xl bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center shrink-0">
                    <flux:icon.users class="size-6 text-violet-500" />
                </div>
                <div>
                    <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider mb-0.5">Team Members</div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white leading-none">{{ $teamMembersCount }}</div>
                    <div class="text-xs text-zinc-400 font-semibold mt-1">Under your team</div>
                </div>
            </div>

        </div>

        {{-- ── Team Leave Calendar ── --}}
        <div class="pulse-card mb-6">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Team Leave Calendar</h3>
                <div class="flex items-center gap-1.5">
                    <button type="button" wire:click="prevCalMonth" class="rounded-lg border border-zinc-200 px-2 py-1 text-xs text-zinc-500 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">&larr;</button>
                    <span class="min-w-28 text-center text-xs font-bold text-zinc-700 dark:text-zinc-200">{{ $calendarLabel }}</span>
                    <button type="button" wire:click="nextCalMonth" class="rounded-lg border border-zinc-200 px-2 py-1 text-xs text-zinc-500 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">&rarr;</button>
                </div>
            </div>
            <div class="mb-1.5 grid grid-cols-7 gap-1.5 text-center text-[10px] font-bold uppercase tracking-wide text-zinc-400">
                @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dow)<div>{{ $dow }}</div>@endforeach
            </div>
            <div class="grid grid-cols-7 gap-1.5">
                @foreach($calendarDays as $day)
                    <div class="min-h-16 rounded-lg border p-1.5 text-left
                        {{ $day['inMonth'] ? 'border-zinc-100 dark:border-zinc-800' : 'border-transparent opacity-40' }}
                        {{ $day['isToday'] ? 'ring-2 ring-brand-500' : '' }}
                        {{ $day['isWeekend'] ? 'bg-zinc-50/60 dark:bg-zinc-800/30' : '' }}">
                        <div class="text-[10px] font-semibold text-zinc-400">{{ $day['date']->day }}</div>
                        <div class="mt-1 space-y-0.5">
                            @foreach($day['leaves']->take(3) as $lv)
                                <div class="truncate rounded px-1 py-0.5 text-[9px] font-bold text-white" style="background: {{ $lv['color'] }}" title="{{ $lv['name'] }} · {{ $lv['type'] }}">{{ $lv['name'] }}</div>
                            @endforeach
                            @if($day['leaves']->count() > 3)
                                <div class="text-[9px] text-zinc-400">+{{ $day['leaves']->count() - 3 }} more</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ── Two-Column: Pending Requests + Quick Filters ────────────────── --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            {{-- Pending Requests (Left 2/3) --}}
            <div class="lg:col-span-2 bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center justify-between">
                    <h3 class="text-base font-bold text-zinc-900 dark:text-white">
                        Pending Action
                        <span class="ml-2 inline-flex items-center justify-center size-5 rounded-full bg-amber-100 text-amber-600 text-[10px] font-black">
                            {{ $pendingRequests->count() }}
                        </span>
                    </h3>
                </div>

                @if($pendingRequests->count() > 0)
                    <div class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                        @foreach($pendingRequests as $req)
                            <div class="p-6 hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 transition-colors">
                                <div class="flex items-start gap-4">
                                    {{-- Avatar --}}
                                    <div class="size-10 rounded-full bg-amber-500 flex items-center justify-center font-bold text-white text-sm shrink-0">
                                        {{ strtoupper(substr($req->employee->user->name, 0, 1)) }}
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap mb-3">
                                            <span class="font-bold text-zinc-900 dark:text-white text-sm">{{ $req->employee->user->name }}</span>
                                            <span class="text-sm text-zinc-500">{{ $req->leaveType->name }}</span>
                                            <span class="px-2 py-0.5 rounded-md bg-amber-50 text-amber-600 border border-amber-200 text-[10px] font-bold uppercase tracking-wide dark:bg-amber-950/30 dark:border-amber-800/40">
                                                PENDING
                                            </span>
                                        </div>

                                        {{-- Info grid --}}
                                        <div class="grid grid-cols-3 gap-3 mb-3">
                                            <div class="bg-zinc-50 dark:bg-zinc-800/50 rounded-lg p-2.5">
                                                <div class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wide mb-1">Requested Period</div>
                                                <div class="text-xs font-bold text-zinc-800 dark:text-zinc-200">
                                                    {{ $req->start_date->format('M d') }} – {{ $req->end_date->format('M d, Y') }}
                                                </div>
                                            </div>
                                            <div class="bg-zinc-50 dark:bg-zinc-800/50 rounded-lg p-2.5">
                                                <div class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wide mb-1">Total Days</div>
                                                <div class="text-xs font-bold text-zinc-800 dark:text-zinc-200">{{ (float)$req->days }} {{ $req->is_half_day ? 'Half Day' : 'Days' }}</div>
                                            </div>
                                            <div class="bg-zinc-50 dark:bg-zinc-800/50 rounded-lg p-2.5">
                                                <div class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wide mb-1">Applied On</div>
                                                <div class="text-xs font-bold text-zinc-800 dark:text-zinc-200">{{ $req->created_at->format('M d, Y') }}</div>
                                            </div>
                                        </div>

                                        @if($req->reason)
                                            <p class="text-xs text-zinc-500 italic mb-4 line-clamp-1">"{{ $req->reason }}"</p>
                                        @endif

                                        <div class="flex gap-2">
                                            <button
                                                type="button"
                                                wire:click="selectRequest({{ $req->id }})"
                                                class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors"
                                            >
                                                <flux:icon.eye class="size-4" />
                                                View Details
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="selectRequest({{ $req->id }})"
                                                class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-bold text-white bg-brand-600 hover:bg-brand-700 rounded-xl transition-colors shadow-sm shadow-brand-200 dark:shadow-none"
                                            >
                                                <flux:icon.check-circle class="size-4" />
                                                Review Request
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-14 flex flex-col items-center justify-center text-center">
                        <div class="size-14 rounded-2xl bg-emerald-50 dark:bg-emerald-950/30 flex items-center justify-center mx-auto mb-3">
                            <flux:icon.check-circle class="size-7 text-emerald-500" />
                        </div>
                        <h4 class="font-bold text-zinc-900 dark:text-white mb-1">All caught up!</h4>
                        <p class="text-sm text-zinc-500 max-w-xs">No pending leave requests from your team right now.</p>
                    </div>
                @endif
            </div>

            {{-- Quick Filters (Right 1/3) --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-sm p-6">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white mb-5">Quick Filters</h3>

                <div class="space-y-4">
                    {{-- Date Range --}}
                    <div class="space-y-2">
                        <label class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Date Range</label>
                        <div class="flex items-center gap-2 p-3 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl">
                            <flux:icon.calendar class="size-4 text-zinc-400 shrink-0" />
                            <input
                                type="date"
                                wire:model="filterFrom"
                                class="flex-1 bg-transparent text-xs font-semibold text-zinc-700 dark:text-zinc-300 border-0 outline-none focus:ring-0 p-0"
                            />
                            <span class="text-zinc-400 text-xs">–</span>
                            <input
                                type="date"
                                wire:model="filterTo"
                                class="flex-1 bg-transparent text-xs font-semibold text-zinc-700 dark:text-zinc-300 border-0 outline-none focus:ring-0 p-0"
                            />
                        </div>
                    </div>

                    {{-- Leave Type --}}
                    <div class="space-y-2">
                        <label class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Leave Type</label>
                        <select
                            wire:model="filterLeaveType"
                            class="w-full px-3 py-2.5 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-700 dark:text-zinc-300 font-medium focus:outline-none focus:ring-2 focus:ring-brand-400/30 focus:border-brand-400 transition-colors"
                        >
                            <option value="">All Leave Types</option>
                            @foreach($leaveTypes as $lt)
                                <option value="{{ $lt->id }}">{{ $lt->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Status --}}
                    <div class="space-y-2">
                        <label class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Status</label>
                        <select
                            wire:model="filterStatus"
                            class="w-full px-3 py-2.5 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm text-zinc-700 dark:text-zinc-300 font-medium focus:outline-none focus:ring-2 focus:ring-brand-400/30 focus:border-brand-400 transition-colors"
                        >
                            <option value="">All Status</option>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>

                    {{-- Buttons --}}
                    <div class="flex gap-2 pt-2">
                        <button
                            type="button"
                            wire:click="resetFilters"
                            class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors"
                        >
                            <flux:icon.arrow-path class="size-3.5" />
                            Reset
                        </button>
                        <button
                            type="button"
                            wire:click="applyFilters"
                            class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-bold text-white bg-brand-600 hover:bg-brand-700 rounded-xl transition-colors shadow-sm shadow-brand-200 dark:shadow-none"
                        >
                            <flux:icon.funnel class="size-3.5" />
                            Apply Filters
                        </button>
                    </div>
                </div>
            </div>

        </div>

        {{-- ── Recent Team Activity Table ───────────────────────────────────── --}}
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-zinc-100 dark:border-zinc-800">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Recent Team Activity</h3>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-zinc-50/70 dark:bg-zinc-950/40 border-b border-zinc-100 dark:border-zinc-800">
                            <th class="py-3 pl-6 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Employee</th>
                            <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Leave Type</th>
                            <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Duration</th>
                            <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Status</th>
                            <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Applied On</th>
                            <th class="py-3 pr-6 text-right text-[10px] font-bold uppercase tracking-widest text-zinc-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                        @forelse($history as $item)
                            @php
                                [$statusClass, $statusLabel] = match($item->status) {
                                    'approved' => ['bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-400 dark:border-emerald-800/40', 'Approved'],
                                    'rejected' => ['bg-red-50 text-red-700 border-red-200 dark:bg-red-950/30 dark:text-red-400 dark:border-red-800/40', 'Rejected'],
                                    'pending'  => ['bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/30 dark:text-amber-400 dark:border-amber-800/40', 'Pending'],
                                    default    => ['bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:border-zinc-700', ucfirst($item->status)],
                                };
                                $avatarColor = match(strtolower(substr($item->employee->user->name, 0, 1))) {
                                    'a','b','c' => 'bg-violet-500',
                                    'd','e','f' => 'bg-blue-500',
                                    'g','h','i' => 'bg-emerald-500',
                                    'j','k','l' => 'bg-amber-500',
                                    'm','n','o' => 'bg-rose-500',
                                    default     => 'bg-zinc-500',
                                };
                            @endphp
                            <tr class="hover:bg-violet-50/20 dark:hover:bg-violet-950/10 transition-colors group">
                                <td class="py-3.5 pl-6 pr-4">
                                    <div class="flex items-center gap-3">
                                        <div class="size-8 rounded-full {{ $avatarColor }} flex items-center justify-center font-bold text-white text-xs shrink-0">
                                            {{ strtoupper(substr($item->employee->user->name, 0, 1)) }}
                                        </div>
                                        <div>
                                            <div class="font-semibold text-zinc-900 dark:text-white text-sm">{{ $item->employee->user->name }}</div>
                                            <div class="text-[11px] text-zinc-400">{{ $item->employee->department?->name ?? '—' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3.5 pr-4 text-zinc-600 dark:text-zinc-300">{{ $item->leaveType->name }}</td>
                                <td class="py-3.5 pr-4">
                                    <div class="text-zinc-800 dark:text-zinc-200 font-medium text-sm">
                                        {{ $item->start_date->format('M d') }} – {{ $item->end_date->format('M d, Y') }}
                                    </div>
                                    <div class="text-[11px] text-zinc-400">{{ (float)$item->days }} {{ (float)$item->days == 1 ? 'Day' : 'Days' }}</div>
                                </td>
                                <td class="py-3.5 pr-4">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg border text-[10px] font-bold {{ $statusClass }}">
                                        {{ strtoupper($statusLabel) }}
                                    </span>
                                </td>
                                <td class="py-3.5 pr-4">
                                    <div class="text-sm text-zinc-700 dark:text-zinc-300">{{ $item->created_at->format('M d, Y') }}</div>
                                    <div class="text-[11px] text-zinc-400">{{ $item->created_at->format('h:i A') }}</div>
                                </td>
                                <td class="py-3.5 pr-6 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <button
                                            type="button"
                                            wire:click="selectRequest({{ $item->id }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-zinc-600 dark:text-zinc-300 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors"
                                        >
                                            <flux:icon.eye class="size-3.5" />
                                            View
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="selectRequest({{ $item->id }})"
                                            class="p-1.5 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-700 rounded-lg transition-colors"
                                            title="More options"
                                        >
                                            <flux:icon.ellipsis-vertical class="size-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-14 text-center">
                                    <div class="size-12 rounded-2xl bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center mx-auto mb-3">
                                        <flux:icon.calendar-days class="size-6 text-zinc-400" />
                                    </div>
                                    <p class="text-sm font-bold text-zinc-600 dark:text-zinc-300">No leave activity found</p>
                                    <p class="text-xs text-zinc-400 mt-1">Try adjusting your filters to see more results</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Table Footer --}}
            <div class="px-6 py-3 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between gap-4">
                <div class="text-xs text-zinc-500">
                    Showing {{ $history->firstItem() ?? 0 }} to {{ $history->lastItem() ?? 0 }} of {{ $history->total() }} results
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-zinc-400">Per page</span>
                        <select
                            wire:model.live="perPage"
                            class="text-xs font-semibold text-zinc-700 dark:text-zinc-300 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-brand-400/30"
                        >
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-1">
                        {{ $history->links('vendor.pagination.simple-tailwind') }}
                    </div>
                </div>
            </div>
        </div>

    </div>

    {{-- ── Review Modal (div-based — no <dialog> to avoid backdrop issues) ── --}}
    @if($showReviewModal && $selectedRequest)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
            x-data
            x-on:keydown.escape.window="$wire.closeReviewModal()"
        >
            {{-- Backdrop --}}
            <div
                class="absolute inset-0 bg-black/40 backdrop-blur-sm"
                wire:click="closeReviewModal"
            ></div>

            {{-- Panel --}}
            <div class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 space-y-5">

                {{-- Close X --}}
                <button
                    type="button"
                    wire:click="closeReviewModal"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors"
                    aria-label="Close"
                >
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                <div>
                    <h2 class="text-lg font-bold text-zinc-900 dark:text-white">Review Leave Request</h2>
                    <p class="text-sm text-zinc-500 mt-0.5">From {{ $selectedRequest->employee->user->name }}</p>
                </div>

                {{-- Request summary --}}
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

                {{-- Error --}}
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
                    <button type="button" wire:click="closeReviewModal"
                        class="flex-1 px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 bg-white dark:bg-zinc-700 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-600 transition-colors">
                        Cancel
                    </button>
                    <button type="button" wire:click="reject"
                        wire:loading.attr="disabled" wire:target="reject"
                        class="flex-1 px-4 py-2 text-sm font-semibold text-red-600 bg-red-50 border border-red-200 rounded-xl hover:bg-red-100 transition-colors disabled:opacity-60 dark:bg-red-950/30 dark:border-red-800 dark:text-red-400">
                        <span wire:loading.remove wire:target="reject">Reject</span>
                        <span wire:loading wire:target="reject">Rejecting…</span>
                    </button>
                    <button type="button" wire:click="approve"
                        wire:loading.attr="disabled" wire:target="approve"
                        class="flex-1 px-4 py-2 text-sm font-bold text-white bg-brand-600 hover:bg-brand-700 rounded-xl transition-colors shadow-sm shadow-brand-200 dark:shadow-none disabled:opacity-60">
                        <span wire:loading.remove wire:target="approve">Approve</span>
                        <span wire:loading wire:target="approve">Approving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</flux:main>
