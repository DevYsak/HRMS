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
        ['Leave', $count(['Leave']) + 0.5 * $count(['Leave (½ day)']), '#6366f1'],
        ['Weekly Off', $count([\App\Services\Attendance\WorkingDayResolver::WEEKLY_OFF_LABEL]), '#d4d4d8'],
        ['Holiday', $count(['Holiday', 'MDL shutdown']), '#38bdf8'],
    ]);
    $donut = [
        'chart' => ['type' => 'donut', 'height' => 190, 'fontFamily' => 'inherit', 'toolbar' => ['show' => false], 'animations' => ['enabled' => false]],
        'series' => $breakdown->pluck(1)->map(fn ($v) => (float) $v)->all(),
        'labels' => $breakdown->pluck(0)->all(),
        'colors' => $breakdown->pluck(2)->all(),
        'legend' => ['show' => false], 'dataLabels' => ['enabled' => false], 'stroke' => ['width' => 2],
        'plotOptions' => ['pie' => ['donut' => ['size' => '72%']]],
        'tooltip' => ['theme' => 'light'],
    ];
    $onTimePct = $present > 0 ? (int) round(($present - $late) / $present * 100) : null;
    $avgWorked = $present > 0 ? (int) round(collect($chartDaily)->sum('hours') * 60 / $present) : 0;
    $missingPunches = (int) ($t['missing_checkout'] ?? 0);
    $metricRow = 'flex items-baseline justify-between gap-3 py-2';
@endphp

<section class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900" aria-labelledby="month-title" data-month>
    <div class="mb-3 flex items-baseline justify-between gap-2">
        <h2 id="month-title" class="text-[17px] font-semibold text-zinc-900 dark:text-white">{{ $isCurrentMonth ? 'This Month' : $mh['label'] }}</h2>
        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $mh['label'] }}</span>
    </div>
    <div class="grid gap-5 lg:grid-cols-2">
        <div class="flex flex-col items-center gap-4 sm:flex-row">
            <div class="w-[150px] shrink-0">
                @if($breakdown->sum(1) > 0)
                    <x-dashboard.chart :options="$donut" id="month-donut" wire:key="month-donut-{{ $mh['month'] }}" class="w-full min-w-0" />
                @endif
            </div>
            <ul class="grid w-full flex-1 grid-cols-2 gap-x-6 gap-y-2 text-sm">
                @foreach($breakdown as [$name, $value, $color])
                    <li class="flex items-center gap-2"><span class="size-2.5 shrink-0 rounded-full" style="background: {{ $color }}"></span><span class="whitespace-nowrap text-zinc-500 dark:text-zinc-400">{{ $name }}</span><span class="ml-auto font-semibold tabular-nums text-zinc-900 dark:text-white">{{ rtrim(rtrim(number_format($value, 1), '0'), '.') }}</span></li>
                @endforeach
            </ul>
        </div>
        <div class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
            <div class="{{ $metricRow }}"><span class="text-zinc-500 dark:text-zinc-400">On-time arrivals</span><span class="font-semibold text-zinc-900 dark:text-white">{{ $onTimePct === null ? '—' : $onTimePct.'%' }}</span></div>
            <div class="{{ $metricRow }}"><span class="text-zinc-500 dark:text-zinc-400">Average worked per day</span><span class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $present > 0 ? $hm($avgWorked) : '—' }}</span></div>
            <div class="{{ $metricRow }}"><span class="text-zinc-500 dark:text-zinc-400">Late days</span><span @class(['font-semibold', 'text-amber-600' => $late > 0, 'text-zinc-900 dark:text-white' => $late === 0])>{{ $late }}</span></div>
            <div class="{{ $metricRow }}"><span class="text-zinc-500 dark:text-zinc-400">Missing punches</span><span @class(['font-semibold', 'text-amber-600' => $missingPunches > 0, 'text-zinc-900 dark:text-white' => $missingPunches === 0])>{{ $missingPunches }}</span></div>
            @if((float) ($stats['ot_hours'] ?? 0) > 0)
                <div class="{{ $metricRow }}"><span class="text-zinc-500 dark:text-zinc-400">Approved overtime</span><span class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $stats['ot_hours'] }}h</span></div>
            @endif
        </div>
    </div>
</section>
