<flux:main class="min-h-screen bg-zinc-50 p-4 sm:p-6"
    x-data="{
        view: 'balances',
        tab: 'requests',
        {{-- One way to move between tabs: keeps the embedded balances component on the same panel. --}}
        go(view, sub = null, scroll = false) {
            this.view = view;
            if (sub) { this.tab = sub; }
            if (view === 'balances' || view === 'requests') { Livewire.dispatch('leave-panel', { panel: view }); }
            if (view === 'history' && sub === 'ledger') { Livewire.dispatch('leave-panel', { panel: 'history' }); }
            if (view === 'history' && sub === 'statement') { Livewire.dispatch('leave-panel', { panel: 'statement' }); }
            if (scroll) { this.$nextTick(() => document.querySelector('[data-leave-tabs]')?.scrollIntoView({ behavior: 'smooth', block: 'start' })); }
        },
    }"
    x-on:show-pending-upcoming.window="view = 'requests'"
    x-on:leave-view.window="view = $event.detail.view; if ($event.detail.sub) tab = $event.detail.sub">
<div class="mx-auto w-full space-y-6" style="max-width: 1440px" data-my-leave>

    {{-- ═══ A. HEADER ═══ greeting, policy metadata and the four summary cards --}}
    @php
        $fmt = fn ($n) => (float) $n == (int) $n ? (int) $n : number_format((float) $n, 1);
        $daysLabel = fn ($n) => $fmt($n).' '.Str::plural('Day', (float) $n == 1 ? 1 : 2);
        // Available paid leave: Earned Leave under V2; under the Conexus policy
        // CSL + Comp Off approved available (EmployeeLeaveOverviewService).
        $totalAvailable = $v2 ? (float) $v2['earned']['available'] : (float) $overview['available_leave'];
        $nextMdl = $overview['next_mdl'];
        $nextHoliday = $upcomingHolidays->first();
        $hour = (int) now()->format('G');
        $greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
        $firstName = \Illuminate\Support\Str::of(auth()->user()->name ?? 'there')->trim()->explode(' ')->first();
        $policyYear = $v2['year'] ?? $overview['year'];
        $policyName = $v2['policy'] ?? ($overview['policy']['name'] ?? 'Leave policy');
        $showMdl = $nextMdl && (! $nextHoliday || $nextMdl->lt($nextHoliday->date));
        $summary = [
            ['label' => 'Available Paid Leave', 'value' => $daysLabel($totalAvailable), 'sub' => $v2 ? 'Earned Leave' : 'Leave + Comp Off', 'icon' => 'calendar-days', 'tone' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'class' => $totalAvailable < 0 ? 'text-rose-600 dark:text-rose-400' : null, 'go' => "go('balances', null, true)"],
            ['label' => 'Pending Requests', 'value' => ($pendingCount ?? 0).' '.Str::plural('Request', (int) ($pendingCount ?? 0)), 'sub' => 'Awaiting approval', 'icon' => 'clock', 'tone' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400', 'class' => null, 'go' => "go('requests', null, true)"],
            ['label' => 'Approved / Taken This Year', 'value' => $daysLabel($approvedThisYearDays), 'sub' => $policyYear->label, 'icon' => 'check-badge', 'tone' => 'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400', 'class' => null, 'go' => "go('history', 'requests', true)"],
            ['label' => 'Next Holiday', 'value' => $showMdl ? 'Company shutdown' : ($nextHoliday?->name ?? 'None scheduled'), 'sub' => $showMdl ? $nextMdl->format('d M Y (D)') : ($nextHoliday?->date->format('d M Y (D)') ?? 'No upcoming holidays'), 'icon' => 'flag', 'tone' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400', 'class' => null, 'go' => "go('calendar', null, true)"],
        ];
    @endphp
    <section class="relative overflow-hidden rounded-2xl border border-orange-100 bg-gradient-to-br from-orange-50 via-amber-50/60 to-white p-5 shadow-sm sm:p-6 dark:border-zinc-800 dark:from-zinc-900 dark:via-zinc-900 dark:to-zinc-900" data-my-leave-header>
        {{-- Decorative only. --}}
        <div class="pointer-events-none absolute -right-16 -top-16 size-56 rounded-full bg-orange-200/40 blur-3xl dark:bg-orange-900/10" aria-hidden="true"></div>
        <svg class="pointer-events-none absolute right-10 top-14 hidden h-24 w-40 text-orange-200 xl:block dark:text-zinc-700" viewBox="0 0 160 96" fill="none" aria-hidden="true">
            <rect x="52" y="14" width="64" height="58" rx="10" fill="currentColor" opacity=".35" />
            <rect x="52" y="14" width="64" height="16" rx="8" fill="currentColor" opacity=".6" />
            <circle cx="70" cy="48" r="4" fill="currentColor" /><circle cx="84" cy="48" r="4" fill="currentColor" /><circle cx="98" cy="48" r="4" fill="currentColor" />
            <circle cx="70" cy="60" r="4" fill="currentColor" /><circle cx="84" cy="60" r="4" fill="#10b981" opacity=".7" />
            <path d="M24 80c4-22 14-34 22-40-2 14-8 28-22 40Z" fill="#10b981" opacity=".35" />
            <path d="M138 82c-6-16-16-26-24-30 4 12 12 24 24 30Z" fill="#10b981" opacity=".3" />
        </svg>

        <div class="relative flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="pulse-page-title">{{ $greeting }}, {{ $firstName }}</h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Manage your leave balance, requests and upcoming time off.</p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if($encashableTypes->isNotEmpty())
                    <flux:button wire:click="openEncashModal" icon="banknotes">Encash Leave</flux:button>
                @endif
                <flux:button wire:click="openRequestModal" variant="primary" icon="plus" data-request-leave-button>Request Leave</flux:button>
            </div>
        </div>

        {{-- Compact policy line: what applies to me, for which year, and why. --}}
        <div class="relative mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm" data-policy-line>
            <span class="text-zinc-500 dark:text-zinc-400">Policy: <span class="font-semibold text-zinc-900 dark:text-white">{{ $policyName }}</span></span>
            <span class="text-zinc-500 dark:text-zinc-400">Leave year: <span class="font-semibold text-zinc-900 dark:text-white">{{ $policyYear->starts_on->format('d M Y') }} – {{ $policyYear->ends_on->format('d M Y') }}</span></span>
            @if($v2)
                <span class="flex flex-wrap gap-2">
                    <x-pulse.badge color="brand">{{ $v2['policy_source'] }}</x-pulse.badge>
                    <x-pulse.badge color="emerald">Current policy</x-pulse.badge>
                </span>
            @endif
        </div>

        {{-- B. Summary: four equal cards, each opening its tab. --}}
        <div class="relative mt-5 grid grid-cols-1 items-stretch gap-4 sm:grid-cols-2 lg:grid-cols-4" data-summary-cards>
            @foreach($summary as $kpi)
                <button type="button" x-on:click="{{ $kpi['go'] }}"
                    class="ui-surface group flex items-center gap-3 p-4 text-left transition hover:-translate-y-0.5 hover:shadow-md">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl {{ $kpi['tone'] }}"><flux:icon :name="$kpi['icon']" class="size-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="line-clamp-2 block break-words text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $kpi['label'] }}</span>
                        <span class="line-clamp-2 block break-words font-bold leading-snug tabular-nums {{ $loop->last ? 'text-base' : 'text-xl' }} {{ $kpi['class'] ?? 'text-zinc-900 dark:text-white' }}">{{ $kpi['value'] }}</span>
                        <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $kpi['sub'] }}</span>
                    </span>
                    <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-300 transition group-hover:translate-x-0.5 group-hover:text-orange-500 dark:text-zinc-600" />
                </button>
            @endforeach
        </div>
    </section>

    {{-- ═══ C. IMPORTANT ALERTS ═══ never buried --}}
    @if((! empty($pendingCount) && $pendingCount > 0) || $totalAvailable < 0)
        <section class="space-y-2" data-leave-alerts>
            @if(! empty($pendingCount) && $pendingCount > 0)
                <div x-data="{ shown: true }" x-show="shown" class="flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center dark:border-amber-500/30 dark:bg-amber-500/10" data-pending-alert>
                    <x-pulse.badge color="amber">Pending</x-pulse.badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">{{ $pendingCount }} leave {{ Str::plural('request', $pendingCount) }} {{ $pendingCount == 1 ? 'is' : 'are' }} awaiting approval</p>
                        <p class="text-xs text-amber-800 dark:text-amber-300">Your application is currently under review. You will be notified once actioned.</p>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <flux:button size="sm" x-on:click="go('requests', null, true)">View Request</flux:button>
                        <flux:button size="sm" variant="primary" wire:click="openRequestModal">Request Another Leave</flux:button>
                        <button type="button" x-on:click="shown = false" class="flex size-8 items-center justify-center rounded-lg text-amber-700 hover:bg-amber-100 dark:text-amber-300 dark:hover:bg-white/5" aria-label="Dismiss"><flux:icon.x-mark class="size-4" /></button>
                    </div>
                </div>
            @endif
            @if($totalAvailable < 0)
                <div class="flex items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm dark:border-rose-500/30 dark:bg-rose-500/10" data-balance-alert>
                    <x-pulse.badge color="rose">Balance</x-pulse.badge>
                    <p class="text-rose-800 dark:text-rose-200">Your available paid leave is below zero. Further leave may be unpaid — check with HR.</p>
                </div>
            @endif
        </section>
    @endif

    {{-- ═══ D. TABS ═══ My Balances · Pending & Upcoming · Leave History · Calendar --}}
    <section class="space-y-4" data-leave-tabs>
        <nav class="flex max-w-full gap-1 overflow-x-auto border-b border-zinc-200 dark:border-white/10" role="tablist" aria-label="My leave">
            @foreach(['balances' => 'My Balances', 'requests' => 'Pending & Upcoming', 'history' => 'Leave History', 'calendar' => 'Calendar'] as $key => $label)
                <button type="button" role="tab" data-leave-tab="{{ $key }}"
                    x-on:click="go('{{ $key }}', {{ $key === 'history' ? "'requests'" : 'null' }})"
                    x-bind:aria-selected="view === '{{ $key }}'"
                    x-bind:class="view === '{{ $key }}' ? 'border-orange-500 text-orange-600 dark:text-orange-400' : 'border-transparent text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                    class="-mb-px shrink-0 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-semibold transition">{{ $label }}</button>
            @endforeach
        </nav>

        {{-- Leave History: applications, encashments, balance movements and statements. --}}
        <div x-show="view === 'history'" x-cloak class="flex max-w-full gap-1 overflow-x-auto" data-history-subtabs>
            @foreach(['requests' => 'Applications', 'encashments' => 'Encashments', 'ledger' => 'Balance movements', 'statement' => 'Month-wise statement'] as $key => $label)
                <button type="button" x-on:click="go('history', '{{ $key }}')"
                    x-bind:class="tab === '{{ $key }}' ? 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300' : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:hover:bg-white/5 dark:hover:text-zinc-200'"
                    class="shrink-0 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-semibold transition">{{ $label }}</button>
            @endforeach
        </div>

        {{-- Balances / pending & upcoming / balance movements / statement (the ledger component). --}}
        <div x-show="view === 'balances' || view === 'requests' || (view === 'history' && (tab === 'ledger' || tab === 'statement'))" data-tab-panel="balances">
            <livewire:time-off.my-leave-balances :embedded="true" />
        </div>

        {{-- Leave applications and encashments --}}
        <div id="recent-requests" x-show="view === 'history' && (tab === 'requests' || tab === 'encashments')" x-cloak class="ui-surface scroll-mt-6 overflow-hidden" data-tab-panel="history">
        {{-- Leave Requests Tab --}}
        <div x-show="tab === 'requests'" x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-1">
            {{-- Filters --}}
            <div
                class="flex flex-wrap items-center gap-3 px-6 py-4 border-b border-zinc-100 dark:border-zinc-800/50 bg-white dark:bg-zinc-900/60">
                <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass"
                    placeholder="Search by reason or leave type..." class="w-full sm:w-64 text-sm" />
                <flux:select wire:model.live="filterStatus" class="w-40 text-sm" placeholder="All Statuses">
                    <flux:select.option value="">All Statuses</flux:select.option>
                    <flux:select.option value="pending">Pending</flux:select.option>
                    <flux:select.option value="approved">Approved</flux:select.option>
                    <flux:select.option value="rejected">Rejected</flux:select.option>
                    <flux:select.option value="cancelled">Cancelled</flux:select.option>
                </flux:select>
                <flux:select wire:model.live="filterTypeId" class="w-44 text-sm" placeholder="All Types">
                    <flux:select.option value="">All Leave Types</flux:select.option>
                    @foreach($leaveTypes as $lt)
                        <flux:select.option value="{{ $lt->id }}">{{ $lt->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="filterYear" class="w-28 text-sm">
                    @foreach(range(now()->year, now()->year - 3) as $yr)
                        <flux:select.option value="{{ $yr }}">{{ $yr }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if($filterStatus || $filterTypeId || $search)
                    <button wire:click="$set('filterStatus', ''); $set('filterTypeId', ''); $set('search', '')"
                        class="text-xs text-zinc-400 hover:text-zinc-600 dark:text-zinc-300 dark:hover:text-zinc-300 underline">Clear</button>
                @endif
            </div>
            @php
                $sortIcon = fn(string $field) => $sortField !== $field
                    ? 'chevron-up-down'
                    : ($sortDirection === 'asc' ? 'chevron-up' : 'chevron-down');
                // Presentation only: every row keeps its stored type, days and status.
                $histCategory = fn ($r) => $r->isCombined() ? 'Combined Leave' : ($r->main_category ? \App\Services\Leave\LeaveCategory::label($r->main_category) : ($r->leaveType?->name ?? 'Leave'));
                $histReason = fn ($r) => ($r->isCombined() || $r->main_category) ? ($r->leaveType?->name ?? '—') : '—';
                // Legacy: a retired / historical type, or a pre-V2 request seen under a V2 policy.
                $histLegacy = fn ($r) => ($r->leaveType && ($r->leaveType->trashed() || ($r->leaveType->availability ?? 'active') !== 'active'))
                    || ($v2 && ! $r->main_category && ! $r->isCombined());
                $histUnpaid = fn ($r) => ($r->leaveType && ! $r->leaveType->is_paid) ? (float) $r->days : $r->unpaidUnits();
                $histPaid = fn ($r) => round(max(0.0, (float) $r->days - $histUnpaid($r)), 2);
                $histApprover = fn ($r) => $r->hrReviewer ?? $r->reviewer;
            @endphp
            <div class="hidden overflow-x-auto sm:block" data-history-table>
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-zinc-50 bg-zinc-50/50 dark:border-zinc-800/50 dark:bg-zinc-950/50">
                            <th class="py-3 pl-4 pr-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Request ID</th>
                            <th class="px-3 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Leave Category</th>
                            <th class="px-3 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Reason / Type</th>
                            <th class="px-3 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                <button wire:click="sortBy('start_date')"
                                    class="inline-flex cursor-pointer items-center gap-1 transition-colors hover:text-zinc-600 dark:text-zinc-300 dark:hover:text-zinc-300">
                                    Date Range
                                    <flux:icon :name="$sortIcon('start_date')" class="size-3" />
                                </button>
                            </th>
                            <th
                                class="px-3 py-3 text-center text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                <button wire:click="sortBy('days')"
                                    class="inline-flex cursor-pointer items-center gap-1 transition-colors hover:text-zinc-600 dark:text-zinc-300 dark:hover:text-zinc-300">
                                    Days
                                    <flux:icon :name="$sortIcon('days')" class="size-3" />
                                </button>
                            </th>
                            <th class="px-3 py-3 text-center text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Paid</th>
                            <th class="px-3 py-3 text-center text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Unpaid</th>
                            <th class="px-3 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Status</th>
                            <th class="px-3 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Approver</th>
                            <th class="px-3 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                <button wire:click="sortBy('created_at')"
                                    class="inline-flex cursor-pointer items-center gap-1 transition-colors hover:text-zinc-600 dark:text-zinc-300 dark:hover:text-zinc-300">
                                    Applied On
                                    <flux:icon :name="$sortIcon('created_at')" class="size-3" />
                                </button>
                            </th>
                            <th
                                class="py-3 pr-4 text-right text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/30">
                        @forelse($requests as $req)
                            <tr id="request-{{ $req->id }}"
                                class="transition-colors hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20">
                                <td class="py-4 pl-4 pr-3">
                                    <span
                                        class="text-xs font-bold text-zinc-400">LR-{{ str_pad($req->id, 4, '0', STR_PAD_LEFT) }}</span>
                                </td>
                                <td class="px-3 py-3.5">
                                    <div class="flex items-center gap-2.5">
                                        <div class="size-2.5 shrink-0 rounded-full shadow-sm"
                                            style="background-color: {{ $req->leaveType?->color ?? '#a1a1aa' }}"></div>
                                        <span
                                            class="text-sm font-bold text-zinc-800 dark:text-zinc-100 dark:text-zinc-200" style="max-width: 9rem">{{ $histCategory($req) }}</span>
                                    </div>
                                    @if($histLegacy($req))
                                        <span class="mt-1 inline-block" data-legacy-badge><x-pulse.badge color="zinc">Legacy</x-pulse.badge></span>
                                    @elseif($req->isCombined())
                                        <span class="mt-1 inline-block"><x-pulse.badge color="violet">Combined</x-pulse.badge></span>
                                    @endif
                                </td>
                                <td class="px-3 py-3.5 text-sm text-zinc-600 dark:text-zinc-300">{{ $histReason($req) }}</td>
                                <td class="px-3 py-3.5">
                                    <div class="whitespace-nowrap text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                                        {{ $req->start_date->format('d M') }}@unless($req->end_date->eq($req->start_date)) – {{ $req->end_date->format('d M') }}@endunless
                                    </div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $req->start_date->year }}@if($req->end_date->year !== $req->start_date->year)–{{ $req->end_date->format('y') }}@endif</div>
                                </td>
                                <td class="px-3 py-3.5 text-center">
                                    <span
                                        class="inline-flex items-center justify-center whitespace-nowrap rounded-lg bg-zinc-50 dark:bg-zinc-800/50 px-2.5 py-1 text-xs font-black text-zinc-900 dark:text-white dark:bg-zinc-800 dark:text-white">
                                        {{ (float) $req->days }}
                                    </span>
                                </td>
                                <td class="px-3 py-3.5 text-center text-sm tabular-nums text-zinc-700 dark:text-zinc-200">{{ $fmt($histPaid($req)) }}</td>
                                <td class="px-3 py-3.5 text-center text-sm tabular-nums {{ $histUnpaid($req) > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-400' }}">{{ $fmt($histUnpaid($req)) }}</td>
                                <td class="px-3 py-3.5">
                                    @php
                                        [$statusClass, $statusIcon, $statusLabel] = match ($req->status) {
                                            'approved' => ['bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400', 'check-circle', 'Approved'],
                                            'rejected' => ['bg-rose-50 text-rose-700 dark:bg-rose-900/20 dark:text-rose-400', 'x-circle', 'Rejected'],
                                            'pending' => ['bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400', 'clock', 'Pending'],
                                            'pending_hr' => ['bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400', 'clock', 'Pending HR'],
                                            'cancelled' => ['bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 dark:bg-zinc-800 dark:text-zinc-400', 'x-circle', 'Cancelled'],
                                            'more_info_requested' => ['bg-orange-50 text-orange-700 dark:bg-orange-900/20 dark:text-orange-400', 'question-mark-circle', 'More Info Needed'],
                                            default => ['bg-zinc-50 dark:bg-zinc-800/50 text-zinc-700 dark:text-zinc-200', 'question-mark-circle', $req->status],
                                        };
                                    @endphp
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider {{ $statusClass }}">
                                        <flux:icon :name="$statusIcon" class="size-3" />
                                        {{ $statusLabel }}
                                    </span>

                                    {{-- Approval timeline: Submitted → Manager → HR → Approved --}}
                                    @php
                                        $tlSteps = ['Submitted', 'Manager', 'HR', 'Approved'];
                                        $tlReached = match ($req->status) {
                                            'pending', 'more_info_requested' => 1,
                                            'pending_hr' => 2,
                                            'approved' => 4,
                                            default => 0,
                                        };
                                        $tlRejected = $req->status === 'rejected';
                                    @endphp
                                    @unless($req->status === 'cancelled')
                                        <div class="mt-2 flex items-center" title="Approval stage: {{ $statusLabel }}">
                                            @foreach($tlSteps as $si => $step)
                                                @php
                                                    $n = $si + 1;
                                                    $done = ! $tlRejected && $n <= $tlReached;
                                                    $current = ! $tlRejected && $req->status !== 'approved' && $n === $tlReached + 1;
                                                    $failed = $tlRejected && $n === $tlReached + 1;
                                                @endphp
                                                <span class="size-1.5 rounded-full {{ $failed ? 'bg-rose-500' : ($done ? 'bg-emerald-500' : ($current ? 'bg-amber-400' : 'bg-zinc-200 dark:bg-zinc-700')) }}"
                                                    title="{{ $step }}"></span>
                                                @if($si < 3)
                                                    <span class="h-px w-3 {{ $done ? 'bg-emerald-300 dark:bg-emerald-800' : 'bg-zinc-200 dark:bg-zinc-700' }}"></span>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endunless
                                    @if($req->status === 'more_info_requested' && $req->reviewer_comment)
                                        <p style="max-width: 8rem" class="mt-1 truncate text-[10px] italic text-orange-500" title="{{ $req->reviewer_comment }}">“{{ $req->reviewer_comment }}”</p>
                                    @elseif($req->status === 'rejected' && $req->reviewer_comment)
                                        <p style="max-width: 8rem" class="mt-1 truncate text-[10px] italic text-rose-500" title="{{ $req->reviewer_comment }}">“{{ $req->reviewer_comment }}”</p>
                                    @endif
                                    @if($req->attachments->isNotEmpty())
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @foreach($req->attachments as $att)
                                                <a href="{{ $att->url() }}" target="_blank" title="{{ $att->typeLabel() }}: {{ $att->original_name }}"
                                                    class="inline-flex items-center gap-1 rounded bg-zinc-50 dark:bg-zinc-800/50 px-1.5 py-0.5 text-[9px] font-semibold text-zinc-500 dark:text-zinc-400 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-400">
                                                    <flux:icon :name="$att->icon()" class="size-2.5" /> {{ $att->typeLabel() }}
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="px-3 py-3.5">
                                    @if($histApprover($req))
                                        <div class="flex items-center gap-2">
                                            <div
                                                class="flex size-7 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-violet-400 to-indigo-500 text-[9px] font-black uppercase text-white shadow-sm">
                                                {{ collect(explode(' ', $histApprover($req)->name))->map(fn($n) => $n[0])->take(2)->join('') }}
                                            </div>
                                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $histApprover($req)->name }}</span>
                                        </div>
                                    @else
                                        <div class="flex items-center gap-1.5 text-[10px] font-bold text-zinc-400">
                                            <div class="size-1.5 animate-pulse rounded-full bg-amber-400"></div>
                                            Awaiting
                                        </div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-3.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">@userDate($req->created_at)</td>
                                <td class="py-4 pr-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        @if($req->status === 'more_info_requested')
                                            <button wire:click="openConversation({{ $req->id }})"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 text-[10px] font-bold text-orange-600 bg-orange-50 hover:bg-orange-100 dark:bg-orange-900/20 dark:text-orange-400 rounded-lg transition-colors">
                                                <flux:icon.chat-bubble-left-right class="size-3" />
                                                Respond
                                            </button>
                                        @elseif($req->status === 'rejected')
                                            <button wire:click="openConversation({{ $req->id }})"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 text-[10px] font-bold text-indigo-600 bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-900/20 dark:text-indigo-400 rounded-lg transition-colors">
                                                <flux:icon.chat-bubble-left-right class="size-3" />
                                                Contact HR
                                                @if($req->messages_count > 0)
                                                    <span class="ml-0.5 inline-flex min-w-4 items-center justify-center rounded-full bg-indigo-600 px-1 text-[9px] font-black text-white">{{ $req->messages_count }}</span>
                                                @endif
                                            </button>
                                        @elseif($req->messages_count > 0)
                                            <button wire:click="openConversation({{ $req->id }})"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 text-[10px] font-bold text-indigo-600 bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-900/20 dark:text-indigo-400 rounded-lg transition-colors">
                                                <flux:icon.chat-bubble-left-right class="size-3" />
                                                Messages
                                                <span class="ml-0.5 inline-flex min-w-4 items-center justify-center rounded-full bg-indigo-600 px-1 text-[9px] font-black text-white">{{ $req->messages_count }}</span>
                                            </button>
                                        @endif
                                        @if(in_array($req->status, ['pending', 'pending_hr', 'approved']))
                                            <button wire:click="cancelRequest({{ $req->id }})"
                                                wire:confirm="Cancel this leave request?"
                                                class="inline-flex items-center gap-1 px-2.5 py-1 text-[10px] font-bold text-red-500 bg-red-50 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/40 rounded-lg transition-colors">
                                                <flux:icon.x-mark class="size-3" />
                                                Cancel
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="py-16 text-center">
                                    <div class="table-empty-pin">
                                        <div class="flex flex-col items-center gap-3">
                                            <div
                                                class="flex size-14 items-center justify-center rounded-2xl bg-zinc-50 dark:bg-zinc-800">
                                                <flux:icon.document-magnifying-glass
                                                    class="size-7 text-zinc-300 dark:text-zinc-600 dark:text-zinc-300" />
                                            </div>
                                            <div>
                                                <p class="text-sm font-bold text-zinc-400">No leave applications yet</p>
                                                <p class="mt-0.5 text-xs text-zinc-300 dark:text-zinc-600 dark:text-zinc-300">Click "Apply for
                                                    Leave" to get started.</p>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <ul class="divide-y divide-zinc-100 sm:hidden dark:divide-white/5" data-history-cards>
                @forelse($requests as $req)
                    <li wire:key="hist-card-{{ $req->id }}" id="request-card-{{ $req->id }}" class="space-y-1.5 p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $histCategory($req) }}@if($histReason($req) !== '—') <span class="font-normal text-zinc-500 dark:text-zinc-400">· {{ $histReason($req) }}</span>@endif</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">LR-{{ str_pad($req->id, 4, '0', STR_PAD_LEFT) }} · {{ $req->start_date->format('d M Y') }}@if(! $req->end_date->eq($req->start_date)) – {{ $req->end_date->format('d M Y') }}@endif</p>
                            </div>
                            <x-pulse.badge :color="match ($req->status) { 'approved' => 'emerald', 'rejected' => 'rose', 'cancelled' => 'zinc', 'pending_hr', 'more_info_requested' => 'blue', default => 'amber' }">{{ str($req->status)->replace('_', ' ')->title() }}</x-pulse.badge>
                        </div>
                        <p class="text-xs text-zinc-600 dark:text-zinc-300">{{ $fmt($req->days) }} {{ Str::plural('day', (float) $req->days == 1 ? 1 : 2) }} · Paid {{ $fmt($histPaid($req)) }} · Unpaid {{ $fmt($histUnpaid($req)) }}@if($histApprover($req)) · {{ $histApprover($req)->name }}@endif</p>
                        <div class="flex flex-wrap items-center gap-2">
                            @if($histLegacy($req))<x-pulse.badge color="zinc">Legacy</x-pulse.badge>@endif
                            @if(in_array($req->status, ['more_info_requested', 'rejected'], true) || $req->messages_count > 0)
                                <flux:button size="xs" variant="ghost" wire:click="openConversation({{ $req->id }})">{{ $req->status === 'more_info_requested' ? 'Respond' : 'Messages' }}</flux:button>
                            @endif
                            @if(in_array($req->status, ['pending', 'pending_hr', 'approved']))
                                <flux:button size="xs" variant="ghost" wire:click="cancelRequest({{ $req->id }})" wire:confirm="Cancel this leave request?">Cancel</flux:button>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="p-8 text-center text-sm text-zinc-500 dark:text-zinc-400">No leave applications yet.</li>
                @endforelse
            </ul>
            @if($requests->hasPages())
                <div class="border-t border-zinc-50 px-6 py-4 dark:border-zinc-800">
                    {{ $requests->links() }}
                </div>
            @endif
        </div>
        {{-- Encashments Tab --}}
        <div x-show="tab === 'encashments'" x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-1">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-zinc-50 bg-zinc-50/50 dark:border-zinc-800/50 dark:bg-zinc-950/50">
                            <th class="py-3 pl-6 pr-4 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Leave Type</th>
                            <th
                                class="px-4 py-3 text-center text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Days</th>
                            <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Status</th>
                            <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Payout Cycle</th>
                            <th class="py-3 pr-6 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Reviewer</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/30">
                        @forelse($encashments as $enc)
                            <tr class="transition-colors hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20">
                                <td class="py-4 pl-6 pr-4">
                                    <div class="flex items-center gap-2.5">
                                        <div class="size-2.5 rounded-full"
                                            style="background-color: {{ $enc->leaveType->color }}"></div>
                                        <span
                                            class="text-sm font-bold text-zinc-800 dark:text-zinc-100 dark:text-zinc-200">{{ $enc->leaveType->name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span
                                        class="inline-flex size-7 items-center justify-center rounded-lg bg-zinc-50 dark:bg-zinc-800/50 text-sm font-black text-zinc-900 dark:text-white dark:bg-zinc-800 dark:text-white">
                                        {{ (float) $enc->requested_days }}
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    @php
                                        [$encStatusClass, $encStatusIcon] = match ($enc->status) {
                                            'approved', 'processed' => ['bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400', 'check-circle'],
                                            'rejected' => ['bg-rose-50 text-rose-700 dark:bg-rose-900/20 dark:text-rose-400', 'x-circle'],
                                            'pending' => ['bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400', 'clock'],
                                            default => ['bg-zinc-50 dark:bg-zinc-800/50 text-zinc-700 dark:text-zinc-200', 'question-mark-circle'],
                                        };
                                    @endphp
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider {{ $encStatusClass }}">
                                        <flux:icon :name="$encStatusIcon" class="size-3" />
                                        {{ $enc->status }}
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    <div
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-50 dark:bg-zinc-800/50 px-2.5 py-1 text-xs font-bold text-zinc-600 dark:text-zinc-300 dark:bg-zinc-800 dark:text-zinc-400">
                                        <flux:icon.calendar class="size-3" />
                                        {{ $enc->payout_month ? Carbon::parse($enc->payout_month)->format('M Y') : '--' }}
                                    </div>
                                </td>
                                <td class="py-4 pr-6">
                                    @if($enc->reviewer)
                                        <div class="flex items-center gap-2">
                                            <div
                                                class="flex size-7 items-center justify-center rounded-full bg-gradient-to-br from-violet-400 to-indigo-500 text-[9px] font-black uppercase text-white shadow-sm">
                                                {{ collect(explode(' ', $enc->reviewer->name))->map(fn($n) => $n[0])->take(2)->join('') }}
                                            </div>
                                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $enc->reviewer->name }}</span>
                                        </div>
                                    @else
                                        <div class="flex items-center gap-1.5 text-[10px] font-bold text-zinc-400">
                                            <div class="size-1.5 animate-pulse rounded-full bg-amber-400"></div>
                                            Awaiting
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-16 text-center">
                                    <div class="table-empty-pin">
                                        <div class="flex flex-col items-center gap-3">
                                            <div
                                                class="flex size-14 items-center justify-center rounded-2xl bg-zinc-50 dark:bg-zinc-800">
                                                <flux:icon.banknotes class="size-7 text-zinc-300 dark:text-zinc-600 dark:text-zinc-300" />
                                            </div>
                                            <p class="text-sm font-bold text-zinc-400">No encashment requests yet.</p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        </div>

        {{-- Calendar and the holiday / shutdown schedule --}}
        <div x-show="view === 'calendar'" x-cloak class="space-y-4" data-tab-panel="calendar" x-data="{ detail: null }">
            <div class="ui-surface p-5">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Leave Calendar</h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Click a working day to apply · a holiday to request work.</p>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button wire:click="previousCalendarMonth" class="flex size-8 items-center justify-center rounded-lg border border-zinc-200 text-zinc-500 hover:bg-zinc-50 dark:border-white/10 dark:text-zinc-400 dark:hover:bg-white/5" aria-label="Previous month"><flux:icon.chevron-left class="size-4" /></button>
                        <button wire:click="goToCurrentCalendarMonth" class="rounded-lg px-3 py-1 text-sm font-semibold text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-white/5">{{ $calendarLabel }}</button>
                        <button wire:click="nextCalendarMonth" class="flex size-8 items-center justify-center rounded-lg border border-zinc-200 text-zinc-500 hover:bg-zinc-50 dark:border-white/10 dark:text-zinc-400 dark:hover:bg-white/5" aria-label="Next month"><flux:icon.chevron-right class="size-4" /></button>
                    </div>
                </div>
                <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-zinc-500 dark:text-zinc-400" data-calendar-legend>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-emerald-500"></span> Approved</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-amber-400"></span> Pending</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-rose-500"></span> Holiday</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-orange-400"></span> Shutdown</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm border border-zinc-200 bg-zinc-100 dark:border-white/10 dark:bg-white/5"></span> Weekly Off</span>
                </div>
        {{-- Weekday headers --}}
        <div class="mb-1.5 grid grid-cols-7 gap-1.5">
            @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $wd)
                <div class="text-center text-[10px] font-black uppercase tracking-wider text-zinc-400">{{ $wd }}</div>
            @endforeach
        </div>

        {{-- Day grid --}}
        <div class="grid grid-cols-7 gap-1.5">
            @foreach($calendarDays as $day)
                @php
                    $cellClasses = 'relative flex flex-col items-center justify-center rounded-xl py-2.5 text-xs font-bold transition-colors';

                    if (!$day['isCurrentMonth']) {
                        $cellClasses .= ' text-zinc-300 dark:text-zinc-700 dark:text-zinc-200';
                    } elseif ($day['holiday']) {
                        $cellClasses .= ' bg-rose-50 text-rose-700 dark:bg-rose-900/20 dark:text-rose-400';
                    } elseif ($day['shutdown']) {
                        $cellClasses .= ' bg-orange-50 text-orange-700 dark:bg-orange-900/20 dark:text-orange-400';
                    } elseif ($day['approved']) {
                        $cellClasses .= ' bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400';
                    } elseif ($day['pending']) {
                        $cellClasses .= ' bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400';
                    } elseif ($day['isWeekend']) {
                        $cellClasses .= ' bg-zinc-50 dark:bg-zinc-800/50 text-zinc-400 dark:bg-zinc-800/50 dark:text-zinc-500 dark:text-zinc-400';
                    } else {
                        $cellClasses .= ' text-zinc-700 dark:text-zinc-200 dark:text-zinc-300';
                    }

                    if ($day['isToday']) {
                        $cellClasses .= ' ring-2 ring-orange-500 ring-offset-1 dark:ring-offset-zinc-900';
                    }

                    $isFuture = $day['date']->gte(now()->startOfDay());
                    // Bookable for LEAVE: a free, future, current-month working day.
                    $bookableLeave = $day['isCurrentMonth'] && ! $day['isWeekend'] && ! $day['holiday'] && ! $day['shutdown']
                        && ! $day['approved'] && ! $day['pending'] && $isFuture;
                    // A future public holiday can be requested to WORK (comp-off / OT).
                    $workableHoliday = $day['isCurrentMonth'] && $day['holiday']
                        && ! $day['approved'] && ! $day['pending'] && $isFuture;

                    if ($bookableLeave) {
                        $cellClasses .= ' cursor-pointer hover:ring-2 hover:ring-orange-300 dark:hover:ring-orange-700';
                    } elseif ($workableHoliday) {
                        $cellClasses .= ' cursor-pointer hover:ring-2 hover:ring-rose-300 dark:hover:ring-rose-700';
                    }

                    $title = $day['holiday']?->name
                        ?? ($day['shutdown'] ? 'Mandatory December Leave (company shutdown)' : null)
                        ?? ($day['approved'] ? $day['approved']->leaveType->name . ' (Approved)' : null)
                        ?? ($day['pending'] ? $day['pending']->leaveType->name . ' (Pending)' : null);
                @endphp
                @if($bookableLeave)
                    <button type="button" wire:click="applyOnDate('{{ $day['date']->toDateString() }}')"
                        class="{{ $cellClasses }} group" title="Apply leave on @userDate($day['date'])">
                        {{ $day['date']->day }}
                        <span class="pointer-events-none absolute right-1 top-1 hidden text-[9px] font-black text-orange-500 group-hover:block">+</span>
                    </button>
                @elseif($workableHoliday)
                    <button type="button" wire:click="openHolidayWork('{{ $day['date']->toDateString() }}')"
                        class="{{ $cellClasses }} group" title="Request to work on {{ $day['holiday']->name }} (@userShortDate($day['date']))">
                        {{ $day['date']->day }}
                        <span class="mt-0.5 size-1 rounded-full bg-rose-500 group-hover:hidden"></span>
                        <span class="pointer-events-none absolute right-0.5 top-0.5 hidden group-hover:block"><flux:icon.briefcase class="size-2.5 text-rose-500" /></span>
                    </button>
                @else
                    <div class="{{ $cellClasses }} @if($title && $day['isCurrentMonth']) cursor-pointer @endif" @if($title) title="{{ $title }}" @endif
                        @if($title && $day['isCurrentMonth']) role="button" tabindex="0" x-on:click="detail = @js(['date' => $day['date']->format('l, d M Y'), 'text' => $title])" x-on:keydown.enter="detail = @js(['date' => $day['date']->format('l, d M Y'), 'text' => $title])" @endif>
                        {{ $day['date']->day }}
                        @if($day['approved'] || $day['pending'] || $day['holiday'] || $day['shutdown'])
                            <span
                                class="mt-0.5 size-1 rounded-full {{ $day['holiday'] ? 'bg-rose-500' : ($day['shutdown'] ? 'bg-orange-400' : ($day['approved'] ? 'bg-emerald-500' : 'bg-amber-400')) }}"></span>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
                <div x-show="detail" x-cloak x-transition class="mt-4 flex items-start justify-between gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-sm dark:border-white/10 dark:bg-white/5" data-calendar-detail>
                    <span><span class="block font-semibold text-zinc-900 dark:text-white" x-text="detail?.date"></span><span class="text-zinc-600 dark:text-zinc-300" x-text="detail?.text"></span></span>
                    <button type="button" x-on:click="detail = null" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200" aria-label="Close"><flux:icon.x-mark class="size-4" /></button>
                </div>
            </div>

            <details class="ui-surface group p-5" data-holiday-schedule>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3">
                    <span>
                        <span class="block text-base font-semibold text-zinc-900 dark:text-white">Holiday / Shutdown Schedule</span>
                        <span class="block text-xs text-zinc-500 dark:text-zinc-400">Company shutdown dates and working on a holiday.</span>
                    </span>
                    <flux:icon.chevron-down class="size-4 shrink-0 text-zinc-400 transition group-open:rotate-180" />
                </summary>
                <div class="mt-3 space-y-1.5">
                    @forelse($mandatoryDays as $m)
                        <div class="flex items-center justify-between gap-2 text-sm">
                            <span class="min-w-0 break-words text-zinc-700 dark:text-zinc-200">@userShortDate($m->date) — {{ $m->description }}</span>
                            <x-pulse.badge color="amber">Shutdown</x-pulse.badge>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">No company shutdown dates are scheduled.</p>
                    @endforelse
                </div>
                <flux:button size="sm" class="mt-3" icon="briefcase" wire:click="openHolidayWork">Request to Work on Holiday</flux:button>
            </details>
        </div>
    </section>

    {{-- Leave V2: the policy-driven Request Leave drawer. --}}
    @if(\App\Services\Leave\LeavePolicyEngine::enabled())
        <livewire:time-off.request-leave />
    @endif

    {{-- ═══ E. QUICK ACTIONS ═══ one compact row of equal tiles --}}
    @php
        $routeOr = fn ($name, $fallback = '#') => \Illuminate\Support\Facades\Route::has($name) ? route($name) : $fallback;
        $quickActions = [
            ['label' => 'Request Leave', 'icon' => 'plus-circle', 'tone' => 'bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400', 'modal' => true],
            ['label' => 'Work From Home', 'icon' => 'home', 'tone' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'href' => $routeOr('wfh.my'), 'nav' => true],
            ['label' => 'Attendance', 'icon' => 'finger-print', 'tone' => 'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400', 'href' => $routeOr('attendance.my'), 'nav' => true],
            ['label' => 'Overtime', 'icon' => 'clock', 'tone' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400', 'href' => $routeOr('overtime.my'), 'nav' => true],
            ['label' => 'Leave History', 'icon' => 'clipboard-document-list', 'tone' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400', 'go' => "go('history', 'requests', true)"],
            ['label' => 'Leave Policy', 'icon' => 'document-text', 'tone' => 'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400', 'href' => '#policy-overview', 'nav' => false],
            ...(app(\App\Services\ModuleFeatureService::class)->payslipsEnabled() ? [['label' => 'Payslips', 'icon' => 'banknotes', 'tone' => 'bg-teal-50 text-teal-600 dark:bg-teal-500/10 dark:text-teal-400', 'href' => $routeOr('payroll.payslips'), 'nav' => true]] : []),
        ];
        $qa = 'ui-surface flex flex-col items-center justify-center gap-2 px-2 py-3.5 text-center transition hover:-translate-y-0.5 hover:shadow-md';
        $qaIcon = fn ($action) => '<span class="flex size-9 items-center justify-center rounded-xl '.$action['tone'].'">';
    @endphp
    <section aria-label="Quick actions" data-quick-actions>
        <h2 class="mb-3 text-base font-semibold text-zinc-900 dark:text-white">Quick Actions</h2>
        <div class="grid grid-cols-2 items-stretch gap-3 sm:grid-cols-4 lg:grid-cols-7">
            @foreach($quickActions as $action)
                @if($action['modal'] ?? false)
                    <button type="button" wire:click="openRequestModal" class="{{ $qa }}">{!! $qaIcon($action) !!}<flux:icon :name="$action['icon']" class="size-5" /></span><span class="text-xs font-semibold text-zinc-700 dark:text-zinc-200">{{ $action['label'] }}</span></button>
                @elseif(isset($action['go']))
                    <button type="button" x-on:click="{{ $action['go'] }}" class="{{ $qa }}">{!! $qaIcon($action) !!}<flux:icon :name="$action['icon']" class="size-5" /></span><span class="text-xs font-semibold text-zinc-700 dark:text-zinc-200">{{ $action['label'] }}</span></button>
                @else
                    <a href="{{ $action['href'] }}" @if($action['nav'] ?? false) wire:navigate @endif class="{{ $qa }}">{!! $qaIcon($action) !!}<flux:icon :name="$action['icon']" class="size-5" /></span><span class="text-xs font-semibold text-zinc-700 dark:text-zinc-200">{{ $action['label'] }}</span></a>
                @endif
            @endforeach
        </div>
    </section>

    {{-- ═══ F. PLANNING ═══ Leave Planner · Upcoming Holidays --}}
    @php $headIcon = 'flex size-8 shrink-0 items-center justify-center rounded-lg'; @endphp
    <section class="grid grid-cols-1 items-stretch gap-4 lg:grid-cols-2" data-planning>
        <div class="ui-surface flex flex-col p-5">
            <h2 class="flex items-center gap-2.5 text-base font-semibold text-zinc-900 dark:text-white"><span class="{{ $headIcon }} bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400"><flux:icon.calendar-days class="size-4" /></span>Leave Planner</h2>
            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <flux:input wire:model.live="planner_start" type="date" label="Start Date" />
                <flux:input wire:model.live="planner_end" type="date" label="End Date" />
            </div>
            <div class="mt-3 flex-1 rounded-lg bg-zinc-50 p-3 text-sm dark:bg-white/5">
                @if($plannerResult)
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 sm:grid-cols-4">
                        <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">Days</dt><dd class="font-semibold tabular-nums">{{ $plannerResult['total'] }}</dd></div>
                        <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">Weekly off</dt><dd class="font-semibold tabular-nums">{{ $plannerResult['weekend'] }}</dd></div>
                        <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">Holidays</dt><dd class="font-semibold tabular-nums">{{ $plannerResult['holidays'] }}</dd></div>
                        <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">Estimated charge</dt><dd class="font-semibold tabular-nums text-orange-600 dark:text-orange-400">{{ $plannerResult['leaveDays'] }}</dd></div>
                    </dl>
                    <p class="mt-2 text-xs text-emerald-700 dark:text-emerald-400">Remaining after this leave: {{ $fmt($plannerResult['remaining']) }} days</p>
                @else
                    <p class="text-center text-xs text-zinc-500 dark:text-zinc-400">Pick a start and end date to see working days, weekly offs, holidays and the estimated charge.</p>
                @endif
            </div>
            <flux:button wire:click="applyFromPlanner" variant="primary" class="mt-3 w-full" :disabled="! $plannerResult">Check Availability &amp; Apply</flux:button>
        </div>

        <div class="ui-surface flex flex-col p-5">
            <div class="flex items-center justify-between gap-2">
                <h2 class="flex items-center gap-2.5 text-base font-semibold text-zinc-900 dark:text-white"><span class="{{ $headIcon }} bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400"><flux:icon.flag class="size-4" /></span>Upcoming Holidays</h2>
                @if(\Illuminate\Support\Facades\Route::has('holidays.calendar'))
                    <a href="{{ route('holidays.calendar') }}" wire:navigate class="text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">View All</a>
                @endif
            </div>
            <div class="mt-3 flex-1 divide-y divide-zinc-100 dark:divide-white/5">
                @forelse($upcomingHolidays->take(4) as $holiday)
                    @php $daysLeft = (int) now()->startOfDay()->diffInDays($holiday->date->copy()->startOfDay()); @endphp
                    <div class="group flex items-center gap-3 py-2.5">
                        <div class="flex size-11 shrink-0 flex-col items-center justify-center rounded-lg bg-zinc-50 dark:bg-white/5">
                            <span class="text-sm font-bold leading-none text-zinc-800 dark:text-zinc-100">{{ $holiday->date->format('d') }}</span>
                            <span class="text-[10px] font-semibold uppercase text-zinc-500 dark:text-zinc-400">{{ $holiday->date->format('M') }}</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $holiday->name }}</p>
                            <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $holiday->date->format('l') }}</p>
                        </div>
                        <button type="button" wire:click="openHolidayWork('{{ $holiday->date->toDateString() }}')" class="shrink-0 rounded px-1.5 py-0.5 text-xs font-semibold text-orange-600 opacity-0 transition group-hover:opacity-100 dark:text-orange-400" title="Request to work this holiday">Work</button>
                        <x-pulse.badge color="brand">{{ $daysLeft <= 0 ? 'Today' : 'In '.$daysLeft.'d' }}</x-pulse.badge>
                    </div>
                @empty
                    <div class="flex h-full flex-col items-center justify-center py-6 text-center">
                        <flux:icon.flag class="size-6 text-zinc-300 dark:text-zinc-600" />
                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">No upcoming holidays.</p>
                    </div>
                @endforelse
            </div>
        </div>
    </section>

    {{-- ═══ G. TEAM AVAILABILITY · POLICY OVERVIEW ═══ --}}
    <section class="grid grid-cols-1 items-stretch gap-4 lg:grid-cols-5">
        @if($teamAvailability->isNotEmpty())
            <div class="ui-surface flex flex-col p-5 lg:col-span-2" data-team-availability>
                <h2 class="flex items-center gap-2.5 text-base font-semibold text-zinc-900 dark:text-white"><span class="{{ $headIcon }} bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400"><flux:icon.users class="size-4" /></span>Team Availability Today</h2>
                <ul class="mt-3 flex-1 divide-y divide-zinc-100 text-sm dark:divide-white/5">
                    @foreach($teamAvailability->take(6) as $dept)
                        @php $dot = $dept->on_leave > 0 ? 'bg-rose-500' : ($dept->wfh > 0 ? 'bg-blue-500' : ($dept->half_day > 0 ? 'bg-amber-400' : 'bg-emerald-500')); @endphp
                        <li class="flex items-center justify-between gap-2 py-2">
                            <span class="flex min-w-0 items-center gap-2.5"><span class="size-2 shrink-0 rounded-full {{ $dot }}"></span><span class="truncate text-zinc-700 dark:text-zinc-200">{{ $dept->dept }}</span></span>
                            <span class="flex shrink-0 items-center gap-2">
                                @if($dept->on_leave > 0)<x-pulse.badge color="rose">{{ $dept->on_leave }} on leave</x-pulse.badge>@elseif($dept->wfh > 0)<x-pulse.badge color="blue">{{ $dept->wfh }} WFH</x-pulse.badge>@endif
                                <span class="tabular-nums text-zinc-500 dark:text-zinc-400">{{ $dept->present }}/{{ $dept->total }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
                @if(auth()->user()->canApproveLeave())
                    <flux:button size="sm" icon="users" href="{{ route('time-off.team') }}" wire:navigate class="mt-3 self-center">View Team Calendar</flux:button>
                @endif
            </div>
        @endif

        @php $pol = $overview['policy']; $ov = $overview; $ovCsl = $ov['csl']['summary'] ?? null; @endphp
        <div id="policy-overview" class="ui-surface scroll-mt-6 p-5 {{ $teamAvailability->isNotEmpty() ? 'lg:col-span-3' : 'lg:col-span-5' }}" data-policy-overview>
            <div class="flex items-center justify-between gap-2">
                <h2 class="flex items-center gap-2.5 text-base font-semibold text-zinc-900 dark:text-white"><span class="{{ $headIcon }} bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400"><flux:icon.book-open class="size-4" /></span>Policy Overview</h2>
                <a href="{{ route('help.employee-guide') }}" wire:navigate class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">View Policy <flux:icon.chevron-right class="size-3" /></a>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $policyName }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $policyYear->starts_on->format('M Y') }} – {{ $policyYear->ends_on->format('M Y') }}</p>
                @if($v2)
                    <x-pulse.badge color="brand">{{ $v2['policy_source'] }}</x-pulse.badge>
                    <x-pulse.badge color="emerald">Current policy</x-pulse.badge>
                @endif
            </div>
            @if($v2)
                <dl class="mt-3 grid grid-cols-2 gap-3 border-y border-zinc-100 py-3 text-sm sm:grid-cols-3 lg:grid-cols-5 dark:border-white/5">
                    <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">Earned Leave</dt><dd class="font-semibold">{{ $fmt($v2['earned']['annual_entitlement'] ?? $v2['annual_cap'] ?? 0) }} days/year</dd></div>
                    <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">Accrual</dt><dd class="font-semibold">{{ ucwords(str_replace('_', ' ', $v2['earned']['accrual_method'] ?? 'as configured')) }}</dd></div>
                    <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">Carry Forward</dt><dd class="font-semibold">As configured</dd></div>
                    <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">Comp Off</dt><dd class="font-semibold">Separate</dd></div>
                    <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">Unpaid</dt><dd class="font-semibold">No balance required</dd></div>
                </dl>
                <ul class="mt-3 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                    <li>• Casual, Sick and other reasons all use your Earned Leave — they are reasons, not balances.</li>
                    <li>• Special Leave has its own entitlement; Unpaid Leave affects payroll.</li>
                    <li>• Leave year: Jul – Jun.</li>
                </ul>
            @else
                <dl class="mt-3 grid grid-cols-2 gap-3 border-y border-zinc-100 py-3 text-sm sm:grid-cols-4 dark:border-white/5">
                    <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">Policy</dt><dd class="font-semibold">{{ $fmt($pol['csl_days']) }} CSL + {{ $pol['mdl_days'] }} MDL</dd></div>
                    <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">Current requestable balance</dt><dd class="font-semibold tabular-nums {{ $ov['available_to_request'] < 0 ? 'text-rose-600 dark:text-rose-400' : '' }}">{{ $fmt($ov['available_to_request']) }} days</dd></div>
                    <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">CSL carry forward</dt><dd class="font-semibold tabular-nums">{{ $fmt($ovCsl['carry_forward'] ?? 0) }} days</dd></div>
                    <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">MDL remaining dates</dt><dd class="font-semibold tabular-nums">{{ $ov['mdl']['remaining'] }} of {{ $ov['mdl']['expected'] }}</dd></div>
                </dl>
                <ul class="mt-3 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                    <li>• {{ $pol['name'] ?? 'Leave policy' }}: {{ $fmt($pol['csl_days'] + $pol['mdl_days']) }} leave days/year — CSL never lapses; MDL is the December shutdown.</li>
                    <li>• Working an MDL day earns one Comp Off credit; only CSL can be encashed.</li>
                    <li>• {{ $pol['grant'] }}</li>
                    <li>• Leave year: Jul – Jun.</li>
                </ul>
            @endif
        </div>
    </section>

    {{-- ═══ H. INSIGHTS ═══ analytics only: balance overview · monthly usage · distribution --}}
    @php
        $maxMonthly = max(max($monthlyStats), 1);
        $monthShort = collect(range(0, 11))->map(fn ($i) => $overview['year']->starts_on->copy()->addMonths($i)->format('M'))->all();
        $insCsl = $v2 ? ['used' => $v2['earned']['used'], 'encashed' => 0, 'approved_available' => $v2['earned']['approved_available'], 'carry_forward' => $v2['earned']['carry_forward']]
            : ($overview['csl']['summary'] ?? null);
        $insName = $v2 ? 'Earned Leave' : 'CSL';
        $insUsed = (float) ($insCsl['used'] ?? 0) + (float) ($insCsl['encashed'] ?? 0);
        $insAvailableReal = (float) ($insCsl['approved_available'] ?? 0);
        $insAvailable = max(0.0, $insAvailableReal);
        $insCarried = (float) ($insCsl['carry_forward'] ?? 0);
        $insAllocated = $insUsed + $insAvailableReal;
        $insBase = max($insUsed + $insAvailable, 0.0001);
        $usedFrac = $insUsed / $insBase;
        $availFrac = $insAvailable / $insBase;
        $dCirc = 2 * pi() * 42;
        $distribution = $v2
            ? collect([$v2['earned']])->merge($v2['comp_off']['subtypes'])->merge($v2['special']['subtypes'])
                ->map(fn ($r) => ['name' => $r['name'], 'color' => $r['color'], 'used' => (float) $r['used'], 'left' => (float) $r['approved_available'], 'pinned' => $r['id'] === $v2['earned']['id']])
            : collect([$overview['csl'], $overview['comp_off']])->filter()
                ->map(fn ($c) => ['name' => $c['type']->name, 'color' => $c['type']->color, 'used' => (float) $c['summary']['used'], 'left' => (float) $c['summary']['approved_available'], 'pinned' => $c === $overview['csl']])
                ->merge($overview['others']->map(fn ($o) => ['name' => $o['type']->name, 'color' => $o['type']->color, 'used' => (float) $o['summary']['used'], 'left' => (float) $o['summary']['approved_available'], 'pinned' => false]));
        // Types with something used, plus the main paid balance for context; the rest stay behind "Show all types".
        $usedTypes = $distribution->filter(fn ($d) => $d['used'] > 0 || $d['pinned'])->sortByDesc('used')->values();
        $otherTypes = $distribution->reject(fn ($d) => $d['used'] > 0 || $d['pinned'])->filter(fn ($d) => $d['left'] != 0)->values();
        $maxUsed = max($usedTypes->max('used') ?? 0, 1);
        $monthTotal = array_sum($monthlyStats);
    @endphp
    <section data-leave-insights>
        <h2 class="mb-3 text-base font-semibold text-zinc-900 dark:text-white">Leave Insights</h2>
        <div class="grid grid-cols-1 items-stretch gap-4 lg:grid-cols-3">
            {{-- Balance overview --}}
            <div class="ui-surface flex flex-col p-5">
                <div class="flex items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Balance Overview</h3>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $insName }} · {{ $overview['year']->label }}</span>
                </div>
                <div class="mt-4 flex flex-1 items-center gap-5">
                    <div class="relative size-28 shrink-0">
                        <svg class="size-28 -rotate-90" viewBox="0 0 100 100">
                            <circle cx="50" cy="50" r="42" fill="none" stroke="currentColor" stroke-width="10" class="text-zinc-100 dark:text-white/10" />
                            <circle cx="50" cy="50" r="42" fill="none" stroke="#10b981" stroke-width="10" stroke-linecap="round" stroke-dasharray="{{ $dCirc * $availFrac }} {{ $dCirc }}" />
                            <circle cx="50" cy="50" r="42" fill="none" stroke="#f97316" stroke-width="10" stroke-linecap="round" stroke-dasharray="{{ $dCirc * $usedFrac }} {{ $dCirc }}" stroke-dashoffset="{{ -$dCirc * $availFrac }}" />
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $fmt($insAllocated) }}</span>
                            <span class="text-[10px] font-semibold uppercase text-zinc-500 dark:text-zinc-400">Days</span>
                        </div>
                    </div>
                    <ul class="min-w-0 flex-1 space-y-2 text-sm">
                        <li class="flex items-center justify-between gap-2"><span class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-orange-500"></span>Used</span><span class="font-semibold tabular-nums">{{ $fmt($insUsed) }}</span></li>
                        <li class="flex items-center justify-between gap-2"><span class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-emerald-500"></span>Available</span><span class="font-semibold tabular-nums">{{ $fmt($insAvailableReal) }}</span></li>
                        <li class="flex items-center justify-between gap-2"><span class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-amber-400"></span>Carry Forward</span><span class="font-semibold tabular-nums">{{ $fmt($insCarried) }}</span></li>
                    </ul>
                </div>
                <p class="mt-4 border-t border-zinc-100 pt-3 text-xs text-zinc-500 dark:border-white/5 dark:text-zinc-400" data-leave-forecast>Leave Forecast: <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $forecast['projected'] }}</span> projected by year end · <span class="{{ $forecast['on_track'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">{{ $forecast['on_track'] ? 'on track' : 'above your balance' }}</span></p>
            </div>

            {{-- Monthly usage --}}
            <div class="ui-surface flex flex-col p-5">
                <div class="flex items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Monthly Usage</h3>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Approved days</span>
                </div>
                @if($monthTotal == 0)
                    <div class="flex flex-1 flex-col items-center justify-center py-6 text-center" data-insights-empty>
                        <flux:icon.chart-bar class="size-7 text-zinc-300 dark:text-zinc-600" />
                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">No approved leave taken yet this leave year.</p>
                    </div>
                @else
                    <div class="mt-4 flex flex-1 gap-1" style="min-height: 7rem">
                        @foreach($monthlyStats as $mi => $days)
                            @php $barH = $days > 0 ? max(8, round(($days / $maxMonthly) * 100)) : 0; @endphp
                            <div class="flex flex-1 flex-col items-center gap-1" title="{{ $monthShort[$mi] }}: {{ $days }} day(s)">
                                <span class="text-[10px] font-semibold {{ $days > 0 ? 'text-violet-600 dark:text-violet-400' : 'text-transparent' }}">{{ $days }}</span>
                                <div class="flex w-full flex-1 items-end border-b border-zinc-200 dark:border-white/10">
                                    @if($days > 0)<div class="w-full rounded-t bg-violet-500" style="height: {{ $barH }}%"></div>@endif
                                </div>
                                <span class="text-[10px] text-zinc-500 dark:text-zinc-400">{{ substr($monthShort[$mi], 0, 1) }}</span>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ $fmt($monthTotal) }} approved leave {{ Str::plural('day', $monthTotal == 1 ? 1 : 2) }} this leave year.</p>
                @endif
            </div>

            {{-- Distribution: meaningful types only --}}
            <div class="ui-surface flex flex-col p-5" x-data="{ allTypes: false }" data-leave-distribution>
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Leave Distribution</h3>
                @if($usedTypes->isEmpty())
                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">No approved leave taken yet this leave year.</p>
                @else
                    <ul class="mt-4 flex-1 space-y-3 text-sm">
                        @foreach($usedTypes as $d)
                            <li>
                                <div class="flex items-center justify-between gap-2"><span class="truncate text-zinc-700 dark:text-zinc-200">{{ $d['name'] }}</span><span class="shrink-0 tabular-nums text-zinc-500 dark:text-zinc-400">{{ $fmt($d['used']) }} {{ Str::plural('day', $d['used'] == 1 ? 1 : 2) }}</span></div>
                                <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full" style="width: {{ round($d['used'] / $maxUsed * 100) }}%; background: {{ $d['color'] ?: '#7c3aed' }}"></div></div>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if($otherTypes->isNotEmpty())
                    <button type="button" class="mt-3 self-start text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400" x-on:click="allTypes = ! allTypes" x-text="allTypes ? 'Hide other types' : 'Show all types'"></button>
                    <ul x-show="allTypes" x-cloak class="mt-2 space-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                        @foreach($otherTypes as $d)
                            <li class="flex justify-between gap-2"><span class="truncate">{{ $d['name'] }}</span><span class="shrink-0 tabular-nums">0 used · {{ $fmt($d['left']) }} left</span></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </section>

    {{-- ═══ I. RECENT LEAVE APPLICATIONS ═══ the latest five; Leave History holds them all --}}
    @php
        $recentChip = fn (string $status) => match ($status) {
            'approved' => ['Approved', 'emerald'],
            'rejected' => ['Rejected', 'rose'],
            'cancelled' => ['Cancelled', 'zinc'],
            'pending_hr' => ['Pending HR', 'amber'],
            'more_info_requested' => ['Needs info', 'blue'],
            default => [ucfirst(str_replace('_', ' ', $status)), 'amber'],
        };
        $recentLegacy = fn ($r) => ($r->leaveType && ($r->leaveType->trashed() || ($r->leaveType->availability ?? 'active') !== 'active'))
            || ($v2 && ! $r->main_category && ! $r->isCombined());
        $recentView = fn ($r) => "go('history', 'requests', true); setTimeout(() => ['request-{$r->id}', 'request-card-{$r->id}'].map(i => document.getElementById(i)).find(e => e && e.offsetParent)?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 350)";
        $recentRange = fn ($r) => $r->start_date->format('d M').($r->end_date->eq($r->start_date) ? '' : ' – '.$r->end_date->format('d M')).' '.$r->end_date->format('Y');
        $th = 'px-3 py-2.5 text-xs font-semibold text-zinc-500 dark:text-zinc-400';
    @endphp
    <section class="ui-surface overflow-hidden" x-data="{ recent: 'requests' }" data-recent-applications>
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 dark:border-white/5">
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Recent Leave Applications</h2>
                <div class="flex gap-1">
                    @foreach(['requests' => 'My Requests', 'encashments' => 'Encashment History'] as $key => $label)
                        <button type="button" x-on:click="recent = '{{ $key }}'"
                            x-bind:class="recent === '{{ $key }}' ? 'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-300' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200'"
                            class="rounded-lg border px-2.5 py-1 text-xs font-semibold transition">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <button type="button" x-on:click="go('history', recent, true)" class="inline-flex items-center gap-1 text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">View All <flux:icon.chevron-right class="size-3" /></button>
        </div>

        {{-- My requests --}}
        <div x-show="recent === 'requests'">
            @if($recentRequests->isEmpty())
                <div class="flex flex-col items-center px-5 py-10 text-center">
                    <flux:icon.document-magnifying-glass class="size-7 text-zinc-300 dark:text-zinc-600" />
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">No leave applications yet.</p>
                </div>
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-zinc-50 dark:bg-white/5">
                            <tr><th class="{{ $th }} pl-5">ID</th><th class="{{ $th }}">Leave Type</th><th class="{{ $th }}">Reason</th><th class="{{ $th }}">Date Range</th><th class="{{ $th }} text-center">Days</th><th class="{{ $th }}">Status</th><th class="{{ $th }}">Approver</th><th class="{{ $th }}">Applied On</th><th class="{{ $th }} pr-5 text-right">Actions</th></tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                            @foreach($recentRequests as $r)
                                @php [$chipLabel, $chipColor] = $recentChip($r->status); @endphp
                                <tr wire:key="recent-{{ $r->id }}">
                                    <td class="whitespace-nowrap px-3 py-3 pl-5 text-xs font-semibold text-zinc-500 dark:text-zinc-400">LR-{{ str_pad($r->id, 4, '0', STR_PAD_LEFT) }}</td>
                                    <td class="px-3 py-3">
                                        <span class="block whitespace-nowrap font-medium text-zinc-900 dark:text-white">{{ $r->leaveType?->name ?? 'Leave' }}</span>
                                        @if($r->isCombined())<x-pulse.badge color="violet" class="mt-1">Combined</x-pulse.badge>@elseif($recentLegacy($r))<x-pulse.badge class="mt-1">Legacy</x-pulse.badge>@endif
                                    </td>
                                    <td style="max-width: 12rem" class="truncate px-3 py-3 text-zinc-500 dark:text-zinc-400" title="{{ $r->reason }}">{{ $r->reason ?: '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-zinc-700 dark:text-zinc-200">{{ $recentRange($r) }}</td>
                                    <td class="px-3 py-3 text-center tabular-nums">{{ $fmt($r->days) }}</td>
                                    <td class="px-3 py-3"><x-pulse.badge :color="$chipColor">{{ $chipLabel }}</x-pulse.badge></td>
                                    <td class="whitespace-nowrap px-3 py-3 text-zinc-600 dark:text-zinc-300">{{ ($r->hrReviewer ?? $r->reviewer)?->name ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 text-zinc-500 dark:text-zinc-400">{{ $r->created_at->format('d M Y') }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 pr-5 text-right">
                                        <flux:button size="xs" variant="ghost" x-on:click="{{ $recentView($r) }}">View</flux:button>
                                        @if(in_array($r->status, ['pending', 'pending_hr', 'approved'], true))
                                            <flux:button size="xs" variant="ghost" wire:click="cancelRequest({{ $r->id }})" wire:confirm="Cancel this leave request?">Cancel</flux:button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <ul class="divide-y divide-zinc-100 md:hidden dark:divide-white/5">
                    @foreach($recentRequests as $r)
                        @php [$chipLabel, $chipColor] = $recentChip($r->status); @endphp
                        <li wire:key="recent-card-{{ $r->id }}" class="space-y-1 px-4 py-3">
                            <div class="flex items-start justify-between gap-2">
                                <p class="min-w-0 text-sm font-semibold text-zinc-900 dark:text-white">{{ $r->leaveType?->name ?? 'Leave' }}</p>
                                <x-pulse.badge :color="$chipColor">{{ $chipLabel }}</x-pulse.badge>
                            </div>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">LR-{{ str_pad($r->id, 4, '0', STR_PAD_LEFT) }} · {{ $recentRange($r) }} · {{ $fmt($r->days) }} {{ Str::plural('day', (float) $r->days == 1 ? 1 : 2) }}</p>
                            <div class="flex flex-wrap items-center gap-2">
                                @if($r->isCombined())<x-pulse.badge color="violet">Combined</x-pulse.badge>@elseif($recentLegacy($r))<x-pulse.badge>Legacy</x-pulse.badge>@endif
                                <flux:button size="xs" variant="ghost" x-on:click="{{ $recentView($r) }}">View</flux:button>
                                @if(in_array($r->status, ['pending', 'pending_hr', 'approved'], true))
                                    <flux:button size="xs" variant="ghost" wire:click="cancelRequest({{ $r->id }})" wire:confirm="Cancel this leave request?">Cancel</flux:button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Encashment history --}}
        <div x-show="recent === 'encashments'" x-cloak>
            @forelse($encashments->take(5) as $enc)
                @php [$chipLabel, $chipColor] = $recentChip($enc->status); @endphp
                <div wire:key="recent-enc-{{ $enc->id }}" class="flex items-center justify-between gap-3 border-b border-zinc-100 px-5 py-3 text-sm last:border-0 dark:border-white/5">
                    <span class="min-w-0">
                        <span class="block truncate font-medium text-zinc-900 dark:text-white">{{ $enc->leaveType?->name ?? 'Leave' }}</span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $fmt($enc->requested_days) }} {{ Str::plural('day', (float) $enc->requested_days == 1 ? 1 : 2) }} · {{ $enc->created_at->format('d M Y') }}</span>
                    </span>
                    <x-pulse.badge :color="$chipColor">{{ $chipLabel }}</x-pulse.badge>
                </div>
            @empty
                <div class="flex flex-col items-center px-5 py-10 text-center">
                    <flux:icon.banknotes class="size-7 text-zinc-300 dark:text-zinc-600" />
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">No encashment requests yet.</p>
                </div>
            @endforelse
        </div>
    </section>

    {{-- Holiday work request and conversation dialogs --}}
        {{-- Holiday Work Request modal --}}
        @if($showHolidayWork)
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.set('showHolidayWork', false)">
                <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showHolidayWork', false)"></div>
                <div class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white dark:bg-zinc-900 p-6 shadow-xl ring ring-black/5 dark:bg-zinc-900">
                    <div class="mb-4 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex size-8 items-center justify-center rounded-xl bg-teal-50 text-teal-600"><flux:icon.briefcase class="size-4" /></span>
                            <flux:heading size="lg">Request to Work on Holiday</flux:heading>
                        </div>
                        <button @click="$wire.set('showHolidayWork', false)" class="text-zinc-400 hover:text-zinc-600 dark:text-zinc-300"><flux:icon.x-mark class="size-5" /></button>
                    </div>
                    <div class="space-y-3">
                        <flux:input wire:model="hw_date" type="date" label="Holiday Date" />
                        @error('hw_date')<p class="text-xs text-rose-500">{{ $message }}</p>@enderror
                        <flux:textarea wire:model="hw_reason" label="Reason" rows="2" placeholder="Why do you need to work this holiday?" />
                        @error('hw_reason')<p class="text-xs text-rose-500">{{ $message }}</p>@enderror
                        <div class="grid grid-cols-2 gap-3">
                            <x-clean-select
                                model="hw_location"
                                label="Work Location"
                                :live="false"
                                :options="[['value' => 'office', 'label' => 'Office'], ['value' => 'wfh', 'label' => 'Work From Home'], ['value' => 'client_site', 'label' => 'Client Site']]"
                            />
                            <flux:input wire:model="hw_hours" type="number" step="0.5" min="0.5" max="24" label="Expected Hours" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <flux:input wire:model="hw_project" label="Project" placeholder="Optional" />
                            @php $hwPayLabels = App\Models\HolidayPaySetting::payTypeLabels(); @endphp
                            <x-clean-select
                                model="hw_pay_type"
                                label="Pay Type"
                                :live="false"
                                :options="collect($holidayPaySettings->enabledPayTypes())->map(fn ($val) => ['value' => $val, 'label' => $hwPayLabels[$val] ?? ucfirst($val)])->all()"
                            />
                        </div>
                        <flux:textarea wire:model="hw_comments" label="Comments" rows="2" placeholder="Optional" />
                        <p class="text-[11px] text-zinc-400">On approval this records a holiday-worked attendance and your chosen pay (overtime record or comp-off credit).</p>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <button @click="$wire.set('showHolidayWork', false)" class="rounded-xl border border-zinc-200 dark:border-zinc-800 px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 transition hover:bg-zinc-50 dark:bg-zinc-800/50 dark:border-zinc-700 dark:text-zinc-300">Cancel</button>
                        <flux:button wire:click="submitHolidayWork" variant="primary">Send to Manager &amp; HR</flux:button>
                    </div>
                </div>
            </div>
        @endif

        {{-- Conversation thread modal — two-way messaging with the reviewer --}}
        @if($conversationId && $conversationRequest)
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.closeConversation()">
                <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.closeConversation()"></div>
                <div class="relative flex max-h-[85vh] w-full max-w-lg flex-col rounded-2xl bg-white dark:bg-zinc-900 shadow-xl ring ring-black/5 dark:bg-zinc-900">
                    <div class="flex items-center justify-between border-b border-zinc-100 dark:border-zinc-800 p-5 dark:border-zinc-800">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex size-8 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 dark:bg-indigo-900/20 dark:text-indigo-400"><flux:icon.chat-bubble-left-right class="size-4" /></span>
                            <div>
                                <flux:heading size="lg">Conversation</flux:heading>
                                <p class="text-[11px] text-zinc-400">{{ $conversationRequest->leaveType?->name }} · @userShortDate($conversationRequest->start_date)–@userDate($conversationRequest->end_date)</p>
                            </div>
                        </div>
                        <button @click="$wire.closeConversation()" class="text-zinc-400 hover:text-zinc-600 dark:text-zinc-300"><flux:icon.x-mark class="size-5" /></button>
                    </div>

                    {{-- Context banner: rejection remark / more-info prompt --}}
                    @if($conversationRequest->status === 'rejected')
                        <div class="mx-5 mt-4 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-xs dark:border-rose-900/40 dark:bg-rose-950/20">
                            <p class="font-bold text-rose-800 dark:text-rose-300">This request was rejected.</p>
                            @if($conversationRequest->reviewer_comment)
                                <p class="mt-0.5 italic text-rose-600 dark:text-rose-400">“{{ $conversationRequest->reviewer_comment }}”</p>
                            @endif
                            <p class="mt-1 text-rose-600 dark:text-rose-400">Message HR &amp; Admin below (with an attachment if needed) to appeal or ask for a review.</p>
                        </div>
                    @elseif($conversationRequest->status === 'more_info_requested' && $conversationRequest->reviewer_comment)
                        <div class="mx-5 mt-4 rounded-xl border border-orange-200 bg-orange-50 px-3.5 py-2.5 text-xs dark:border-orange-900/40 dark:bg-orange-950/20">
                            <p class="font-bold text-orange-800 dark:text-orange-300">More information requested</p>
                            <p class="mt-0.5 italic text-orange-600 dark:text-orange-400">“{{ $conversationRequest->reviewer_comment }}”</p>
                        </div>
                    @endif

                    {{-- Thread --}}
                    <div class="flex-1 space-y-3 overflow-y-auto p-5">
                        @php $meId = auth()->id(); @endphp
                        @forelse($conversationRequest->messages->sortBy('created_at') as $msg)
                            @php $mine = $msg->user_id === $meId; @endphp
                            <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[80%] rounded-2xl px-3.5 py-2.5 text-sm {{ $mine ? 'bg-indigo-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-200 dark:bg-zinc-800 dark:text-zinc-200' }}">
                                    <div class="mb-0.5 flex items-center gap-2 text-[10px] font-bold uppercase tracking-wide {{ $mine ? 'text-indigo-100' : 'text-zinc-400' }}">
                                        <span>{{ $mine ? 'You' : ($msg->user?->name ?? 'HR') }}</span>
                                        <span class="font-medium normal-case">{{ $msg->created_at->format('d M, H:i') }}</span>
                                    </div>
                                    @if($msg->body)
                                        <p class="whitespace-pre-line leading-snug">{{ $msg->body }}</p>
                                    @endif
                                    @if($msg->attachment_path)
                                        <a href="{{ asset('storage/'.$msg->attachment_path) }}" target="_blank"
                                            class="mt-1.5 inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-semibold {{ $mine ? 'bg-white/20 dark:bg-zinc-900/20 text-white hover:bg-white/30' : 'bg-white dark:bg-zinc-900 text-indigo-600 hover:bg-zinc-50 dark:bg-zinc-800/50 dark:bg-zinc-700 dark:text-indigo-300' }}">
                                            <flux:icon.paper-clip class="size-3" /> {{ $msg->attachment_name ?? 'Attachment' }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="py-8 text-center text-sm text-zinc-400">No messages yet.</p>
                        @endforelse
                    </div>

                    {{-- Composer --}}
                    <div class="space-y-2 border-t border-zinc-100 dark:border-zinc-800 p-4 dark:border-zinc-800">
                        <flux:textarea wire:model="conversation_body" rows="2" placeholder="Write a message…" />
                        @error('conversation_body')<p class="text-xs text-rose-500">{{ $message }}</p>@enderror
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <input type="file" wire:model="conversation_attachment" accept=".pdf,.jpg,.jpeg,.png,.webp"
                                    class="block w-full text-xs text-zinc-500 dark:text-zinc-400 file:mr-2 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-2.5 file:py-1.5 file:text-xs file:font-bold file:text-indigo-600 hover:file:bg-indigo-100 dark:text-zinc-400">
                                @error('conversation_attachment')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                                @if($conversation_attachment)
                                    <p class="mt-1 truncate text-[11px] text-zinc-500 dark:text-zinc-400">{{ $conversation_attachment->getClientOriginalName() }}</p>
                                @endif
                            </div>
                            <flux:button wire:click="postConversationMessage" variant="primary" class="shrink-0">Send</flux:button>
                        </div>
                        <p class="text-[10px] text-zinc-400">PDF or image — max 5 MB.</p>
                    </div>
                </div>
            </div>
        @endif
</div>


    {{-- â"€â"€â"€ REQUEST LEAVE MODAL â"€â"€â"€ --}}
    @if($showRequestModal)
        {{-- Upload state for the supporting document: Submit waits until the
             file has reached the server (a phone upload takes seconds), a file of
             the wrong type or size is refused before it is sent, and a failed
             upload says so instead of looking attached. --}}
        <x-pulse.modal title="Request Time Off" subtitle="Submit a new leave application for review." close="$wire.closeRequestModal()" width="sm:max-w-lg"
            x-data="{
                uploading: false, progress: 0, uploadError: '',
                maxBytes: {{ \App\Livewire\TimeOff\MyTimeOff::ATTACHMENT_MAX_KB * 1024 }},
                types: {{ \Illuminate\Support\Js::from(\App\Livewire\TimeOff\MyTimeOff::ATTACHMENT_TYPES) }},
                check(event) {
                    const input = event.target;
                    if (input.id !== 'leave-attachment' || ! input.files || ! input.files[0]) return;
                    const file = input.files[0];
                    const ext = (file.name.split('.').pop() || '').toLowerCase();
                    this.uploadError = ! this.types.includes(ext) ? 'Please upload a PDF, JPG, JPEG or PNG.'
                        : (file.size > this.maxBytes ? 'Attachment must be 5 MB or smaller.' : '');
                    if (this.uploadError) { input.value = ''; event.stopImmediatePropagation(); }
                },
            }"
            x-on:change.capture="check($event)"
            x-on:livewire-upload-start="uploading = true; progress = 0; uploadError = ''"
            x-on:livewire-upload-progress="progress = $event.detail.progress"
            x-on:livewire-upload-finish="uploading = false; progress = 100"
            x-on:livewire-upload-cancel="uploading = false"
            x-on:livewire-upload-error="uploading = false; uploadError = 'The attachment could not be uploaded. Check your connection and choose the file again.'; if ($refs.attachmentInput) $refs.attachmentInput.value = ''"
            x-on:submit.capture="if (uploading) { $event.preventDefault(); $event.stopImmediatePropagation(); }">
                <div class="space-y-6">
                    <form id="leave-request-form" wire:submit="submitRequest" class="space-y-5" novalidate>

                        {{-- Leave Type --}}
                        <x-clean-select
                            model="leave_type_id"
                            label="Leave Type"
                            placeholder="Select type…"
                            :required="true"
                            :options="$leaveTypes->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->all()"
                        />
                        @error('leave_type_id') <flux:error>{{ $message }}</flux:error> @enderror

                        {{-- Balance preview for selected type --}}
                        @if($selectedType && $selectedSummary)
                            <div class="flex items-center gap-2 rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-2 text-xs text-indigo-700 dark:border-indigo-900/40 dark:bg-indigo-950/20 dark:text-indigo-300">
                                <flux:icon.information-circle class="size-3.5 shrink-0" />
                                <span>Available to request:
                                    <strong @class(['text-rose-600' => $selectedSummary['available_to_request'] < 0])>{{ $fmt($selectedSummary['available_to_request']) }} day(s)</strong>
                                    @if($selectedSummary['pending'] > 0)
                                        <span class="text-indigo-500">({{ $fmt($selectedSummary['pending']) }} already pending)</span>
                                    @endif
                                </span>
                            </div>
                        @endif

                        {{-- Dates --}}
                        <x-pulse.date-range start="start_date" end="end_date" start-label="Start Date" end-label="End Date"
                            :required="true" :live="true" :min="$this->earliestLeaveDate()" />
                        @if($start_date && $start_date < now()->toDateString())
                            <p class="-mt-2 flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400" data-backdated-note>
                                <flux:icon.clock class="size-3.5 shrink-0" /> Past dates: this is a backdated request. It goes to your approver like any other.
                            </p>
                        @endif

                        {{-- Live day count for the chosen range --}}
                        @if($rangeDays !== null && $rangeWeekendDays->isEmpty() && $rangeHolidays->isEmpty())
                            <div class="flex items-center justify-between rounded-xl border border-brand-100 bg-brand-50 px-3.5 py-2.5 dark:border-brand-900/40 dark:bg-brand-950/20">
                                <span class="flex items-center gap-2 text-xs font-semibold text-brand-700 dark:text-brand-300">
                                    <flux:icon.calendar-days class="size-4 shrink-0" />
                                    {{ $is_half_day ? 'Half day' : 'This request counts as' }}
                                </span>
                                <span class="text-sm font-black text-brand-700 dark:text-brand-300">
                                    {{ rtrim(rtrim(number_format($rangeDays, 1), '0'), '.') }} {{ (float) $rangeDays === 1.0 ? 'day' : 'days' }}
                                    @if($selectedType?->is_sandwich_applicable && ! $is_half_day)
                                        <span class="ml-1 text-[10px] font-semibold text-brand-500">(incl. weekends)</span>
                                    @elseif(! $is_half_day)
                                        <span class="ml-1 text-[10px] font-semibold text-brand-500">(working days)</span>
                                    @endif
                                </span>
                            </div>
                        @endif

                        {{-- MDL warning — the company is shut; no leave is taken on these dates --}}
                        @if($rangeMdl->isNotEmpty())
                            <div class="flex items-start gap-2.5 rounded-xl border border-orange-200 bg-orange-50 px-3.5 py-2.5 dark:border-orange-900/40 dark:bg-orange-950/20">
                                <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0 text-orange-500" />
                                <p class="text-xs text-orange-700 dark:text-orange-300">
                                    <span class="font-bold">Mandatory December Leave:</span>
                                    @foreach($rangeMdl as $md)<span class="font-semibold">@userShortDate($md->date)</span>@if(! $loop->last), @endif @endforeach
                                    {{ $rangeMdl->count() === 1 ? 'is a' : 'are' }} company shutdown {{ \Illuminate\Support\Str::plural('day', $rangeMdl->count()) }} and not charged to your leave. Please exclude {{ $rangeMdl->count() === 1 ? 'it' : 'them' }}.
                                </p>
                            </div>
                        @endif

                        {{-- Company holiday warning — holidays cannot be included in leave --}}
                        @if($rangeHolidays->isNotEmpty())
                            <div class="flex items-start gap-2.5 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2.5 dark:border-rose-900/40 dark:bg-rose-950/20">
                                <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0 text-rose-500" />
                                <div class="text-xs">
                                    <p class="font-bold text-rose-800 dark:text-rose-300">This date is already a company holiday.</p>
                                    <p class="mt-0.5 text-rose-600 dark:text-rose-400">
                                        Your range includes
                                        @foreach($rangeHolidays as $rh)<span class="font-semibold">@userShortDate(\Carbon\Carbon::parse($rh->date)) ({{ $rh->name }})</span>@if(! $loop->last), @endif @endforeach.
                                        Please exclude {{ $rangeHolidays->count() === 1 ? 'it' : 'them' }} from your leave dates.
                                    </p>
                                </div>
                            </div>
                        @endif

                        {{-- Weekend warning — Sat/Sun are non-working days, cannot be a leave start/end --}}
                        @if($rangeWeekendDays->isNotEmpty())
                            <div class="flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-2.5 dark:border-amber-900/40 dark:bg-amber-950/20">
                                <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0 text-amber-500" />
                                <div class="text-xs">
                                    <p class="font-bold text-amber-800 dark:text-amber-300">Weekends are non-working days.</p>
                                    <p class="mt-0.5 text-amber-600 dark:text-amber-400">
                                        @foreach($rangeWeekendDays as $wd)<span class="font-semibold">{{ $wd }}</span>@if(! $loop->last), @endif @endforeach
                                        {{ $rangeWeekendDays->count() === 1 ? 'is a Saturday/Sunday' : 'are Saturdays/Sundays' }}. You can't take leave on a weekend — to work a weekend use “Request to Work on Holiday” instead.
                                    </p>
                                </div>
                            </div>
                        @endif

                        {{-- Half Day --}}
                        @if(!$selectedType || $selectedType->allow_half_day)
                            <div
                                class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/30 space-y-3">
                                <div class="flex items-center gap-3">
                                    <flux:checkbox wire:model.live="is_half_day" id="half_day_toggle" />
                                    <label for="half_day_toggle"
                                        class="cursor-pointer text-sm font-semibold text-zinc-900 dark:text-white dark:text-zinc-100">Half-Day
                                        Leave <span class="text-xs font-normal text-zinc-400">(deducts 0.5 days)</span></label>
                                </div>
                                @if($is_half_day)
                                    <div class="flex gap-4 pl-7">
                                        <label
                                            class="flex items-center gap-2 cursor-pointer text-sm text-zinc-700 dark:text-zinc-200 dark:text-zinc-300">
                                            <input type="radio" wire:model="half_day_period" value="first_half"
                                                class="accent-indigo-600" />
                                            First Half
                                        </label>
                                        <label
                                            class="flex items-center gap-2 cursor-pointer text-sm text-zinc-700 dark:text-zinc-200 dark:text-zinc-300">
                                            <input type="radio" wire:model="half_day_period" value="second_half"
                                                class="accent-indigo-600" />
                                            Second Half
                                        </label>
                                    </div>
                                    @error('half_day_period')
                                        <p class="pl-7 text-xs text-red-500">{{ $message }}</p>
                                    @enderror
                                @endif
                            </div>
                        @endif

                        {{-- Paid / Unpaid choice --}}
                        @if($selectedType && ($selectedType->allow_paid_request || $selectedType->allow_unpaid_request))
                            <div
                                class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/30">
                                <p class="mb-2 text-sm font-semibold text-zinc-700 dark:text-zinc-200 dark:text-zinc-300">Leave Payment Type</p>
                                <div class="flex gap-4">
                                    @if($selectedType->allow_paid_request)
                                        <label
                                            class="flex items-center gap-2 cursor-pointer text-sm text-zinc-700 dark:text-zinc-200 dark:text-zinc-300">
                                            <input type="radio" wire:model="requested_leave_status" value="paid"
                                                class="accent-indigo-600" />
                                            <span class="font-medium text-emerald-600 dark:text-emerald-400">Paid Leave</span>
                                        </label>
                                    @endif
                                    @if($selectedType->allow_unpaid_request)
                                        <label
                                            class="flex items-center gap-2 cursor-pointer text-sm text-zinc-700 dark:text-zinc-200 dark:text-zinc-300">
                                            <input type="radio" wire:model="requested_leave_status" value="unpaid"
                                                class="accent-indigo-600" />
                                            <span class="font-medium text-amber-600 dark:text-amber-400">Unpaid Leave</span>
                                        </label>
                                    @endif
                                </div>
                                @error('requested_leave_status')
                                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

                        {{-- Reason --}}
                        <div>
                            <x-pulse.label for="leave-reason" required>Reason</x-pulse.label>
                            <flux:textarea id="leave-reason" wire:model="reason" aria-required="true"
                                placeholder="Please describe why you need time off..." rows="2" />
                            <flux:error name="reason" />
                        </div>

                        {{-- Employee Remarks --}}
                        <div>
                            <x-pulse.label for="leave-remarks" optional>Additional Remarks</x-pulse.label>
                            <flux:textarea id="leave-remarks" wire:model="employee_remarks" maxlength="1000"
                                placeholder="Any other notes for the approver..." rows="2" />
                            <flux:error name="employee_remarks" />
                        </div>

                        {{-- Attachment — one supporting document, previewed before submit --}}
                        <div class="rounded-xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 p-4 dark:border-zinc-800 dark:bg-zinc-900/40">
                            <div class="mb-2 flex items-center justify-between">
                                <x-pulse.label for="leave-attachment" class="!mb-0" :required="(bool) $selectedType?->attachment_required" :optional="! $selectedType?->attachment_required">Attachment</x-pulse.label>
                                <span class="text-[10px] font-semibold text-zinc-400">PDF or image — max 5 MB</span>
                            </div>
                            <input id="leave-attachment" x-ref="attachmentInput" type="file" wire:model="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp" @if($selectedType?->attachment_required) aria-required="true" @endif
                                aria-describedby="leave-attachment-status"
                                class="block w-full text-xs text-zinc-500 dark:text-zinc-400 file:mr-2 file:rounded-lg file:border-0 file:bg-orange-50 file:px-2.5 file:py-1.5 file:text-xs file:font-bold file:text-orange-600 hover:file:bg-orange-100 dark:text-zinc-400">
                            <div id="leave-attachment-status" aria-live="polite">
                                <div x-show="uploading" x-cloak class="mt-2" data-upload-progress>
                                    <p class="text-xs font-semibold text-orange-600 dark:text-orange-400">Uploading attachment… <span x-text="progress + '%'"></span></p>
                                    <div class="mt-1 h-1 overflow-hidden rounded-full bg-orange-100 dark:bg-orange-500/20"><div class="h-full rounded-full bg-orange-500 transition-all" x-bind:style="'width: ' + progress + '%'"></div></div>
                                </div>
                                <p x-show="uploadError" x-cloak x-text="uploadError" class="mt-1.5 text-xs font-medium text-rose-600 dark:text-rose-400" data-upload-error></p>
                                {{-- A server message from before this file was chosen is not shown while it uploads. --}}
                                <div x-show="! uploading && ! uploadError">@error('attachment') <flux:error>{{ $message }}</flux:error> @enderror</div>
                            </div>
                            @if($attachment && ! $errors->has('attachment'))
                                <div class="mt-1.5 flex items-center gap-2 rounded-lg bg-white dark:bg-zinc-900 px-2 py-1.5 text-[11px] dark:bg-zinc-800" data-attachment-ready>
                                    @if(str_starts_with($attachment->getMimeType() ?? '', 'image/'))
                                        <img src="{{ $attachment->temporaryUrl() }}" class="size-8 rounded object-cover" alt="preview">
                                    @else
                                        <flux:icon.document-text class="size-4 shrink-0 text-orange-400" />
                                    @endif
                                    <span class="min-w-0 flex-1 truncate text-zinc-600 dark:text-zinc-300">{{ $attachment->getClientOriginalName() }}</span>
                                    <span class="shrink-0 font-semibold text-emerald-600 dark:text-emerald-400">Uploaded ✓</span>
                                    <button type="button" wire:click="removeAttachment" x-on:click="if ($refs.attachmentInput) $refs.attachmentInput.value = ''"
                                        class="shrink-0 font-semibold text-zinc-500 underline-offset-2 hover:text-rose-600 hover:underline" data-remove-attachment>Remove</button>
                                </div>
                            @endif
                            <p class="mt-2 text-[10px] text-zinc-400">Attachments are automatically deleted 30 days after the leave is approved.</p>
                        </div>

                        {{-- Leave Assistant: explains the request from live data only.
                             Nothing here decides anything — the submit is validated
                             by the leave rules on the server. --}}
                        @if($assistant)
                            @php
                                $tone = [
                                    'info' => ['information-circle', 'text-zinc-600 dark:text-zinc-300', 'text-zinc-400'],
                                    'success' => ['check-circle', 'text-emerald-700 dark:text-emerald-300', 'text-emerald-500'],
                                    'warning' => ['exclamation-triangle', 'text-amber-700 dark:text-amber-300', 'text-amber-500'],
                                    'error' => ['x-circle', 'text-rose-700 dark:text-rose-300', 'text-rose-500'],
                                ];
                            @endphp
                            <div class="rounded-xl border border-brand-100 bg-brand-50/50 p-3.5 dark:border-brand-900/40 dark:bg-brand-950/10" data-testid="leave-assistant">
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-brand-700 dark:text-brand-300">
                                        <flux:icon.sparkles class="size-4" /> Leave Assistant
                                    </p>
                                    @if($assistantAiAvailable)
                                        <button type="button" wire:click="explainWithAi" wire:loading.attr="disabled" wire:target="explainWithAi"
                                            class="text-[11px] font-semibold text-brand-700 hover:underline dark:text-brand-300">
                                            <span wire:loading.remove wire:target="explainWithAi">Explain in plain words</span>
                                            <span wire:loading wire:target="explainWithAi">Thinking…</span>
                                        </button>
                                    @endif
                                </div>
                                <ul class="space-y-1.5">
                                    @foreach($assistant['messages'] as $message)
                                        @php [$icon, $text, $iconColor] = $tone[$message['tone']] ?? $tone['info']; @endphp
                                        <li class="flex items-start gap-2 text-xs {{ $text }}">
                                            <flux:icon :name="$icon" class="mt-px size-4 shrink-0 {{ $iconColor }}" />
                                            <span>{{ $message['text'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                @if($assistant['suggestion'] && (int) $assistant['suggestion']['type_id'] !== (int) $leave_type_id)
                                    <button type="button" wire:click="useSuggestedType({{ $assistant['suggestion']['type_id'] }})"
                                        class="mt-2 inline-flex items-center gap-1 rounded-lg bg-white px-2.5 py-1 text-[11px] font-bold text-brand-700 shadow-sm ring-1 ring-brand-200 hover:bg-brand-50 dark:bg-zinc-900 dark:text-brand-300 dark:ring-brand-800">
                                        Use {{ $assistant['suggestion']['name'] }}
                                    </button>
                                @endif
                                @if($assistantAiText)
                                    <p class="mt-2 rounded-lg bg-white/70 p-2 text-xs text-zinc-700 dark:bg-zinc-900/60 dark:text-zinc-200">{{ $assistantAiText }}</p>
                                @endif
                                <p class="mt-2 text-[10px] text-zinc-400">Figures come from your live balance and the leave rules. Final checks happen when you submit.</p>
                            </div>
                        @endif

                        {{-- Validation summary — surfaces EVERY blocked-submit reason
                             (missing reason, bad dates, insufficient balance, …) right
                             next to the button the user just clicked. --}}
                        @if($errors->any())
                            <div
                                class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700 dark:bg-red-950/30 dark:border-red-800 dark:text-red-400">
                                <p class="mb-1 font-semibold">Please fix the following before submitting:</p>
                                <ul class="list-inside list-disc space-y-0.5">
                                    @foreach($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                    </form>
                </div>

            <x-slot:footer>
                <button type="button" x-on:click="$wire.closeRequestModal()"
                    class="rounded-xl border border-zinc-200 px-4 py-2 text-sm font-semibold text-zinc-600 transition-colors hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-700">Cancel</button>
                {{-- Disabled while the attachment uploads (Alpine upload state above),
                     while submitting, or when the range cannot be requested. --}}
                <button type="submit" form="leave-request-form" wire:loading.attr="disabled" wire:target="submitRequest" data-submit-leave
                    x-bind:disabled="uploading || @js($rangeHolidays->isNotEmpty() || $rangeMdl->isNotEmpty() || $rangeWeekendDays->isNotEmpty())"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-600 px-5 py-2 text-sm font-bold text-white transition-colors hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60">
                    <span x-show="uploading" x-cloak>Uploading attachment…</span>
                    <span x-show="! uploading" wire:loading.remove wire:target="submitRequest">{{ $rangeHolidays->isNotEmpty() ? 'Holiday in range' : ($rangeMdl->isNotEmpty() ? 'MDL date in range' : ($rangeWeekendDays->isNotEmpty() ? 'Weekend selected' : 'Submit Request')) }}</span>
                    <span wire:loading wire:target="submitRequest">Submitting...</span>
                </button>
            </x-slot:footer>
        </x-pulse.modal>
    @endif

    {{-- ─── ENCASHMENT MODAL ─── --}}
    <flux:modal name="encashment-modal" class="max-w-lg" @close="closeEncashModal">
        <div class="space-y-6">
            <div class="flex items-start gap-3">
                <div class="shrink-0 rounded-xl bg-amber-50 p-2.5 dark:bg-amber-900/20">
                    <flux:icon.banknotes class="size-5 text-amber-600 dark:text-amber-400" />
                </div>
                <div>
                    <flux:heading size="lg">Encash Leave Balance</flux:heading>
                    <flux:subheading>Convert unused paid leave into salary payout. Requires HR then Finance approval.
                    </flux:subheading>
                </div>
            </div>

            <div class="space-y-4">
                <flux:select wire:model.live="encash_leave_type_id" label="Select Leave Type" required>
                    <option value="">— Choose leave type —</option>
                    @foreach($encashableTypes as $type)
                        <option value="{{ $type->id }}">
                            {{ $type->name }}
                            ({{ $type->available_for_encashment }}d
                            available{{ $type->encashment_rate_multiplier != 1.00 ? ' · ' . number_format($type->encashment_rate_multiplier, 2) . '× rate' : '' }})
                        </option>
                    @endforeach
                </flux:select>

                @php $selectedEncashType = $encashableTypes->firstWhere('id', (int) $encash_leave_type_id); @endphp

                @if($selectedEncashType)
                    <div class="grid grid-cols-2 gap-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/60 p-3 text-xs">
                        <div>
                            <span class="text-zinc-400 block mb-0.5">Carry-forward available</span>
                            <span
                                class="font-bold text-zinc-800 dark:text-zinc-100 dark:text-zinc-200">{{ $selectedEncashType->cf_available }}d</span>
                        </div>
                        @if($selectedEncashType->allow_current_year_encashment)
                            <div>
                                <span class="text-zinc-400 block mb-0.5">Current-year available</span>
                                <span
                                    class="font-bold text-zinc-800 dark:text-zinc-100 dark:text-zinc-200">{{ $selectedEncashType->cy_available }}d</span>
                            </div>
                        @endif
                        <div>
                            <span class="text-zinc-400 block mb-0.5">Total encashable</span>
                            <span
                                class="font-bold text-emerald-600">{{ $selectedEncashType->available_for_encashment }}d</span>
                        </div>
                        @if($selectedEncashType->remaining_cap !== null)
                            <div>
                                <span class="text-zinc-400 block mb-0.5">Remaining cap this year</span>
                                <span
                                    class="font-bold text-zinc-800 dark:text-zinc-100 dark:text-zinc-200">{{ $selectedEncashType->remaining_cap }}d</span>
                            </div>
                        @endif
                        @if($selectedEncashType->encashment_rate_multiplier != 1.00)
                            <div>
                                <span class="text-zinc-400 block mb-0.5">Rate multiplier</span>
                                <span
                                    class="font-bold text-zinc-800 dark:text-zinc-100 dark:text-zinc-200">{{ number_format($selectedEncashType->encashment_rate_multiplier, 2) }}×</span>
                            </div>
                        @endif
                    </div>
                @endif

                <flux:input wire:model="encash_days" label="Days to Encash" type="number" step="0.5" min="0.5"
                    suffix="Days" required />

                <div
                    class="flex gap-3 rounded-xl border border-amber-100 bg-amber-50 p-3 dark:border-amber-800/30 dark:bg-amber-900/10">
                    <flux:icon.information-circle class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                    <p class="text-xs leading-relaxed text-amber-700 dark:text-amber-400">
                        Carry-forward (previous-year) days are encashed first. Payout = daily rate × multiplier,
                        included in payroll for the current month.
                    </p>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" wire:click="closeEncashModal"
                    class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-800 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:bg-zinc-800/50 dark:hover:bg-zinc-700 transition-colors">
                    Cancel
                </button>
                <flux:button wire:click="submitEncashment()" variant="primary">Submit Request</flux:button>
            </div>
        </div>
    </flux:modal>


</flux:main>