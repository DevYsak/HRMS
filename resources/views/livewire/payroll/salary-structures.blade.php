<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Salary Structures</h1>
            <p class="pulse-page-subtitle">Define reusable salary structures and link them to salary components</p>
        </div>
        <div class="flex items-center gap-3">
            <flux:switch wire:model.live="showTrashed" label="Show deleted" />
            <flux:button wire:click="create" variant="primary" icon="plus">Add Structure</flux:button>
        </div>
    </div>

    <div class="pulse-card divide-y divide-zinc-50 dark:divide-zinc-800/60 p-0 overflow-hidden">
        @forelse($structures as $structure)
            <div class="p-4 flex items-center justify-between hover:bg-zinc-50/50 transition-colors dark:hover:bg-zinc-900/40">
                <div class="flex items-center gap-4">
                    <div class="size-10 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center dark:bg-violet-900/20">
                        <flux:icon.rectangle-stack class="size-5" />
                    </div>
                    <div>
                        <div class="font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                            {{ $structure->name }}
                            @if($structure->code)
                                <flux:badge size="sm" color="zinc">{{ $structure->code }}</flux:badge>
                            @endif
                            @if($structure->trashed())
                                <flux:badge size="sm" color="red">Deleted</flux:badge>
                            @elseif(! $structure->is_active)
                                <flux:badge size="sm" color="amber">Inactive</flux:badge>
                            @else
                                <flux:badge size="sm" color="green">Active</flux:badge>
                            @endif
                        </div>
                        <div class="text-xs text-zinc-500">
                            {{ $structure->description ?: 'No description' }}
                            &middot; {{ $structure->components->count() }} component{{ $structure->components->count() === 1 ? '' : 's' }}
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    @if($structure->trashed())
                        <flux:button wire:click="restore({{ $structure->id }})" variant="ghost" size="sm" icon="arrow-uturn-left">Restore</flux:button>
                    @else
                        <flux:dropdown>
                            <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                            <flux:menu>
                                <flux:menu.item wire:click="edit({{ $structure->id }})" icon="pencil">Edit</flux:menu.item>
                                <flux:menu.item wire:click="toggleActive({{ $structure->id }})" icon="{{ $structure->is_active ? 'eye-slash' : 'eye' }}">
                                    {{ $structure->is_active ? 'Deactivate' : 'Activate' }}
                                </flux:menu.item>
                                <flux:menu.separator />
                                <flux:menu.item wire:click="delete({{ $structure->id }})" wire:confirm="Delete this salary structure?" variant="danger" icon="trash">
                                    Delete
                                </flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                </div>
            </div>
        @empty
            <div class="p-8 text-center text-sm text-zinc-500">
                No salary structures configured yet. Click "Add Structure" to create one.
            </div>
        @endforelse
    </div>

    {{-- Management Modal --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showModal', false)"></div>
            <div class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 space-y-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
                <div>
                    <h2 class="text-base font-bold text-zinc-900 dark:text-white">{{ $editingId ? 'Edit Structure' : 'New Salary Structure' }}</h2>
                    <p class="text-sm text-zinc-500 mt-0.5">Define the structure and the components it includes.</p>
                </div>
                <form wire:submit="save" class="space-y-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:input wire:model="form.name" label="Structure Name" placeholder="e.g. Standard Employee" required />
                        <flux:input wire:model="form.code" label="Structure Code" placeholder="e.g. STD_EMP" />
                    </div>
                    <flux:textarea wire:model="form.description" label="Description" rows="2" placeholder="Optional description" />
                    <flux:switch wire:model="form.is_active" label="Active" />

                    <div class="space-y-2 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                        <flux:label>Salary Components</flux:label>
                        @forelse($components as $payComponent)
                            <div class="flex items-center gap-3">
                                <flux:checkbox wire:model="selectedComponents.{{ $payComponent->id }}" />
                                <span class="flex-1 text-sm text-zinc-700 dark:text-zinc-200">
                                    {{ $payComponent->name }}
                                    <span class="text-xs text-zinc-400">({{ $payComponent->type }})</span>
                                </span>
                                <flux:input wire:model="componentAmounts.{{ $payComponent->id }}" type="number" step="0.01"
                                    placeholder="{{ number_format($payComponent->default_amount, 2) }}" class="w-32" size="sm" />
                            </div>
                        @empty
                            <p class="text-sm text-zinc-500">No active salary components available.</p>
                        @endforelse
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="button" @click="$wire.set('showModal', false)"
                            class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">
                            Cancel
                        </button>
                        <flux:button type="submit" variant="primary">{{ $editingId ? 'Update' : 'Create' }} Structure</flux:button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</flux:main>
