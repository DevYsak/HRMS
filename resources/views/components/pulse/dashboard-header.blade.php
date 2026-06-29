@props([
    'title' => '',
    'subtitle' => null,
    'showBadge' => true,   // time-of-day pill (Morning/Afternoon/Evening)
])

@php
    $hour = now()->hour;
@endphp

<div {{ $attributes->class('relative overflow-hidden rounded-[20px] border border-zinc-100 bg-white p-6 shadow-sm dark:border-white/5 dark:bg-ink-900 lg:p-7') }}>
    <div class="pointer-events-none absolute -right-16 -top-20 size-56 rounded-full bg-brand-500/10 blur-3xl"></div>
    <div class="pointer-events-none absolute inset-0 opacity-[0.04] dark:opacity-[0.07]" style="background-image:radial-gradient(#f97316 1px,transparent 1px);background-size:22px 22px;-webkit-mask-image:radial-gradient(circle at 90% 10%,#000,transparent 55%);mask-image:radial-gradient(circle at 90% 10%,#000,transparent 55%)"></div>

    <div class="relative flex flex-col gap-6 xl:flex-row xl:items-center xl:justify-between">
        <div class="min-w-0">
            <div class="mb-2 flex items-center gap-2">
                @if($showBadge)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                        @if($hour < 12)<flux:icon.sun class="size-3" /> Morning
                        @elseif($hour < 17)<flux:icon.sun class="size-3" /> Afternoon
                        @else<flux:icon.moon class="size-3" /> Evening @endif
                    </span>
                @endif
                <span class="text-xs font-medium text-zinc-400 dark:text-zinc-500">{{ now()->format('l, d F Y') }}</span>
            </div>
            <h1 class="text-2xl font-black tracking-tight text-zinc-900 dark:text-white lg:text-3xl">{{ $title }}</h1>
            @if($subtitle)
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $subtitle }}</p>
            @endif

            {{-- Optional inline summary strip (passed as default slot) --}}
            @if(trim($slot) !== '')
                <div class="mt-5 flex flex-wrap items-center gap-x-7 gap-y-3">{{ $slot }}</div>
            @endif
        </div>

        @isset($actions)
            <div class="flex shrink-0 flex-wrap items-center gap-2.5">{{ $actions }}</div>
        @endisset
    </div>
</div>
