@props([
    'label' => '',
    'value' => '0',
    'icon' => null,         // Flux icon name, e.g. 'users'
    'trend' => null,        // e.g. '12%'
    'trendDir' => 'flat',   // up | down | flat
    'sub' => null,          // small caption under the value
    'accent' => 'brand',    // brand | emerald | amber | rose | blue | indigo
    'sparkline' => [],      // array<int|float> — optional mini trend
    'compare' => null,      // e.g. 'vs last month'
])

@php
    $accentMap = [
        'brand'   => ['bg-brand-50 dark:bg-brand-500/10', 'text-brand-600 dark:text-brand-400'],
        'emerald' => ['bg-emerald-50 dark:bg-emerald-500/10', 'text-emerald-600 dark:text-emerald-400'],
        'amber'   => ['bg-amber-50 dark:bg-amber-500/10', 'text-amber-600 dark:text-amber-400'],
        'rose'    => ['bg-rose-50 dark:bg-rose-500/10', 'text-rose-600 dark:text-rose-400'],
        'blue'    => ['bg-blue-50 dark:bg-blue-500/10', 'text-blue-600 dark:text-blue-400'],
        'indigo'  => ['bg-indigo-50 dark:bg-indigo-500/10', 'text-indigo-600 dark:text-indigo-400'],
    ];
    [$iconBg, $iconText] = $accentMap[$accent] ?? $accentMap['brand'];
    $trendClasses = match ($trendDir) {
        'up' => 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10 dark:text-emerald-400',
        'down' => 'text-rose-600 bg-rose-50 dark:bg-rose-500/10 dark:text-rose-400',
        default => 'text-zinc-500 bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-400',
    };
    $trendIcon = match ($trendDir) {
        'up' => 'arrow-trending-up',
        'down' => 'arrow-trending-down',
        default => 'minus',
    };

    // Build an inline SVG sparkline path from the series.
    $spark = array_values(array_map('floatval', $sparkline));
    $sparkPoly = null;
    $sparkLast = null;
    if (count($spark) > 1) {
        $w = 100;
        $h = 26;
        $min = min($spark);
        $range = (max($spark) - $min) ?: 1;
        $n = count($spark);
        $pts = [];
        foreach ($spark as $i => $v) {
            $x = round($i / ($n - 1) * $w, 1);
            $y = round($h - (($v - $min) / $range) * $h, 1);
            $pts[] = "{$x},{$y}";
        }
        $sparkPoly = implode(' ', $pts);
        $sparkLast = explode(',', end($pts));
    }
@endphp

<div {{ $attributes->class('group rounded-2xl border border-zinc-100 bg-white p-4 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md dark:border-white/5 dark:bg-ink-900') }}>
    <div class="mb-3 flex items-center justify-between">
        @if($icon)
            <div class="flex size-9 items-center justify-center rounded-xl {{ $iconBg }}">
                <flux:icon :name="$icon" class="size-4 {{ $iconText }}" />
            </div>
        @endif
        @if($trend)
            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold {{ $trendClasses }}">
                <flux:icon :name="$trendIcon" class="size-3" />{{ $trend }}
            </span>
        @endif
    </div>

    <div class="text-2xl font-black tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ $value }}</div>
    <div class="mt-0.5 text-[12px] font-semibold text-zinc-500 dark:text-zinc-400">{{ $label }}</div>

    @if($sparkPoly)
        <svg viewBox="0 0 100 26" preserveAspectRatio="none" class="mt-2.5 h-7 w-full overflow-visible {{ $iconText }}">
            <polyline points="{{ $sparkPoly }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
            @if($sparkLast)
                <circle cx="{{ $sparkLast[0] }}" cy="{{ $sparkLast[1] }}" r="2.5" fill="currentColor" />
            @endif
        </svg>
    @endif

    @if($compare)
        <div class="mt-2 text-[11px] font-medium text-zinc-400 dark:text-zinc-500">{{ $compare }}</div>
    @elseif($sub)
        <div class="mt-2 text-[11px] font-medium text-zinc-400 dark:text-zinc-500">{{ $sub }}</div>
    @endif
</div>
