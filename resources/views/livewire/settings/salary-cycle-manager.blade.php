<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">

    <div class="pulse-page-header">
        <div>
            <flux:breadcrumbs class="mb-2">
                <flux:breadcrumbs.item>Settings</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Salary Cycles</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <h1 class="pulse-page-title">Salary Cycles</h1>
            <p class="pulse-page-subtitle">Define payroll periods and pay dates. Replace the legacy Cycle A/B with named cycles.</p>
        </div>
        <flux:button wire:click="openCreate" variant="primary" icon="plus">Add Cycle</flux:button>
    </div>

    <div class="pulse-card overflow-hidden p-0">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-100 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-400">Name</th>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-400">Period</th>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-400">Pay Day</th>
                    <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-400">Default</th>
                    <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-400">Status</th>
                    <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-400">Employees</th>
                    <th class="px-4 py-3 text-right font-semibold text-zinc-600 dark:text-zinc-400">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse($cycles as $cycle)
                    <tr class="@if($cycle->trashed()) opacity-50 @endif hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                        <td class="px-4 py-3">
                            <div class="font-medium text-zinc-900 dark:text-white">{{ $cycle->name }}</div>
                            <div class="text-xs text-zinc-400 font-mono">{{ $cycle->slug }}</div>
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">
                            {{ $cycle->start_day }}{{ $cycle->start_day === 1 ? 'st' : 'th' }}
                            &ndash;
                            {{ $cycle->end_day === 0 ? 'Last' : $cycle->end_day.'th' }}
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $cycle->pay_day }}{{ $cycle->pay_day === 1 ? 'st' : ($cycle->pay_day === 2 ? 'nd' : ($cycle->pay_day === 3 ? 'rd' : 'th')) }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($cycle->is_default)
                                <flux:badge color="emerald" size="sm">Default</flux:badge>
                            @else
                                <span class="text-zinc-300 dark:text-zinc-700">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($cycle->trashed())
                                <flux:badge color="red" size="sm">Deleted</flux:badge>
                            @elseif($cycle->is_active)
                                <flux:badge color="green" size="sm">Active</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-zinc-600 dark:text-zinc-300">
                            {{ $cycle->employees()->count() }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                @if($cycle->trashed())
                                    <flux:button wire:click="restore({{ $cycle->id }})" size="sm" variant="ghost">Restore</flux:button>
                                @else
                                    <flux:button wire:click="openEdit({{ $cycle->id }})" size="sm" variant="ghost" icon="pencil" />
                                    <flux:button
                                        wire:click="delete({{ $cycle->id }})"
                                        wire:confirm="Delete '{{ $cycle->name }}'? Employees will lose their cycle assignment."
                                        size="sm" variant="ghost" icon="trash" class="text-red-500"
                                    />
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-zinc-400">No salary cycles defined.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Add / Edit Modal --}}
    <flux:modal wire:model="showModal" class="md:max-w-lg">
        <div class="space-y-5 p-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Salary Cycle' : 'Add Salary Cycle' }}</flux:heading>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model.live="name" label="Name" placeholder="e.g. Cycle A" required />
                <flux:input wire:model="slug" label="Slug" placeholder="cycle-a" class="font-mono" required />
            </div>

            <div class="grid grid-cols-3 gap-4">
                <flux:input wire:model="start_day" type="number" min="1" max="31" label="Start Day" required />
                <flux:field>
                    <flux:label>End Day</flux:label>
                    <flux:input wire:model="end_day" type="number" min="0" max="31" />
                    <flux:description>0 = last day of month</flux:description>
                    <flux:error name="end_day" />
                </flux:field>
                <flux:input wire:model="pay_day" type="number" min="1" max="31" label="Pay Day" required />
            </div>

            <div class="flex items-center gap-6">
                <flux:checkbox wire:model="is_default" label="Set as Default" />
                <flux:checkbox wire:model="is_active" label="Active" />
            </div>

            <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button wire:click="closeModal" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="save" variant="primary">{{ $editingId ? 'Update' : 'Create' }}</flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
