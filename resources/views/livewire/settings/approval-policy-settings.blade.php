<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">

    <div class="pulse-page-header">
        <div>
            <flux:breadcrumbs class="mb-2">
                <flux:breadcrumbs.item>Settings</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Payroll Approval Policy</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <h1 class="pulse-page-title">Payroll Approval Policy</h1>
            <p class="pulse-page-subtitle">Define the ordered approval chain a payroll goes through before it's finalized. With no active steps below, any Finance/Director/Super Admin can approve in a single step, exactly as before.</p>
        </div>
        <flux:button wire:click="openCreate" variant="primary" icon="plus">Add Step</flux:button>
    </div>

    @if($policies->where('is_active', true)->isEmpty())
        <div class="pulse-card p-4 flex items-center gap-3 bg-blue-50 border-blue-100 dark:bg-blue-900/10 dark:border-blue-900/30">
            <flux:icon.information-circle class="size-5 text-blue-500 shrink-0" />
            <p class="text-sm text-blue-700 dark:text-blue-300">No active approval steps configured — payrolls use the original single-step finance approval.</p>
        </div>
    @endif

    <div class="pulse-card overflow-hidden p-0">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-100 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-400">Order</th>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-400">Step</th>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-400">Approver</th>
                    <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-400">Status</th>
                    <th class="px-4 py-3 text-right font-semibold text-zinc-600 dark:text-zinc-400">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse($policies as $policy)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1">
                                <span class="font-mono text-xs text-zinc-400 w-5">{{ $policy->level }}</span>
                                <div class="flex flex-col">
                                    <button wire:click="moveUp({{ $policy->id }})" class="text-zinc-300 hover:text-zinc-600 disabled:opacity-30" @if($loop->first) disabled @endif>
                                        <flux:icon.chevron-up class="size-3" />
                                    </button>
                                    <button wire:click="moveDown({{ $policy->id }})" class="text-zinc-300 hover:text-zinc-600 disabled:opacity-30" @if($loop->last) disabled @endif>
                                        <flux:icon.chevron-down class="size-3" />
                                    </button>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-zinc-900 dark:text-white">{{ $policy->label }}</div>
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">
                            @if($policy->approver_type === 'specific_user')
                                {{ $policy->specificUser?->name ?? 'Unknown user' }}
                            @else
                                {{ ucwords(str_replace('_', ' ', $policy->approver_type)) }}
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button wire:click="toggleActive({{ $policy->id }})">
                                @if($policy->is_active)
                                    <flux:badge color="green" size="sm">Active</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                                @endif
                            </button>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button wire:click="openEdit({{ $policy->id }})" size="sm" variant="ghost" icon="pencil" />
                                <flux:button
                                    wire:click="delete({{ $policy->id }})"
                                    wire:confirm="Remove the '{{ $policy->label }}' step?"
                                    size="sm" variant="ghost" icon="trash" class="text-red-500"
                                />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-zinc-400">No approval steps defined yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Add / Edit Modal --}}
    <flux:modal wire:model="showModal" class="md:max-w-lg">
        <div class="space-y-5 p-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Approval Step' : 'Add Approval Step' }}</flux:heading>

            <flux:input wire:model="label" label="Step Name" placeholder="e.g. HR Review" required />

            <flux:select wire:model.live="approver_type" label="Approver">
                <option value="hr_admin">HR Admin</option>
                <option value="finance">Finance</option>
                <option value="director">Director</option>
                <option value="super_admin">Super Admin</option>
                <option value="specific_user">Specific person</option>
            </flux:select>

            @if($approver_type === 'specific_user')
                <flux:select wire:model="specific_user_id" label="Person" placeholder="Select a person&hellip;">
                    @foreach($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </flux:select>
            @endif

            <flux:checkbox wire:model="is_active" label="Active" description="Inactive steps aren't included in new submissions" />

            <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button wire:click="closeModal" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="save" variant="primary">{{ $editingId ? 'Update' : 'Add' }}</flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
