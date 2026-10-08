{{-- Four primary cards: Attendance Rate (selected month), Worked Today, First In, Last Out / status. --}}
@php
    $card = 'rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900';
    $tile = 'flex size-12 shrink-0 items-center justify-center rounded-full';
    $label = 'text-[13px] font-medium text-zinc-500 dark:text-zinc-400';
    $metric = 'mt-0.5 text-[28px] font-bold leading-none tracking-tight text-zinc-900 dark:text-white';
    $help = 'mt-3 text-xs text-zinc-500 dark:text-zinc-400';
    $pill = 'mt-2 inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold';
@endphp

<section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Attendance summary" data-kpis>
    {{-- 1 · Attendance Rate --}}
    <div class="{{ $card }}" data-kpi="rate">
        <div class="flex items-center gap-4">
            <span class="{{ $tile }} bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400"><flux:icon.chart-bar class="size-6" /></span>
            <div class="min-w-0">
                <div class="{{ $label }}">Attendance Rate</div>
                <div class="{{ $metric }}">{{ $attendanceRate === null ? '—' : $attendanceRate.'%' }}</div>
            </div>
        </div>
        <div class="{{ $help }}">{{ $present }} of {{ $scheduled }} working {{ \Illuminate\Support\Str::plural('day', $scheduled) }} · {{ $isCurrentMonth ? 'so far this month' : $mh['label'] }}</div>
    </div>

    {{-- 2 · Worked Today (live while working) --}}
    <div class="{{ $card }}" data-kpi="worked"
         x-data="{ base: {{ $liveBaseMin }}, start: {{ $liveStartMs ?? 'null' }}, label: @js($hm($workedMin)),
                   tick() { if (this.start === null) return; const t = Math.max(0, this.base + Math.floor((Date.now() - this.start) / 60000)); this.label = Math.floor(t / 60) + 'h ' + String(t % 60).padStart(2, '0') + 'm'; } }"
         x-init="tick(); if (start !== null) setInterval(() => tick(), 30000)">
        <div class="flex items-center gap-4">
            <span class="{{ $tile }} bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400"><flux:icon.clock class="size-6" /></span>
            <div class="min-w-0">
                <div class="{{ $label }} flex items-center gap-1.5">Worked Today @if($isLive)<span class="size-1.5 animate-pulse rounded-full bg-emerald-500" aria-label="live"></span>@endif</div>
                <div class="{{ $metric }}" x-text="label">{{ $hm($workedMin) }}</div>
            </div>
        </div>
        <div class="{{ $help }}">of {{ $hm($targetMin) }} shift ·
            <span class="font-semibold text-zinc-700 dark:text-zinc-200">Break:</span>
            <span @class(['font-semibold', 'text-orange-600' => $breakAllowance > 0 && $breakMin > $breakAllowance, 'text-zinc-700 dark:text-zinc-200' => ! ($breakAllowance > 0 && $breakMin > $breakAllowance)])>{{ $breakMin }}m</span>@if($breakAllowance > 0) / {{ $breakAllowance }}m @endif
        </div>
    </div>

    {{-- 3 · First In --}}
    <div class="{{ $card }}" data-kpi="first-in">
        <div class="flex items-center gap-4">
            <span class="{{ $tile }} bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300"><flux:icon.arrow-right-end-on-rectangle class="size-6" /></span>
            <div class="min-w-0">
                <div class="{{ $label }}">First In</div>
                <div class="{{ $metric }}">{{ $firstIn ?? '—' }}</div>
                @if($firstIn)
                    <span @class([
                        $pill,
                        'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' => $isLate,
                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => ! $isLate,
                    ])>{{ $isLate ? 'Late'.($lateMinutes > 0 ? ' · '.$lateMinutes.'m' : '') : 'On time' }}</span>
                @else
                    <div class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">No punch yet today</div>
                @endif
            </div>
        </div>
    </div>

    {{-- 4 · Last Out / current status --}}
    <div class="{{ $card }}" data-kpi="status">
        <div class="flex items-center gap-4">
            <span class="{{ $tile }} bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400"><flux:icon.arrow-left-start-on-rectangle class="size-6" /></span>
            <div class="min-w-0">
                @if($isLive)
                    <div class="{{ $label }}">Current Status</div>
                    <div class="mt-0.5 text-[22px] font-bold leading-tight text-emerald-600 dark:text-emerald-400">Currently working</div>
                    <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Started {{ $firstIn ?? $todayAttendance?->check_in?->format('h:i A') }}</div>
                @elseif($missingOut)
                    <div class="{{ $label }}">Last Out</div>
                    <div class="mt-0.5 text-[22px] font-bold leading-tight text-red-600 dark:text-red-400">Missing Checkout</div>
                    <span class="{{ $pill }} bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400">Action required</span>
                @elseif($lastOut)
                    <div class="{{ $label }}">Last Out</div>
                    <div class="{{ $metric }}">{{ $lastOut }}</div>
                    @if($autoClosed)
                        <span class="{{ $pill }} bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300" title="{{ \App\Models\Attendance::AUTO_CHECKOUT_EXPLANATION }}">Auto Checkout</span>
                    @else
                        <span class="{{ $pill }} bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300">Completed</span>
                    @endif
                @else
                    <div class="{{ $label }}">Current Status</div>
                    <div class="mt-0.5 text-[22px] font-bold leading-tight text-zinc-900 dark:text-white">
                        {{ match ($todayRow['status'] ?? null) { null, 'Today' => 'Not clocked in', default => $todayRow['status'] } }}
                    </div>
                    <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $todayRow['holiday'] ?? ($shiftWindow ? 'Shift '.$shiftWindow : 'No punch yet today') }}</div>
                @endif
            </div>
        </div>
    </div>
</section>
