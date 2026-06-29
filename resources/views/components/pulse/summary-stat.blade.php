@props([
    'label' => '',
    'value' => 0,
    'icon' => 'chart-bar',
    'accent' => 'brand', // brand | emerald | amber | rose | blue | indigo
])

@php
    $cls = [
        'brand' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400',
        'emerald' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
        'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
        'rose' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400',
        'blue' => 'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400',
        'indigo' => 'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400',
    ][$accent] ?? 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400';
@endphp

<div class="flex items-center gap-2.5">
    <span class="flex size-9 items-center justify-center rounded-xl {{ $cls }}"><flux:icon :name="$icon" class="size-4" /></span>
    <div>
        <div class="text-lg font-black leading-none text-zinc-900 tabular-nums dark:text-white">{{ $value }}</div>
        <div class="mt-0.5 text-[11px] font-semibold text-zinc-400">{{ $label }}</div>
    </div>
</div>
