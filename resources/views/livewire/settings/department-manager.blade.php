<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">

    <div class="pulse-page-header">
        <div>
            <flux:breadcrumbs class="mb-2">
                <flux:breadcrumbs.item>Settings</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Departments</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <h1 class="pulse-page-title">Departments</h1>
            <p class="pulse-page-subtitle">Manage departments and configure default OT tracking source per department.</p>
        </div>
        <div>
            <flux:button wire:click="openCreate" variant="primary" icon="plus">Add Department</flux:button>
        </div>
    </div>

    <div class="pulse-card overflow-hidden p-0">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-100 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-400">Name</th>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-400">Code</th>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-400">Employees</th>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-400">Default OT Source</th>
                    <th class="px-4 py-3 text-right font-semibold text-zinc-600 dark:text-zinc-400">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse($departments as $dept)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                        <td class="px-4 py-3">
                            <div class="font-medium text-zinc-900 dark:text-white">{{ $dept->name }}</div>
                            @if($dept->description)
                                <div class="text-xs text-zinc-400">{{ Str::limit($dept->description, 60) }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300 font-mono text-xs">{{ $dept->code ?: '—' }}</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $dept->employees()->count() }}</td>
                        <td class="px-4 py-3">
                            @php
                                $sourceColors = ['biometric' => 'zinc', 'manual' => 'blue', 'nexflow' => 'purple', 'hybrid' => 'amber'];
                                $sourceColor = $sourceColors[$dept->default_ot_source] ?? 'zinc';
                            @endphp
                            <flux:badge color="{{ $sourceColor }}" size="sm">{{ ucfirst($dept->default_ot_source) }}</flux:badge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button wire:click="openEdit({{ $dept->id }})" size="sm" variant="ghost" icon="pencil" />
                                <flux:button wire:click="delete({{ $dept->id }})" wire:confirm="Delete this department?" size="sm" variant="ghost" icon="trash" class="text-red-500 hover:text-red-600" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-zinc-400">No departments found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Create / Edit Modal --}}
    <flux:modal wire:model="showModal" class="w-full max-w-md">
        <div class="space-y-4 p-1">
            <flux:heading size="lg">{{ $editingId ? 'Edit Department' : 'New Department' }}</flux:heading>

            <flux:field>
                <flux:label>Name <flux:badge size="sm" color="red">Required</flux:badge></flux:label>
                <flux:input wire:model="name" placeholder="e.g. Engineering" />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>Code</flux:label>
                <flux:input wire:model="code" placeholder="e.g. ENG" />
                <flux:error name="code" />
            </flux:field>

            <flux:field>
                <flux:label>Description</flux:label>
                <flux:textarea wire:model="description" rows="2" placeholder="Optional description" />
                <flux:error name="description" />
            </flux:field>

            <flux:field>
                <flux:label>Default OT Tracking Source</flux:label>
                <flux:select wire:model="default_ot_source">
                    <flux:select.option value="biometric">Biometric (standard attendance)</flux:select.option>
                    <flux:select.option value="manual">Manual (HR-entered)</flux:select.option>
                    <flux:select.option value="nexflow">Nexflow (IT/Dev/QA teams)</flux:select.option>
                    <flux:select.option value="hybrid">Hybrid (both sources)</flux:select.option>
                </flux:select>
                <flux:error name="default_ot_source" />
            </flux:field>

            <div class="flex justify-end gap-2 pt-2">
                <flux:button wire:click="closeModal" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="save" variant="primary">{{ $editingId ? 'Update' : 'Create' }}</flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
