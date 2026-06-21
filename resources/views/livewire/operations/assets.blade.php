<flux:main>
        <div class="pulse-action-bar">
            <div>
                <flux:heading size="xl" level="1">{{ __('Company Assets') }}</flux:heading>
                <flux:subheading>{{ __('Manage hardware, equipment, and other company assets assigned to employees.') }}</flux:subheading>
            </div>
            <div class="flex items-center gap-2">
                <flux:button icon="plus" variant="primary" wire:click="openCreateModal">{{ __('Add Asset') }}</flux:button>
            </div>
        </div>

        <div class="pulse-card p-0 overflow-hidden">
            <div class="pulse-table-wrap">
                <table class="pulse-table">
                    <thead>
                        <tr>
                            <th class="pulse-th pl-6">{{ __('Asset Name') }}</th>
                            <th class="pulse-th">{{ __('Type') }}</th>
                            <th class="pulse-th">{{ __('Assigned To') }}</th>
                            <th class="pulse-th">{{ __('Status') }}</th>
                            <th class="pulse-th text-right pr-6">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($assets as $asset)
                            <tr>
                                <td class="pulse-td pl-6">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $asset->name }}</div>
                                    <div class="text-xs text-zinc-500">SN: {{ $asset->serial_number ?? 'N/A' }}</div>
                                </td>
                                <td class="pulse-td capitalize">{{ $asset->type }}</td>
                                <td class="pulse-td">
                                    @if($asset->employee)
                                        <div class="flex items-center gap-2">
                                            <flux:avatar size="xs" :initials="strtoupper(substr($asset->employee->user->name, 0, 1))" class="bg-brand-600 text-white" />
                                            <span>{{ $asset->employee->user->name }}</span>
                                        </div>
                                    @else
                                        <span class="text-zinc-400 italic">Unassigned</span>
                                    @endif
                                </td>
                                <td class="pulse-td">
                                    @if($asset->status->value === 'available')
                                        <flux:badge color="green" size="sm">Available</flux:badge>
                                    @elseif($asset->status->value === 'assigned')
                                        <flux:badge color="blue" size="sm">Assigned</flux:badge>
                                    @elseif($asset->status->value === 'maintenance')
                                        <flux:badge color="orange" size="sm">Maintenance</flux:badge>
                                    @else
                                        <flux:badge color="red" size="sm">Lost/Broken</flux:badge>
                                    @endif
                                </td>
                                <td class="pulse-td text-right pr-6">
                                    @if($asset->status->value === 'available')
                                        <flux:button variant="ghost" size="sm" wire:click="openAssignModal({{ $asset->id }})">Assign</flux:button>
                                    @elseif($asset->status->value === 'assigned')
                                        <flux:button variant="ghost" size="sm" wire:click="openReturnModal({{ $asset->id }})">Return</flux:button>
                                    @else
                                        <flux:button variant="ghost" size="sm" disabled>No action</flux:button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="pulse-table__empty">
                                    <flux:icon.computer-desktop class="size-8 mx-auto mb-3 text-zinc-400" />
                                    <p>{{ __('No assets found in the system.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showCreateModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showCreateModal', false)"></div>
            <div class="relative w-full max-w-xl bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showCreateModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

            <div class="space-y-4">
                <flux:heading size="lg">Add Asset</flux:heading>
                <flux:input wire:model="name" label="Asset Name" required />
                <flux:input wire:model="type" label="Type" placeholder="laptop, phone, card, etc." required />
                <flux:input wire:model="serialNumber" label="Serial Number" />
                <flux:textarea wire:model="notes" label="Notes" rows="3" />
                <div class="flex justify-end">
                    <flux:button variant="primary" wire:click="createAsset">Create</flux:button>
                </div>
            </div>
        
            </div>
        </div>
    @endif

        @if($showAssignModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showAssignModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showAssignModal', false)"></div>
            <div class="relative w-full max-w-xl bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showAssignModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

            <div class="space-y-4">
                <flux:heading size="lg">Assign Asset</flux:heading>
                <flux:select wire:model="employeeId" label="Employee" required>
                    <option value="">Select employee</option>
                    @foreach($employees as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->user->name }}</option>
                    @endforeach
                </flux:select>
                <flux:textarea wire:model="notes" label="Assignment Notes" rows="3" />
                <div class="flex justify-end">
                    <flux:button variant="primary" wire:click="assignAsset">Assign</flux:button>
                </div>
            </div>
        
            </div>
        </div>
    @endif

        @if($showReturnModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showReturnModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showReturnModal', false)"></div>
            <div class="relative w-full max-w-xl bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showReturnModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

            <div class="space-y-4">
                <flux:heading size="lg">Return Asset</flux:heading>
                <flux:input wire:model="conditionOnReturn" label="Condition on Return" required />
                <flux:textarea wire:model="returnNotes" label="Return Notes" rows="3" />
                <div class="flex justify-end">
                    <flux:button variant="primary" wire:click="returnAsset">Mark Returned</flux:button>
                </div>
            </div>
        
            </div>
        </div>
    @endif
    </flux:main>
