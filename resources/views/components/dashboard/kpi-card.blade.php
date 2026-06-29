@props([
    'label' => '',
    'value' => '0',
    'icon' => 'chart-bar',
    'accent' => 'orange',          // orange | green | amber | blue | red
    'delta' => 0,                  // signed percentage
    'dir' => 'flat',               // up | down | flat
    'compare' => '',
    'spark' => [],                 // array<float>
])

@php
    $accentMap = [
        'orange' => ['#F97316', 'bg-orange-50 text-orange-500'],
        'green' => ['#22C55E', 'bg-green-50 text-green-500'],
        'amber' => ['#F59E0B', 'bg-amber-50 text-amber-500'],
        'blue' => ['#3B82F6', 'bg-blue-50 text-blue-500'],
        'red' => ['#EF4444', 'bg-red-50 text-red-500'],
    ];
    [$hex, $iconCls] = $accentMap[$accent] ?? $accentMap['orange'];

    $deltaCls = match ($dir) {
        'up' => 'bg-green-50 text-green-600',
        'down' => 'bg-red-50 text-red-600',
        default => 'bg-gray-100 text-gray-500',
    };
    $deltaIcon = match ($dir) {
        'up' => 'arrow-trending-up',
        'down' => 'arrow-trending-down',
        default => 'minus',
    };

    // Build a smooth SVG sparkline path.
    $pts = array_values(array_map('floatval', $spark));
    $poly = '';
    $area = '';
    if (count($pts) > 1) {
        $min = min($pts);
        $range = (max($pts) - $min) ?: 1;
        $n = count($pts);
        $coords = [];
        foreach ($pts as $i => $v) {
            $x = round($i / ($n - 1) * 100, 2);
            $y = round(30 - (($v - $min) / $range) * 26 - 2, 2);
            $coords[] = "$x,$y";
        }
        $poly = implode(' ', $coords);
        $area = "0,32 ".$poly." 100,32";
    }
    $gid = 'spark-'.\Illuminate\Support\Str::slug($label).'-'.substr(md5($label.$accent), 0, 5);
@endphp

<div {{ $attributes->class('group flex h-[150px] flex-col justify-between rounded-2xl border border-[#F1E7DD] bg-white p-5 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-orange-500/5') }}>
    <div class="flex items-start justify-between">
        <div class="flex size-10 items-center justify-center rounded-xl {{ $iconCls }}">
            <flux:icon :name="$icon" class="size-5" />
        </div>
        @if($delta !== 0 || $dir !== 'flat')
            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold {{ $deltaCls }}">
                <flux:icon :name="$deltaIcon" class="size-3" />{{ $delta > 0 ? '+' : '' }}{{ $delta }}%
            </span>
        @endif
    </div>

    <div class="flex items-end justify-between gap-3">
        <div class="min-w-0">
            <div class="text-[32px] font-extrabold leading-none tracking-tight text-[#1F2937] tabular-nums" x-data="countUp(@js((string) $value))" x-text="display">{{ $value }}</div>
            <div class="mt-1.5 truncate text-[13px] font-medium text-[#6B7280]">{{ $label }}</div>
            @if($compare)<div class="mt-0.5 text-[11px] text-[#9CA3AF]">{{ $compare }}</div>@endif
        </div>
        @if($poly)
            <svg viewBox="0 0 100 32" preserveAspectRatio="none" class="h-10 w-20 shrink-0 overflow-visible">
                <defs>
                    <linearGradient id="{{ $gid }}" x1="0" x2="0" y1="0" y2="1">
                        <stop offset="0%" stop-color="{{ $hex }}" stop-opacity="0.25" />
                        <stop offset="100%" stop-color="{{ $hex }}" stop-opacity="0" />
                    </linearGradient>
                </defs>
                <polygon points="{{ $area }}" fill="url(#{{ $gid }})" />
                <polyline points="{{ $poly }}" fill="none" stroke="{{ $hex }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
            </svg>
        @endif
    </div>
</div>
