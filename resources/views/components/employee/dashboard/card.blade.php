@props(['title' => null, 'icon' => null, 'subtitle' => null, 'href' => null, 'cta' => 'View all'])

{{-- Dashboard card shell: one header pattern (icon · title · subtitle · link)
     shared by every employee dashboard card. Pass an `action` slot to replace
     the link with something else. --}}
<section {{ $attributes->class('flex min-w-0 flex-col rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_0_rgb(16_24_40/0.04)] dark:border-white/[0.06] dark:bg-ink-900') }}>
    @if($title)
        <header class="flex items-center justify-between gap-3 px-4 pb-2.5 pt-3.5">
            <div class="flex min-w-0 items-center gap-2">
                @if($icon)
                    <flux:icon :name="$icon" class="size-4 shrink-0 text-zinc-400 dark:text-zinc-500" />
                @endif
                <h2 class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $title }}</h2>
                @if($subtitle)
                    <span class="hidden truncate text-xs text-zinc-400 sm:inline">{{ $subtitle }}</span>
                @endif
            </div>

            @isset($action)
                {{ $action }}
            @elseif($href)
                <a href="{{ $href }}" wire:navigate
                   class="inline-flex shrink-0 items-center gap-1 rounded-md text-xs font-medium text-orange-600 transition hover:text-orange-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500 dark:text-orange-400">
                    {{ $cta }} <flux:icon.arrow-right class="size-3" />
                </a>
            @endisset
        </header>
    @endif

    {{-- No flex-1: a card is only as tall as its content (stretched bodies left
         large empty areas in shorter cards). --}}
    <div @class(['flex flex-col px-4 pb-4', 'pt-4' => ! $title])>
        {{ $slot }}
    </div>
</section>
