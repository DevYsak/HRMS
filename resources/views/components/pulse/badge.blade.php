@props([
    'color' => 'zinc',   // zinc | brand | emerald | amber | rose | blue | indigo | violet
])

@php
    $map = [
        'zinc'    => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300',
        'brand'   => 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-400',
        'emerald' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
        'amber'   => 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
        'rose'    => 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400',
        'blue'    => 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400',
        'indigo'  => 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-400',
        'violet'  => 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-400',
    ];
    $classes = $map[$color] ?? $map['zinc'];
@endphp

<span {{ $attributes->class("inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide $classes") }}>
    {{ $slot }}
</span>
