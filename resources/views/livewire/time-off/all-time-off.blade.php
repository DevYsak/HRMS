<flux:main class="bg-[#F7F8FA] min-h-screen dark:bg-zinc-950">

    {{-- ── Page Header ─────────────────────────────────────────────────────── --}}
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
                <h1 class="text-3xl font-black tracking-tight text-white">Employee Leave Master</h1>
                <p class="mt-1.5 text-sm font-medium text-violet-200/80">Track and audit all leave requests company-wide.</p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-3">
                <button type="button"
                    class="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-white/25 bg-white/15 px-4 py-2.5 text-sm font-bold text-white backdrop-blur-sm transition-all hover:bg-white/25">
                    <flux:icon.arrow-up-tray class="size-4 shrink-0" />
                    <span>Export</span>
                </button>
                <button type="button" wire:click="openNewModal"
                    class="inline-flex cursor-pointer items-center gap-2 rounded-xl bg-white px-5 py-2.5 text-sm font-black text-orange-700 shadow-lg shadow-black/20 transition-all hover:bg-violet-50">
                    <flux:icon.plus class="size-4 shrink-0" />
                    <span>New Leave Request</span>
                </button>
            </div>
        </div>
    </div>

    <div class="p-4 md:p-6 space-y-5">

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
                    <x-clean-select model="leave_type_id" :live="true"
                        :options="array_merge([['value' => '', 'label' => 'All Leave Types']], $leaveTypes->map(fn ($lt) => ['value' => $lt->id, 'label' => $lt->name])->all())" />

                    {{-- Status --}}
                    <x-clean-select model="status" :live="true"
                        :options="[['value' => '', 'label' => 'All Statuses'], ['value' => 'pending', 'label' => 'Pending'], ['value' => 'approved', 'label' => 'Approved'], ['value' => 'rejected', 'label' => 'Rejected'], ['value' => 'cancelled', 'label' => 'Cancelled']]" />

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
                            <x-clean-select model="perPage" :live="true"
                                :options="[['value' => '10', 'label' => '10'], ['value' => '25', 'label' => '25'], ['value' => '50', 'label' => '50']]" />
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
                        @php
                            $panelAvatarColors = ['bg-violet-500','bg-emerald-500','bg-amber-500','bg-rose-500','bg-sky-500','bg-indigo-500','bg-teal-500','bg-pink-500'];
                            $panelColor = $panelAvatarColors[$viewingRequest->employee->id % count($panelAvatarColors)];
                            $panelBalance = $viewingRequest->availableBalance ?? \App\Models\LeaveBalance::where('employee_id', $viewingRequest->employee_id)->where('leave_type_id', $viewingRequest->leave_type_id)->where('year', now()->year)->first();
                            $effectivePayStatus = $viewingRequest->approved_leave_status ?? $viewingRequest->requested_leave_status ?? ($viewingRequest->leaveType?->is_paid ? 'paid' : 'unpaid');
                        @endphp

                        {{-- Employee info --}}
                        <div class="flex items-center gap-3">
                            <div class="size-12 rounded-full {{ $panelColor }} flex items-center justify-center font-bold text-white text-lg shrink-0">
                                {{ strtoupper(substr($viewingRequest->employee->user->name, 0, 1)) }}
                            </div>
                            <div>
                                <div class="font-bold text-zinc-900 dark:text-white">{{ $viewingRequest->employee->user->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $viewingRequest->employee->department?->name ?? '—' }} Team</div>
                                <div class="text-xs text-zinc-400">Employee ID: {{ $viewingRequest->employee->employee_id ?? 'N/A' }}</div>
                            </div>
                        </div>

                        {{-- Request Details --}}
                        <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-4 space-y-3">
                            <div class="flex justify-between items-start">
                                <div>
                                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-wide">Leave Type</div>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <div class="size-2 rounded-full" style="background-color: {{ $viewingRequest->leaveType->color ?? '#6b7280' }}"></div>
                                        <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">{{ $viewingRequest->leaveType->name }}</span>
                                    </div>
                                </div>
                                {{-- Payment status badge --}}
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold
                                    {{ $effectivePayStatus === 'paid' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' }}">
                                    {{ ucfirst($effectivePayStatus) }}
                                    @if($viewingRequest->approved_leave_status && $viewingRequest->approved_leave_status !== $viewingRequest->requested_leave_status)
                                        <span class="opacity-60">(overridden)</span>
                                    @endif
                                </span>
                            </div>

                            {{-- Balance --}}
                            @if($panelBalance)
                                <div class="flex items-center gap-2 text-xs">
                                    <flux:icon.chart-bar class="size-3.5 text-zinc-400" />
                                    <span class="text-zinc-500">Available balance:</span>
                                    <span class="font-semibold {{ max(0, $panelBalance->allocated_days - $panelBalance->used_days - ($panelBalance->encashed_days ?? 0)) < $viewingRequest->days ? 'text-red-500' : 'text-emerald-600 dark:text-emerald-400' }}">
                                        {{ max(0, $panelBalance->allocated_days - $panelBalance->used_days - ($panelBalance->encashed_days ?? 0)) }} day(s)
                                    </span>
                                </div>
                            @endif

                            <div class="grid grid-cols-2 gap-3 text-xs">
                                <div>
                                    <div class="text-zinc-400 font-semibold uppercase tracking-wide mb-0.5">Dates</div>
                                    <div class="text-zinc-700 dark:text-zinc-300 font-semibold">{{ $viewingRequest->start_date->format('d M') }} – {{ $viewingRequest->end_date->format('d M Y') }}</div>
                                </div>
                                <div>
                                    <div class="text-zinc-400 font-semibold uppercase tracking-wide mb-0.5">Days</div>
                                    <div class="text-zinc-700 dark:text-zinc-300 font-semibold">
                                        {{ (float)$viewingRequest->days }}
                                        @if($viewingRequest->is_half_day) <span class="text-zinc-400">({{ $viewingRequest->half_day_period === 'first_half' ? 'First' : 'Second' }} Half)</span> @endif
                                    </div>
                                </div>
                                <div>
                                    <div class="text-zinc-400 font-semibold uppercase tracking-wide mb-0.5">Requested As</div>
                                    <div class="font-semibold {{ ($viewingRequest->requested_leave_status ?? 'paid') === 'paid' ? 'text-emerald-600' : 'text-amber-600' }}">{{ ucfirst($viewingRequest->requested_leave_status ?? 'paid') }}</div>
                                </div>
                                <div>
                                    <div class="text-zinc-400 font-semibold uppercase tracking-wide mb-0.5">Applied On</div>
                                    <div class="text-zinc-700 dark:text-zinc-300">{{ $viewingRequest->created_at->format('d M Y') }}</div>
                                </div>
                            </div>

                            @if($viewingRequest->reason)
                                <div>
                                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-wide mb-0.5">Reason</div>
                                    <div class="text-xs text-zinc-700 dark:text-zinc-300">{{ $viewingRequest->reason }}</div>
                                </div>
                            @endif

                            @if($viewingRequest->attachments->isNotEmpty())
                                <div>
                                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-wide mb-1">Documents</div>
                                    <div class="space-y-1">
                                        @foreach($viewingRequest->attachments as $att)
                                            <a href="{{ asset('storage/'.$att->path) }}" target="_blank" download
                                                class="flex items-center gap-1.5 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                                                <flux:icon :name="$att->icon()" class="size-3.5" />
                                                {{ $att->typeLabel() }} <span class="text-zinc-400">({{ $att->humanSize() }})</span>
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @elseif($viewingRequest->attachment_path)
                                <a href="{{ asset('storage/'.$viewingRequest->attachment_path) }}" target="_blank"
                                    class="flex items-center gap-1.5 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                                    <flux:icon.paper-clip class="size-3.5" />
                                    View Attachment
                                </a>
                            @endif
                        </div>

                        {{-- Conversation with the employee --}}
                        <div>
                            <h4 class="mb-3 text-xs font-bold uppercase tracking-wide text-zinc-500">Conversation</h4>
                            @php $meId = auth()->id(); @endphp
                            <div class="mb-3 space-y-2">
                                @forelse($viewingRequest->messages->sortBy('created_at') as $msg)
                                    @php $mine = $msg->user_id === $meId; @endphp
                                    <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                                        <div class="max-w-[85%] rounded-xl px-3 py-2 text-xs {{ $mine ? 'bg-indigo-600 text-white' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200' }}">
                                            <div class="mb-0.5 flex items-center gap-2 text-[9px] font-bold uppercase tracking-wide {{ $mine ? 'text-indigo-100' : 'text-zinc-400' }}">
                                                <span>{{ $mine ? 'You' : ($msg->user?->name ?? 'Employee') }}</span>
                                                <span class="font-medium normal-case">{{ $msg->created_at->format('d M, H:i') }}</span>
                                            </div>
                                            @if($msg->body)<p class="whitespace-pre-line leading-snug">{{ $msg->body }}</p>@endif
                                            @if($msg->attachment_path)
                                                <a href="{{ asset('storage/'.$msg->attachment_path) }}" target="_blank"
                                                    class="mt-1 inline-flex items-center gap-1 rounded-lg px-1.5 py-0.5 text-[10px] font-semibold {{ $mine ? 'bg-white/20 text-white hover:bg-white/30' : 'bg-white text-indigo-600 hover:bg-zinc-50 dark:bg-zinc-700 dark:text-indigo-300' }}">
                                                    <flux:icon.paper-clip class="size-2.5" /> {{ $msg->attachment_name ?? 'Attachment' }}
                                                </a>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-[11px] text-zinc-400">No messages yet. Send a note if you need clarification.</p>
                                @endforelse
                            </div>
                            <div class="space-y-2 rounded-xl border border-zinc-100 bg-zinc-50/60 p-2.5 dark:border-zinc-800 dark:bg-zinc-900/40">
                                <textarea wire:model="panelMessage" rows="2" placeholder="Message the employee…"
                                    class="w-full resize-none rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-700 placeholder-zinc-400 transition-colors focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-400/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"></textarea>
                                @error('panelMessage')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                                <div class="flex items-center justify-between gap-2">
                                    <div class="min-w-0 flex-1">
                                        <input type="file" wire:model="panelMessageAttachment" accept=".pdf,.jpg,.jpeg,.png"
                                            class="block w-full text-[11px] text-zinc-500 file:mr-2 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-2 file:py-1 file:text-[11px] file:font-bold file:text-indigo-600 hover:file:bg-indigo-100 dark:text-zinc-400">
                                        @error('panelMessageAttachment')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                                    </div>
                                    <button type="button" wire:click="postPanelMessage" wire:loading.attr="disabled" wire:target="postPanelMessage,panelMessageAttachment"
                                        class="shrink-0 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-indigo-700 disabled:opacity-60">Send</button>
                                </div>
                                <p class="text-[10px] text-zinc-400">PDF or image — max 500 KB. Sending on a pending request marks it “More Info Needed”.</p>
                            </div>
                        </div>

                        {{-- Approval History (timeline) --}}
                        <div>
                            <h4 class="text-xs font-bold text-zinc-500 uppercase tracking-wide mb-3">Approval Timeline</h4>
                            <div class="space-y-3">
                                <div class="flex items-start gap-3">
                                    <div class="size-2 rounded-full bg-indigo-500 mt-1.5 shrink-0"></div>
                                    <div>
                                        <div class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">Submitted</div>
                                        <div class="text-[11px] text-zinc-400">{{ $viewingRequest->created_at->format('d M Y, h:i A') }}</div>
                                    </div>
                                </div>
                                @if($viewingRequest->reviewer)
                                    <div class="flex items-start gap-3">
                                        <div class="size-2 rounded-full {{ in_array($viewingRequest->status, ['approved','pending_hr']) ? 'bg-emerald-500' : 'bg-red-500' }} mt-1.5 shrink-0"></div>
                                        <div>
                                            <div class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ $viewingRequest->status === 'pending_hr' ? 'Manager Approved → Pending HR' : ucfirst($viewingRequest->status) }}</div>
                                            <div class="text-[11px] text-zinc-400">by {{ $viewingRequest->reviewer->name }}</div>
                                            @if($viewingRequest->reviewer_comment)
                                                <div class="text-[11px] text-zinc-500 italic">"{{ $viewingRequest->reviewer_comment }}"</div>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                                @if($viewingRequest->hrReviewer)
                                    <div class="flex items-start gap-3">
                                        <div class="size-2 rounded-full bg-emerald-500 mt-1.5 shrink-0"></div>
                                        <div>
                                            <div class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">HR Approved</div>
                                            <div class="text-[11px] text-zinc-400">by {{ $viewingRequest->hrReviewer->name }} · {{ $viewingRequest->hr_reviewed_at?->format('d M Y, h:i A') }}</div>
                                            @if($viewingRequest->hr_reviewer_comment)
                                                <div class="text-[11px] text-zinc-500 italic">"{{ $viewingRequest->hr_reviewer_comment }}"</div>
                                            @endif
                                        </div>
                                    </div>
                                @endif

                                {{-- Payment Status Change History --}}
                                @foreach($viewingRequest->paymentAuditLogs as $log)
                                    <div class="flex items-start gap-3">
                                        <div class="size-2 rounded-full bg-orange-400 mt-1.5 shrink-0"></div>
                                        <div>
                                            <div class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                                                Payment: {{ ucfirst($log->from_status) }} → {{ ucfirst($log->to_status) }}
                                            </div>
                                            <div class="text-[11px] text-zinc-400">by {{ $log->changedByUser?->name }} · {{ $log->created_at->format('d M Y, h:i A') }}</div>
                                            <div class="text-[11px] text-zinc-500 italic">"{{ $log->reason }}"</div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Comment box + HR Override (for pending/pending_hr) --}}
                        @if(in_array($viewingRequest->status, ['pending', 'pending_hr']))
                            <div class="space-y-3">
                                <div class="space-y-1.5">
                                    <label class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Reviewer Comment</label>
                                    <textarea wire:model="panelReviewComment" rows="2"
                                        placeholder="Add a comment (required to reject)…"
                                        class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 px-3 py-2 text-sm text-zinc-700 dark:text-zinc-300 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-brand-400/30 focus:border-brand-400 resize-none transition-colors"></textarea>
                                    @error('panelReviewComment')
                                        <p class="text-xs text-red-500">{{ $message }}</p>
                                    @enderror
                                </div>

                                {{-- HR Override toggle --}}
                                @if($viewingRequest->leaveType?->allow_hr_override)
                                    <div class="rounded-xl border border-amber-200 dark:border-amber-800/40 overflow-hidden">
                                        <button type="button" wire:click="$toggle('panelShowHrOverride')"
                                            class="w-full flex items-center justify-between px-3 py-2 bg-amber-50 dark:bg-amber-950/20 text-xs font-semibold text-amber-700 dark:text-amber-400">
                                            <span class="flex items-center gap-1.5"><flux:icon.shield-check class="size-3.5" /> HR Payment Override</span>
                                            <flux:icon.chevron-down class="size-3.5 transition-transform {{ $panelShowHrOverride ? 'rotate-180' : '' }}" />
                                        </button>
                                        @if($panelShowHrOverride)
                                            <div class="px-3 pb-3 pt-2 space-y-2 bg-amber-50/50 dark:bg-amber-950/10">
                                                <div class="flex gap-3">
                                                    <label class="flex items-center gap-1.5 cursor-pointer text-xs text-zinc-700 dark:text-zinc-300">
                                                        <input type="radio" wire:model="panelHrOverrideStatus" value="paid" class="accent-emerald-600" />
                                                        <span class="font-semibold text-emerald-700 dark:text-emerald-400">Approve as Paid</span>
                                                    </label>
                                                    <label class="flex items-center gap-1.5 cursor-pointer text-xs text-zinc-700 dark:text-zinc-300">
                                                        <input type="radio" wire:model="panelHrOverrideStatus" value="unpaid" class="accent-amber-600" />
                                                        <span class="font-semibold text-amber-700 dark:text-amber-400">Approve as Unpaid</span>
                                                    </label>
                                                </div>
                                                <textarea wire:model="panelHrRemark" rows="2"
                                                    placeholder="HR remark — mandatory when overriding payment status…"
                                                    class="w-full rounded-lg border border-amber-200 dark:border-amber-800 bg-white dark:bg-zinc-800 px-2.5 py-1.5 text-xs text-zinc-700 dark:text-zinc-300 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-amber-300 resize-none"></textarea>
                                                @error('panelHrRemark')
                                                    <p class="text-xs text-red-500">{{ $message }}</p>
                                                @enderror
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endif

                        {{-- HR Override on already-approved requests --}}
                        @if($viewingRequest->status === 'approved' && $viewingRequest->leaveType?->allow_hr_override)
                            <div class="rounded-xl border border-amber-200 dark:border-amber-800/40 overflow-hidden">
                                <button type="button" wire:click="$toggle('panelShowHrOverride')"
                                    class="w-full flex items-center justify-between px-3 py-2 bg-amber-50 dark:bg-amber-950/20 text-xs font-semibold text-amber-700 dark:text-amber-400">
                                    <span class="flex items-center gap-1.5"><flux:icon.shield-check class="size-3.5" /> HR Payment Override</span>
                                    <flux:icon.chevron-down class="size-3.5 transition-transform {{ $panelShowHrOverride ? 'rotate-180' : '' }}" />
                                </button>
                                @if($panelShowHrOverride)
                                    <div class="px-3 pb-3 pt-2 space-y-2 bg-amber-50/50 dark:bg-amber-950/10">
                                        <p class="text-xs text-amber-700 dark:text-amber-400">Current: <strong>{{ ucfirst($effectivePayStatus) }}</strong>. Override to:</p>
                                        <div class="flex gap-3">
                                            <label class="flex items-center gap-1.5 cursor-pointer text-xs">
                                                <input type="radio" wire:model="panelHrOverrideStatus" value="paid" class="accent-emerald-600" />
                                                <span class="font-semibold text-emerald-700 dark:text-emerald-400">Paid</span>
                                            </label>
                                            <label class="flex items-center gap-1.5 cursor-pointer text-xs">
                                                <input type="radio" wire:model="panelHrOverrideStatus" value="unpaid" class="accent-amber-600" />
                                                <span class="font-semibold text-amber-700 dark:text-amber-400">Unpaid</span>
                                            </label>
                                        </div>
                                        <textarea wire:model="panelHrRemark" rows="2"
                                            placeholder="HR remark — mandatory (min 10 characters)…"
                                            class="w-full rounded-lg border border-amber-200 dark:border-amber-800 bg-white dark:bg-zinc-800 px-2.5 py-1.5 text-xs text-zinc-700 dark:text-zinc-300 placeholder-zinc-400 focus:outline-none resize-none"></textarea>
                                        @error('panelHrRemark')
                                            <p class="text-xs text-red-500">{{ $message }}</p>
                                        @enderror
                                        <flux:button wire:click="applyHrOverride" variant="filled" size="xs" icon="check">Apply Override</flux:button>
                                    </div>
                                @endif
                            </div>
                        @endif

                    </div>

                    {{-- Panel Footer --}}
                    @if(in_array($viewingRequest->status, ['pending', 'pending_hr']))
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
                            <button type="button" wire:click="requestMoreInfo"
                                wire:loading.attr="disabled" wire:target="requestMoreInfo"
                                title="Ask the employee for more information"
                                class="inline-flex items-center justify-center gap-2 px-3 py-2.5 text-sm font-bold text-orange-600 bg-orange-50 hover:bg-orange-100 dark:bg-orange-900/20 dark:text-orange-400 rounded-xl transition-colors disabled:opacity-60">
                                <flux:icon.question-mark-circle class="size-4" />
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
                    <x-clean-select model="form.status" label="Request Status" :live="false"
                        :options="[['value' => 'pending', 'label' => 'Pending'], ['value' => 'approved', 'label' => 'Approved'], ['value' => 'rejected', 'label' => 'Rejected'], ['value' => 'cancelled', 'label' => 'Cancelled']]" />

                    <x-clean-select model="form.leave_type_id" label="Leave Type" :live="false"
                        :options="$leaveTypes->map(fn ($type) => ['value' => $type->id, 'label' => $type->name.' ('.($type->is_paid ? 'Paid' : 'Unpaid').')'])->all()" />

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
                        <x-clean-select model="newForm.employee_id" label="Employee" placeholder="Select employee…" :live="false"
                            :options="$allEmployees->map(fn ($emp) => ['value' => $emp->id, 'label' => $emp->user->name.' ('.($emp->employee_id ?? $emp->id).')'])->all()" />
                        @error('newForm.employee_id') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                    <x-clean-select model="newForm.leave_type_id" label="Leave Type" placeholder="Select type…" :live="false"
                        :options="$leaveTypes->map(fn ($lt) => ['value' => $lt->id, 'label' => $lt->name])->all()" />

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
