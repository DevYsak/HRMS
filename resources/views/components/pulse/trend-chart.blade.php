@props([
    'title' => '',
    'value' => null,
    'trend' => null,
    'trendDir' => 'up',     // up | down | flat
    'accent' => 'brand',    // brand | emerald | amber | rose | blue | indigo
    'series' => [],         // array<int|float>
    'type' => 'line',       // line | bar
    'caption' => null,
])

@php
    $accentText = [
        'brand' => 'text-brand-500', 'emerald' => 'text-emerald-500', 'amber' => 'text-amber-500',
        'rose' => 'text-rose-500', 'blue' => 'text-blue-500', 'indigo' => 'text-indigo-500',
    ][$accent] ?? 'text-brand-500';

    $trendCls = match ($trendDir) {
        'up' => 'text-emerald-600 dark:text-emerald-400',
        'down' => 'text-rose-600 dark:text-rose-400',
        default => 'text-zinc-400',
    };
    $trendIcon = match ($trendDir) { 'up' => 'arrow-trending-up', 'down' => 'arrow-trending-down', default => 'minus' };

    $data = array_values(array_map('floatval', $series));
    $n = count($data);
    $w = 100;
    $h = 40;
    $min = $n ? min($data) : 0;
    $max = $n ? max($data) : 1;
    $range = ($max - $min) ?: 1;

    $linePoly = '';
    $areaPath = '';
    $bars = [];
    if ($n > 1) {
        $pts = [];
        foreach ($data as $i => $v) {
            $x = round($i / ($n - 1) * $w, 2);
            $y = round($h - (($v - $min) / $range) * ($h - 4) - 2, 2);
            $pts[] = "{$x},{$y}";
        }
        $linePoly = implode(' ', $pts);
        $areaPath = "M0,{$h} L".implode(' L', $pts)." L{$w},{$h} Z";
    }
    if ($n >= 1 && $type === 'bar') {
        $bw = $w / $n * 0.6;
        $gap = $w / $n;
        foreach ($data as $i => $v) {
            $bh = round((($v - $min) / $range) * ($h - 4) + 2, 2);
            $x = round($i * $gap + ($gap - $bw) / 2, 2);
            $y = round($h - $bh, 2);
            $bars[] = ['x' => $x, 'y' => $y, 'w' => round($bw, 2), 'h' => $bh];
        }
    }
@endphp

<div {{ $attributes->class('rounded-2xl border border-zinc-100 bg-white p-4 shadow-sm dark:border-white/5 dark:bg-ink-900') }}>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <h3 class="truncate text-[13px] font-bold text-zinc-900 dark:text-white">{{ $title }}</h3>
            @if($value !== null)
                <div class="mt-0.5 text-xl font-black tabular-nums text-zinc-900 dark:text-white">{{ $value }}</div>
            @endif
        </div>
        @if($trend)
            <span class="inline-flex items-center gap-1 text-[11px] font-bold {{ $trendCls }}">
                <flux:icon :name="$trendIcon" class="size-3" />{{ $trend }}
            </span>
        @endif
    </div>

    <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" class="mt-3 h-12 w-full overflow-visible {{ $accentText }}">
        @if($type === 'bar')
            @foreach($bars as $b)
                <rect x="{{ $b['x'] }}" y="{{ $b['y'] }}" width="{{ $b['w'] }}" height="{{ $b['h'] }}" rx="1.2" fill="currentColor" fill-opacity="0.85" />
            @endforeach
        @elseif($linePoly)
            <path d="{{ $areaPath }}" fill="currentColor" fill-opacity="0.10" />
            <polyline points="{{ $linePoly }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
        @endif
    </svg>

    @if($caption)
        <div class="mt-2 text-[11px] font-medium text-zinc-400 dark:text-zinc-500">{{ $caption }}</div>
    @endif
</div>
