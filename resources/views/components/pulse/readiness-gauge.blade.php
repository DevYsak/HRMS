@props([
    'title' => 'Workforce Insights',
    'subtitle' => 'Readiness at a glance',
    'icon' => 'sparkles',
    'value' => 0,             // big gauge value 0–100
    'gaugeLabel' => 'Workforce Readiness',
    'accent' => 'emerald',    // brand | emerald | amber | rose | blue | indigo
    'metrics' => [],          // array<array{label:string, value:int|string, accent?:string}>
    'caption' => null,        // small status line under the gauge value
])

@php
    $v = max(0, min(100, (float) $value));
    $accentText = [
        'brand' => 'text-brand-500', 'emerald' => 'text-emerald-500', 'amber' => 'text-amber-500',
        'rose' => 'text-rose-500', 'blue' => 'text-blue-500', 'indigo' => 'text-indigo-500',
    ][$accent] ?? 'text-emerald-500';
    $dotMap = [
        'brand' => 'bg-brand-500', 'emerald' => 'bg-emerald-500', 'amber' => 'bg-amber-500',
        'rose' => 'bg-rose-500', 'blue' => 'bg-blue-500', 'indigo' => 'bg-indigo-500',
    ];
    $r = 44;
    $circ = 2 * M_PI * $r;
    $offset = round($circ * (1 - $v / 100), 2);
@endphp

<div {{ $attributes->class('flex h-full flex-col rounded-2xl border border-zinc-100 bg-white p-5 shadow-sm dark:border-white/5 dark:bg-ink-900') }}>
    <div class="mb-4 flex items-center gap-2.5">
        <div class="flex size-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
            <flux:icon :name="$icon" class="size-4" />
        </div>
        <div>
            <h3 class="text-[15px] font-bold tracking-tight text-zinc-900 dark:text-white">{{ $title }}</h3>
            @if($subtitle)
                <p class="text-xs font-medium text-zinc-400">{{ $subtitle }}</p>
            @endif
        </div>
    </div>

    <div class="flex flex-1 flex-col items-center justify-center gap-4 sm:flex-row sm:items-center sm:gap-6">
        {{-- Signature big gauge --}}
        <div class="relative flex shrink-0 items-center justify-center">
            <svg viewBox="0 0 110 110" class="size-32 -rotate-90 {{ $accentText }}">
                <circle cx="55" cy="55" r="{{ $r }}" fill="none" stroke="currentColor" stroke-opacity="0.12" stroke-width="9" />
                <circle cx="55" cy="55" r="{{ $r }}" fill="none" stroke="currentColor" stroke-width="9" stroke-linecap="round"
                    stroke-dasharray="{{ round($circ, 2) }}" stroke-dashoffset="{{ $offset }}"
                    style="transition: stroke-dashoffset 1s cubic-bezier(.4,0,.2,1)" />
            </svg>
            <div class="absolute text-center">
                <div class="text-3xl font-black tabular-nums text-zinc-900 dark:text-white">{{ round($v) }}</div>
                <div class="mt-0.5 max-w-[5.5rem] text-[9px] font-bold uppercase leading-tight tracking-wide text-zinc-400">{{ $gaugeLabel }}</div>
            </div>
        </div>

        {{-- Metric breakdown --}}
        <div class="w-full flex-1 space-y-2.5">
            @foreach($metrics as $m)
                @php $mAccent = $m['accent'] ?? 'brand'; @endphp
                <div class="flex items-center justify-between rounded-xl border border-zinc-100 px-3 py-2 dark:border-white/5">
                    <div class="flex items-center gap-2.5">
                        <span class="size-2 rounded-full {{ $dotMap[$mAccent] ?? 'bg-brand-500' }}"></span>
                        <span class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">{{ $m['label'] }}</span>
                    </div>
                    <span class="text-sm font-black tabular-nums text-zinc-900 dark:text-white">{{ $m['value'] }}</span>
                </div>
            @endforeach
            @if($caption)
                <p class="pt-1 text-[11px] font-medium text-zinc-400">{{ $caption }}</p>
            @endif
        </div>
    </div>
</div>
