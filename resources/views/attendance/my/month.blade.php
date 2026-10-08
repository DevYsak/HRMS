{{--
    This Month — one compact overview of the selected month: day breakdown
    (monthHistory statuses) and a few figures (computeStats). The attendance
    rate itself is the first KPI card and is not repeated here.
--}}
@php
    // Day counts up to today, straight from monthHistory's day statuses (future
    // weekly offs and holidays are not counted against to-date attendance).
    $t = $monthTotals;
    $pastRows = collect($mh['rows'] ?? [])->filter(fn (array $r) => $r['date'] <= today()->toDateString());
    $count = fn (array $statuses) => $pastRows->whereIn('status', $statuses)->count();
    $breakdown = collect([
        ['Present', $count(['Present', 'WFH', 'Half day', \App\Services\Attendance\WorkingDayResolver::WORKED_WEEKLY_OFF_LABEL]), '#10b981'],
        ['Late', $count(['Late']), '#f59e0b'],
        ['Absent', $count(['Absent']), '#ef4444'],
        ['Leave', $count(['Leave']) + 0.5 * $count(['Leave (½ day)']), '#3b82f6'],
        ['Weekly Off', $count([\App\Services\Attendance\WorkingDayResolver::WEEKLY_OFF_LABEL]), '#d4d4d8'],
        ['Holiday', $count(['Holiday', 'MDL shutdown']), '#8b5cf6'],
    ]);
    $donut = [
        'chart' => ['type' => 'donut', 'height' => 180, 'fontFamily' => 'inherit', 'toolbar' => ['show' => false], 'animations' => ['enabled' => false]],
        'series' => $breakdown->pluck(1)->map(fn ($v) => (float) $v)->all(),
        'labels' => $breakdown->pluck(0)->all(),
        'colors' => $breakdown->pluck(2)->all(),
        'legend' => ['show' => false], 'dataLabels' => ['enabled' => false], 'stroke' => ['width' => 2],
        'plotOptions' => ['pie' => ['donut' => ['size' => '74%', 'labels' => ['show' => false]]]],
        'tooltip' => ['theme' => 'light'],
    ];
    $onTimePct = $present > 0 ? (int) round(($present - $late) / $present * 100) : null;
    $avgWorked = $present > 0 ? (int) round(collect($chartDaily)->sum('hours') * 60 / $present) : 0;
    $missingPunches = (int) ($t['missing_checkout'] ?? 0);
    $metricRow = 'flex items-center gap-3 py-3';
@endphp

<section class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900" aria-labelledby="month-title" data-month>
    <div class="mb-4 flex items-center justify-between gap-2">
        <h2 id="month-title" class="flex items-center gap-3 text-[17px] font-semibold text-zinc-900 dark:text-white"><flux:icon.calendar class="size-6 text-orange-500" /> {{ $isCurrentMonth ? 'This Month' : $mh['label'] }}</h2>
        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $mh['label'] }}</span>
    </div>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] lg:divide-x lg:divide-zinc-100 dark:lg:divide-zinc-800">
        <div class="flex flex-col items-center gap-6 sm:flex-row">
            <div class="relative w-[170px] shrink-0">
                @if($breakdown->sum(1) > 0)
                    <x-dashboard.chart :options="$donut" id="month-donut" wire:key="month-donut-{{ $mh['month'] }}" class="w-full min-w-0" />
                    <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                        <span class="text-2xl font-bold leading-none text-zinc-900 dark:text-white">{{ $scheduled }}</span>
                        <span class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">Working Days</span>
                    </div>
                @endif
            </div>
            <ul class="grid w-full flex-1 grid-cols-2 gap-x-10 gap-y-3 pr-4 text-sm">
                @foreach($breakdown as [$name, $value, $color])
                    <li class="flex items-center gap-2.5"><span class="size-2.5 shrink-0 rounded-full" style="background: {{ $color }}"></span><span class="whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ $name }}</span><span class="ml-auto font-bold tabular-nums text-zinc-900 dark:text-white">{{ rtrim(rtrim(number_format($value, 1), '0'), '.') }}</span></li>
                @endforeach
            </ul>
        </div>
        <div class="divide-y divide-zinc-100 text-sm lg:pl-6 dark:divide-zinc-800">
            <div class="{{ $metricRow }}"><flux:icon.calendar-days class="size-5 text-zinc-400" /><span class="flex-1 text-zinc-600 dark:text-zinc-400">On-time arrivals</span><span class="font-bold text-zinc-900 dark:text-white">{{ $onTimePct === null ? '—' : $onTimePct.'%' }}</span></div>
            <div class="{{ $metricRow }}"><flux:icon.clock class="size-5 text-zinc-400" /><span class="flex-1 text-zinc-600 dark:text-zinc-400">Average worked per day</span><span class="font-bold tabular-nums text-zinc-900 dark:text-white">{{ $present > 0 ? $hm($avgWorked) : '—' }}</span></div>
            <div class="{{ $metricRow }}"><flux:icon.exclamation-circle @class(['size-5', 'text-orange-500' => $late > 0, 'text-zinc-400' => $late === 0]) /><span class="flex-1 text-zinc-600 dark:text-zinc-400">Late days</span><span @class(['font-bold', 'text-orange-600' => $late > 0, 'text-zinc-900 dark:text-white' => $late === 0])>{{ $late }}</span></div>
            <div class="{{ $metricRow }}"><flux:icon.exclamation-triangle @class(['size-5', 'text-orange-500' => $missingPunches > 0, 'text-zinc-400' => $missingPunches === 0]) /><span class="flex-1 text-zinc-600 dark:text-zinc-400">Missing punches</span><span @class(['font-bold', 'text-orange-600' => $missingPunches > 0, 'text-zinc-900 dark:text-white' => $missingPunches === 0])>{{ $missingPunches }}</span></div>
            @if((float) ($stats['ot_hours'] ?? 0) > 0)
                <div class="{{ $metricRow }}"><flux:icon.bolt class="size-5 text-zinc-400" /><span class="flex-1 text-zinc-600 dark:text-zinc-400">Approved overtime</span><span class="font-bold tabular-nums text-zinc-900 dark:text-white">{{ $stats['ot_hours'] }}h</span></div>
            @endif
        </div>
    </div>
</section>
