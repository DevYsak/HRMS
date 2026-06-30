@props(['title' => null, 'icon' => null, 'href' => null, 'cta' => null])

{{-- Reusable premium section card with optional title row + action slot. --}}
<div {{ $attributes->merge(['class' => 'flex h-full flex-col rounded-2xl border border-zinc-100 bg-white p-5 shadow-sm transition duration-300 hover:shadow-md dark:border-white/5 dark:bg-zinc-900']) }}>
    @if($title)
        <div class="mb-4 flex items-center justify-between gap-3">
            <h3 class="flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white">
                @if($icon)
                    <span class="flex size-7 items-center justify-center rounded-lg bg-orange-50 dark:bg-orange-900/20">
                        <flux:icon :name="$icon" class="size-4 text-orange-500" />
                    </span>
                @endif
                {{ $title }}
            </h3>
            @if(isset($action))
                {{ $action }}
            @elseif($href)
                <a href="{{ $href }}" wire:navigate class="text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">{{ $cta ?? 'View All' }} →</a>
            @endif
        </div>
    @endif

    <div class="flex flex-1 flex-col">
        {{ $slot }}
    </div>
</div>
