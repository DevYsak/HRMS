{{--
    NexflowActivity — Livewire Blade View
    Shows daily clock time + break time from Nexflow (NexBridge API)
    Mounted inside the "Nexflow" tab of the Employee Edit page.
--}}

<div class="space-y-6">

    {{-- ── Header + Date Range Controls ──────────────────────────────────── --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                {{-- Nexflow brand dot --}}
                <span class="flex size-2 rounded-full bg-indigo-500"></span>
                <h3 class="text-base font-black text-zinc-900 dark:text-white">Nexflow Activity</h3>
            </div>
            <p class="mt-1 text-sm text-zinc-400">Live clock & break data pulled from Nexflow task tracker.</p>
        </div>

        <div class="flex items-end gap-3">
            <div>
                <label class="mb-1 block text-xs font-bold text-zinc-500 uppercase tracking-wider">From</label>
                <input type="date" wire:model.live="from" class="pulse-input" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-bold text-zinc-500 uppercase tracking-wider">To</label>
                <input type="date" wire:model.live="to" class="pulse-input" />
            </div>
            <button type="button" wire:click="loadData"
                class="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-indigo-700 active:scale-95 transition-all">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
                {{ $loaded ? 'Refresh' : 'Load Data' }}
            </button>
        </div>
    </div>

    {{-- ── Loading State ────────────────────────────────────────────────── --}}
    <div wire:loading.flex class="items-center justify-center py-16">
        <div class="flex flex-col items-center gap-3 text-zinc-400">
            <svg class="size-8 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <span class="text-sm font-medium">Fetching from Nexflow…</span>
        </div>
    </div>

    <div wire:loading.remove>

        {{-- ── Not loaded yet — empty prompt ─────────────────────────────── --}}
        @if(! $loaded && ! $errorMsg)
            <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-zinc-200 bg-zinc-50 py-16 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-4 flex size-14 items-center justify-center rounded-2xl bg-indigo-50 dark:bg-indigo-900/20">
                    <svg class="size-7 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>
                <h4 class="font-bold text-zinc-700 dark:text-zinc-200">No data loaded yet</h4>
                <p class="mt-1 max-w-xs text-sm text-zinc-400">
                    Set a date range and click <strong>Load Data</strong> to pull this employee's clock and break time from Nexflow.
                </p>
                <button type="button" wire:click="loadData"
                    class="mt-5 flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-indigo-700 transition-colors">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    Load Nexflow Data
                </button>
            </div>
        @endif

        {{-- ── Error State ─────────────────────────────────────────────── --}}
        @if($errorMsg)
            <div class="flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-900/40 dark:bg-rose-950/20">
                <svg class="mt-0.5 size-5 shrink-0 text-rose-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
                <div>
                    <p class="text-sm font-bold text-rose-700 dark:text-rose-400">Could not load Nexflow data</p>
                    <p class="mt-0.5 text-sm text-rose-600 dark:text-rose-300">{{ $errorMsg }}</p>
                </div>
            </div>
        @endif

        {{-- ── Data Loaded ────────────────────────────────────────────────── --}}
        @if($loaded && $clockData && ! $errorMsg)

            @php
                $summary = $clockData['summary'];
                $period  = $clockData['period'];
                $emp     = $clockData['employee'];
                $days    = collect($clockData['daily_breakdown']);
                $workDays = $days->where('work_minutes', '>', 0);
            @endphp

            {{-- ── Summary stat cards ──────────────────────────────────── --}}
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">

                {{-- Total Work Hours --}}
                <div class="rounded-2xl border border-indigo-100 bg-indigo-50 p-4 dark:border-indigo-900/30 dark:bg-indigo-950/20">
                    <div class="text-xs font-bold uppercase tracking-widest text-indigo-400">Total Work</div>
                    <div class="mt-1 text-2xl font-black text-indigo-700 dark:text-indigo-300">
                        {{ number_format($summary['total_work_hours'], 1) }}h
                    </div>
                    <div class="text-xs text-indigo-400">{{ $summary['total_work_minutes'] }} mins logged</div>
                </div>

                {{-- Net Work Hours (work - break) --}}
                <div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-4 dark:border-emerald-900/30 dark:bg-emerald-950/20">
                    <div class="text-xs font-bold uppercase tracking-widest text-emerald-400">Net Work</div>
                    <div class="mt-1 text-2xl font-black text-emerald-700 dark:text-emerald-300">
                        {{ number_format($summary['net_work_hours'], 1) }}h
                    </div>
                    <div class="text-xs text-emerald-400">After deducting breaks</div>
                </div>

                {{-- Total Break Time --}}
                <div class="rounded-2xl border border-amber-100 bg-amber-50 p-4 dark:border-amber-900/30 dark:bg-amber-950/20">
                    <div class="text-xs font-bold uppercase tracking-widest text-amber-400">Break Time</div>
                    <div class="mt-1 text-2xl font-black text-amber-700 dark:text-amber-300">
                        {{ $summary['total_break_minutes'] }}m
                    </div>
                    <div class="text-xs text-amber-400">
                        {{ $summary['active_days'] > 0 ? round($summary['total_break_minutes'] / $summary['active_days'], 0) : 0 }} m/day avg
                    </div>
                </div>

                {{-- Active Days + Avg --}}
                <div class="rounded-2xl border border-zinc-100 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-xs font-bold uppercase tracking-widest text-zinc-400">Active Days</div>
                    <div class="mt-1 text-2xl font-black text-zinc-800 dark:text-zinc-100">
                        {{ $summary['active_days'] }}
                    </div>
                    <div class="text-xs text-zinc-400">
                        Avg {{ $summary['avg_work_hours_per_day'] }}h/day
                    </div>
                </div>

            </div>

            {{-- OT badge (show only if any OT) --}}
            @if($summary['ot_hours'] > 0)
                <div class="flex items-center gap-2 rounded-xl border border-purple-200 bg-purple-50 px-4 py-3 dark:border-purple-900/40 dark:bg-purple-950/20">
                    <svg class="size-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <span class="text-sm font-bold text-purple-700 dark:text-purple-300">
                        {{ $summary['ot_hours'] }}h overtime in this period
                    </span>
                </div>
            @endif

            {{-- ── Daily Breakdown Table ───────────────────────────────── --}}
            <div>
                <h4 class="mb-3 text-xs font-black uppercase tracking-widest text-zinc-400">Daily Breakdown</h4>
                <div class="overflow-hidden rounded-2xl border border-zinc-100 dark:border-zinc-800">
                    <table class="pulse-table">
                        <thead>
                            <tr>
                                <th class="pulse-th pl-4">Date</th>
                                <th class="pulse-th text-right">Work</th>
                                <th class="pulse-th text-right">Break</th>
                                <th class="pulse-th text-right">Net</th>
                                <th class="pulse-th text-right">OT</th>
                                <th class="pulse-th text-right">Sessions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($days->sortByDesc('date') as $day)
                                @php
                                    $isWeekend = $day['is_weekend'];
                                    $isToday   = $day['date'] === now()->toDateString();
                                    $hasWork   = $day['work_minutes'] > 0;
                                @endphp
                                <tr class="{{ $isWeekend ? 'opacity-50' : '' }} {{ $isToday ? 'bg-brand-50/30 dark:bg-brand-950/10' : '' }}">

                                    {{-- Date --}}
                                    <td class="pulse-td pl-4">
                                        <div class="flex items-center gap-2">
                                            @if($isToday)
                                                <span class="flex size-1.5 rounded-full bg-brand-500"></span>
                                            @endif
                                            <div>
                                                <div class="font-bold text-zinc-800 dark:text-zinc-100">
                                                    {{ \Carbon\Carbon::parse($day['date'])->format('d M') }}
                                                </div>
                                                <div class="text-[11px] text-zinc-400">
                                                    {{ $day['day_name'] }}
                                                    @if($isWeekend) · Weekend @endif
                                                    @if($isToday) · Today @endif
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    {{-- Work Hours --}}
                                    <td class="pulse-td text-right">
                                        @if($hasWork)
                                            <span class="font-bold text-zinc-800 dark:text-zinc-100">{{ $day['work_hours'] }}h</span>
                                            <div class="text-[11px] text-zinc-400">{{ $day['work_minutes'] }}m</div>
                                        @else
                                            <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                        @endif
                                    </td>

                                    {{-- Break Time --}}
                                    <td class="pulse-td text-right">
                                        @if($day['break_minutes'] > 0)
                                            <span class="font-bold text-amber-600 dark:text-amber-400">{{ $day['break_minutes'] }}m</span>
                                            <div class="text-[11px] text-zinc-400">{{ $day['breaks_count'] }} break{{ $day['breaks_count'] !== 1 ? 's' : '' }}</div>
                                        @else
                                            <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                        @endif
                                    </td>

                                    {{-- Net Work --}}
                                    <td class="pulse-td text-right">
                                        @if($hasWork)
                                            <span class="font-black text-emerald-600 dark:text-emerald-400">{{ $day['net_work_hours'] }}h</span>
                                        @else
                                            <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                        @endif
                                    </td>

                                    {{-- OT --}}
                                    <td class="pulse-td text-right">
                                        @if($day['ot_minutes'] > 0)
                                            <span class="font-bold text-purple-600 dark:text-purple-400">{{ $day['ot_hours'] }}h</span>
                                        @else
                                            <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                        @endif
                                    </td>

                                    {{-- Sessions --}}
                                    <td class="pulse-td text-right">
                                        @if($hasWork)
                                            <span class="inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] font-bold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                                {{ $day['sessions'] }}
                                            </span>
                                        @else
                                            <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="pulse-table__empty">No days in selected range.</td>
                                </tr>
                            @endforelse
                        </tbody>

                        {{-- Footer totals --}}
                        <tfoot>
                            <tr class="border-t-2 border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
                                <td class="px-4 py-3 text-xs font-black uppercase tracking-wider text-zinc-500">
                                    Total · {{ $summary['active_days'] }} days
                                </td>
                                <td class="px-4 py-3 text-right font-black text-zinc-800 dark:text-zinc-100">
                                    {{ $summary['total_work_hours'] }}h
                                </td>
                                <td class="px-4 py-3 text-right font-black text-amber-600">
                                    {{ $summary['total_break_minutes'] }}m
                                </td>
                                <td class="px-4 py-3 text-right font-black text-emerald-600">
                                    {{ $summary['net_work_hours'] }}h
                                </td>
                                <td class="px-4 py-3 text-right font-black text-purple-600">
                                    @if($summary['ot_hours'] > 0) {{ $summary['ot_hours'] }}h @else — @endif
                                </td>
                                <td class="px-4 py-3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            {{-- ── Source footnote ────────────────────────────────────── --}}
            <div class="flex items-center gap-1.5 text-xs text-zinc-400">
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                </svg>
                Data from Nexflow · Nexflow ID: <span class="font-mono font-bold">{{ $emp['nexflow_id'] }}</span> ·
                Generated {{ \Carbon\Carbon::parse($clockData['generated_at'])->format('d M Y, H:i') }}
                @if(config('nexbridge.cache_ttl') > 0)
                    · Cached for {{ config('nexbridge.cache_ttl') / 60 }} min
                @endif
            </div>

        @endif

    </div>{{-- end wire:loading.remove --}}

</div>
