<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">

    <div class="pulse-page-header">
        <div>
            <flux:breadcrumbs class="mb-2">
                <flux:breadcrumbs.item>Settings</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Roles & Permissions</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <h1 class="pulse-page-title">Roles & Permissions</h1>
            <p class="pulse-page-subtitle">What each role can do, and whose records it reaches — set per permission, with per-person exceptions.</p>
        </div>
        @if($tab === 'roles')
            <flux:button wire:click="openCreate" variant="primary" icon="plus">Create Role</flux:button>
        @endif
    </div>

    <flux:radio.group wire:model.live="tab" variant="segmented" size="sm" class="w-fit" aria-label="Roles or user overrides">
        <flux:radio value="roles" icon="shield-check" label="Roles" />
        <flux:radio value="overrides" icon="user" label="User overrides" />
    </flux:radio.group>

    @if($tab === 'overrides')
        <livewire:settings.user-permission-overrides />
    @else
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @forelse($roles as $role)
            <div class="pulse-card flex flex-col gap-3 p-5">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="font-semibold text-zinc-900 dark:text-white">{{ $role->name }}</h3>
                            @if($role->is_system)
                                <flux:badge color="blue" size="sm">System Role</flux:badge>
                            @endif
                            @unless($role->is_active)
                                <flux:badge color="zinc" size="sm">Inactive</flux:badge>
                            @endunless
                        </div>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $role->description ?: 'No description provided.' }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-4 text-sm text-zinc-500 dark:text-zinc-400">
                    <span class="flex items-center gap-1">
                        <flux:icon.shield-check class="size-4" />
                        {{ $role->permissions_count }} permissions
                    </span>
                    <button type="button" wire:click="viewUsers({{ $role->id }})" class="flex items-center gap-1 hover:text-zinc-700 dark:hover:text-zinc-200">
                        <flux:icon.users class="size-4" />
                        {{ $role->users_count }} users
                    </button>
                    @if(($overrideCounts[$role->id] ?? 0) > 0)
                        <button type="button" wire:click="$set('tab', 'overrides')" class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30" data-override-badge title="Users on this role with personal permission overrides">
                            Custom Permissions · {{ $overrideCounts[$role->id] }}
                        </button>
                    @endif
                </div>

                <p class="text-xs text-zinc-400">Created {{ $role->created_at->format('M j, Y') }}</p>

                <div class="mt-auto flex items-center gap-1 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <flux:button wire:click="openEdit({{ $role->id }})" size="sm" variant="ghost" icon="pencil">Edit</flux:button>
                    <flux:button wire:click="cloneRole({{ $role->id }})" size="sm" variant="ghost" icon="document-duplicate">Clone</flux:button>
                    @unless($role->is_system)
                        <flux:button wire:click="toggleActive({{ $role->id }})" size="sm" variant="ghost" icon="{{ $role->is_active ? 'eye-slash' : 'eye' }}">
                            {{ $role->is_active ? 'Deactivate' : 'Activate' }}
                        </flux:button>
                        <flux:button wire:click="confirmDelete({{ $role->id }})" size="sm" variant="ghost" icon="trash" class="text-red-500" />
                    @endunless
                </div>
            </div>
        @empty
            <div class="pulse-card col-span-full p-10 text-center text-zinc-400">No roles defined yet.</div>
        @endforelse
    </div>
    @endif

    {{-- Edit / Create Role modal --}}
    <flux:modal wire:model="showModal" class="md:max-w-3xl">
        <div class="flex max-h-[85vh] flex-col">
            <div class="space-y-5 overflow-y-auto p-6">
                <flux:heading size="lg">{{ $editingId ? 'Edit Role' : 'Create Role' }}</flux:heading>

                <flux:input wire:model="name" label="Role Name" placeholder="e.g. Team Lead" required />
                <flux:textarea wire:model="description" label="Description" placeholder="What does this role do?" rows="2" />

                {{-- Role summary: what the ticks below add up to, before Save. --}}
                @if($roleSummary)
                    <section class="rounded-xl border border-orange-200 bg-orange-50/60 p-4 text-sm dark:border-orange-500/30 dark:bg-orange-500/10" data-role-summary>
                        <p class="text-[11px] font-bold uppercase tracking-wide text-orange-700 dark:text-orange-300">Role summary</p>
                        <p class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ $roleSummary['name'] }}</p>
                        <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
                            <div><dt class="text-xs text-zinc-500">Dashboard</dt><dd class="text-zinc-800 dark:text-zinc-100">{{ $roleSummary['dashboard'] }}</dd></div>
                            <div><dt class="text-xs text-zinc-500">Default scope</dt><dd class="text-zinc-800 dark:text-zinc-100">{{ $roleSummary['default_scope'] }}</dd></div>
                            <div><dt class="text-xs text-zinc-500">Can approve</dt><dd class="text-zinc-800 dark:text-zinc-100">{{ $roleSummary['approve'] === [] ? 'Nothing' : implode(', ', $roleSummary['approve']) }}</dd></div>
                            <div><dt class="text-xs text-zinc-500">Can view</dt><dd class="text-zinc-800 dark:text-zinc-100">{{ $roleSummary['view'] === [] ? 'Own records only' : implode(', ', $roleSummary['view']) }}</dd></div>
                            <div class="sm:col-span-2"><dt class="text-xs text-zinc-500">Cannot access</dt><dd class="text-zinc-800 dark:text-zinc-100">{{ $roleSummary['cannot'] === [] ? '—' : implode(', ', $roleSummary['cannot']) }}</dd></div>
                            @if($roleSummary['privileged'] !== [])
                                <div class="sm:col-span-2"><dt class="text-xs text-rose-600">Privileged permissions</dt><dd class="text-rose-700 dark:text-rose-300">{{ implode(', ', $roleSummary['privileged']) }}</dd></div>
                            @endif
                        </dl>
                    </section>
                @endif

                {{-- Presets: a convenience that only fills the editor, after a preview. --}}
                <div class="space-y-2">
                    <p class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">Start from a preset</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($presets as $slug => $label)
                            <button type="button" wire:click="previewPreset('{{ $slug }}')" class="rounded-full border border-zinc-200 px-2.5 py-1 text-xs font-semibold text-zinc-600 hover:border-orange-300 hover:text-orange-700 dark:border-zinc-700 dark:text-zinc-300">{{ $label }}</button>
                        @endforeach
                    </div>
                    @if($presetPreview)
                        <div class="rounded-xl border border-zinc-200 bg-white p-3 text-xs dark:border-zinc-700 dark:bg-zinc-900" data-preset-preview>
                            <p class="font-semibold text-zinc-900 dark:text-white">Preview: {{ $presetPreview['label'] }} preset</p>
                            <p class="mt-1 text-emerald-700 dark:text-emerald-300">Adds ({{ count($presetPreview['add']) }}): {{ implode(', ', $presetPreview['add']) ?: '—' }}</p>
                            <p class="mt-1 text-rose-700 dark:text-rose-300">Removes ({{ count($presetPreview['remove']) }}): {{ implode(', ', $presetPreview['remove']) ?: '—' }}</p>
                            @if($presetPreview['blocked'] !== [])
                                <p class="mt-1 text-amber-700 dark:text-amber-300">Beyond your own access — left out: {{ implode(', ', $presetPreview['blocked']) }}</p>
                            @endif
                            <p class="mt-1 text-zinc-500">Data scopes go back to the role default. User overrides are not changed.</p>
                            <div class="mt-2 flex gap-2">
                                <flux:button size="sm" variant="primary" wire:click="applyPreset">Load into editor</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="cancelPreset">Cancel</flux:button>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <flux:heading size="sm">Permissions by section</flux:heading>
                        <flux:badge color="blue">{{ count($selectedPermissions) }} / {{ $totalPermissionsCount }} selected</flux:badge>
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:input wire:model.live.debounce.300ms="permissionSearch" placeholder="Search permissions…" icon="magnifying-glass" class="flex-1" />
                        <flux:button wire:click="selectAllPermissions" size="sm" variant="ghost">Select All</flux:button>
                        <flux:button wire:click="deselectAllPermissions" size="sm" variant="ghost">Deselect All</flux:button>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach($groupedPermissions as $module => $permissions)
                            @php
                                $moduleIds = $permissions->pluck('id')->all();
                                $selectedInModule = count(array_intersect($moduleIds, $selectedPermissions));
                            @endphp
                            <div class="rounded-lg border border-zinc-200 dark:border-zinc-800">
                                <div class="flex items-center justify-between gap-2 border-b border-zinc-100 bg-zinc-50 px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900">
                                    <label class="flex items-center gap-2 text-sm font-medium text-zinc-700 dark:text-zinc-200">
                                        <flux:checkbox
                                            :checked="$selectedInModule === count($moduleIds) && count($moduleIds) > 0"
                                            wire:click="toggleModule('{{ $module }}')"
                                        />
                                        {{ $module }}
                                    </label>
                                    <span class="text-xs text-zinc-400">{{ $selectedInModule }} / {{ count($moduleIds) }}</span>
                                </div>
                                <div class="space-y-1.5 p-3">
                                    @foreach($permissions as $permission)
                                        @php $isOn = in_array($permission->id, $selectedPermissions, true); @endphp
                                        <div wire:key="perm-{{ $permission->id }}" class="space-y-1.5">
                                            <flux:checkbox
                                                :checked="$isOn"
                                                wire:click="togglePermission({{ $permission->id }})"
                                                label="{{ $permission->label }}"
                                                description="{{ $permission->key }}"
                                            />
                                            {{-- Data scope: only for a granted permission that reaches employee data. --}}
                                            @if($isOn && $permission->is_scoped)
                                                <div class="ms-7 space-y-1.5">
                                                    <flux:select size="sm" wire:model.live="scopes.{{ $permission->id }}.scope" aria-label="Data scope for {{ $permission->label }}">
                                                        <flux:select.option value="">Inherit — {{ $inheritedScopes[$permission->id] ?? 'role default' }}</flux:select.option>
                                                        @foreach($scopeOptions as $option)
                                                            <flux:select.option value="{{ $option->value }}">{{ $option->label() }}</flux:select.option>
                                                        @endforeach
                                                    </flux:select>
                                                    <flux:error name="scopes.{{ $permission->id }}.scope" />
                                                    @if(($scopes[$permission->id]['scope'] ?? '') === \App\Enums\DataScope::SelectedDepartments->value)
                                                        <div class="grid max-h-32 grid-cols-1 gap-1 overflow-y-auto rounded-lg border border-zinc-100 p-2 dark:border-zinc-800">
                                                            @foreach($departments as $department)
                                                                <flux:checkbox wire:model="scopes.{{ $permission->id }}.departments" value="{{ $department->id }}" label="{{ $department->name }}" />
                                                            @endforeach
                                                        </div>
                                                        <flux:error name="scopes.{{ $permission->id }}.departments" />
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3 border-t border-zinc-100 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <flux:button wire:click="closeModal" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="save" variant="primary">{{ $editingId ? 'Save Changes' : 'Create Role' }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- View Users modal --}}
    <flux:modal wire:model="showUsersModal" class="md:max-w-md">
        <div class="space-y-4 p-6">
            <flux:heading size="lg">{{ $viewingRole?->name }} — Assigned Users</flux:heading>

            <div class="max-h-96 space-y-2 overflow-y-auto">
                @forelse($viewingRole?->users ?? [] as $user)
                    <div class="flex items-center gap-3 rounded-lg border border-zinc-100 px-3 py-2 dark:border-zinc-800">
                        <flux:avatar size="sm" src="{{ $user->avatarUrl() }}" />
                        <div>
                            <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ $user->name }}</div>
                            <div class="text-xs text-zinc-400">{{ $user->email }}</div>
                        </div>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-zinc-400">No users are assigned to this role.</p>
                @endforelse
            </div>

            <div class="flex justify-end border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button wire:click="closeUsersModal" variant="ghost">Close</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Delete confirmation modal --}}
    <flux:modal wire:model="showDeleteModal" class="md:max-w-sm">
        <div class="space-y-4 p-6">
            <flux:heading size="lg">Delete Role</flux:heading>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Are you sure you want to delete this role? This cannot be undone, and is blocked while users are assigned to it.</p>
            <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button wire:click="closeDeleteModal" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="deleteRole" variant="danger">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
