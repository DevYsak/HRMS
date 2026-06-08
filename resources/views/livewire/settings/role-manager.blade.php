<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">

    <div class="pulse-page-header">
        <div>
            <flux:breadcrumbs class="mb-2">
                <flux:breadcrumbs.item>Settings</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Roles & Permissions</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <h1 class="pulse-page-title">Roles & Permissions</h1>
            <p class="pulse-page-subtitle">Create roles and control exactly which modules each role can access.</p>
        </div>
        <flux:button wire:click="openCreate" variant="primary" icon="plus">Create Role</flux:button>
    </div>

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

    {{-- Edit / Create Role modal --}}
    <flux:modal wire:model="showModal" class="md:max-w-3xl">
        <div class="flex max-h-[85vh] flex-col">
            <div class="space-y-5 overflow-y-auto p-6">
                <flux:heading size="lg">{{ $editingId ? 'Edit Role' : 'Create Role' }}</flux:heading>

                <flux:input wire:model="name" label="Role Name" placeholder="e.g. Team Lead" required />
                <flux:textarea wire:model="description" label="Description" placeholder="What does this role do?" rows="2" />

                <div class="space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <flux:heading size="sm">Module Permissions</flux:heading>
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
                                        <flux:checkbox
                                            :checked="in_array($permission->id, $selectedPermissions, true)"
                                            wire:click="togglePermission({{ $permission->id }})"
                                            label="{{ $permission->label }}"
                                            description="{{ $permission->key }}"
                                        />
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
