{{--
    Four primary cards: Attendance Rate (selected month, computeStats),
    Worked Today (AttendanceCalculator, ticking while working), First In and
    Current Status (todayCalc / punch timeline / day type).
--}}
@php
    $label = 'text-[13px] font-medium text-zinc-500 dark:text-slate-400';
    $metric = 'mt-1 text-[28px] font-bold leading-none tracking-tight tabular-nums text-zinc-900 dark:text-white';
    $pill = 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold';
    $rateDelta = $comparison['rate'] ?? null;
    $ringPct = $attendanceRate ?? 0;
    $ringLen = 2 * M_PI * 26;
    $workedPct = $targetMin > 0 ? (int) min(100, round($workedMin / $targetMin * 100)) : 0;
    $shiftStart = $shiftWindow ? trim(\Illuminate\Support\Str::before($shiftWindow, '–')) : null;
    $workedNote = match (true) {
        $targetMin > 0 && $workedMin >= $targetMin => ['Shift target reached', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
        $isLive => ['In progress · '.$workedPct.'% of shift', 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'],
        (bool) $lastOut && $targetMin > 0 => [$hm($targetMin - $workedMin).' short of shift', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
        default => null,
    };

    [$statusText, $statusTone, $statusSub] = match (true) {
        $isLive && $activeBreak !== null => ['On Break', 'text-amber-600 dark:text-amber-400', 'Since '.$activeBreak->break_start->toImmutable()->userTime()],
        $isLive => ['At Work', 'text-emerald-600 dark:text-emerald-400', 'Since '.($liveStartMs ? \Illuminate\Support\Carbon::createFromTimestampMs($liveStartMs, config('app.timezone'))->toImmutable()->userTime() : ($firstIn ?? '—'))],
        $missingOut => ['Missing Checkout', 'text-red-600 dark:text-red-400', 'Action required — request regularisation'],
        $autoClosed => ['Auto Checkout', 'text-amber-600 dark:text-amber-400', 'Closed at '.$lastOut.' (shift end)'],
        (bool) $lastOut => ['Checked Out', 'text-zinc-900 dark:text-white', 'At '.$lastOut],
        default => [
            match ($todayRow['status'] ?? null) { null, 'Today' => 'Not Clocked In', 'On leave' => 'On Leave', default => $todayRow['status'] },
            'text-zinc-900 dark:text-white',
            $todayRow['holiday'] ?? ($firstIn ? null : 'No punch yet today'),
        ],
    };
@endphp

<section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Attendance summary" data-kpis>
    {{-- 1 · Attendance Rate --}}
    <div class="{{ $ui['card'] }} {{ $ui['lift'] }} p-5" data-kpi="rate">
        <div class="flex items-center gap-4">
            <div class="relative size-16 shrink-0" aria-hidden="true">
                <svg viewBox="0 0 64 64" class="size-16 -rotate-90">
                    <circle cx="32" cy="32" r="26" fill="none" stroke-width="7" class="stroke-emerald-100 dark:stroke-emerald-500/15" />
                    <circle cx="32" cy="32" r="26" fill="none" stroke-width="7" stroke-linecap="round" class="stroke-emerald-500 motion-safe:transition-all motion-safe:duration-700"
                            stroke-dasharray="{{ round($ringLen, 2) }}" stroke-dashoffset="{{ round($ringLen * (1 - $ringPct / 100), 2) }}" />
                </svg>
                <flux:icon.chart-bar class="absolute inset-0 m-auto size-5 text-emerald-600 dark:text-emerald-400" />
            </div>
            <div class="min-w-0">
                <div class="{{ $label }}">Attendance Rate</div>
                <div class="{{ $metric }}">{{ $attendanceRate === null ? '—' : $attendanceRate.'%' }}</div>
            </div>
        </div>
        <div class="mt-3 text-xs text-zinc-500 dark:text-slate-400">{{ $present }} of {{ $scheduled }} working {{ \Illuminate\Support\Str::plural('day', $scheduled) }} · {{ $isCurrentMonth ? 'so far this month' : $mh['label'] }}</div>
        @if($rateDelta !== null && $rateDelta !== 0)
            <span @class([$pill, 'mt-2', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $rateDelta > 0, 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' => $rateDelta < 0]) data-rate-delta>
                <flux:icon :name="$rateDelta > 0 ? 'arrow-trending-up' : 'arrow-trending-down'" variant="micro" class="size-3.5" />
                {{ $rateDelta > 0 ? '+' : '' }}{{ $rateDelta }} pts {{ $comparison['label'] ?? '' }}
            </span>
        @endif
    </div>

    {{-- 2 · Worked Today (ticks while working) --}}
    <div class="{{ $ui['card'] }} {{ $ui['lift'] }} p-5" data-kpi="worked"
         x-data="{ base: {{ $liveBaseMin }}, start: {{ $liveStartMs ?? 'null' }}, target: {{ max(1, $targetMin) }}, label: @js($hm($workedMin)), pct: {{ $workedPct }}, timer: null,
                   tick() { if (this.start === null) return; const t = Math.max(0, this.base + Math.floor((Date.now() - this.start) / 60000)); this.label = Math.floor(t / 60) + 'h ' + String(t % 60).padStart(2, '0') + 'm'; this.pct = Math.min(100, Math.round(t / this.target * 100)); },
                   init() { this.tick(); if (this.start !== null) this.timer = setInterval(() => this.tick(), 30000) }, destroy() { clearInterval(this.timer) } }">
        <div class="flex items-center gap-4">
            <span class="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-50 to-blue-100 text-blue-600 dark:from-sky-500/15 dark:to-blue-500/10 dark:text-sky-300"><flux:icon.clock class="size-7" /></span>
            <div class="min-w-0">
                <div class="{{ $label }} flex items-center gap-1.5">Worked Today @if($isLive)<span class="size-1.5 rounded-full bg-emerald-500 motion-safe:animate-pulse" aria-label="live"></span>@endif</div>
                <div class="{{ $metric }}" x-text="label">{{ $hm($workedMin) }}</div>
            </div>
        </div>
        <div class="mt-3 flex items-center justify-between gap-2 text-xs text-zinc-500 dark:text-slate-400">
            <span>of {{ $hm($targetMin) }} shift</span>
            <span>Break <span @class(['font-semibold', 'text-orange-600 dark:text-orange-400' => $breakAllowance > 0 && $breakMin > $breakAllowance, 'text-zinc-700 dark:text-slate-200' => ! ($breakAllowance > 0 && $breakMin > $breakAllowance)])>{{ $breakMin }}m</span>@if($breakAllowance > 0) / {{ $breakAllowance }}m @endif</span>
        </div>
        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-sky-100 dark:bg-white/[0.06]" role="progressbar" aria-label="Share of today's shift worked" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $workedPct }}">
            <div class="h-full rounded-full bg-gradient-to-r from-sky-400 to-blue-600 motion-safe:transition-all motion-safe:duration-700" style="width: {{ $workedPct }}%" :style="`width: ${pct}%`"></div>
        </div>
        @if($workedNote)
            <span class="{{ $pill }} mt-2 {{ $workedNote[1] }}">{{ $workedNote[0] }}</span>
        @endif
    </div>

    {{-- 3 · First In --}}
    <div class="{{ $ui['card'] }} {{ $ui['lift'] }} p-5" data-kpi="first-in">
        <div class="flex items-center gap-4">
            <span class="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-violet-50 to-purple-100 text-violet-600 dark:from-violet-500/15 dark:to-purple-500/10 dark:text-violet-300"><flux:icon.arrow-right-end-on-rectangle class="size-7" /></span>
            <div class="min-w-0">
                <div class="{{ $label }}">First In</div>
                <div class="{{ $metric }}">{{ $firstIn ?? '—' }}</div>
            </div>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-zinc-500 dark:text-slate-400">
            @if($firstIn)
                <span @class([
                    $pill,
                    'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $isLate,
                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => ! $isLate,
                ])>{{ $isLate ? 'Late'.($lateMinutes > 0 ? ' · '.$lateMinutes.'m' : '') : 'On time' }}</span>
            @else
                <span>No punch yet today</span>
            @endif
            @if($shiftStart)<span>Shift starts {{ $shiftStart }}</span>@endif
        </div>
    </div>

    {{-- 4 · Current Status (canonical: todayCalc / punch timeline / day type) --}}
    <div class="{{ $ui['card'] }} {{ $ui['lift'] }} p-5" data-kpi="status">
        <div class="flex items-center gap-4">
            <span class="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-50 to-amber-100 text-orange-600 dark:from-orange-500/15 dark:to-amber-500/10 dark:text-orange-300"><flux:icon.signal class="size-7" /></span>
            <div class="min-w-0">
                <div class="{{ $label }} flex flex-wrap items-center gap-2">Current Status
                    @if($isLive)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30" data-live-badge>
                            <span class="relative flex size-1.5" aria-hidden="true"><span class="absolute inline-flex size-full rounded-full bg-emerald-400 opacity-75 motion-safe:animate-ping"></span><span class="relative inline-flex size-1.5 rounded-full bg-emerald-500"></span></span>
                            Live
                        </span>
                    @endif
                </div>
                <div class="mt-1 text-[24px] font-bold leading-tight tracking-tight {{ $statusTone }}" data-current-status>{{ $statusText }}</div>
            </div>
        </div>
        <div class="mt-3 space-y-0.5 text-xs text-zinc-500 dark:text-slate-400">
            @if($statusSub)<div @if($autoClosed) title="{{ \App\Models\Attendance::AUTO_CHECKOUT_EXPLANATION }}" @endif>{{ $statusSub }}</div>@endif
            @if($shiftWindow)<div>Shift: {{ $shiftWindow }}</div>@endif
        </div>
    </div>
</section>
