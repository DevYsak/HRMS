@props([
    'score' => 0,
    'grade' => null,
    'size' => 176,
    'label' => 'Overall Score',
])

@php
    $score = max(0, min(100, (float) $score));
    $radius = 70;
    $circumference = 2 * M_PI * $radius;
    $offset = $circumference * (1 - $score / 100);

    $gradeRingColors = [
        'a_plus' => '#10b981',
        'a' => '#22c55e',
        'b' => '#3b82f6',
        'c' => '#f59e0b',
        'd' => '#ef4444',
    ];
    $gradeLabels = ['a_plus' => 'A+', 'a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'];

    $ringColor = $gradeRingColors[$grade] ?? '#22c55e';
    $gradeText = $gradeLabels[$grade] ?? null;
@endphp

<div class="relative inline-flex items-center justify-center" style="width: {{ $size }}px; height: {{ $size }}px;">
    <svg viewBox="0 0 160 160" class="-rotate-90" style="width: {{ $size }}px; height: {{ $size }}px;">
        <circle cx="80" cy="80" r="{{ $radius }}" fill="none" stroke="currentColor"
            class="text-zinc-100 dark:text-zinc-800" stroke-width="12" />
        <circle cx="80" cy="80" r="{{ $radius }}" fill="none" stroke="{{ $ringColor }}"
            stroke-width="12" stroke-linecap="round"
            stroke-dasharray="{{ $circumference }}"
            stroke-dashoffset="{{ $offset }}"
            style="transition: stroke-dashoffset 0.6s ease;" />
    </svg>
    <div class="absolute inset-0 flex flex-col items-center justify-center">
        <span class="text-3xl font-black text-zinc-900 dark:text-white tabular-nums">{{ number_format($score, 0) }}</span>
        <span class="text-[10px] text-zinc-400 font-semibold">/100</span>
        @if($gradeText)
            <span class="mt-1 text-xs font-bold px-2 py-0.5 rounded-full" style="background-color: {{ $ringColor }}1a; color: {{ $ringColor }};">{{ $gradeText }}</span>
        @endif
    </div>
</div>
@if($label)
    <p class="text-center text-xs text-zinc-400 font-semibold mt-2">{{ $label }}</p>
@endif
