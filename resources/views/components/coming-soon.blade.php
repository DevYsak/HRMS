@props(['title', 'subtitle' => null, 'icon' => 'wrench-screwdriver'])

<div class="flex flex-col items-center justify-center py-24 text-center">
    <div class="flex size-16 items-center justify-center rounded-2xl bg-brand-50 mb-5">
        <flux:icon :name="$icon" class="size-8 text-brand-600" />
    </div>
    <h2 class="text-xl font-bold text-zinc-900 dark:text-white">{{ $title }}</h2>
    @if($subtitle)
        <p class="mt-2 text-sm text-zinc-500 max-w-sm">{{ $subtitle }}</p>
    @endif
    <flux:badge color="yellow" class="mt-4">Coming in next phase</flux:badge>
</div>
