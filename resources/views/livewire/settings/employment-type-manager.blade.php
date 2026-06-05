<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">

    <div class="pulse-page-header">
        <div>
            <flux:breadcrumbs class="mb-2">
                <flux:breadcrumbs.item>Settings</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Employment Types</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <h1 class="pulse-page-title">Employment Types</h1>
            <p class="pulse-page-subtitle">Define employment types, probation durations, and eligibility rules.</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:checkbox wire:model.live="showTrashed" label="Show deleted" />
            <flux:button wire:click="openCreate" variant="primary" icon="plus">Add Type</flux:button>
        </div>
    </div>

    <div class="pulse-card overflow-hidden p-0">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-100 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-400">Name</th>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-400">Probation</th>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-400">Notice Period</th>
                    <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-400">Leave</th>
                    <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-400">Payroll</th>
                    <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-400">OT</th>
                    <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-400">Status</th>
                    <th class="px-4 py-3 text-right font-semibold text-zinc-600 dark:text-zinc-400">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse($types as $type)
                    <tr class="@if($type->trashed()) opacity-50 @endif hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                        <td class="px-4 py-3">
                            <div class="font-medium text-zinc-900 dark:text-white">{{ $type->name }}</div>
                            <div class="text-xs text-zinc-400 font-mono">{{ $type->slug }}</div>
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $type->probation_days }} days</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $type->notice_period_days }} days</td>
                        <td class="px-4 py-3 text-center">
                            <flux:icon.check-circle class="mx-auto size-5 @if($type->leave_eligible) text-emerald-500 @else text-zinc-300 dark:text-zinc-700 @endif" />
                        </td>
                        <td class="px-4 py-3 text-center">
                            <flux:icon.check-circle class="mx-auto size-5 @if($type->payroll_eligible) text-emerald-500 @else text-zinc-300 dark:text-zinc-700 @endif" />
                        </td>
                        <td class="px-4 py-3 text-center">
                            <flux:icon.check-circle class="mx-auto size-5 @if($type->ot_eligible) text-emerald-500 @else text-zinc-300 dark:text-zinc-700 @endif" />
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($type->trashed())
                                <flux:badge color="red" size="sm">Deleted</flux:badge>
                            @elseif($type->is_active)
                                <flux:badge color="green" size="sm">Active</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                @if($type->trashed())
                                    <flux:button wire:click="restore({{ $type->id }})" size="sm" variant="ghost">Restore</flux:button>
                                @else
                                    <flux:button wire:click="openEdit({{ $type->id }})" size="sm" variant="ghost" icon="pencil" />
                                    <flux:button
                                        wire:click="delete({{ $type->id }})"
                                        wire:confirm="Delete '{{ $type->name }}'? Employees assigned to it will keep their assignment."
                                        size="sm" variant="ghost" icon="trash" class="text-red-500"
                                    />
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-zinc-400">No employment types defined.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Add / Edit Modal --}}
    <flux:modal wire:model="showModal" class="md:max-w-xl">
        <div class="space-y-5 p-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Employment Type' : 'Add Employment Type' }}</flux:heading>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model.live="name" label="Name" placeholder="e.g. Full-time" required />
                <flux:input wire:model="slug" label="Slug" placeholder="full-time" class="font-mono" required />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="probation_days" type="number" min="0" max="730" label="Probation Days" required />
                <flux:input wire:model="notice_period_days" type="number" min="0" max="365" label="Notice Period Days" required />
            </div>

            <div class="grid grid-cols-3 gap-4">
                <flux:checkbox wire:model="leave_eligible" label="Leave Eligible" />
                <flux:checkbox wire:model="payroll_eligible" label="Payroll Eligible" />
                <flux:checkbox wire:model="ot_eligible" label="OT Eligible" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:checkbox wire:model="is_active" label="Active" />
                <flux:input wire:model="sort_order" type="number" min="0" label="Sort Order" />
            </div>

            <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button wire:click="closeModal" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="save" variant="primary">{{ $editingId ? 'Update' : 'Create' }}</flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
