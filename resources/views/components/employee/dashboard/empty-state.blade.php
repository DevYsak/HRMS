@props(['icon' => 'inbox', 'title', 'text' => null])

<div {{ $attributes->class('flex flex-1 flex-col items-center justify-center gap-1.5 py-6 text-center') }}>
    <span class="mb-1 flex size-9 items-center justify-center rounded-full bg-zinc-100 text-zinc-400 dark:bg-white/5 dark:text-zinc-500">
        <flux:icon :name="$icon" class="size-4" />
    </span>
    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $title }}</p>
    @if($text)
        <p class="max-w-[17rem] text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $text }}</p>
    @endif
    {{ $slot }}
</div>
