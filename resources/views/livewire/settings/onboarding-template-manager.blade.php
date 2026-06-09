<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">

    <div class="pulse-page-header">
        <div>
            <flux:breadcrumbs class="mb-2">
                <flux:breadcrumbs.item>Settings</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Onboarding Templates</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <h1 class="pulse-page-title">Onboarding Templates</h1>
            <p class="pulse-page-subtitle">Configure task templates for new hire onboarding. Scope templates by department, job title, or employment type.</p>
        </div>
        <flux:button wire:click="openCreate" variant="primary" icon="plus">Add Template</flux:button>
    </div>

    <div class="pulse-card overflow-hidden p-0">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-100 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-400">Template</th>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-400">Scope</th>
                    <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-400">Tasks</th>
                    <th class="px-4 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-400">Status</th>
                    <th class="px-4 py-3 text-right font-semibold text-zinc-600 dark:text-zinc-400">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse($templates as $template)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $template->name }}</span>
                                @if($template->is_default)
                                    <flux:badge color="blue" size="sm">Default</flux:badge>
                                @endif
                            </div>
                            @if($template->description)
                                <div class="text-xs text-zinc-400 mt-0.5">{{ Str::limit($template->description, 60) }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">
                            @if($template->isScoped())
                                <div class="flex flex-wrap gap-1">
                                    @if($template->department)
                                        <flux:badge color="purple" size="sm">{{ $template->department->name }}</flux:badge>
                                    @endif
                                    @if($template->jobTitle)
                                        <flux:badge color="indigo" size="sm">{{ $template->jobTitle->name }}</flux:badge>
                                    @endif
                                    @if($template->employmentType)
                                        <flux:badge color="cyan" size="sm">{{ $template->employmentType->name }}</flux:badge>
                                    @endif
                                </div>
                            @else
                                <span class="text-zinc-400 text-xs">All employees</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="{{ route('settings.onboarding-template-tasks', $template) }}" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">
                                {{ $template->tasks_count }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($template->is_active)
                                <flux:badge color="green" size="sm">Active</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                @unless($template->is_default)
                                    <flux:button wire:click="setDefault({{ $template->id }})" size="sm" variant="ghost">Set Default</flux:button>
                                @endunless
                                <flux:button
                                    :href="route('settings.onboarding-template-tasks', $template)"
                                    size="sm" variant="ghost" icon="list-bullet"
                                    tooltip="Manage Tasks"
                                />
                                <flux:button wire:click="openEdit({{ $template->id }})" size="sm" variant="ghost" icon="pencil" />
                                @unless($template->is_default)
                                    <flux:button
                                        wire:click="delete({{ $template->id }})"
                                        wire:confirm="Delete '{{ $template->name }}'? This cannot be undone."
                                        size="sm" variant="ghost" icon="trash" class="text-red-500"
                                    />
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-zinc-400">No onboarding templates defined. Create one to get started.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Add / Edit Modal --}}
    <flux:modal wire:model="showModal" class="md:max-w-xl">
        <div class="space-y-5 p-6">
            <flux:heading size="lg">{{ $editingId ? 'Edit Template' : 'Add Onboarding Template' }}</flux:heading>

            <flux:field>
                <flux:label>Template Name <flux:badge as="span" color="red" size="sm">Required</flux:badge></flux:label>
                <flux:input wire:model="name" placeholder="e.g. Engineering Onboarding" />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>Description</flux:label>
                <flux:textarea wire:model="description" rows="2" placeholder="Brief description of when this template applies..." />
                <flux:error name="description" />
            </flux:field>

            <div class="rounded-lg border border-zinc-100 p-4 dark:border-zinc-800 space-y-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Scope (optional — leave blank for all employees)</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <flux:field>
                        <flux:label>Department</flux:label>
                        <flux:select wire:model="department_id">
                            <option value="">Any</option>
                            @foreach($departments as $dept)
                                <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                    <flux:field>
                        <flux:label>Job Title</flux:label>
                        <flux:select wire:model="job_title_id">
                            <option value="">Any</option>
                            @foreach($jobTitles as $jt)
                                <option value="{{ $jt->id }}">{{ $jt->name }}</option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                    <flux:field>
                        <flux:label>Employment Type</flux:label>
                        <flux:select wire:model="employment_type_id">
                            <option value="">Any</option>
                            @foreach($employmentTypes as $et)
                                <option value="{{ $et->id }}">{{ $et->name }}</option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:checkbox wire:model="is_default" label="Set as default template" />
                <flux:checkbox wire:model="is_active" label="Active" />
            </div>

            <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button wire:click="closeModal" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="save" variant="primary">{{ $editingId ? 'Update' : 'Create' }}</flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
