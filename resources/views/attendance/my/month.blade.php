{{--
    Quick Stats — the selected month at a glance: the day breakdown (monthHistory
    statuses up to today; weekly off, holiday and leave are their own statuses,
    never absences), working days (computeStats) and on-time arrivals.
--}}
@php
    $t = $monthTotals;
    $pastRows = collect($mh['rows'] ?? [])->filter(fn (array $r) => $r['date'] <= today()->toDateString());
    $count = fn (array $statuses) => $pastRows->whereIn('status', $statuses)->count();
    $breakdown = collect([
        ['Present', $count(['Present', 'WFH', 'Half day', \App\Services\Attendance\WorkingDayResolver::WORKED_WEEKLY_OFF_LABEL]), 'bg-emerald-500'],
        ['Late', $count(['Late']), 'bg-amber-400'],
        ['Absent', $count(['Absent']), 'bg-red-500'],
        ['Leave', $count(['Leave']) + 0.5 * $count(['Leave (½ day)']), 'bg-sky-500'],
        ['Weekly Off', $count([\App\Services\Attendance\WorkingDayResolver::WEEKLY_OFF_LABEL]), 'bg-zinc-300 dark:bg-slate-500'],
        ['Holiday', $count(['Holiday', 'MDL shutdown']), 'bg-violet-500'],
    ]);
    $breakdownTotal = max(1, $breakdown->sum(1));
    $onTimePct = $present > 0 ? (int) round(($present - $late) / $present * 100) : null;
    $avgWorked = $present > 0 ? (int) round(collect($chartDaily)->sum('hours') * 60 / $present) : 0;
    $missingPunches = (int) ($t['missing_checkout'] ?? 0);
    $ringLen = 2 * M_PI * 34;
    $fmt = fn ($v) => rtrim(rtrim(number_format($v, 1), '0'), '.');
@endphp

<section @class([$ui['card'], $ui['lift'], 'flex flex-col p-5', 'md:col-span-2 xl:col-span-1' => $hasAttention]) aria-labelledby="month-title" data-month>
    <div class="flex items-start justify-between gap-3">
        <div class="flex items-start gap-3">
            <span class="{{ $ui['tile'] }} bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300"><flux:icon.chart-pie class="size-5" /></span>
            <div>
                <h2 id="month-title" class="{{ $ui['title'] }}">Quick Stats ({{ $isCurrentMonth ? 'This Month' : $mh['label'] }})</h2>
                <p class="{{ $ui['sub'] }} mt-0.5">{{ $mh['label'] }} · up to {{ $isCurrentMonth ? 'today' : 'month end' }}</p>
            </div>
        </div>
    </div>

    {{-- Working days + the month's days as one segmented bar --}}
    <div class="mt-4 flex items-end gap-4">
        <div class="shrink-0">
            <div class="text-[30px] font-bold leading-none tabular-nums text-zinc-900 dark:text-white">{{ $scheduled }}</div>
            <div class="mt-1 text-xs text-zinc-500 dark:text-slate-400">Working {{ \Illuminate\Support\Str::plural('day', $scheduled) }}</div>
        </div>
        <div class="mb-1 flex h-2.5 min-w-0 flex-1 gap-0.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/[0.05]" aria-hidden="true">
            @foreach($breakdown as [$name, $value, $color])
                @if($value > 0)<span class="h-full {{ $color }}" style="flex: {{ $value }} 1 0%" title="{{ $name }}: {{ $fmt($value) }}"></span>@endif
            @endforeach
        </div>
    </div>

    <div class="mb-4 mt-5 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-5">
        {{-- Legend --}}
        <ul class="grid grid-cols-1 gap-x-6 gap-y-2.5 text-sm sm:grid-cols-2">
            @foreach($breakdown as [$name, $value, $color])
                <li class="flex items-center gap-2.5"><span class="size-2.5 shrink-0 rounded-full {{ $color }}"></span><span class="whitespace-nowrap text-zinc-600 dark:text-slate-400">{{ $name }}</span><span class="ml-auto font-bold tabular-nums text-zinc-900 dark:text-white">{{ $fmt($value) }}</span></li>
            @endforeach
        </ul>

        {{-- On-time ring --}}
        <div class="relative mx-auto size-[92px] shrink-0" role="img" aria-label="On-time arrivals {{ $onTimePct === null ? 'not available' : $onTimePct.'%' }}">
            <svg viewBox="0 0 84 84" class="size-[92px] -rotate-90" aria-hidden="true">
                <circle cx="42" cy="42" r="34" fill="none" stroke-width="9" class="stroke-emerald-100 dark:stroke-emerald-500/15" />
                <circle cx="42" cy="42" r="34" fill="none" stroke-width="9" stroke-linecap="round" class="stroke-emerald-500"
                        stroke-dasharray="{{ round($ringLen, 2) }}" stroke-dashoffset="{{ round($ringLen * (1 - ($onTimePct ?? 0) / 100), 2) }}" />
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
                <span class="text-lg font-bold leading-none text-zinc-900 dark:text-white">{{ $onTimePct === null ? '—' : $onTimePct.'%' }}</span>
                <span class="mt-1 text-[10px] leading-tight text-zinc-500 dark:text-slate-400">On-time<br>Arrivals</span>
            </div>
        </div>
    </div>

    <div class="mt-auto grid grid-cols-3 gap-2 border-t border-zinc-100 pt-3 text-xs dark:border-white/[0.06]">
        <div><div class="text-zinc-500 dark:text-slate-400">Avg / day</div><div class="mt-0.5 font-bold tabular-nums text-zinc-900 dark:text-white">{{ $present > 0 ? $hm($avgWorked) : '—' }}</div></div>
        <div><div class="text-zinc-500 dark:text-slate-400">Missing punches</div><div @class(['mt-0.5 font-bold', 'text-orange-600 dark:text-orange-400' => $missingPunches > 0, 'text-zinc-900 dark:text-white' => $missingPunches === 0])>{{ $missingPunches }}</div></div>
        <div><div class="text-zinc-500 dark:text-slate-400">Approved OT</div><div class="mt-0.5 font-bold tabular-nums text-zinc-900 dark:text-white">{{ (float) ($stats['ot_hours'] ?? 0) > 0 ? $stats['ot_hours'].'h' : '0h' }}</div></div>
    </div>
</section>
