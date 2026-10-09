@props([
    'title' => '',
    'subtitle' => null,
    'icon' => null,
    'accent' => 'orange',
])

@php
    $chip = [
        'orange' => 'bg-orange-50 text-orange-500 dark:bg-orange-500/10 dark:text-orange-400', 'green' => 'bg-green-50 text-green-600 dark:bg-green-500/10 dark:text-green-400',
        'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400', 'blue' => 'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400',
        'red' => 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400', 'violet' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400',
    ][$accent] ?? 'bg-orange-50 text-orange-500 dark:bg-orange-500/10 dark:text-orange-400';
@endphp

<div {{ $attributes->class('rounded-2xl border border-zinc-100 bg-white p-5 shadow-sm dark:border-white/5 dark:bg-ink-900') }}>
    @if($title)
        <div class="mb-4 flex items-center gap-2.5">
            @if($icon)
                <span class="flex size-8 items-center justify-center rounded-lg {{ $chip }}"><flux:icon :name="$icon" class="size-4" /></span>
            @endif
            <div class="flex-1">
                <h3 class="text-base font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $title }}</h3>
                @if($subtitle)<p class="text-xs font-medium text-zinc-400">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)<div class="flex items-center gap-2">{{ $actions }}</div>@endisset
        </div>
    @endif
    {{ $slot }}
</div>
