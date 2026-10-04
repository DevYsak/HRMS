@props(['alerts'])

{{-- Things waiting on the employee. Rendered only when there is something to
     do — the service returns an empty collection otherwise. --}}
@if($alerts->isNotEmpty())
    <section aria-label="Tasks waiting for you" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach($alerts as $alert)
            @php $tag = $alert['url'] ? 'a' : 'div'; @endphp
            <{{ $tag }} @if($alert['url']) href="{{ $alert['url'] }}" wire:navigate @endif
                class="group flex items-center gap-3 rounded-xl border border-zinc-200/70 bg-white p-3.5 shadow-[0_1px_2px_0_rgb(16_24_40/0.04)] transition hover:border-orange-200 hover:bg-orange-50/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500 dark:border-white/[0.06] dark:bg-ink-900 dark:hover:border-orange-500/30">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400">
                    <flux:icon :name="$alert['icon']" class="size-4" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $alert['title'] }}</span>
                    <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $alert['status'] }}</span>
                    @if($alert['progress'] !== null)
                        <span class="mt-2 block h-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10" role="progressbar"
                              aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $alert['progress'] }}" aria-label="{{ $alert['title'] }} progress">
                            <span class="block h-full rounded-full bg-orange-500" style="width: {{ $alert['progress'] }}%"></span>
                        </span>
                    @endif
                </span>
                @if($alert['url'])
                    <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-300 transition group-hover:translate-x-0.5 group-hover:text-orange-500 dark:text-zinc-600" />
                @endif
            </{{ $tag }}>
        @endforeach
    </section>
@endif
