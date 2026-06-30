@props([
    'label',
    'value',
    'icon' => 'chart-bar',
    'tone' => 'orange',
    'suffix' => '',
    'sub' => null,
    'trend' => null,      // e.g. "+6%" — optional change indicator
    'trendUp' => true,
    'href' => null,
])

@php
    $tones = [
        'orange'  => ['bg' => 'bg-orange-50 dark:bg-orange-900/20',  'tx' => 'text-orange-600 dark:text-orange-400'],
        'emerald' => ['bg' => 'bg-emerald-50 dark:bg-emerald-900/20','tx' => 'text-emerald-600 dark:text-emerald-400'],
        'rose'    => ['bg' => 'bg-rose-50 dark:bg-rose-900/20',      'tx' => 'text-rose-600 dark:text-rose-400'],
        'amber'   => ['bg' => 'bg-amber-50 dark:bg-amber-900/20',    'tx' => 'text-amber-600 dark:text-amber-400'],
        'violet'  => ['bg' => 'bg-violet-50 dark:bg-violet-900/20',  'tx' => 'text-violet-600 dark:text-violet-400'],
        'blue'    => ['bg' => 'bg-blue-50 dark:bg-blue-900/20',      'tx' => 'text-blue-600 dark:text-blue-400'],
        'cyan'    => ['bg' => 'bg-cyan-50 dark:bg-cyan-900/20',      'tx' => 'text-cyan-600 dark:text-cyan-400'],
    ];
    $t = $tones[$tone] ?? $tones['orange'];
    $isNumeric = is_numeric($value);
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if($href) href="{{ $href }}" wire:navigate @endif
    class="group relative flex flex-col gap-2 overflow-hidden rounded-2xl border border-zinc-100 bg-white p-4 shadow-sm transition duration-300 hover:-translate-y-0.5 hover:shadow-md dark:border-white/5 dark:bg-zinc-900">

    {{-- soft accent glow on hover --}}
    <div class="pointer-events-none absolute -right-6 -top-6 size-16 rounded-full {{ $t['bg'] }} opacity-0 blur-2xl transition group-hover:opacity-100"></div>

    <div class="flex items-center justify-between">
        <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">{{ $label }}</span>
        <span class="flex size-8 items-center justify-center rounded-xl {{ $t['bg'] }}">
            <flux:icon :name="$icon" class="size-4 {{ $t['tx'] }}" />
        </span>
    </div>

    <div class="flex items-end gap-2">
        @if($isNumeric)
            <span class="text-2xl font-black tabular-nums text-zinc-900 dark:text-white"
                  x-data="{ n: 0 }"
                  x-init="$nextTick(() => { const target = {{ (float) $value }}; const step = Math.max(1, target / 22); const id = setInterval(() => { n += step; if (n >= target) { n = target; clearInterval(id); } }, 28); })"
                  x-text="(Math.round(n)).toLocaleString()"></span>@if($suffix)<span class="mb-0.5 text-sm font-bold text-zinc-400">{{ $suffix }}</span>@endif
        @else
            <span class="text-2xl font-black text-zinc-900 dark:text-white">{{ $value }}</span>
        @endif

        @if($trend)
            <span class="mb-1 inline-flex items-center gap-0.5 text-[11px] font-bold {{ $trendUp ? 'text-emerald-600' : 'text-rose-500' }}">
                <flux:icon :name="$trendUp ? 'arrow-trending-up' : 'arrow-trending-down'" class="size-3" />{{ $trend }}
            </span>
        @endif
    </div>

    @if($sub)
        <div class="text-xs text-zinc-400">{{ $sub }}</div>
    @endif
</{{ $tag }}>
