<flux:main class="space-y-6 p-4 md:p-6">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">Sidebar Menu</flux:heading>
            <flux:subheading>Control the employee sidebar — reorder, rename, or hide top-level items. No code changes needed.</flux:subheading>
        </div>
        <flux:button wire:click="save" variant="primary" icon="check">Save changes</flux:button>
    </div>

    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <div class="divide-y divide-zinc-100 dark:divide-white/5">
            @foreach($items as $i => $item)
                <div class="flex items-center gap-3 px-4 py-3 {{ $item['enabled'] ? '' : 'opacity-50' }}">
                    {{-- Reorder --}}
                    <div class="flex flex-col">
                        <button type="button" wire:click="moveUp({{ $i }})" @disabled($i === 0)
                            class="text-zinc-400 transition hover:text-orange-500 disabled:opacity-30">
                            <flux:icon.chevron-up class="size-4" />
                        </button>
                        <button type="button" wire:click="moveDown({{ $i }})" @disabled($i === count($items) - 1)
                            class="text-zinc-400 transition hover:text-orange-500 disabled:opacity-30">
                            <flux:icon.chevron-down class="size-4" />
                        </button>
                    </div>

                    {{-- Icon --}}
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-orange-50 dark:bg-orange-900/20">
                        <flux:icon :name="$item['icon']" class="size-4 text-orange-500" />
                    </span>

                    {{-- Label --}}
                    <div class="flex-1">
                        <input type="text" wire:model="items.{{ $i }}.label"
                            class="w-full max-w-xs rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm font-medium text-zinc-800 focus:border-orange-400 focus:ring-orange-400 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-100">
                        <div class="mt-0.5 text-[10px] uppercase tracking-wide text-zinc-400">{{ $item['key'] }} · {{ $item['type'] }}</div>
                    </div>

                    {{-- Enable toggle --}}
                    <button type="button" wire:click="$toggle('items.{{ $i }}.enabled')"
                        @class([
                            'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition',
                            'bg-orange-500' => $item['enabled'],
                            'bg-zinc-200 dark:bg-zinc-700' => ! $item['enabled'],
                        ])>
                        <span @class([
                            'inline-block size-5 transform rounded-full bg-white shadow transition',
                            'translate-x-5' => $item['enabled'],
                            'translate-x-0.5' => ! $item['enabled'],
                        ])></span>
                    </button>
                    <span class="w-16 text-right text-xs font-semibold {{ $item['enabled'] ? 'text-emerald-600' : 'text-zinc-400' }}">
                        {{ $item['enabled'] ? 'Visible' : 'Hidden' }}
                    </span>
                </div>
            @endforeach
        </div>
    </div>

    <p class="text-xs text-zinc-400">
        Changes apply to the employee role's sidebar. Manager, HR and admin sidebars are unaffected.
        Groups (Performance, Development, Payroll) keep their sub-items; hiding a group hides all of its links.
    </p>

</flux:main>
