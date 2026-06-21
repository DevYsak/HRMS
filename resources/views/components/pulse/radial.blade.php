@props([
    'value' => 0,        // 0–100
    'suffix' => '%',
    'caption' => null,   // small text under the number inside the ring
    'accent' => 'brand', // brand | emerald | amber | rose | blue | indigo
    'size' => 'size-28',
])

@php
    $v = max(0, min(100, (float) $value));
    $r = 40;
    $circ = 2 * M_PI * $r;
    $offset = round($circ * (1 - $v / 100), 2);
    $accentText = [
        'brand' => 'text-brand-500', 'emerald' => 'text-emerald-500', 'amber' => 'text-amber-500',
        'rose' => 'text-rose-500', 'blue' => 'text-blue-500', 'indigo' => 'text-indigo-500',
    ][$accent] ?? 'text-brand-500';
@endphp

<div {{ $attributes->class('relative flex items-center justify-center') }}>
    <svg viewBox="0 0 100 100" class="{{ $size }} -rotate-90 {{ $accentText }}">
        <circle cx="50" cy="50" r="{{ $r }}" fill="none" stroke="currentColor" stroke-opacity="0.12" stroke-width="9" />
        <circle cx="50" cy="50" r="{{ $r }}" fill="none" stroke="currentColor" stroke-width="9" stroke-linecap="round"
            stroke-dasharray="{{ round($circ, 2) }}" stroke-dashoffset="{{ $offset }}"
            style="transition: stroke-dashoffset 1s cubic-bezier(.4,0,.2,1)" />
    </svg>
    <div class="absolute text-center">
        <div class="text-2xl font-black tabular-nums text-zinc-900 dark:text-white">{{ round($v) }}<span class="text-base">{{ $suffix }}</span></div>
        @if($caption)
            <div class="mt-0.5 text-[10px] font-bold uppercase tracking-wide text-zinc-400">{{ $caption }}</div>
        @endif
    </div>
</div>
