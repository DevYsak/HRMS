<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">

    <div class="pulse-page-header">
        <div>
            <flux:breadcrumbs class="mb-2">
                <flux:breadcrumbs.item href="{{ route('settings.onboarding-templates') }}">Onboarding Templates</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $template->name }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <h1 class="pulse-page-title">{{ $template->name }}</h1>
            <p class="pulse-page-subtitle">
                Manage tasks for this template.
                @if($template->is_default)
                    <flux:badge color="blue" size="sm" class="ml-1">Default</flux:badge>
                @endif
            </p>
        </div>
        <flux:button wire:click="openCreate" variant="primary" icon="plus">Add Task</flux:button>
    </div>

    @php
        $onboardingTasks = $tasks->where('phase', 'onboarding');
        $offboardingTasks = $tasks->where('phase', 'offboarding');
    @endphp

    @foreach([['label' => 'Onboarding Tasks', 'phase' => 'onboarding', 'items' => $onboardingTasks], ['label' => 'Offboarding Tasks', 'phase' => 'offboarding', 'items' => $offboardingTasks]] as $group)
        @if($group['items']->isNotEmpty())
            <div class="pulse-card overflow-hidden p-0">
                <div class="border-b border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $group['label'] }}</span>
                    <span class="ml-2 text-xs text-zinc-400">{{ $group['items']->count() }} task(s)</span>
                </div>
                <table class="w-full text-sm">
                    <thead class="border-b border-zinc-100 dark:border-zinc-800">
                        <tr>
                            <th class="w-10 px-4 py-2 text-center text-xs font-semibold text-zinc-500">#</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-zinc-500">Task</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-zinc-500">Category</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-zinc-500">Owner</th>
                            <th class="px-4 py-2 text-center text-xs font-semibold text-zinc-500">Due (days)</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-zinc-500">Auto-trigger</th>
                            <th class="px-4 py-2 text-right text-xs font-semibold text-zinc-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach($group['items'] as $task)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40" wire:key="task-{{ $task->id }}">
                                <td class="px-4 py-2 text-center text-zinc-400">{{ $task->sort_order }}</td>
                                <td class="px-4 py-2">
                                    <div class="font-medium text-zinc-900 dark:text-white">{{ $task->title }}</div>
                                    @if($task->description)
                                        <div class="text-xs text-zinc-400">{{ Str::limit($task->description, 60) }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-zinc-600 dark:text-zinc-300">{{ $task->category }}</td>
                                <td class="px-4 py-2">
                                    <flux:badge color="zinc" size="sm">{{ $task->owner_role ?? '—' }}</flux:badge>
                                </td>
                                <td class="px-4 py-2 text-center text-zinc-600 dark:text-zinc-300">{{ $task->due_days }}d</td>
                                <td class="px-4 py-2">
                                    @if($task->auto_trigger)
                                        <flux:badge color="blue" size="sm">{{ $task->auto_trigger }}</flux:badge>
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <flux:button wire:click="moveUp({{ $task->id }})" size="sm" variant="ghost" icon="chevron-up" />
                                        <flux:button wire:click="moveDown({{ $task->id }})" size="sm" variant="ghost" icon="chevron-down" />
                                        <flux:button wire:click="openEdit({{ $task->id }})" size="sm" variant="ghost" icon="pencil" />
                                        <flux:button
                                            wire:click="delete({{ $task->id }})"
                                            wire:confirm="Remove '{{ $task->title }}'?"
                                            size="sm" variant="ghost" icon="trash" class="text-red-500"
                                        />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endforeach

    @if($tasks->isEmpty())
        <div class="pulse-card flex flex-col items-center justify-center py-16 text-center">
            <flux:icon.clipboard-document-list class="size-12 text-zinc-300 dark:text-zinc-600 mb-3" />
            <p class="text-zinc-500">No tasks in this template yet.</p>
            <flux:button wire:click="openCreate" variant="primary" class="mt-4" icon="plus">Add First Task</flux:button>
        </div>
    @endif

    {{-- Add / Edit Modal --}}
    <flux:modal wire:model="showModal" class="md:max-w-lg">
        <div class="space-y-4 p-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Task' : 'Add Task' }}</flux:heading>

            <flux:field>
                <flux:label>Title <flux:badge as="span" color="red" size="sm">Required</flux:badge></flux:label>
                <flux:input wire:model="title" placeholder="e.g. Complete personal profile" />
                <flux:error name="title" />
            </flux:field>

            <flux:field>
                <flux:label>Instructions</flux:label>
                <flux:textarea wire:model="description" rows="2" placeholder="Optional guidance for the task owner..." />
                <flux:error name="description" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Phase</flux:label>
                    <flux:select wire:model="phase">
                        <option value="onboarding">Onboarding</option>
                        <option value="offboarding">Offboarding</option>
                    </flux:select>
                </flux:field>
                <flux:field>
                    <flux:label>Category</flux:label>
                    <flux:select wire:model="category">
                        <option value="hr">HR</option>
                        <option value="it_setup">IT Setup</option>
                        <option value="finance">Finance</option>
                        <option value="general">General</option>
                        <option value="documentation">Documentation</option>
                    </flux:select>
                </flux:field>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Owner Role</flux:label>
                    <flux:select wire:model="owner_role">
                        <option value="hr">HR</option>
                        <option value="it">IT</option>
                        <option value="finance">Finance</option>
                        <option value="manager">Manager</option>
                        <option value="employee">Employee</option>
                    </flux:select>
                </flux:field>
                <flux:field>
                    <flux:label>Due (days from start)</flux:label>
                    <flux:input wire:model="due_days" type="number" min="0" max="365" />
                    <flux:error name="due_days" />
                </flux:field>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Auto-Trigger</flux:label>
                    <flux:select wire:model="auto_trigger">
                        <option value="">None (manual)</option>
                        <option value="account_create">Account Created</option>
                        <option value="kyc_upload">KYC Document Uploaded</option>
                        <option value="biometric_sync">Biometric Enrolled</option>
                        <option value="asset_assign">Asset Assigned</option>
                        <option value="asset_return">Asset Returned</option>
                    </flux:select>
                    <flux:error name="auto_trigger" />
                </flux:field>
                <flux:field>
                    <flux:label>Sort Order</flux:label>
                    <flux:input wire:model="sort_order" type="number" min="0" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button wire:click="closeModal" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="save" variant="primary">{{ $editingId ? 'Update' : 'Add Task' }}</flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
