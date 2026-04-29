<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Leave Settings</h1>
            <p class="pulse-page-subtitle">Configure leave types and company policies</p>
        </div>
        <flux:button wire:click="openModal()" variant="primary" icon="plus">
            Add Leave Type
        </flux:button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="pulse-card">
                <h3 class="text-base font-bold text-zinc-900 dark:text-white mb-4">Leave Types</h3>
                
                <div class="space-y-3">
                    @foreach($leaveTypes as $type)
                        <div class="flex items-center justify-between p-4 bg-white border border-zinc-100 rounded-xl group hover:border-brand-200 transition-colors dark:bg-zinc-900 dark:border-zinc-800 dark:hover:border-brand-800">
                            <div class="flex items-center gap-4">
                                <div class="size-4 rounded-full shadow-sm" style="background-color: {{ $type->color }}"></div>
                                <div>
                                    <div class="font-semibold text-zinc-900 dark:text-white">{{ $type->name }}</div>
                                    <div class="text-[10px] uppercase font-bold text-zinc-400">
                                        {{ $type->is_paid ? 'Paid' : 'Unpaid' }} · {{ str_replace('_', ' ', $type->category ?? 'other') }}
                                    </div>
                                    <div class="text-[10px] text-zinc-500 mt-1">
                                        Carry Forward: {{ $type->allow_carry_forward ? ($type->carry_forward_limit > 0 ? 'Yes · Limit '.$type->carry_forward_limit.' days' : 'Yes · No limit') : 'No' }}
                                        · Encashment: {{ $type->allow_encashment ? 'Enabled' : 'Disabled' }}
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                <flux:button wire:click="openModal({{ $type->id }})" variant="ghost" size="sm" icon="pencil-square" />
                                <flux:button wire:click="delete({{ $type->id }})" wire:confirm="Are you sure? This may affect existing requests." variant="ghost" size="sm" icon="trash" class="text-red-400 hover:text-red-600" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="pulse-card bg-brand-600 text-white dark:bg-brand-900/40">
                <flux:icon.information-circle class="size-6 mb-3 text-brand-200" />
                <h4 class="font-bold mb-1">Policy Management</h4>
                <p class="text-sm text-brand-100 leading-relaxed">
                    Leave types defined here are available for all employees. Balances must be initialized for new leave types in the employee profile settings.
                </p>
            </div>
        </div>
    </div>

    {{-- Type Modal --}}
    <flux:modal wire:model="showModal" class="w-full max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Edit' : 'Add' }} Leave Type</flux:heading>
            </div>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model="name" label="Type Name" placeholder="e.g. Annual Leave" required />

                <div class="flex items-center gap-4 py-2">
                    <flux:checkbox wire:model="is_paid" label="Paid Leave" />
                </div>

                <flux:select wire:model="category" label="Category">
                    <option value="annual">Annual</option>
                    <option value="sick">Sick</option>
                    <option value="mdl">MDL</option>
                    <option value="comp_off">Comp Off</option>
                    <option value="encashment">Encashment</option>
                    <option value="unpaid">Unpaid</option>
                    <option value="other">Other</option>
                </flux:select>

                <div class="grid grid-cols-1 gap-4">
                    <flux:switch wire:model="allow_carry_forward" label="Allow Carry Forward" />
                    <flux:input wire:model="carry_forward_limit" type="number" min="0" label="Carry Forward Limit" suffix="days" />
                    <flux:switch wire:model="allow_encashment" label="Allow Encashment" />
                </div>

                <div class="space-y-2">
                    <flux:label>UI Color Marker</flux:label>
                    <div class="flex flex-wrap gap-2">
                        @foreach(['#1DB77A', '#3B82F6', '#EF4444', '#F59E0B', '#8B5CF6', '#EC4899', '#6B7280', '#06B6D4'] as $c)
                            <button type="button" wire:click="$set('color', '{{ $c }}')" 
                                class="size-7 rounded-full border-2 transition-transform hover:scale-110 {{ $color === $c ? 'border-zinc-900 scale-110 dark:border-white' : 'border-transparent' }}"
                                style="background-color: {{ $c }}">
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-4">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">Save Type</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</flux:main>
