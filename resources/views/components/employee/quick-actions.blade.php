@props(['items' => []])

{{-- Grid of quick-action shortcuts. Each item: ['label','icon','href','nav'=>true]. --}}
<div class="grid grid-cols-3 gap-3 sm:grid-cols-4">
    @foreach($items as $a)
        <a href="{{ $a['href'] }}" @if($a['nav'] ?? true) wire:navigate @endif
           class="group flex flex-col items-center gap-2 rounded-2xl border border-zinc-100 bg-white p-3 text-center shadow-sm transition duration-300 hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-md dark:border-white/5 dark:bg-zinc-900 dark:hover:border-orange-900/40">
            <span class="flex size-10 items-center justify-center rounded-xl bg-orange-50 text-orange-600 transition duration-300 group-hover:scale-110 group-hover:bg-orange-500 group-hover:text-white dark:bg-orange-900/20 dark:text-orange-400">
                <flux:icon :name="$a['icon']" class="size-5" />
            </span>
            <span class="text-[11px] font-semibold leading-tight text-zinc-700 dark:text-zinc-300">{{ $a['label'] }}</span>
        </a>
    @endforeach
</div>
