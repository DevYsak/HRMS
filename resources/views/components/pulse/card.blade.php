@props([
    'title' => null,
    'subtitle' => null,
    'icon' => null,
    'flush' => false,   // remove inner padding (for tables)
])

<div {{ $attributes->class('rounded-2xl border border-zinc-100 bg-white shadow-sm dark:border-white/5 dark:bg-ink-900') }}>
    @if($title || isset($actions))
        <div class="flex items-center justify-between gap-3 px-5 pt-5 {{ $flush ? 'pb-4' : '' }}">
            <div class="flex items-center gap-2.5">
                @if($icon)
                    <div class="flex size-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                        <flux:icon :name="$icon" class="size-4" />
                    </div>
                @endif
                <div>
                    <h3 class="text-[15px] font-bold tracking-tight text-zinc-900 dark:text-white">{{ $title }}</h3>
                    @if($subtitle)
                        <p class="text-xs font-medium text-zinc-400">{{ $subtitle }}</p>
                    @endif
                </div>
            </div>
            @isset($actions)
                <div class="flex items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div class="{{ $flush ? '' : 'p-5' }} {{ ($title || isset($actions)) && ! $flush ? 'pt-4' : '' }}">
        {{ $slot }}
    </div>
</div>
