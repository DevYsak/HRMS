{{-- Four primary cards: Attendance Rate (selected month), Worked Today, First In, Current Status. --}}
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

    {{-- 4 · Current Status (canonical: todayCalc / punch timeline / day type) --}}
    @php
        [$statusText, $statusTone, $statusSub] = match (true) {
            $isLive && $activeBreak !== null => ['On Break', 'text-amber-600 dark:text-amber-400', 'Since '.$activeBreak->break_start->format('h:i A')],
            $isLive => ['At Work', 'text-emerald-600 dark:text-emerald-400', 'Since '.($liveStartMs ? \Illuminate\Support\Carbon::createFromTimestampMs($liveStartMs, config('app.timezone'))->format('h:i A') : ($firstIn ?? '—'))],
            $missingOut => ['Missing Checkout', 'text-red-600 dark:text-red-400', 'Action required — request regularisation'],
            $autoClosed => ['Auto Checkout', 'text-indigo-600 dark:text-indigo-300', 'Closed at '.$lastOut.' (shift end)'],
            (bool) $lastOut => ['Checked Out', 'text-zinc-900 dark:text-white', 'At '.$lastOut],
            default => [
                match ($todayRow['status'] ?? null) { null, 'Today' => 'Not Clocked In', 'On leave' => 'On Leave', default => $todayRow['status'] },
                'text-zinc-900 dark:text-white',
                $todayRow['holiday'] ?? ($shiftWindow ? 'Shift '.$shiftWindow : 'No punch yet today'),
            ],
        };
    @endphp
    <div class="{{ $card }}" data-kpi="status">
        <div class="flex items-center gap-4">
            <span class="{{ $tile }} bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400"><flux:icon.signal class="size-6" /></span>
            <div class="min-w-0">
                <div class="{{ $label }} flex items-center gap-1.5">Current Status @if($isLive && $activeBreak === null)<span class="size-1.5 rounded-full bg-emerald-500 motion-safe:animate-pulse" aria-hidden="true"></span>@endif</div>
                <div class="mt-0.5 text-[22px] font-bold leading-tight {{ $statusTone }}" data-current-status>{{ $statusText }}</div>
                <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400" @if($autoClosed) title="{{ \App\Models\Attendance::AUTO_CHECKOUT_EXPLANATION }}" @endif>{{ $statusSub }}</div>
            </div>
        </div>
    </div>
</section>
