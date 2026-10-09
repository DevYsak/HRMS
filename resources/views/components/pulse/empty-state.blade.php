@props([
    'title' => null,
    'text' => null,
    'icon' => null,
    'href' => null,       // optional action link
    'cta' => 'View',
])

{{-- Compact empty state (about 100-140px): a short line and an optional link,
     never a tall blank card. --}}
<div {{ $attributes->class('flex min-h-[96px] flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-zinc-200 px-4 py-5 text-center dark:border-white/10') }}>
    @if($icon)
        <flux:icon :name="$icon" class="size-5 text-zinc-300 dark:text-zinc-600" />
    @endif
    @if($title)
        <p class="text-[13px] font-semibold text-zinc-700 dark:text-zinc-200">{{ $title }}</p>
    @endif
    @if($text)
        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $text }}</p>
    @endif
    @if($href)
        <a href="{{ $href }}" wire:navigate class="mt-1 text-xs font-semibold text-brand-600 hover:text-brand-700 dark:text-brand-400">{{ $cta }} <span aria-hidden="true">→</span></a>
    @endif
</div>
