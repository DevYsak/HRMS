{{--
    Two trends only: working hours over the last 14 worked days (chartDaily,
    engine worked minutes) and punctuality by week (monthHistory statuses).
--}}
@php
    $worked = collect($chartDaily)->filter(fn (array $d) => (float) $d['hours'] > 0)->take(-14)->values();
    $axis = ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false]];
    $base = fn (string $type) => ['type' => $type, 'height' => 220, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit', 'animations' => ['enabled' => false]];
    $hoursChart = [
        'chart' => $base('bar'),
        'colors' => ['#F97316'],
        'plotOptions' => ['bar' => ['borderRadius' => 4, 'columnWidth' => '50%']],
        'dataLabels' => ['enabled' => false],
        'grid' => ['borderColor' => '#F1F1F3', 'strokeDashArray' => 4],
        'xaxis' => array_merge($axis, ['categories' => $worked->pluck('label')->all()]),
        'yaxis' => ['min' => 0, 'decimalsInFloat' => 0, 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]],
        'annotations' => ['yaxis' => [['y' => $stdHours, 'borderColor' => '#a1a1aa', 'strokeDashArray' => 4, 'label' => ['text' => 'Shift: '.rtrim(rtrim(number_format($stdHours, 1), '0'), '.').'h', 'style' => ['color' => '#71717a', 'background' => 'transparent']]]]],
        'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Hours worked', 'data' => $worked->pluck('hours')->all()]],
    ];
    $weeks = collect($this->monthPunctuality);
    $punctualityChart = [
        'chart' => array_merge($base('bar'), ['stacked' => true]),
        'colors' => ['#10b981', '#f59e0b', '#ef4444'],
        'plotOptions' => ['bar' => ['borderRadius' => 3, 'columnWidth' => '45%']],
        'dataLabels' => ['enabled' => false],
        'legend' => ['position' => 'top', 'horizontalAlign' => 'right', 'fontSize' => '11px'],
        'grid' => ['borderColor' => '#F1F1F3', 'strokeDashArray' => 4],
        'xaxis' => array_merge($axis, ['categories' => $weeks->map(fn ($w) => 'Wk '.$w['label'])->all()]),
        'yaxis' => ['min' => 0, 'forceNiceScale' => true, 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]],
        'tooltip' => ['theme' => 'light'],
        'series' => [
            ['name' => 'Present', 'data' => $weeks->pluck('present')->all()],
            ['name' => 'Late', 'data' => $weeks->pluck('late')->all()],
            ['name' => 'Absent', 'data' => $weeks->pluck('absent')->all()],
        ],
    ];
    $hasPunctuality = $weeks->sum(fn ($w) => $w['present'] + $w['late'] + $w['absent']) > 0;
@endphp

<section class="grid gap-4 lg:grid-cols-2" aria-label="Trends" data-trends>
    <div class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <h2 class="flex items-center gap-3 text-[17px] font-semibold text-zinc-900 dark:text-white"><flux:icon.chart-bar class="size-6 text-orange-500" /> Working Hours</h2>
        <p class="mt-1 pl-9 text-xs text-zinc-500 dark:text-zinc-400">Last {{ $worked->count() }} worked {{ \Illuminate\Support\Str::plural('day', $worked->count()) }} · {{ $mh['label'] }}</p>
        @if($worked->isNotEmpty())
            <p class="mt-3 text-[11px] font-medium text-zinc-500 dark:text-zinc-400">Hours</p>
            <x-dashboard.chart :options="$hoursChart" id="hours-trend" wire:key="hours-trend-{{ $mh['month'] }}" class="mt-2 w-full min-w-0" />
        @else
            <p class="flex h-[180px] items-center justify-center text-xs text-zinc-400">No worked days yet.</p>
        @endif
    </div>
    <div class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <h2 class="flex items-center gap-3 text-[17px] font-semibold text-zinc-900 dark:text-white"><flux:icon.arrow-trending-up class="size-6 text-orange-500" /> Punctuality</h2>
        <p class="mt-1 pl-9 text-xs text-zinc-500 dark:text-zinc-400">Present, late and absent days by week · {{ $mh['label'] }}</p>
        @if($hasPunctuality)
            <x-dashboard.chart :options="$punctualityChart" id="punctuality-trend" wire:key="punctuality-trend-{{ $mh['month'] }}" class="mt-2 w-full min-w-0" />
        @else
            <p class="flex h-[180px] items-center justify-center text-xs text-zinc-400">No working days yet.</p>
        @endif
    </div>
</section>
