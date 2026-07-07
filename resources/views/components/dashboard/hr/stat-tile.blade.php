@props([
    'label' => '',
    'value' => '0',
    'icon' => 'chart-bar',
    'accent' => 'orange',     // orange | green | amber | blue | red | violet
    'sub' => null,
    'progress' => null,        // null or 0-100 → renders a progress bar
])

@php
    $map = [
        'orange' => ['bg-orange-50 text-orange-500 dark:bg-orange-500/10 dark:text-orange-400', 'bg-orange-500'],
        'green' => ['bg-green-50 text-green-600 dark:bg-green-500/10 dark:text-green-400', 'bg-green-500'],
        'amber' => ['bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400', 'bg-amber-500'],
        'blue' => ['bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400', 'bg-blue-500'],
        'red' => ['bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400', 'bg-red-500'],
        'violet' => ['bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400', 'bg-violet-500'],
    ];
    [$chip, $bar] = $map[$accent] ?? $map['orange'];
@endphp

<div {{ $attributes->class('rounded-xl border border-[#F3E8DD] bg-[#FFFDF8] p-4 transition hover:-translate-y-0.5 hover:shadow-md dark:border-white/10 dark:bg-white/5') }}>
    <div class="flex items-start justify-between">
        <span class="flex size-9 items-center justify-center rounded-lg {{ $chip }}"><flux:icon :name="$icon" class="size-[18px]" /></span>
        @if($progress !== null)
            <span class="text-[11px] font-bold text-[#9CA3AF] dark:text-zinc-500">{{ (int) $progress }}%</span>
        @endif
    </div>
    <div class="mt-3 text-2xl font-extrabold text-[#111827] dark:text-white" x-data="countUp(@js((string) $value))" x-text="display">{{ $value }}</div>
    <div class="text-[11px] font-medium text-[#6B7280] dark:text-zinc-400">{{ $label }}</div>
    @if($sub)<div class="mt-0.5 text-[10px] text-[#9CA3AF] dark:text-zinc-500">{{ $sub }}</div>@endif
    @if($progress !== null)
        <div class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-[#F3E8DD] dark:bg-zinc-800">
            <div class="h-full rounded-full {{ $bar }} transition-all duration-700" style="width: {{ max(0, min(100, (int) $progress)) }}%"></div>
        </div>
    @endif
</div>
