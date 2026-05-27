<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Salary Components</h1>
            <p class="pulse-page-subtitle">Define and manage earning and deduction structure</p>
        </div>
        <flux:button wire:click="create" variant="primary" icon="plus">Add Component</flux:button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        {{-- Earnings Section --}}
        <div class="space-y-4">
            <h3 class="text-sm font-bold text-zinc-400 uppercase tracking-wider flex items-center gap-2">
                <flux:icon.plus-circle class="size-4 text-green-500" /> Earnings
            </h3>
            <div class="pulse-card divide-y divide-zinc-50 dark:divide-zinc-800/60 p-0 overflow-hidden">
                @foreach($earnings as $comp)
                    <div class="p-4 flex items-center justify-between hover:bg-zinc-50/50 transition-colors dark:hover:bg-zinc-900/40">
                        <div class="flex items-center gap-4">
                            <div class="size-10 rounded-xl bg-green-50 text-green-600 flex items-center justify-center dark:bg-green-900/20">
                                <flux:icon.banknotes class="size-5" />
                            </div>
                            <div>
                                <div class="font-bold text-zinc-900 dark:text-white">{{ $comp->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $comp->is_fixed ? 'Fixed amount' : 'Variable' }}</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="text-right">
                                <div class="font-bold text-zinc-900 dark:text-white">{{ number_format($comp->default_amount, 2) }}</div>
                                <div class="text-[10px] text-zinc-400 uppercase font-bold tracking-widest">Default</div>
                            </div>
                            <flux:dropdown>
                                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                                <flux:menu>
                                    <flux:menu.item wire:click="edit({{ $comp->id }})" icon="pencil">Edit</flux:menu.item>
                                    <flux:menu.item wire:click="toggleActive({{ $comp->id }})" icon="{{ $comp->is_active ? 'eye-slash' : 'eye' }}">
                                        {{ $comp->is_active ? 'Deactivate' : 'Activate' }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Deductions Section --}}
        <div class="space-y-4">
            <h3 class="text-sm font-bold text-zinc-400 uppercase tracking-wider flex items-center gap-2">
                <flux:icon.minus-circle class="size-4 text-red-500" /> Deductions
            </h3>
            <div class="pulse-card divide-y divide-zinc-50 dark:divide-zinc-800/60 p-0 overflow-hidden">
                @foreach($deductions as $comp)
                    <div class="p-4 flex items-center justify-between hover:bg-zinc-50/50 transition-colors dark:hover:bg-zinc-900/40">
                        <div class="flex items-center gap-4">
                            <div class="size-10 rounded-xl bg-red-50 text-red-600 flex items-center justify-center dark:bg-red-900/20">
                                <flux:icon.shield-check class="size-5" />
                            </div>
                            <div>
                                <div class="font-bold text-zinc-900 dark:text-white">{{ $comp->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $comp->is_fixed ? 'Fixed amount' : 'Variable' }}</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="text-right">
                                <div class="font-bold text-zinc-900 dark:text-white">{{ number_format($comp->default_amount, 2) }}</div>
                                <div class="text-[10px] text-zinc-400 uppercase font-bold tracking-widest">Default</div>
                            </div>
                            <flux:dropdown>
                                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                                <flux:menu>
                                    <flux:menu.item wire:click="edit({{ $comp->id }})" icon="pencil">Edit</flux:menu.item>
                                    <flux:menu.item wire:click="toggleActive({{ $comp->id }})" icon="{{ $comp->is_active ? 'eye-slash' : 'eye' }}">
                                        {{ $comp->is_active ? 'Deactivate' : 'Activate' }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Management Modal --}}
    <flux:modal wire:model.self="showModal" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Edit Component' : 'New Salary Component' }}</flux:heading>
                <flux:subheading>Define how this earning or deduction behaves.</flux:subheading>
            </div>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model="form.name" label="Component Name" placeholder="e.g. Basic Salary, Tax" required />
                
                <div class="grid grid-cols-2 gap-4">
                    <flux:select wire:model="form.type" label="Component Type">
                        <option value="earning">Earning (+)</option>
                        <option value="deduction">Deduction (-)</option>
                    </flux:select>
                    <flux:input wire:model="form.default_amount" type="number" step="0.01" label="Default Amount" />
                </div>

                <div class="pt-2 space-y-4">
                    <flux:switch wire:model="form.is_fixed" label="Fixed Amount" description="Is this a standard flat value or variable?" />
                    <flux:switch wire:model="form.is_active" label="Enabled" />
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ $editingId ? 'Update' : 'Create' }} Component</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</flux:main>
