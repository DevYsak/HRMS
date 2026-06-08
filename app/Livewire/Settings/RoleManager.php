<?php

namespace App\Livewire\Settings;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class RoleManager extends Component
{
    // ── Edit Role modal state ─────────────────────────────────────────────────
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    /** @var array<int, int> Selected permission IDs */
    public array $selectedPermissions = [];

    public string $permissionSearch = '';

    // ── View Users modal state ────────────────────────────────────────────────
    public bool $showUsersModal = false;

    public ?int $viewingRoleId = null;

    // ── Delete confirmation state ─────────────────────────────────────────────
    public bool $showDeleteModal = false;

    public ?int $deletingId = null;

    public function mount(): void
    {
        $this->authorize('manage-settings');
    }

    // ── Edit Role modal helpers ───────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $role = Role::with('permissions')->findOrFail($id);

        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->description = (string) $role->description;
        $this->selectedPermissions = $role->permissions->pluck('id')->all();
        $this->permissionSearch = '';
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    // ── Permission matrix helpers ─────────────────────────────────────────────

    public function togglePermission(int $permissionId): void
    {
        if (in_array($permissionId, $this->selectedPermissions, true)) {
            $this->selectedPermissions = array_values(array_diff($this->selectedPermissions, [$permissionId]));
        } else {
            $this->selectedPermissions[] = $permissionId;
        }
    }

    public function toggleModule(string $module): void
    {
        $moduleIds = Permission::query()->where('module', $module)->pluck('id')->all();

        $allSelected = empty(array_diff($moduleIds, $this->selectedPermissions));

        if ($allSelected) {
            $this->selectedPermissions = array_values(array_diff($this->selectedPermissions, $moduleIds));
        } else {
            $this->selectedPermissions = array_values(array_unique(array_merge($this->selectedPermissions, $moduleIds)));
        }
    }

    public function selectAllPermissions(): void
    {
        $this->selectedPermissions = Permission::query()->pluck('id')->all();
    }

    public function deselectAllPermissions(): void
    {
        $this->selectedPermissions = [];
    }

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public function save(): void
    {
        $this->authorize('manage-settings');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($this->editingId)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $data['slug'] = $this->editingId
            ? Role::findOrFail($this->editingId)->slug
            : $this->uniqueSlug(Str::slug($data['name']));

        if ($this->editingId) {
            $role = Role::findOrFail($this->editingId);
            $role->update($data);
        } else {
            $role = Role::create([...$data, 'is_system' => false, 'is_active' => true]);
        }

        $role->permissions()->sync($this->selectedPermissions);
        $role->flushPermissionCache();

        \Flux::toast($this->editingId ? 'Role updated.' : 'Role created.', variant: 'success');

        $this->closeModal();
    }

    public function cloneRole(int $id): void
    {
        $this->authorize('manage-settings');

        $source = Role::with('permissions')->findOrFail($id);
        $name = $this->uniqueName($source->name.' (Copy)');

        $clone = Role::create([
            'name' => $name,
            'slug' => $this->uniqueSlug(Str::slug($name)),
            'description' => $source->description,
            'is_system' => false,
            'is_active' => true,
        ]);

        $clone->permissions()->sync($source->permissions->pluck('id')->all());
        $clone->flushPermissionCache();

        \Flux::toast("Role cloned as \"{$name}\".", variant: 'success');
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('manage-settings');

        $role = Role::findOrFail($id);

        if ($role->is_system) {
            \Flux::toast('System roles cannot be deactivated.', variant: 'danger');

            return;
        }

        $role->update(['is_active' => ! $role->is_active]);
        \Flux::toast($role->is_active ? 'Role activated.' : 'Role deactivated.', variant: 'success');
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteRole(): void
    {
        $this->authorize('manage-settings');

        $role = Role::findOrFail($this->deletingId);

        if ($role->is_system) {
            \Flux::toast('System roles cannot be deleted.', variant: 'danger');
            $this->closeDeleteModal();

            return;
        }

        if ($role->users()->exists()) {
            \Flux::toast('Cannot delete — users are assigned to this role.', variant: 'danger');
            $this->closeDeleteModal();

            return;
        }

        $role->flushPermissionCache();
        $role->delete();

        \Flux::toast('Role deleted.', variant: 'warning');
        $this->closeDeleteModal();
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingId = null;
    }

    // ── View Users modal ──────────────────────────────────────────────────────

    public function viewUsers(int $id): void
    {
        $this->viewingRoleId = $id;
        $this->showUsersModal = true;
    }

    public function closeUsersModal(): void
    {
        $this->showUsersModal = false;
        $this->viewingRoleId = null;
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render()
    {
        $roles = Role::withCount(['permissions', 'users'])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        $permissions = Permission::query()->orderBy('module')->orderBy('label')->get();

        $groupedPermissions = $permissions
            ->when($this->permissionSearch !== '', fn ($collection) => $collection->filter(
                fn (Permission $permission) => str_contains(Str::lower($permission->label), Str::lower($this->permissionSearch))
                    || str_contains(Str::lower($permission->key), Str::lower($this->permissionSearch))
            ))
            ->groupBy('module');

        $viewingRole = $this->viewingRoleId
            ? Role::with('users')->find($this->viewingRoleId)
            : null;

        return view('livewire.settings.role-manager', [
            'roles' => $roles,
            'groupedPermissions' => $groupedPermissions,
            'totalPermissionsCount' => $permissions->count(),
            'viewingRole' => $viewingRole,
        ])->layout('layouts.app', ['title' => 'Roles & Permissions']);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'description', 'selectedPermissions', 'permissionSearch']);
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $suffix = 1;

        while (Role::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function uniqueName(string $base): string
    {
        $name = $base;
        $suffix = 2;

        while (Role::where('name', $name)->exists()) {
            $name = "{$base} ({$suffix})";
            $suffix++;
        }

        return $name;
    }
}
