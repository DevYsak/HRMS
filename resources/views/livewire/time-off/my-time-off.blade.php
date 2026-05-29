<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950 p-6 space-y-5" x-data="{ tab: 'requests' }">

    {{-- â”€â”€â”€ HERO HEADER â”€â”€â”€ --}}
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
                <h1 class="text-3xl font-black tracking-tight text-white">My Time Off</h1>
                <p class="mt-1.5 text-sm font-medium text-violet-200/80">Manage balances, apply for leave & track encashments.</p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-3">
                @if($encashableTypes->isNotEmpty())
                    <button @click="$flux.modal('encashment-modal').show()"
                        class="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-white/25 bg-white/15 px-4 py-2.5 text-sm font-bold text-white backdrop-blur-sm transition-all hover:bg-white/25">
                        <flux:icon.banknotes class="size-4 shrink-0" />
                        <span>Encash Leave</span>
                    </button>
                @endif
                <button wire:click="openRequestModal"
                    class="inline-flex cursor-pointer items-center gap-2 rounded-xl bg-white px-5 py-2.5 text-sm font-black text-violet-700 shadow-lg shadow-black/20 transition-all hover:bg-violet-50">
                    <flux:icon.plus class="size-4 shrink-0" />
                    <span>Apply for Leave</span>
                </button>
            </div>
        </div>
    </div>

    {{-- â”€â”€â”€ PENDING ALERT â”€â”€â”€ --}}
    @if(!empty($pendingCount) && $pendingCount > 0)
        <div class="flex items-center gap-4 rounded-2xl border border-amber-200 bg-gradient-to-r from-amber-50 to-orange-50 p-4 dark:border-amber-800/40 dark:from-amber-950/30 dark:to-orange-950/20">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 dark:bg-amber-900/40">
                <flux:icon.clock class="size-5 text-amber-600 dark:text-amber-400" />
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-bold text-amber-900 dark:text-amber-200">
                    {{ $pendingCount }} pending leave {{ Str::plural('request', $pendingCount) }} awaiting approval
                </p>
                <p class="mt-0.5 text-xs text-amber-700/70 dark:text-amber-400/70">
                    Your applications are under review. You'll be notified once actioned.
                </p>
            </div>
            <button wire:click="openRequestModal"
                class="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-bold text-white transition-colors hover:bg-amber-600">
                <flux:icon.plus class="size-3" />
                New Request
            </button>
        </div>
    @endif

    {{-- â”€â”€â”€ LEAVE BALANCE CARDS â”€â”€â”€ --}}
    {{-- Each card shows: Available days clearly, plus a plain breakdown row --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @forelse($balances as $balance)
            @php
                $allocated  = (float)$balance->allocated_days;
                $used       = (float)$balance->used_days;
                $encashed   = (float)($balance->encashed_days ?? 0);
                $carryFwd   = (float)($balance->carried_forward_days ?? 0);
                $compOff    = (float)($balance->comp_off_credits ?? 0);
                $available  = max(0, $allocated - $used - $encashed);
                $pct        = $allocated > 0 ? min(100, round(($used + $encashed) / $allocated * 100)) : 0;
                $color      = $balance->leaveType->color ?? '#7c3aed';
                $isLow      = $available <= 2 && $allocated > 0;
            @endphp
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm border dark:bg-zinc-900 dark:border-zinc-800 transition-all duration-200 hover:shadow-md"
                 style="border-color: {{ $color }}40">

                {{-- Colored top band --}}
                <div class="px-5 pt-4 pb-3" style="background: linear-gradient(135deg, {{ $color }}f0, {{ $color }}b0)">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-white/75">
                                {{ $balance->leaveType->name }}
                            </p>
                            {{-- THE KEY NUMBER: Available days --}}
                            <div class="mt-1 flex items-baseline gap-1.5">
                                <span class="text-4xl font-black tabular-nums text-white leading-none">{{ number_format($available, 1) == number_format($available, 0) ? (int)$available : number_format($available, 1) }}</span>
                                <span class="text-sm font-semibold text-white/70">available</span>
                            </div>
                        </div>
                        {{-- Low balance warning --}}
                        @if($isLow && $available > 0)
                            <span class="mt-0.5 inline-flex items-center gap-1 rounded-full bg-white/25 px-2 py-0.5 text-[10px] font-bold text-white">
                                âš  Low
                            </span>
                        @elseif($available === 0.0 && $allocated > 0)
                            <span class="mt-0.5 inline-flex items-center gap-1 rounded-full bg-white/25 px-2 py-0.5 text-[10px] font-bold text-white">
                                No balance
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Bottom breakdown -- plain language --}}
                <div class="px-5 pb-4 pt-3 space-y-2.5">
                    {{-- Progress bar --}}
                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                        <div class="h-full rounded-full transition-all duration-700" style="width: {{ $pct }}%; background: {{ $color }}"></div>
                    </div>

                    {{-- Breakdown grid: 3 rows, always visible --}}
                    <div class="grid grid-cols-3 gap-x-2 text-center text-[11px]">
                        <div>
                            <p class="font-bold text-zinc-900 dark:text-zinc-100">{{ (int)$allocated }}</p>
                            <p class="text-zinc-400">Allocated</p>
                        </div>
                        <div>
                            <p class="font-bold text-zinc-900 dark:text-zinc-100">{{ (float)$used }}</p>
                            <p class="text-zinc-400">Used</p>
                        </div>
                        <div>
                            <p class="font-bold" style="color: {{ $color }}">{{ $available > (int)$available ? number_format($available, 1) : (int)$available }}</p>
                            <p class="text-zinc-400">Left</p>
                        </div>
                    </div>

                    {{-- Extra info pills (only shown if non-zero) --}}
                    @if($carryFwd > 0 || $encashed > 0 || $compOff > 0)
                        <div class="flex flex-wrap gap-1.5 pt-1 border-t border-zinc-100 dark:border-zinc-800">
                            @if($carryFwd > 0)
                                <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-semibold text-blue-600 dark:bg-blue-950/30 dark:text-blue-400">
                                    â†© {{ (float)$carryFwd }} carried fwd
                                </span>
                            @endif
                            @if($encashed > 0)
                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-600 dark:bg-amber-950/30 dark:text-amber-400">
                                    â‚¹ {{ (float)$encashed }} encashed
                                </span>
                            @endif
                            @if($compOff > 0)
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-400">
                                    âœ“ {{ (float)$compOff }} comp off credits
                                </span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="col-span-full rounded-2xl border border-dashed border-zinc-200 p-10 text-center dark:border-zinc-800">
                <flux:icon.calendar-days class="mx-auto mb-3 size-10 text-zinc-200 dark:text-zinc-700" />
                <p class="text-sm font-medium text-zinc-400">No leave balances found. Contact HR to set up your leave account.</p>
            </div>
        @endforelse
    </div>

    {{-- â”€â”€â”€ ANALYTICS SECTION â”€â”€â”€ --}}
    @php
        $maxWeekday   = max(max($weeklyPattern), 1);
        $maxMonthly   = max(max($monthlyStats), 1);
        $totalUsed    = array_sum($weeklyPattern);
        $dayLabels    = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        $dayShort     = ['M','T','W','T','F','S','S'];
        $monthShort   = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    @endphp
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">

        {{-- â”€â”€ Weekly Pattern â”€â”€ --}}
        <div class="rounded-2xl border border-zinc-100 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-1 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="rounded-lg bg-violet-50 p-1.5 dark:bg-violet-900/20">
                        <flux:icon.calendar class="size-4 text-violet-600 dark:text-violet-400" />
                    </div>
                    <h3 class="text-xs font-black uppercase tracking-[0.12em] text-zinc-600 dark:text-zinc-300">Weekly Pattern</h3>
                </div>
                <span class="text-[10px] font-bold text-violet-600 dark:text-violet-400">{{ $totalUsed }}d this year</span>
            </div>
            <p class="mb-4 text-[11px] text-zinc-400">Approved leave days taken per weekday.</p>

            @if($totalUsed === 0)
                <div class="flex flex-col items-center justify-center py-6 text-center">
                    <flux:icon.calendar class="size-8 mb-2 text-zinc-200 dark:text-zinc-700" />
                    <p class="text-xs font-medium text-zinc-400">No approved leave taken yet this year.</p>
                </div>
            @else
                <div class="flex items-end gap-1.5 h-24">
                    @foreach($dayShort as $i => $d)
                        @php
                            $count  = $weeklyPattern[$i];
                            $pct    = $maxWeekday > 0 ? round(($count / $maxWeekday) * 100) : 0;
                            $height = max(8, $pct);
                            $isWknd = $i >= 5;
                        @endphp
                        <div class="flex flex-1 flex-col items-center gap-1">
                            @if($count > 0)
                                <span class="text-[9px] font-black text-violet-600 dark:text-violet-400">{{ $count }}</span>
                            @else
                                <span class="text-[9px] text-transparent">0</span>
                            @endif
                            <div class="w-full rounded-md transition-all duration-500 relative group"
                                style="height: {{ $height }}%; min-height: 6px; background: {{ $count > 0 ? ($isWknd ? '#a78bfa' : '#7c3aed') : '#f4f4f5' }};"
                                title="{{ $dayLabels[$i] }}: {{ $count }} day(s)">
                            </div>
                            <span class="text-[9px] font-bold uppercase {{ $count > 0 ? 'text-zinc-600 dark:text-zinc-300' : 'text-zinc-300 dark:text-zinc-600' }}">{{ $d }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- â”€â”€ Leave Distribution â”€â”€ --}}
        <div class="rounded-2xl border border-zinc-100 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-1 flex items-center gap-2">
                <div class="rounded-lg bg-violet-50 p-1.5 dark:bg-violet-900/20">
                    <flux:icon.chart-pie class="size-4 text-violet-600 dark:text-violet-400" />
                </div>
                <h3 class="text-xs font-black uppercase tracking-[0.12em] text-zinc-600 dark:text-zinc-300">Leave Distribution</h3>
            </div>
            <p class="mb-4 text-[11px] text-zinc-400">Usage across all leave types this year.</p>

            @if($balances->isEmpty())
                <div class="flex flex-col items-center justify-center py-6 text-center">
                    <flux:icon.chart-pie class="size-8 mb-2 text-zinc-200 dark:text-zinc-700" />
                    <p class="text-xs font-medium text-zinc-400">No leave balances found.</p>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($balances as $bal)
                        @php
                            $alloc   = (float) $bal->allocated_days;
                            $used    = (float) $bal->used_days;
                            $pctUsed = $alloc > 0 ? min(100, round(($used / $alloc) * 100)) : 0;
                            $color   = $bal->leaveType->color ?? '#7c3aed';
                        @endphp
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <div class="flex items-center gap-1.5">
                                    <div class="size-2 rounded-full shrink-0" style="background: {{ $color }}"></div>
                                    <span class="text-[11px] font-semibold text-zinc-700 dark:text-zinc-300">{{ $bal->leaveType->name }}</span>
                                </div>
                                <span class="text-[11px] text-zinc-500">
                                    <span class="font-bold text-zinc-900 dark:text-white">{{ (int)$used }}</span> / {{ (int)$alloc }}d
                                </span>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div class="h-full rounded-full transition-all duration-700"
                                    style="width: {{ $pctUsed }}%; background: {{ $color }}"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- â”€â”€ Monthly Trend â”€â”€ --}}
        <div class="rounded-2xl border border-zinc-100 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-1 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="rounded-lg bg-violet-50 p-1.5 dark:bg-violet-900/20">
                        <flux:icon.chart-bar class="size-4 text-violet-600 dark:text-violet-400" />
                    </div>
                    <h3 class="text-xs font-black uppercase tracking-[0.12em] text-zinc-600 dark:text-zinc-300">Monthly Trend</h3>
                </div>
                <span class="text-[10px] font-bold text-zinc-400">{{ now()->year }}</span>
            </div>
            <p class="mb-4 text-[11px] text-zinc-400">Days of approved leave per month.</p>

            @if(array_sum($monthlyStats) === 0)
                <div class="flex flex-col items-center justify-center py-6 text-center">
                    <flux:icon.chart-bar class="size-8 mb-2 text-zinc-200 dark:text-zinc-700" />
                    <p class="text-xs font-medium text-zinc-400">No leave data for {{ now()->year }} yet.</p>
                </div>
            @else
                {{-- Pure CSS bar chart â€” no Chart.js, no CDN --}}
                <div class="flex items-end gap-1 h-24">
                    @foreach($monthlyStats as $mi => $days)
                        @php
                            $barPct    = $maxMonthly > 0 ? round(($days / $maxMonthly) * 100) : 0;
                            $barH      = max(4, $barPct);
                            $isCurrent = ($mi + 1) === now()->month;
                        @endphp
                        <div class="flex flex-1 flex-col items-center gap-1">
                            @if($days > 0)
                                <span class="text-[8px] font-black text-violet-600">{{ $days }}</span>
                            @else
                                <span class="text-[8px] text-transparent">0</span>
                            @endif
                            <div class="w-full rounded-t-md transition-all duration-500"
                                style="height: {{ $barH }}%; min-height: 4px; background: {{ $days > 0 ? '#7c3aed' : '#f4f4f5' }}; {{ $isCurrent && $days > 0 ? 'box-shadow: 0 0 0 2px #7c3aed40;' : '' }}">
                            </div>
                            <span class="text-[8px] font-bold {{ $isCurrent ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-300 dark:text-zinc-600' }}">
                                {{ substr($monthShort[$mi], 0, 1) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- â”€â”€â”€ HISTORY SECTION â”€â”€â”€ --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-100 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

        {{-- Tab Nav --}}
        <div class="flex items-center gap-1 border-b border-zinc-100 bg-zinc-50/50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-950/30">
            <button
                @click="tab = 'requests'"
                :class="tab === 'requests' ? 'bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                class="flex items-center gap-2 rounded-xl px-5 py-2 text-sm font-bold transition-all">
                <flux:icon.document-text class="size-3.5" />
                Leave Applications
            </button>
            <button
                @click="tab = 'encashments'"
                :class="tab === 'encashments' ? 'bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                class="flex items-center gap-2 rounded-xl px-5 py-2 text-sm font-bold transition-all">
                <flux:icon.banknotes class="size-3.5" />
                Encashment History
            </button>
        </div>

        {{-- Leave Requests Tab --}}
        <div x-show="tab === 'requests'" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-1">
            {{-- Filters --}}
            <div class="flex flex-wrap items-center gap-3 px-6 py-4 border-b border-zinc-100 dark:border-zinc-800/50 bg-white dark:bg-zinc-900/60">
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
                @if($filterStatus || $filterTypeId)
                    <button wire:click="$set('filterStatus', ''); $set('filterTypeId', '')" class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 underline">Clear</button>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-zinc-50 bg-zinc-50/50 dark:border-zinc-800/50 dark:bg-zinc-950/50">
                            <th class="py-3 pl-6 pr-4 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Leave Type</th>
                            <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Period</th>
                            <th class="px-4 py-3 text-center text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Days</th>
                            <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Status</th>
                            <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Reviewer</th>
                            <th class="py-3 pr-6 text-right text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Applied</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/30">
                        @forelse($requests as $req)
                            <tr id="request-{{ $req->id }}" class="transition-colors hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20">
                                <td class="py-4 pl-6 pr-4">
                                    <div class="flex items-center gap-2.5">
                                        <div class="size-2.5 rounded-full shadow-sm" style="background-color: {{ $req->leaveType->color }}"></div>
                                        <span class="text-sm font-bold text-zinc-800 dark:text-zinc-200">{{ $req->leaveType->name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-1.5 text-sm text-zinc-600 dark:text-zinc-400">
                                        <span class="font-semibold">{{ $req->start_date->format('d M') }}</span>
                                        <flux:icon.arrow-long-right class="inline size-3 opacity-30" />
                                        <span class="font-semibold">{{ $req->end_date->format('d M, Y') }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span class="inline-flex size-7 items-center justify-center rounded-lg bg-zinc-50 text-sm font-black text-zinc-900 dark:bg-zinc-800 dark:text-white">
                                        {{ (float)$req->days }}
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    @php
                                        [$statusClass, $statusIcon] = match($req->status) {
                                            'approved' => ['bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400', 'check-circle'],
                                            'rejected' => ['bg-rose-50 text-rose-700 dark:bg-rose-900/20 dark:text-rose-400', 'x-circle'],
                                            'pending'  => ['bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400', 'clock'],
                                            default    => ['bg-zinc-50 text-zinc-700', 'question-mark-circle'],
                                        };
                                    @endphp
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider {{ $statusClass }}">
                                        <flux:icon :name="$statusIcon" class="size-3" />
                                        {{ $req->status }}
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    @if($req->reviewer)
                                        <div class="flex items-center gap-2">
                                            <div class="flex size-7 items-center justify-center rounded-full bg-gradient-to-br from-violet-400 to-indigo-500 text-[9px] font-black uppercase text-white shadow-sm">
                                                {{ collect(explode(' ', $req->reviewer->name))->map(fn($n) => $n[0])->take(2)->join('') }}
                                            </div>
                                            <span class="text-xs font-medium text-zinc-500">{{ $req->reviewer->name }}</span>
                                        </div>
                                    @else
                                        <div class="flex items-center gap-1.5 text-[10px] font-bold text-zinc-400">
                                            <div class="size-1.5 animate-pulse rounded-full bg-amber-400"></div>
                                            Awaiting
                                        </div>
                                    @endif
                                </td>
                                <td class="py-4 pr-6 text-right">
                                    <span class="text-xs font-medium text-zinc-400">{{ $req->created_at->format('d M Y') }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-16 text-center">
                                    <div class="flex flex-col items-center gap-3">
                                        <div class="flex size-14 items-center justify-center rounded-2xl bg-zinc-50 dark:bg-zinc-800">
                                            <flux:icon.document-magnifying-glass class="size-7 text-zinc-300 dark:text-zinc-600" />
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold text-zinc-400">No leave applications yet</p>
                                            <p class="mt-0.5 text-xs text-zinc-300 dark:text-zinc-600">Click "Apply for Leave" to get started.</p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($requests->hasPages())
                <div class="border-t border-zinc-50 px-6 py-4 dark:border-zinc-800">
                    {{ $requests->links() }}
                </div>
            @endif
        </div>

        {{-- Encashments Tab --}}
        <div x-show="tab === 'encashments'" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-1">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-zinc-50 bg-zinc-50/50 dark:border-zinc-800/50 dark:bg-zinc-950/50">
                            <th class="py-3 pl-6 pr-4 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Leave Type</th>
                            <th class="px-4 py-3 text-center text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Days</th>
                            <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Status</th>
                            <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Payout Cycle</th>
                            <th class="py-3 pr-6 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Reviewer</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/30">
                        @forelse($encashments as $enc)
                            <tr class="transition-colors hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20">
                                <td class="py-4 pl-6 pr-4">
                                    <div class="flex items-center gap-2.5">
                                        <div class="size-2.5 rounded-full" style="background-color: {{ $enc->leaveType->color }}"></div>
                                        <span class="text-sm font-bold text-zinc-800 dark:text-zinc-200">{{ $enc->leaveType->name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span class="inline-flex size-7 items-center justify-center rounded-lg bg-zinc-50 text-sm font-black text-zinc-900 dark:bg-zinc-800 dark:text-white">
                                        {{ (float)$enc->requested_days }}
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    @php
                                        [$encStatusClass, $encStatusIcon] = match($enc->status) {
                                            'approved', 'processed' => ['bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400', 'check-circle'],
                                            'rejected' => ['bg-rose-50 text-rose-700 dark:bg-rose-900/20 dark:text-rose-400', 'x-circle'],
                                            'pending'  => ['bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400', 'clock'],
                                            default    => ['bg-zinc-50 text-zinc-700', 'question-mark-circle'],
                                        };
                                    @endphp
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider {{ $encStatusClass }}">
                                        <flux:icon :name="$encStatusIcon" class="size-3" />
                                        {{ $enc->status }}
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-50 px-2.5 py-1 text-xs font-bold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                        <flux:icon.calendar class="size-3" />
                                        {{ $enc->payout_month ? Carbon::parse($enc->payout_month)->format('M Y') : '--' }}
                                    </div>
                                </td>
                                <td class="py-4 pr-6">
                                    @if($enc->reviewer)
                                        <div class="flex items-center gap-2">
                                            <div class="flex size-7 items-center justify-center rounded-full bg-gradient-to-br from-violet-400 to-indigo-500 text-[9px] font-black uppercase text-white shadow-sm">
                                                {{ collect(explode(' ', $enc->reviewer->name))->map(fn($n) => $n[0])->take(2)->join('') }}
                                            </div>
                                            <span class="text-xs font-medium text-zinc-500">{{ $enc->reviewer->name }}</span>
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
                                    <div class="flex flex-col items-center gap-3">
                                        <div class="flex size-14 items-center justify-center rounded-2xl bg-zinc-50 dark:bg-zinc-800">
                                            <flux:icon.banknotes class="size-7 text-zinc-300 dark:text-zinc-600" />
                                        </div>
                                        <p class="text-sm font-bold text-zinc-400">No encashment requests yet.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- â”€â”€â”€ REQUEST LEAVE MODAL â”€â”€â”€ --}}
    @if($showRequestModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showRequestModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" wire:click="$set('showRequestModal', false)"></div>
            <div class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" wire:click="$set('showRequestModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

        <div class="space-y-6">
            <div class="flex items-start gap-3">
                <div class="shrink-0 rounded-xl bg-violet-50 p-2.5 dark:bg-violet-900/20">
                    <flux:icon.calendar-days class="size-5 text-violet-600 dark:text-violet-400" />
                </div>
                <div>
                    <flux:heading size="lg">Request Time Off</flux:heading>
                    <flux:subheading>Submit a new leave application for review.</flux:subheading>
                </div>
            </div>

            @error('request')
                <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700 dark:bg-red-950/30 dark:border-red-800 dark:text-red-400">
                    {{ $message }}
                </div>
            @enderror

            <form wire:submit="submitRequest" class="space-y-5">
                <flux:select wire:model="leave_type_id" label="Leave Type" placeholder="Select type..." required>
                    @foreach($leaveTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }} ({{ $type->is_paid ? 'Paid' : 'Unpaid' }})</option>
                    @endforeach
                </flux:select>

                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="start_date" type="date" label="Start Date" required />
                    <flux:input wire:model="end_date" type="date" label="End Date" required />
                </div>

                <div class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-zinc-50/50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/30">
                    <flux:checkbox wire:model.live="is_half_day" id="half_day_toggle" />
                    <div>
                        <label for="half_day_toggle" class="cursor-pointer text-sm font-semibold text-zinc-900 dark:text-zinc-100">Half-Day Leave</label>
                        <p class="mt-0.5 text-xs text-zinc-500">Deducts 0.5 days. Only valid when start and end date are the same.</p>
                    </div>
                </div>

                <flux:textarea wire:model="reason" label="Reason" placeholder="Please describe why you need time off..." rows="3" required />

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" wire:click="$set('showRequestModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">Cancel</button>
                    <button type="submit" class="inline-flex items-center gap-2 px-5 py-2 text-sm font-bold text-white bg-brand-600 hover:bg-brand-700 rounded-xl transition-colors">Submit Request</button>
                </div>
            </form>
        </div>
    
            </div>
        </div>
    @endif

    {{-- ─── ENCASHMENT MODAL ─── --}}
    <flux:modal name=”encashment-modal” class=”max-w-md”>
        <div class=”space-y-6”>
            <div class="flex items-start gap-3">
                <div class="shrink-0 rounded-xl bg-amber-50 p-2.5 dark:bg-amber-900/20">
                    <flux:icon.banknotes class="size-5 text-amber-600 dark:text-amber-400" />
                </div>
                <div>
                    <flux:heading size="lg">Encash Leave Balance</flux:heading>
                    <flux:subheading>Convert unused paid leave into salary payout.</flux:subheading>
                </div>
            </div>

            <div class="space-y-4">
                <flux:select wire:model="encash_leave_type_id" label="Select Leave Type" required>
                    @foreach($encashableTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="encash_days" label="Days to Encash" type="number" step="0.5" suffix="Days" required />

                <div class="flex gap-3 rounded-xl border border-amber-100 bg-amber-50 p-3 dark:border-amber-800/30 dark:bg-amber-900/10">
                    <flux:icon.information-circle class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                    <p class="text-xs leading-relaxed text-amber-700 dark:text-amber-400">
                        Only CSL leaves are eligible for encashment per company policy. Requests require HR &amp; Finance approval.
                    </p>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button @click="$flux.modal('encashment-modal').close()">Cancel</flux:button>
                <flux:button wire:click="submitEncashment" variant="primary">Submit Request</flux:button>
            </div>
        </div>
    </flux:modal>


</flux:main>
