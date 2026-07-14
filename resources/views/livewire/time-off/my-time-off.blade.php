<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-800/50 dark:bg-zinc-950 p-6 space-y-5" x-data="{ tab: 'requests' }">

    {{-- â"€â"€â"€ HERO HEADER â"€â"€â"€ --}}
    <div class="pulse-hero shadow-xl">
        <div
            class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_bottom_left,_rgba(249,115,22,0.22),_transparent_65%)]">
        </div>
        <div class="pointer-events-none absolute -bottom-10 -left-10 size-64 rounded-full blur-3xl"
            style="background:radial-gradient(circle,rgba(249,115,22,0.30),transparent 70%)"></div>
        <div class="pointer-events-none absolute top-0 right-0 size-48 rounded-full blur-3xl"
            style="background:radial-gradient(circle,rgba(249,115,22,0.08),transparent 70%)"></div>

        <div class="relative flex flex-col sm:flex-row sm:items-center justify-between gap-6">
            <div>
                <div
                    class="mb-3 inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 dark:bg-zinc-900/10 px-3 py-1 backdrop-blur-sm">
                    <div class="size-1.5 animate-pulse rounded-full bg-orange-200"></div>
                    <span class="text-[11px] font-bold uppercase tracking-[0.18em] text-orange-100">Leave
                        Management</span>
                </div>
                <h1 class="text-3xl font-black tracking-tight text-white">My Time Off</h1>
                <p class="mt-1.5 text-sm font-medium text-violet-200/80">Manage balances, apply for leave and track
                    requests.</p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-3">
                @if($encashableTypes->isNotEmpty())
                    <button @click="$flux.modal('encashment-modal').show()"
                        class="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-white/25 bg-white/15 dark:bg-zinc-900/15 px-4 py-2.5 text-sm font-bold text-white backdrop-blur-sm transition-all hover:bg-white/25">
                        <flux:icon.banknotes class="size-4 shrink-0" />
                        <span>Encash Leave</span>
                    </button>
                @endif
                <button wire:click="openRequestModal"
                    class="inline-flex cursor-pointer items-center gap-2 rounded-xl bg-white dark:bg-zinc-900 px-5 py-2.5 text-sm font-black text-orange-700 shadow-lg shadow-black/20 transition-all hover:bg-violet-50">
                    <flux:icon.plus class="size-4 shrink-0" />
                    <span>Apply for Leave</span>
                </button>
            </div>
        </div>
    </div>

    {{-- â"€â"€â"€ PENDING ALERT â"€â"€â"€ --}}
    @if(!empty($pendingCount) && $pendingCount > 0)
        <div
            class="pulse-margin flex flex-col gap-4 rounded-2xl border border-amber-200 bg-gradient-to-r from-amber-50 to-orange-50 p-4 dark:border-amber-800/40 dark:from-amber-950/30 dark:to-orange-950/20 sm:flex-row sm:items-center">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 dark:bg-amber-900/40">
                <flux:icon.clock class="size-5 text-amber-600 dark:text-amber-400" />
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-bold text-amber-900 dark:text-amber-200">
                    {{ $pendingCount }} leave {{ Str::plural('request', $pendingCount) }} awaiting approval
                </p>
                <p class="mt-0.5 text-xs text-amber-700/70 dark:text-amber-400/70">
                    Your applications are currently under review. You'll be notified once actioned.
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="#recent-requests" @click="tab = 'requests'"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-amber-300 bg-white dark:bg-zinc-900 px-3 py-1.5 text-xs font-bold text-amber-700 transition-colors hover:bg-amber-100 dark:border-amber-800/50 dark:bg-zinc-900 dark:text-amber-300 dark:hover:bg-amber-900/30">
                    <flux:icon.eye class="size-3" />
                    View Requests
                </a>
                <button wire:click="openRequestModal"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-bold text-white transition-colors hover:bg-amber-600">
                    <flux:icon.plus class="size-3" />
                    New Request
                </button>
            </div>
        </div>
    @endif

    {{-- â"€â"€â"€ LEAVE BALANCE CARDS â"€â"€â"€ --}}
    {{-- Each card shows: Available days clearly, plus a plain breakdown row --}}
    @php
        $leaveTypeStyles = [
            'annual' => ['color' => '#10b981', 'icon' => 'sun'],
            'earned' => ['color' => '#10b981', 'icon' => 'sun'],
            'sick' => ['color' => '#ef4444', 'icon' => 'heart'],
            'casual' => ['color' => '#3b82f6', 'icon' => 'calendar-days'],
            'maternity' => ['color' => '#a855f7', 'icon' => 'gift'],
            'paternity' => ['color' => '#6366f1', 'icon' => 'user-group'],
            'bereavement' => ['color' => '#64748b', 'icon' => 'moon'],
            'comp' => ['color' => '#14b8a6', 'icon' => 'arrow-path'],
            'unpaid' => ['color' => '#71717a', 'icon' => 'banknotes'],
            'without pay' => ['color' => '#71717a', 'icon' => 'banknotes'],
            'unauthor' => ['color' => '#dc2626', 'icon' => 'exclamation-triangle'],
        ];
        $fmt = fn ($n) => (float) $n == (int) $n ? (int) $n : number_format((float) $n, 1);
    @endphp
    <div class="pulse-margin">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            @forelse($balanceCards as $card)
                @php
                    $available = $card->available;
                    $pct = $card->allocated > 0 ? min(100, round(($card->used + $card->encashed) / $card->allocated * 100)) : 0;
                    $isLow = $available > 0 && $available <= 2 && $card->allocated > 0;
                    $isEmpty = $available === 0.0 && $card->allocated > 0;

                    $typeName = strtolower($card->name);
                    $matchedStyle = collect($leaveTypeStyles)->first(fn($s, $key) => str_contains($typeName, $key));
                    $color = $matchedStyle['color'] ?? ($card->color ?: '#7c3aed');
                    $typeIcon = $matchedStyle['icon'] ?? 'calendar-days';
                @endphp
                <div class="group relative overflow-hidden rounded-2xl border border-zinc-100 bg-white p-4 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                    {{-- Left accent bar --}}
                    <span class="absolute inset-y-0 left-0 w-1" style="background: {{ $color }}"></span>

                    <div class="flex items-start justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-lg" style="background: {{ $color }}1a">
                                <flux:icon :name="$typeIcon" class="size-3.5" style="color: {{ $color }}" />
                            </span>
                            <p class="truncate text-[10px] font-black uppercase leading-tight tracking-wider text-zinc-500 dark:text-zinc-400">{{ $card->name }}</p>
                        </div>
                        @if($isLow)
                            <span class="shrink-0 rounded-full bg-amber-100 px-1.5 py-0.5 text-[9px] font-bold text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">LOW</span>
                        @elseif($isEmpty)
                            <span class="shrink-0 rounded-full bg-zinc-100 px-1.5 py-0.5 text-[9px] font-bold text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">NIL</span>
                        @endif
                    </div>

                    {{-- Key number: days left --}}
                    <div class="mt-3 flex items-baseline gap-1.5">
                        <span class="text-3xl font-black leading-none tabular-nums text-zinc-900 dark:text-white">{{ $fmt($available) }}</span>
                        <span class="text-[11px] font-semibold text-zinc-400">{{ $card->allocated > 0 ? 'of '.$fmt($card->allocated).' left' : 'available' }}</span>
                    </div>

                    {{-- Usage bar --}}
                    <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                        <div class="h-full rounded-full transition-all duration-700" style="width: {{ $pct }}%; background: {{ $color }}"></div>
                    </div>

                    {{-- Footer: used + extras --}}
                    <div class="mt-2 flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-[10px] font-semibold text-zinc-400">
                        <span>{{ $fmt($card->used) }} used</span>
                        @if($card->carried > 0)<span class="text-blue-500">↩ {{ $fmt($card->carried) }} c/f</span>@endif
                        @if($card->encashed > 0)<span class="text-amber-500">₹ {{ $fmt($card->encashed) }} enc.</span>@endif
                        @if($card->comp_off > 0)<span class="text-emerald-500">✔ {{ $fmt($card->comp_off) }} comp</span>@endif
                    </div>
                </div>
            @empty
                <div
                    class="col-span-full rounded-2xl border border-dashed border-zinc-200 dark:border-zinc-800 p-10 text-center">
                    <flux:icon.calendar-days class="mx-auto mb-3 size-10 text-zinc-200 dark:text-zinc-700" />
                    <p class="text-sm font-medium text-zinc-400">No leave balances found. Contact HR to set up your leave
                        account.</p>
                </div>
            @endforelse
        </div>
    </div>
    {{-- â"€â"€â"€ LEAVE STATISTICS (KPI) SECTION â"€â"€â"€ --}}
    @php
        $totalAllocated = (float) $balances->sum('allocated_days');
        $totalUsedDays = (float) $balances->sum(fn($b) => $b->used_days + ($b->encashed_days ?? 0));
        $totalRemaining = max(0, $totalAllocated - $totalUsedDays);

        $curMonthIdx = now()->month - 1;
        $prevMonthIdx = ($curMonthIdx - 1 + 12) % 12;
        $thisMonthUsed = $monthlyStats[$curMonthIdx] ?? 0;
        $lastMonthUsed = $monthlyStats[$prevMonthIdx] ?? 0;
        $usageTrend = $thisMonthUsed - $lastMonthUsed;

        $statCards = [
            [
                'label' => 'Total Leaves Allocated',
                'value' => $totalAllocated,
                'icon' => 'calendar-days',
                'color' => '#7c3aed',
                'sub' => 'Across ' . $balanceCards->count() . ' leave ' . Str::plural('type', $balanceCards->count()),
            ],
            [
                'label' => 'Leaves Used',
                'value' => $totalUsedDays,
                'icon' => 'arrow-trending-up',
                'color' => '#f97316',
                'sub' => $usageTrend == 0 ? 'No change vs last month' : (($usageTrend > 0 ? '+' . $usageTrend : $usageTrend) . 'd vs last month'),
                'trend' => $usageTrend,
            ],
            [
                'label' => 'Leaves Remaining',
                'value' => $totalRemaining,
                'icon' => 'check-badge',
                'color' => '#10b981',
                'sub' => $totalAllocated > 0 ? round(($totalRemaining / $totalAllocated) * 100) . '% of allocation left' : 'No allocation set',
            ],
            [
                'label' => 'Pending Requests',
                'value' => $pendingCount,
                'icon' => 'clock',
                'color' => '#f59e0b',
                'sub' => $pendingCount > 0 ? 'Awaiting approval' : 'All caught up',
            ],
            [
                'label' => 'Holiday Worked',
                'value' => $holidayWorkedCount,
                'icon' => 'briefcase',
                'color' => '#14b8a6',
                'sub' => $holidayWorkedCount > 0 ? 'Approved this year' : 'None this year',
            ],
        ];
    @endphp
    <div class="pulse-margin grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach($statCards as $stat)
            <div
                class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <div class="flex size-10 items-center justify-center rounded-xl"
                        style="background: {{ $stat['color'] }}1a">
                        <flux:icon :name="$stat['icon']" class="size-5" style="color: {{ $stat['color'] }}" />
                    </div>
                    @if(isset($stat['trend']))
                        @if($stat['trend'] > 0)
                            <span
                                class="inline-flex items-center gap-0.5 rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-bold text-rose-600 dark:bg-rose-900/20 dark:text-rose-400">
                                <flux:icon.arrow-up class="size-3" /> {{ $stat['trend'] }}d
                            </span>
                        @elseif($stat['trend'] < 0)
                            <span
                                class="inline-flex items-center gap-0.5 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-600 dark:bg-emerald-900/20 dark:text-emerald-400">
                                <flux:icon.arrow-down class="size-3" /> {{ abs($stat['trend']) }}d
                            </span>
                        @else
                            <span
                                class="inline-flex items-center gap-0.5 rounded-full bg-zinc-50 dark:bg-zinc-800/50 px-2 py-0.5 text-[10px] font-bold text-zinc-500 dark:text-zinc-400 dark:bg-zinc-800 dark:text-zinc-400">
                                <flux:icon.minus class="size-3" /> 0d
                            </span>
                        @endif
                    @endif
                </div>
                <p class="mt-3 text-3xl font-black tabular-nums text-zinc-900 dark:text-white">
                    {{ $stat['value'] == (int) $stat['value'] ? (int) $stat['value'] : number_format($stat['value'], 1) }}
                </p>
                <p class="mt-0.5 text-xs font-bold uppercase tracking-wide text-zinc-400">{{ $stat['label'] }}</p>
                <p class="mt-2 text-[11px] text-zinc-400">{{ $stat['sub'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- â"€â"€â"€ ANALYTICS SECTION â"€â"€â"€ --}}
    @php
        $maxWeekday = max(max($weeklyPattern), 1);
        $maxMonthly = max(max($monthlyStats), 1);
        $totalUsed = array_sum($weeklyPattern);
        $dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $dayShort = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
        $monthShort = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    @endphp
    <div class="pulse-margin grid grid-cols-1 gap-4 lg:grid-cols-3">

        {{-- â"€â"€ Weekly Pattern â"€â"€ --}}
        <div class=" rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-1 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="rounded-lg bg-violet-50 p-1.5 dark:bg-violet-900/20">
                        <flux:icon.calendar class="size-4 text-violet-600 dark:text-violet-400" />
                    </div>
                    <h3 class="text-xs font-black uppercase tracking-[0.12em] text-zinc-600 dark:text-zinc-300">Weekly
                        Pattern</h3>
                </div>
                <span class="text-[10px] font-bold text-violet-600 dark:text-violet-400">{{ $totalUsed }}d this
                    year</span>
            </div>
            <p class="mb-4 text-[11px] text-zinc-400">Approved leave days taken per weekday.</p>

            @if($totalUsed === 0)
                <div class="flex flex-col items-center justify-center py-6 text-center">
                    <flux:icon.calendar class="size-8 mb-2 text-zinc-200 dark:text-zinc-700 dark:text-zinc-200" />
                    <p class="text-xs font-medium text-zinc-400">No approved leave taken yet this year.</p>
                </div>
            @else
                <div class="flex items-end gap-1.5 h-24">
                    @foreach($dayShort as $i => $d)
                        @php
                            $count = $weeklyPattern[$i];
                            $pct = $maxWeekday > 0 ? round(($count / $maxWeekday) * 100) : 0;
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
                            <span
                                class="text-[9px] font-bold uppercase {{ $count > 0 ? 'text-zinc-600 dark:text-zinc-300' : 'text-zinc-300 dark:text-zinc-600 dark:text-zinc-300' }}">{{ $d }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- â"€â"€ Leave Distribution â"€â"€ --}}
        <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-1 flex items-center gap-2">
                <div class="rounded-lg bg-violet-50 p-1.5 dark:bg-violet-900/20">
                    <flux:icon.chart-pie class="size-4 text-violet-600 dark:text-violet-400" />
                </div>
                <h3 class="text-xs font-black uppercase tracking-[0.12em] text-zinc-600 dark:text-zinc-300">Leave
                    Distribution</h3>
            </div>
            <p class="mb-4 text-[11px] text-zinc-400">Usage across all leave types this year.</p>

            @if($balances->isEmpty())
                <div class="flex flex-col items-center justify-center py-6 text-center">
                    <flux:icon.chart-pie class="size-8 mb-2 text-zinc-200 dark:text-zinc-700 dark:text-zinc-200" />
                    <p class="text-xs font-medium text-zinc-400">No leave balances found.</p>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($balances as $bal)
                        @php
                            $alloc = (float) $bal->allocated_days;
                            $used = (float) $bal->used_days;
                            $pctUsed = $alloc > 0 ? min(100, round(($used / $alloc) * 100)) : 0;
                            $color = $bal->leaveType->color ?? '#7c3aed';
                        @endphp
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <div class="flex items-center gap-1.5">
                                    <div class="size-2 rounded-full shrink-0" style="background: {{ $color }}"></div>
                                    <span
                                        class="text-[11px] font-semibold text-zinc-700 dark:text-zinc-200 dark:text-zinc-300">{{ $bal->leaveType->name }}</span>
                                </div>
                                <span class="text-[11px] text-zinc-500 dark:text-zinc-400">
                                    <span class="font-bold text-zinc-900 dark:text-white">{{ (int) $used }}</span> /
                                    {{ (int) $alloc }}d
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

        {{-- â"€â"€ Monthly Trend â"€â"€ --}}
        <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-1 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="rounded-lg bg-violet-50 p-1.5 dark:bg-violet-900/20">
                        <flux:icon.chart-bar class="size-4 text-violet-600 dark:text-violet-400" />
                    </div>
                    <h3 class="text-xs font-black uppercase tracking-[0.12em] text-zinc-600 dark:text-zinc-300">Monthly
                        Trend</h3>
                </div>
                <span class="text-[10px] font-bold text-zinc-400">{{ now()->year }}</span>
            </div>
            <p class="mb-4 text-[11px] text-zinc-400">Days of approved leave per month.</p>

            @if(array_sum($monthlyStats) === 0)
                <div class="flex flex-col items-center justify-center py-6 text-center">
                    <flux:icon.chart-bar class="size-8 mb-2 text-zinc-200 dark:text-zinc-700 dark:text-zinc-200" />
                    <p class="text-xs font-medium text-zinc-400">No leave data for {{ now()->year }} yet.</p>
                </div>
            @else
                {{-- Pure CSS bar chart ”" no Chart.js, no CDN --}}
                <div class="flex items-end gap-1 h-24">
                    @foreach($monthlyStats as $mi => $days)
                        @php
                            $barPct = $maxMonthly > 0 ? round(($days / $maxMonthly) * 100) : 0;
                            $barH = max(4, $barPct);
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
                            <span
                                class="text-[8px] font-bold {{ $isCurrent ? 'text-violet-600 dark:text-violet-400' : 'text-zinc-300 dark:text-zinc-600 dark:text-zinc-300' }}">
                                {{ substr($monthShort[$mi], 0, 1) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- â"€â"€â"€ HISTORY SECTION â"€â"€â"€ --}}
    <div id="recent-requests"
        class="pulse-margin overflow-hidden rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 scroll-mt-6">

        {{-- Tab Nav --}}
        <div
            class="flex items-center gap-1 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-950/30">
            <button @click="tab = 'requests'"
                :class="tab === 'requests' ? 'bg-white dark:bg-zinc-900 dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:text-zinc-200 dark:hover:text-zinc-300'"
                class="flex items-center gap-2 rounded-xl px-5 py-2 text-sm font-bold transition-all">
                <flux:icon.document-text class="size-3.5" />
                Leave Applications
            </button>
            <button @click="tab = 'encashments'"
                :class="tab === 'encashments' ? 'bg-white dark:bg-zinc-900 dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:text-zinc-200 dark:hover:text-zinc-300'"
                class="flex items-center gap-2 rounded-xl px-5 py-2 text-sm font-bold transition-all">
                <flux:icon.banknotes class="size-3.5" />
                Encashment History
            </button>
        </div>

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
            @endphp
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-zinc-50 bg-zinc-50/50 dark:border-zinc-800/50 dark:bg-zinc-950/50">
                            <th class="py-3 pl-6 pr-4 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Request ID</th>
                            <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Leave Type</th>
                            <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                <button wire:click="sortBy('start_date')"
                                    class="inline-flex cursor-pointer items-center gap-1 transition-colors hover:text-zinc-600 dark:text-zinc-300 dark:hover:text-zinc-300">
                                    Date Range
                                    <flux:icon :name="$sortIcon('start_date')" class="size-3" />
                                </button>
                            </th>
                            <th
                                class="px-4 py-3 text-center text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                <button wire:click="sortBy('days')"
                                    class="inline-flex cursor-pointer items-center gap-1 transition-colors hover:text-zinc-600 dark:text-zinc-300 dark:hover:text-zinc-300">
                                    Duration
                                    <flux:icon :name="$sortIcon('days')" class="size-3" />
                                </button>
                            </th>
                            <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Status</th>
                            <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                Reviewer</th>
                            <th
                                class="py-3 pr-6 text-right text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">
                                <button wire:click="sortBy('created_at')"
                                    class="inline-flex cursor-pointer items-center gap-1 transition-colors hover:text-zinc-600 dark:text-zinc-300 dark:hover:text-zinc-300">
                                    Applied On
                                    <flux:icon :name="$sortIcon('created_at')" class="size-3" />
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/30">
                        @forelse($requests as $req)
                            <tr id="request-{{ $req->id }}"
                                class="transition-colors hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20">
                                <td class="py-4 pl-6 pr-4">
                                    <span
                                        class="text-xs font-bold text-zinc-400">LR-{{ str_pad($req->id, 4, '0', STR_PAD_LEFT) }}</span>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-2.5">
                                        <div class="size-2.5 rounded-full shadow-sm"
                                            style="background-color: {{ $req->leaveType->color }}"></div>
                                        <span
                                            class="text-sm font-bold text-zinc-800 dark:text-zinc-100 dark:text-zinc-200">{{ $req->leaveType->name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-1.5 text-sm text-zinc-600 dark:text-zinc-300 dark:text-zinc-400">
                                        <span class="font-semibold">{{ $req->start_date->format('d M') }}</span>
                                        <flux:icon.arrow-long-right class="inline size-3 opacity-30" />
                                        <span class="font-semibold">{{ $req->end_date->format('d M, Y') }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span
                                        class="inline-flex items-center justify-center rounded-lg bg-zinc-50 dark:bg-zinc-800/50 px-2.5 py-1 text-xs font-black text-zinc-900 dark:text-white dark:bg-zinc-800 dark:text-white">
                                        {{ (float) $req->days }} {{ Str::plural('day', (float) $req->days == 1 ? 1 : 2) }}
                                    </span>
                                </td>
                                <td class="px-4 py-4">
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
                                    @if($req->status === 'more_info_requested' && $req->reviewer_comment)
                                        <p class="mt-1 max-w-[220px] truncate text-[10px] italic text-orange-500" title="{{ $req->reviewer_comment }}">“{{ $req->reviewer_comment }}”</p>
                                    @elseif($req->status === 'rejected' && $req->reviewer_comment)
                                        <p class="mt-1 max-w-[220px] truncate text-[10px] italic text-rose-500" title="{{ $req->reviewer_comment }}">“{{ $req->reviewer_comment }}”</p>
                                    @endif
                                    @if($req->attachments->isNotEmpty())
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @foreach($req->attachments as $att)
                                                <a href="{{ asset('storage/'.$att->path) }}" target="_blank" title="{{ $att->typeLabel() }}: {{ $att->original_name }}"
                                                    class="inline-flex items-center gap-1 rounded bg-zinc-50 dark:bg-zinc-800/50 px-1.5 py-0.5 text-[9px] font-semibold text-zinc-500 dark:text-zinc-400 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-400">
                                                    <flux:icon :name="$att->icon()" class="size-2.5" /> {{ $att->typeLabel() }}
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-4">
                                    @if($req->reviewer)
                                        <div class="flex items-center gap-2">
                                            <div
                                                class="flex size-7 items-center justify-center rounded-full bg-gradient-to-br from-violet-400 to-indigo-500 text-[9px] font-black uppercase text-white shadow-sm">
                                                {{ collect(explode(' ', $req->reviewer->name))->map(fn($n) => $n[0])->take(2)->join('') }}
                                            </div>
                                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $req->reviewer->name }}</span>
                                        </div>
                                    @else
                                        <div class="flex items-center gap-1.5 text-[10px] font-bold text-zinc-400">
                                            <div class="size-1.5 animate-pulse rounded-full bg-amber-400"></div>
                                            Awaiting
                                        </div>
                                    @endif
                                </td>
                                <td class="py-4 pr-6 text-right">
                                    <div class="flex items-center justify-end gap-3">
                                        <span
                                            class="text-xs font-medium text-zinc-400">{{ $req->created_at->format('d M Y') }}</span>
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
                                <td colspan="6" class="py-16 text-center">
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
                                    <div class="flex flex-col items-center gap-3">
                                        <div
                                            class="flex size-14 items-center justify-center rounded-2xl bg-zinc-50 dark:bg-zinc-800">
                                            <flux:icon.banknotes class="size-7 text-zinc-300 dark:text-zinc-600 dark:text-zinc-300" />
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

    {{-- ─── LEAVE FORECAST · HOLIDAY PLANNER · POLICY EXPLORER ─── --}}
    <div class="pulse-margin grid grid-cols-1 gap-4 lg:grid-cols-3">
        {{-- Leave Forecast --}}
        <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-3 flex items-center gap-2">
                <div class="rounded-lg bg-sky-50 p-1.5 dark:bg-sky-900/20"><flux:icon.chart-bar class="size-4 text-sky-600 dark:text-sky-400" /></div>
                <h3 class="text-xs font-black uppercase tracking-[0.12em] text-zinc-600 dark:text-zinc-300">Leave Forecast</h3>
            </div>
            <div class="flex items-end gap-2">
                <div class="text-3xl font-black text-zinc-900 dark:text-white">{{ $forecast['projected'] }}</div>
                <div class="pb-1 text-xs text-zinc-400">projected {{ $forecast['label'] }} days this year</div>
            </div>
            <div class="mt-2 text-xs font-semibold {{ $forecast['on_track'] ? 'text-emerald-600' : 'text-rose-600' }}">
                {{ $forecast['on_track'] ? 'On track' : 'Above allocation' }} · {{ $forecast['used'] }} used of {{ $forecast['allocated'] }}
            </div>
            @php $fpct = $forecast['allocated'] > 0 ? min(100, round($forecast['used'] / $forecast['allocated'] * 100)) : 0; @endphp
            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                <div class="h-full rounded-full {{ $forecast['on_track'] ? 'bg-emerald-500' : 'bg-rose-500' }}" style="width: {{ $fpct }}%"></div>
            </div>
        </div>

        {{-- Holiday Planner --}}
        <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-3 flex items-center gap-2">
                <div class="rounded-lg bg-rose-50 p-1.5 dark:bg-rose-900/20"><flux:icon.calendar class="size-4 text-rose-600 dark:text-rose-400" /></div>
                <h3 class="text-xs font-black uppercase tracking-[0.12em] text-zinc-600 dark:text-zinc-300">Holiday Planner</h3>
            </div>
            <div class="space-y-1.5">
                @forelse($upcomingHolidays as $h)
                    <div class="group flex items-center justify-between gap-2 text-sm">
                        <span class="truncate text-zinc-700 dark:text-zinc-200">{{ $h->name }}</span>
                        <span class="flex shrink-0 items-center gap-2">
                            <button type="button" wire:click="openHolidayWork('{{ $h->date->toDateString() }}')"
                                class="rounded-lg px-1.5 py-0.5 text-[10px] font-bold text-orange-500 opacity-0 transition hover:bg-orange-50 group-hover:opacity-100" title="Request to work this holiday">Work</button>
                            <span class="text-xs text-zinc-400">{{ $h->date->format('d M') }}</span>
                        </span>
                    </div>
                @empty
                    <p class="text-xs text-zinc-400">No upcoming public holidays.</p>
                @endforelse
                @if($mandatoryDays->isNotEmpty())
                    <div class="mt-2 border-t border-zinc-100 dark:border-zinc-800 pt-2 dark:border-zinc-800">
                        <p class="mb-1 text-[10px] font-bold uppercase tracking-wide text-amber-600">December Mandatory</p>
                        @foreach($mandatoryDays as $m)
                            <div class="flex items-center justify-between text-sm">
                                <span class="truncate text-zinc-700 dark:text-zinc-200">{{ $m->description }}</span>
                                <span class="shrink-0 text-xs text-zinc-400">{{ $m->date->format('d M') }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            <button type="button" wire:click="openHolidayWork"
                class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-xl border border-orange-200 bg-orange-50/60 px-3 py-2 text-xs font-bold text-orange-600 transition hover:bg-orange-100">
                <flux:icon.briefcase class="size-4" /> Request to Work on Holiday
            </button>
        </div>

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
                                <p class="text-[11px] text-zinc-400">{{ $conversationRequest->leaveType?->name }} · {{ $conversationRequest->start_date->format('d M') }}–{{ $conversationRequest->end_date->format('d M Y') }}</p>
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

        {{-- Policy Explorer --}}
        <div x-data="{ open: true }" class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <button type="button" @click="open = !open" class="flex w-full items-center justify-between gap-2">
                <span class="flex items-center gap-2">
                    <span class="rounded-lg bg-indigo-50 p-1.5 dark:bg-indigo-900/20"><flux:icon.book-open class="size-4 text-indigo-600 dark:text-indigo-400" /></span>
                    <h3 class="text-xs font-black uppercase tracking-[0.12em] text-zinc-600 dark:text-zinc-300">Policy Explorer</h3>
                </span>
                <flux:icon.chevron-down class="size-4 text-zinc-400" x-bind:class="open && 'rotate-180'" />
            </button>
            <ul class="mt-3 space-y-1.5 text-xs text-zinc-600 dark:text-zinc-300" x-show="open" x-transition>
                <li>&bull; 18 leave days/year = 12 CSL + 6 MDL.</li>
                <li>&bull; December shutdown uses Mandatory Days (MDL).</li>
                <li>&bull; No lapse — unused leave carries forward.</li>
                <li>&bull; Encashment is paid on approval.</li>
                <li>&bull; Overtime is pre-approved only (₹100/hr), tracked separately.</li>
                <li>&bull; Financial year runs July 1 – June 30.</li>
            </ul>
        </div>
    </div>

    {{-- ─── LEAVE CALENDAR WIDGET ─── --}}
    <div
        class="pulse-margin rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <div class="rounded-lg bg-violet-50 p-1.5 dark:bg-violet-900/20">
                    <flux:icon.calendar-days class="size-4 text-violet-600 dark:text-violet-400" />
                </div>
                <h3 class="text-xs font-black uppercase tracking-[0.12em] text-zinc-600 dark:text-zinc-300">Leave Calendar</h3>
            </div>
            <div class="flex items-center gap-1.5">
                <button wire:click="previousCalendarMonth"
                    class="flex size-7 cursor-pointer items-center justify-center rounded-lg border border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400 transition-colors hover:bg-zinc-50 dark:bg-zinc-800/50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800">
                    <flux:icon.chevron-left class="size-3.5" />
                </button>
                <button wire:click="goToCurrentCalendarMonth"
                    class="cursor-pointer rounded-lg px-3 py-1 text-xs font-black text-zinc-700 dark:text-zinc-200 transition-colors hover:bg-zinc-50 dark:bg-zinc-800/50 dark:text-zinc-200 dark:hover:bg-zinc-800">
                    {{ $calendarLabel }}
                </button>
                <button wire:click="nextCalendarMonth"
                    class="flex size-7 cursor-pointer items-center justify-center rounded-lg border border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400 transition-colors hover:bg-zinc-50 dark:bg-zinc-800/50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800">
                    <flux:icon.chevron-right class="size-3.5" />
                </button>
            </div>
        </div>

        {{-- Legend --}}
        <div
            class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[11px] font-medium text-zinc-500 dark:text-zinc-400">
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-emerald-500"></span>
                Approved</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-amber-400"></span>
                Pending</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-rose-500"></span>
                Holiday</span>
            <span class="inline-flex items-center gap-1.5"><span
                    class="size-2.5 rounded-sm border border-zinc-200 dark:border-zinc-800 bg-zinc-100 dark:bg-zinc-800 dark:border-zinc-700 dark:bg-zinc-800"></span>
                Weekend</span>
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

                    $title = $day['holiday']?->name
                        ?? ($day['approved'] ? $day['approved']->leaveType->name . ' (Approved)' : null)
                        ?? ($day['pending'] ? $day['pending']->leaveType->name . ' (Pending)' : null);
                @endphp
                <div class="{{ $cellClasses }}" @if($title) title="{{ $title }}" @endif>
                    {{ $day['date']->day }}
                    @if($day['approved'] || $day['pending'] || $day['holiday'])
                        <span
                            class="mt-0.5 size-1 rounded-full {{ $day['holiday'] ? 'bg-rose-500' : ($day['approved'] ? 'bg-emerald-500' : 'bg-amber-400') }}"></span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- â"€â"€â"€ REQUEST LEAVE MODAL â"€â"€â"€ --}}
    @if($showRequestModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data
            x-on:keydown.escape.window="$wire.closeRequestModal()">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.closeRequestModal()"></div>
            <div
                class="relative w-full max-w-lg bg-white dark:bg-zinc-900 dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.closeRequestModal()"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:text-zinc-300 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
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

                    <form wire:submit="submitRequest" class="space-y-5">

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
                        @if($selectedType && $selectedBalance)
                            <div
                                class="flex items-center gap-2 rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-2 text-xs text-indigo-700 dark:border-indigo-900/40 dark:bg-indigo-950/20 dark:text-indigo-300">
                                <flux:icon.information-circle class="size-3.5 shrink-0" />
                                <span>Available balance:
                                    <strong>{{ max(0, $selectedBalance->allocated_days - $selectedBalance->used_days - ($selectedBalance->encashed_days ?? 0)) }}
                                        day(s)</strong></span>
                            </div>
                        @endif

                        {{-- Dates --}}
                        <div class="grid grid-cols-2 gap-4">
                            <flux:input wire:model.live="start_date" type="date" label="Start Date" required />
                            <flux:input wire:model.live="end_date" type="date" label="End Date" required />
                        </div>

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

                        {{-- Company holiday warning — holidays cannot be included in leave --}}
                        @if($rangeHolidays->isNotEmpty())
                            <div class="flex items-start gap-2.5 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2.5 dark:border-rose-900/40 dark:bg-rose-950/20">
                                <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0 text-rose-500" />
                                <div class="text-xs">
                                    <p class="font-bold text-rose-800 dark:text-rose-300">This date is already a company holiday.</p>
                                    <p class="mt-0.5 text-rose-600 dark:text-rose-400">
                                        Your range includes
                                        @foreach($rangeHolidays as $rh)<span class="font-semibold">{{ \Carbon\Carbon::parse($rh->date)->format('d M') }} ({{ $rh->name }})</span>@if(! $loop->last), @endif @endforeach.
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
                        <flux:textarea wire:model="reason" label="Reason"
                            placeholder="Please describe why you need time off..." rows="2" required />

                        {{-- Employee Remarks --}}
                        <flux:textarea wire:model="employee_remarks" label="Additional Remarks"
                            placeholder="Any other notes for the approver (optional)..." rows="2" />

                        {{-- Attachment — one supporting document, previewed before submit --}}
                        <div class="rounded-xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 p-4 dark:border-zinc-800 dark:bg-zinc-900/40">
                            <div class="mb-2 flex items-center justify-between">
                                <flux:label>
                                    Attachment
                                    @if($selectedType?->attachment_required)<span class="text-red-500">*</span>@endif
                                </flux:label>
                                <span class="text-[10px] font-semibold text-zinc-400">PDF or image — max 5 MB</span>
                            </div>
                            <input type="file" wire:model="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp"
                                class="block w-full text-xs text-zinc-500 dark:text-zinc-400 file:mr-2 file:rounded-lg file:border-0 file:bg-orange-50 file:px-2.5 file:py-1.5 file:text-xs file:font-bold file:text-orange-600 hover:file:bg-orange-100 dark:text-zinc-400">
                            @error('attachment') <flux:error>{{ $message }}</flux:error> @enderror
                            @if($attachment)
                                <div class="mt-1.5 flex items-center gap-2 rounded-lg bg-white dark:bg-zinc-900 px-2 py-1.5 text-[11px] dark:bg-zinc-800">
                                    @if(str_starts_with($attachment->getMimeType() ?? '', 'image/'))
                                        <img src="{{ $attachment->temporaryUrl() }}" class="size-8 rounded object-cover" alt="preview">
                                    @else
                                        <flux:icon.document-text class="size-4 shrink-0 text-orange-400" />
                                    @endif
                                    <span class="truncate text-zinc-600 dark:text-zinc-300">{{ $attachment->getClientOriginalName() }}</span>
                                </div>
                            @endif
                            <p class="mt-2 text-[10px] text-zinc-400">Attachments are automatically deleted 30 days after the leave is approved.</p>
                        </div>

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

                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="$wire.closeRequestModal()"
                                class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-800 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:bg-zinc-800/50 dark:hover:bg-zinc-700 transition-colors">Cancel</button>
                            <button type="submit" wire:loading.attr="disabled" wire:target="submitRequest,attachment" @disabled($rangeHolidays->isNotEmpty() || $rangeWeekendDays->isNotEmpty())
                                class="inline-flex items-center gap-2 px-5 py-2 text-sm font-bold text-white bg-brand-600 hover:bg-brand-700 rounded-xl transition-colors disabled:cursor-not-allowed disabled:opacity-60">
                                <span wire:loading.remove wire:target="submitRequest,attachment">{{ $rangeHolidays->isNotEmpty() ? 'Holiday in range' : ($rangeWeekendDays->isNotEmpty() ? 'Weekend selected' : 'Submit Request') }}</span>
                                <span wire:loading wire:target="submitRequest,attachment">Submitting...</span>
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    @endif

    {{-- ─── ENCASHMENT MODAL ─── --}}
    <flux:modal name="encashment-modal" class="max-w-lg">
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
                @error('encash_leave_type_id') <p class="text-xs text-red-500">{{ $message }}</p> @enderror

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
                @error('encash_days') <p class="text-xs text-red-500">{{ $message }}</p> @enderror

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
                <button type="button" x-on:click="$flux.modal('encashment-modal').close()"
                    class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-800 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:bg-zinc-800/50 dark:hover:bg-zinc-700 transition-colors">
                    Cancel
                </button>
                <flux:button wire:click="submitEncashment()" variant="primary">Submit Request</flux:button>
            </div>
        </div>
    </flux:modal>


</flux:main>