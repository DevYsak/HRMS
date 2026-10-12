@props([
    'title' => null,
    'subtitle' => null,
    'icon' => null,
    'flush' => false,   // remove inner padding (for tables)
])

{{-- Surface, radius, shadow and padding follow Settings → Brand & Layout (.ui-surface, --ui-card-padding). --}}
<div {{ $attributes->class('ui-surface') }}>
    @if($title || isset($actions))
        <div class="flex items-center justify-between gap-3 px-[var(--ui-card-padding,1.25rem)] pt-[var(--ui-card-padding,1.25rem)] {{ $flush ? 'pb-4' : '' }}">
            <div class="flex items-center gap-2.5">
                @if($icon)
                    <div class="flex size-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                        <flux:icon :name="$icon" class="size-4" />
                    </div>
                @endif
                <div>
                    <h3 class="text-base font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $title }}</h3>
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

    <div class="{{ $flush ? '' : 'ui-card-pad' }} {{ ($title || isset($actions)) && ! $flush ? 'pt-4' : '' }}">
        {{ $slot }}
    </div>
</div>
