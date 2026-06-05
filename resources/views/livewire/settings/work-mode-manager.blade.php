<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">

    <div class="pulse-page-header">
        <div>
            <flux:breadcrumbs class="mb-2">
                <flux:breadcrumbs.item>Settings</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Work Modes</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <h1 class="pulse-page-title">Work Modes</h1>
            <p class="pulse-page-subtitle">Define where employees work — office, remote, hybrid, field, or custom.</p>
        </div>
        <flux:button wire:click="openCreate" variant="primary" icon="plus">Add Mode</flux:button>
    </div>

    <div class="pulse-card overflow-hidden p-0">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-100 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-400">Mode</th>
                    <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-400">Attendance Tracking</th>
                    <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-400">Status</th>
                    <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-400">Employees</th>
                    <th class="px-4 py-3 text-right font-semibold text-zinc-600 dark:text-zinc-400">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse($modes as $mode)
                    <tr class="@if($mode->trashed()) opacity-50 @endif hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="size-3 rounded-full shrink-0" style="background-color: {{ $mode->color }}"></div>
                                <div>
                                    <div class="font-medium text-zinc-900 dark:text-white">{{ $mode->name }}</div>
                                    <div class="text-xs text-zinc-400 font-mono">{{ $mode->slug }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <flux:icon.check-circle class="mx-auto size-5 @if($mode->requires_attendance_tracking) text-emerald-500 @else text-zinc-300 dark:text-zinc-700 @endif" />
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($mode->trashed())
                                <flux:badge color="red" size="sm">Deleted</flux:badge>
                            @elseif($mode->is_active)
                                <flux:badge color="green" size="sm">Active</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-zinc-600 dark:text-zinc-300">
                            {{ $mode->employees_count ?? $mode->employees()->count() }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                @if($mode->trashed())
                                    <flux:button wire:click="restore({{ $mode->id }})" size="sm" variant="ghost">Restore</flux:button>
                                @else
                                    <flux:button wire:click="openEdit({{ $mode->id }})" size="sm" variant="ghost" icon="pencil" />
                                    <flux:button
                                        wire:click="delete({{ $mode->id }})"
                                        wire:confirm="Delete '{{ $mode->name }}'?"
                                        size="sm" variant="ghost" icon="trash" class="text-red-500"
                                    />
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-zinc-400">No work modes defined.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Add / Edit Modal --}}
    <flux:modal wire:model="showModal" class="md:max-w-lg">
        <div class="space-y-5 p-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Work Mode' : 'Add Work Mode' }}</flux:heading>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model.live="name" label="Name" placeholder="e.g. Hybrid" required />
                <flux:input wire:model="slug" label="Slug" placeholder="hybrid" class="font-mono" required />
            </div>

            <flux:field>
                <flux:label>Badge Colour</flux:label>
                <div class="flex items-center gap-3 mt-1">
                    <input type="color" wire:model="color" class="size-9 cursor-pointer rounded border border-zinc-200 p-0.5 dark:border-zinc-700" />
                    <flux:input wire:model="color" class="font-mono" placeholder="#1DB77A" />
                </div>
                <flux:error name="color" />
            </flux:field>

            <div class="flex items-center gap-6">
                <flux:checkbox wire:model="requires_attendance_tracking" label="Requires Attendance Tracking" />
                <flux:checkbox wire:model="is_active" label="Active" />
            </div>

            <flux:input wire:model="sort_order" type="number" min="0" label="Sort Order" />

            <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button wire:click="closeModal" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="save" variant="primary">{{ $editingId ? 'Update' : 'Create' }}</flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
