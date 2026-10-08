{{-- Four primary cards: Attendance Rate (selected month), Worked Today, First In, Last Out / status. --}}
@php
    $card = 'rounded-2xl border border-zinc-200/80 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900';
    $label = 'text-xs font-medium text-zinc-500 dark:text-zinc-400';
    $metric = 'mt-1 text-[28px] font-bold leading-none tracking-tight text-zinc-900 dark:text-white';
    $help = 'mt-2 text-xs text-zinc-500 dark:text-zinc-400';
@endphp

<section class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Attendance summary" data-kpis>
    {{-- 1 · Attendance Rate --}}
    <div class="{{ $card }}" data-kpi="rate">
        <div class="{{ $label }}">Attendance Rate</div>
        <div class="{{ $metric }}">{{ $attendanceRate === null ? '—' : $attendanceRate.'%' }}</div>
        <div class="{{ $help }}">{{ $present }} of {{ $scheduled }} working {{ \Illuminate\Support\Str::plural('day', $scheduled) }} · {{ $isCurrentMonth ? 'so far this month' : $mh['label'] }}</div>
    </div>

    {{-- 2 · Worked Today (live while working) --}}
    <div class="{{ $card }}" data-kpi="worked"
         x-data="{ base: {{ $liveBaseMin }}, start: {{ $liveStartMs ?? 'null' }}, label: @js($hm($workedMin)),
                   tick() { if (this.start === null) return; const t = Math.max(0, this.base + Math.floor((Date.now() - this.start) / 60000)); this.label = Math.floor(t / 60) + 'h ' + String(t % 60).padStart(2, '0') + 'm'; } }"
         x-init="tick(); if (start !== null) setInterval(() => tick(), 30000)">
        <div class="{{ $label }} flex items-center gap-1.5">Worked Today @if($isLive)<span class="size-1.5 animate-pulse rounded-full bg-emerald-500" aria-label="live"></span>@endif</div>
        <div class="{{ $metric }}" x-text="label">{{ $hm($workedMin) }}</div>
        <div class="{{ $help }}">of {{ $hm($targetMin) }} shift
            · Break: <span @class(['font-semibold text-amber-600' => $breakAllowance > 0 && $breakMin > $breakAllowance])>{{ $breakMin }}m</span>@if($breakAllowance > 0) / {{ $breakAllowance }}m allowance @endif
        </div>
    </div>

    {{-- 3 · First In --}}
    <div class="{{ $card }}" data-kpi="first-in">
        <div class="{{ $label }}">First In</div>
        <div class="{{ $metric }}">{{ $firstIn ?? '—' }}</div>
        <div class="mt-2">
            @if($firstIn)
                <span @class([
                    'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold',
                    'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' => $isLate,
                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => ! $isLate,
                ])>{{ $isLate ? 'Late'.($lateMinutes > 0 ? ' · '.$lateMinutes.'m' : '') : 'On time' }}</span>
            @else
                <span class="text-xs text-zinc-500 dark:text-zinc-400">No punch yet today</span>
            @endif
        </div>
    </div>

    {{-- 4 · Last Out / current status --}}
    <div class="{{ $card }}" data-kpi="status">
        @if($isLive)
            <div class="{{ $label }}">Current Status</div>
            <div class="mt-1 text-[22px] font-bold leading-tight text-emerald-600 dark:text-emerald-400">Currently working</div>
            <div class="{{ $help }}">Started {{ $firstIn ?? $todayAttendance?->check_in?->format('h:i A') }}</div>
        @elseif($missingOut)
            <div class="{{ $label }}">Last Out</div>
            <div class="mt-1 text-[22px] font-bold leading-tight text-amber-600 dark:text-amber-400">Missing Checkout</div>
            <div class="{{ $help }} font-semibold text-amber-700 dark:text-amber-400">Action required</div>
        @elseif($lastOut)
            <div class="{{ $label }}">Last Out</div>
            <div class="{{ $metric }}">{{ $lastOut }}</div>
            <div class="mt-2"><span class="inline-flex rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] font-semibold text-zinc-600 dark:bg-white/5 dark:text-zinc-300">Completed</span></div>
        @else
            <div class="{{ $label }}">Current Status</div>
            <div class="mt-1 text-[22px] font-bold leading-tight text-zinc-900 dark:text-white">
                {{ match ($todayRow['status'] ?? null) { null, 'Today' => 'Not clocked in', default => $todayRow['status'] } }}
            </div>
            <div class="{{ $help }}">{{ $todayRow['holiday'] ?? ($shiftWindow ? 'Shift '.$shiftWindow : 'No punch yet today') }}</div>
        @endif
    </div>
</section>
