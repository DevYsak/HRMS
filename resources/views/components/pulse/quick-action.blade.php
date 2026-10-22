@props([
    'label' => '',
    'icon' => 'arrow-right',
    'href' => '#',
])

<a href="{{ $href }}" wire:navigate {{ $attributes->class('flex items-center gap-2.5 rounded-xl border border-zinc-100 bg-white px-3 py-2.5 text-[13px] font-medium text-zinc-700 transition hover:border-brand-200 hover:text-brand-600 dark:border-white/5 dark:bg-ink-900 dark:text-zinc-200') }}>
    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400"><flux:icon :name="$icon" class="size-4" /></span>
    <span class="min-w-0 truncate">{{ $label }}</span>
</a>
