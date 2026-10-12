{{--
    Working Hours — the last 7 working days of the selected month that were
    worked (monthHistory: AttendanceCalculator worked minutes; weekly off,
    holiday and approved leave are left out) against the shift target.
    Punctuality — present / late / absent by week (monthHistory statuses;
    weekly off, holiday and leave are never absent).
    Plain HTML bars: each one is focusable and has its own tooltip.
--}}
@php
    $workedDays = collect($mh['rows'] ?? [])
        ->filter(fn (array $r) => $r['date'] <= today()->toDateString()
            && in_array($r['status'], ['Present', 'Late', 'WFH', 'Half day'], true)
            && (int) $r['worked_minutes'] > 0)
        ->take(-7)->values();
    $hoursScale = max(12, (int) ceil(max($targetMin, (int) $workedDays->max('worked_minutes')) / 60 / 3) * 3);
    $ticks = collect(range($hoursScale, 0, -3));
    $targetPct = $targetMin / 60 / $hoursScale * 100;
    $weeks = collect($this->monthPunctuality);
    $weekScale = max(1, (int) $weeks->max(fn ($w) => $w['present'] + $w['late'] + $w['absent']));
    $hasPunctuality = $weeks->sum(fn ($w) => $w['present'] + $w['late'] + $w['absent']) > 0;
    $legend = [['Present', 'bg-emerald-500'], ['Late', 'bg-amber-400'], ['Absent', 'bg-rose-500']];
@endphp

{{-- Working Hours --}}
<section class="{{ $ui['card'] }} min-w-0 p-5 lg:col-span-2 xl:col-span-1" aria-labelledby="hours-title" data-working-hours>
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="flex items-start gap-3">
            <span class="{{ $ui['tile'] }} bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300"><flux:icon.chart-bar class="size-5" /></span>
            <div>
                <h2 id="hours-title" class="{{ $ui['title'] }}">Working Hours</h2>
                <p class="{{ $ui['sub'] }} mt-0.5">Last {{ $workedDays->count() }} working {{ \Illuminate\Support\Str::plural('day', $workedDays->count()) }} · {{ $mh['label'] }}</p>
            </div>
        </div>
        <span class="inline-flex items-center gap-1.5 text-xs text-zinc-500 dark:text-slate-400"><span class="h-0 w-4 border-t-2 border-dashed border-zinc-400 dark:border-slate-500"></span> {{ rtrim(rtrim(number_format($targetMin / 60, 1), '0'), '.') }}h target</span>
    </div>

    @if($workedDays->isNotEmpty())
        <div class="mt-5 flex gap-2">
            <div class="flex h-48 flex-col justify-between pb-6 text-right text-[10px] tabular-nums text-zinc-400 dark:text-slate-500" aria-hidden="true">
                @foreach($ticks as $tick)<span class="-translate-y-1/2 leading-none">{{ $tick }}h</span>@endforeach
            </div>
            <div class="relative min-w-0 flex-1">
                <div class="pointer-events-none absolute inset-x-0 top-0 h-[168px]" aria-hidden="true">
                    @foreach($ticks as $tick)
                        <div class="absolute inset-x-0 border-t border-zinc-100 dark:border-white/[0.05]" style="bottom: {{ $tick / $hoursScale * 100 }}%"></div>
                    @endforeach
                    <div class="absolute inset-x-0 z-10 border-t-2 border-dashed border-zinc-400/80 dark:border-slate-500" style="bottom: {{ $targetPct }}%" data-hours-target></div>
                </div>
                <ol class="relative flex h-48 items-end justify-around gap-2 sm:gap-3">
                    @foreach($workedDays as $day)
                        @php
                            $worked = (int) $day['worked_minutes'];
                            $diff = $worked - $targetMin;
                            $date = \Carbon\Carbon::parse($day['date']);
                            $isTodayBar = $day['date'] === today()->toDateString();
                            $diffText = $diff === 0 ? 'On target' : ($diff > 0 ? '+'.$hm($diff).' over' : $hm(-$diff).' short');
                        @endphp
                        <li class="flex h-full min-w-0 flex-1 flex-col items-center justify-end" data-hours-bar="{{ $day['date'] }}">
                            <div class="relative flex h-[168px] w-full max-w-11 items-end justify-center">
                                <flux:tooltip position="top" class="block w-full">
                                    <button type="button" class="group relative w-full rounded-t-lg rounded-b-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 motion-safe:hover:brightness-105 {{ $worked >= $targetMin ? 'bg-gradient-to-t from-orange-600 to-amber-400' : 'bg-gradient-to-t from-orange-400/80 to-amber-300/80 dark:from-orange-500/70 dark:to-amber-400/60' }}"
                                            style="height: {{ max(4, (int) round(min($worked / 60, $hoursScale) / $hoursScale * 168)) }}px"
                                            aria-label="{{ $date->format('D d M') }}: worked {{ $hm($worked) }}, target {{ $hm($targetMin) }}, {{ $diffText }}">
                                        @if($isTodayBar || $loop->last)
                                            <span class="absolute -top-7 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-md bg-zinc-900 px-1.5 py-0.5 text-[10px] font-semibold text-white shadow dark:bg-white dark:text-zinc-900">{{ $hm($worked) }}</span>
                                        @endif
                                    </button>
                                    <flux:tooltip.content class="text-left">
                                        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 p-0.5">
                                            <dt class="text-zinc-400">Date</dt><dd class="font-medium text-white">{{ $date->format('D, d M Y') }}</dd>
                                            <dt class="text-zinc-400">Worked</dt><dd class="font-medium text-white">{{ $hm($worked) }}</dd>
                                            <dt class="text-zinc-400">Target</dt><dd class="font-medium text-white">{{ $hm($targetMin) }}</dd>
                                            <dt class="text-zinc-400">Difference</dt><dd @class(['font-medium', 'text-emerald-300' => $diff >= 0, 'text-amber-300' => $diff < 0])>{{ $diffText }}</dd>
                                        </dl>
                                    </flux:tooltip.content>
                                </flux:tooltip>
                            </div>
                            <span class="mt-2 whitespace-nowrap text-[10px] font-medium text-zinc-500 dark:text-slate-400">{{ $isTodayBar ? 'Today' : $date->format('d M') }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    @else
        <p class="flex h-48 items-center justify-center text-xs text-zinc-400 dark:text-slate-500">No worked days yet.</p>
    @endif
</section>

{{-- Punctuality --}}
<section class="{{ $ui['card'] }} min-w-0 p-5" aria-labelledby="punctuality-title" data-punctuality>
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="flex items-start gap-3">
            <span class="{{ $ui['tile'] }} bg-rose-100 text-rose-600 dark:bg-rose-500/15 dark:text-rose-300"><flux:icon.arrow-trending-up class="size-5" /></span>
            <div>
                <h2 id="punctuality-title" class="{{ $ui['title'] }}">Punctuality</h2>
                <p class="{{ $ui['sub'] }} mt-0.5">Present, late and absent days by week · {{ $mh['label'] }}</p>
            </div>
        </div>
        <ul class="flex items-center gap-3 text-[11px] text-zinc-500 dark:text-slate-400">
            @foreach($legend as [$name, $color])<li class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full {{ $color }}"></span>{{ $name }}</li>@endforeach
        </ul>
    </div>

    @if($hasPunctuality)
        <ol class="mt-5 flex h-48 items-end justify-around gap-3 border-b border-zinc-100 dark:border-white/[0.06]">
            @foreach($weeks as $week)
                @php $total = $week['present'] + $week['late'] + $week['absent']; @endphp
                <li class="flex h-full min-w-0 flex-1 flex-col items-center justify-end" data-punctuality-week="{{ $week['label'] }}">
                    <flux:tooltip position="top" class="flex w-full justify-center">
                        <button type="button" class="flex w-full max-w-12 flex-col-reverse overflow-hidden rounded-t-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-400"
                                style="height: {{ max(4, (int) round($total / $weekScale * 160)) }}px"
                                aria-label="Week of {{ $week['label'] }}: {{ $week['present'] }} present, {{ $week['late'] }} late, {{ $week['absent'] }} absent">
                            @foreach([['present', 'bg-emerald-500'], ['late', 'bg-amber-400'], ['absent', 'bg-rose-500']] as [$key, $color])
                                @if($week[$key] > 0)<span class="block w-full {{ $color }}" style="height: {{ $total > 0 ? round($week[$key] / $total * 100, 2) : 0 }}%"></span>@endif
                            @endforeach
                        </button>
                        <flux:tooltip.content class="text-left">
                            <div class="mb-1 font-semibold text-white">Week of {{ $week['label'] }}</div>
                            <div>Present {{ $week['present'] }} · Late {{ $week['late'] }} · Absent {{ $week['absent'] }}</div>
                        </flux:tooltip.content>
                    </flux:tooltip>
                    <span class="mt-2 whitespace-nowrap text-[10px] font-medium text-zinc-500 dark:text-slate-400">Wk {{ $week['label'] }}</span>
                </li>
            @endforeach
        </ol>
        <p class="mt-3 text-[11px] text-zinc-500 dark:text-slate-400">Weekly offs, holidays and approved leave are never counted as absent.</p>
    @else
        <p class="flex h-48 items-center justify-center text-xs text-zinc-400 dark:text-slate-500">No working days yet.</p>
    @endif
</section>
