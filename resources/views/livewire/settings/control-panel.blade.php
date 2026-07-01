<flux:main class="space-y-8 p-4 md:p-6">

    <div>
        <flux:heading size="xl">Control Panel</flux:heading>
        <flux:subheading>Every administrative setting in one place.</flux:subheading>
    </div>

    @foreach($groups as $group)
        @php
            $visible = collect($group['items'])->filter(fn ($i) => \Illuminate\Support\Facades\Route::has($i['route']))->values();
        @endphp
        @if($visible->isNotEmpty())
            <div>
                <h3 class="mb-3 flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-zinc-500">
                    <flux:icon :name="$group['icon']" class="size-4 text-orange-400" />
                    {{ $group['title'] }}
                </h3>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($visible as $item)
                        <a href="{{ route($item['route']) }}" wire:navigate
                            class="group flex items-start gap-3 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-md dark:border-white/10 dark:bg-zinc-900 dark:hover:border-orange-500/30">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-orange-500 transition group-hover:bg-orange-100 dark:bg-orange-900/20">
                                <flux:icon :name="$item['icon']" class="size-5" />
                            </span>
                            <div class="min-w-0">
                                <div class="flex items-center gap-1 text-sm font-bold text-zinc-900 dark:text-white">
                                    {{ $item['label'] }}
                                    <flux:icon.arrow-up-right class="size-3.5 text-zinc-300 transition group-hover:text-orange-400" />
                                </div>
                                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $item['description'] }}</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach

</flux:main>
