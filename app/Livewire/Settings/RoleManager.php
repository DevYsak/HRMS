<?php

namespace App\Livewire\Settings;

use App\Enums\DataScope;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditService;
use App\Services\Security\PermissionAdministration;
use App\Services\Security\PermissionScopes;
use App\Services\Security\RoleDelegationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RoleManager extends Component
{
    // ── Edit Role modal state ─────────────────────────────────────────────────
    public bool $showModal = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    /** @var array<int, int> Selected permission IDs */
    public array $selectedPermissions = [];

    public string $permissionSearch = '';

    /**
     * Data scope per granted permission: permissionId => ['scope' => '' (inherit
     * the role default) | DataScope value, 'departments' => [ids]].
     *
     * @var array<int|string, array{scope?: string, departments?: array<int, int|string>}>
     */
    public array $scopes = [];

    /** Roles & Permissions tab: roles | overrides. */
    public string $tab = 'roles';

    // ── View Users modal state ────────────────────────────────────────────────
    public bool $showUsersModal = false;

    #[Locked]
    public ?int $viewingRoleId = null;

    // ── Delete confirmation state ─────────────────────────────────────────────
    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingId = null;

    public function mount(): void
    {
        $this->authorizeRoleManagement();
    }

    /**
     * Role management is a privileged capability (manage_roles). Every action
     * re-checks it server-side; the delegation ceiling is applied per role.
     */
    protected function authorizeRoleManagement(): void
    {
        $this->authorize('manage-settings');
        abort_unless(Auth::user()->hasPermission('manage_roles'), 403);
    }

    protected function guard(): RoleDelegationGuard
    {
        return app(RoleDelegationGuard::class);
    }

    // ── Edit Role modal helpers ───────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $this->authorizeRoleManagement();
        $role = Role::with('permissions')->findOrFail($id);

        if ($refusal = $this->guard()->refusalToManageRole(Auth::user(), $role)) {
            \Flux::toast($refusal, variant: 'danger');

            return;
        }

        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->description = (string) $role->description;
        $this->selectedPermissions = $role->permissions->pluck('id')->all();
        $this->scopes = $role->permissions
            ->filter(fn (Permission $p) => $p->pivot->scope !== null)
            ->mapWithKeys(fn (Permission $p) => [$p->id => [
                'scope' => $p->pivot->scope,
                'departments' => array_map('strval', json_decode((string) $p->pivot->department_ids, true) ?: []),
            ]])
            ->all();
        $this->permissionSearch = '';
        $this->resetErrorBag();
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
        $this->authorizeRoleManagement();

        $this->selectedPermissions = array_values(array_unique(array_map('intval', $this->selectedPermissions)));
        $existing = $this->editingId ? Role::with('permissions')->findOrFail($this->editingId) : null;

        if ($refusal = $this->guard()->refusalToEditRole(Auth::user(), $existing, $this->selectedPermissions)) {
            $this->addError('selectedPermissions', $refusal);
            \Flux::toast($refusal, variant: 'danger');

            return;
        }

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($this->editingId)],
            'description' => ['nullable', 'string', 'max:255'],
            'scopes.*.scope' => ['nullable', Rule::in(DataScope::values())],
            'scopes.*.departments' => ['array'],
            'scopes.*.departments.*' => ['integer', Rule::exists('departments', 'id')],
        ], [], ['scopes.*.scope' => 'data scope', 'scopes.*.departments.*' => 'department']);
        unset($data['scopes']);

        $pivot = $this->scopePivot();

        if ($pivot === null) {
            return;
        }

        $data['slug'] = $this->editingId
            ? Role::findOrFail($this->editingId)->slug
            : $this->uniqueSlug(Str::slug($data['name']));

        $oldPermissionKeys = $existing?->permissions->pluck('key')->sort()->values()->all() ?? [];
        $oldScopes = $existing ? $this->scopeSnapshot($existing) : [];
        $oldDetails = $existing?->only(['name', 'description']);

        if ($existing) {
            $role = $existing;
            $role->update($data);
        } else {
            $role = Role::create([...$data, 'is_system' => false, 'is_active' => true]);
        }

        $role->permissions()->sync($pivot);
        $role->flushPermissionCache();

        $newScopes = $this->scopeSnapshot($role->fresh());
        $newPermissionKeys = $role->permissions()->pluck('key')->sort()->values()->all();
        $audit = app(AuditService::class);
        $audit->event($existing ? 'ROLE_UPDATED' : 'ROLE_CREATED', AuditService::ROLES, $role,
            old: $oldDetails, new: $role->only(['name', 'description']), module: AuditService::SETTINGS);

        if ($oldPermissionKeys !== $newPermissionKeys) {
            $audit->event('ROLE_PERMISSIONS_CHANGED', AuditService::PERMISSIONS, $role,
                old: ['permissions' => $oldPermissionKeys],
                new: [
                    'permissions' => $newPermissionKeys,
                    'granted' => array_values(array_diff($newPermissionKeys, $oldPermissionKeys)),
                    'revoked' => array_values(array_diff($oldPermissionKeys, $newPermissionKeys)),
                ],
                module: AuditService::SETTINGS);
        }

        if ($oldScopes !== $newScopes) {
            $audit->event('ROLE_PERMISSION_SCOPES_CHANGED', AuditService::PERMISSIONS, $role,
                old: ['scopes' => $oldScopes], new: ['scopes' => $newScopes], module: AuditService::SETTINGS);
        }

        \Flux::toast($this->editingId ? 'Role updated.' : 'Role created.', variant: 'success');

        $this->closeModal();
    }

    public function cloneRole(int $id): void
    {
        $this->authorizeRoleManagement();

        $source = Role::with('permissions')->findOrFail($id);

        // Cloning must not copy permissions the actor could not grant directly.
        if ($refusal = $this->guard()->refusalToEditRole(Auth::user(), null, $source->permissions->pluck('id')->all())) {
            \Flux::toast($refusal, variant: 'danger');

            return;
        }
        $name = $this->uniqueName($source->name.' (Copy)');

        $clone = Role::create([
            'name' => $name,
            'slug' => $this->uniqueSlug(Str::slug($name)),
            'description' => $source->description,
            'is_system' => false,
            'is_active' => true,
        ]);

        // A clone keeps each grant's data scope.
        $clone->permissions()->sync($source->permissions->mapWithKeys(fn (Permission $p) => [$p->id => [
            'scope' => $p->pivot->scope,
            'department_ids' => $p->pivot->department_ids,
        ]])->all());
        $clone->flushPermissionCache();

        app(AuditService::class)->event('ROLE_CLONED', AuditService::ROLES, $clone,
            new: ['name' => $clone->name, 'cloned_from' => $source->name, 'permissions' => $source->permissions->pluck('key')->sort()->values()->all()],
            module: AuditService::SETTINGS);

        \Flux::toast("Role cloned as \"{$name}\".", variant: 'success');
    }

    public function toggleActive(int $id): void
    {
        $this->authorizeRoleManagement();

        $role = Role::findOrFail($id);

        if ($role->is_system) {
            \Flux::toast('System roles cannot be deactivated.', variant: 'danger');

            return;
        }

        if ($refusal = $this->guard()->refusalToManageRole(Auth::user(), $role)) {
            \Flux::toast($refusal, variant: 'danger');

            return;
        }

        $role->update(['is_active' => ! $role->is_active]);
        app(AuditService::class)->event($role->is_active ? 'ROLE_ACTIVATED' : 'ROLE_DEACTIVATED', AuditService::ROLES, $role,
            old: ['is_active' => ! $role->is_active], new: ['is_active' => $role->is_active], module: AuditService::SETTINGS);
        \Flux::toast($role->is_active ? 'Role activated.' : 'Role deactivated.', variant: 'success');
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->showDeleteModal = true;
    }

    public function deleteRole(): void
    {
        $this->authorizeRoleManagement();

        $role = Role::findOrFail($this->deletingId);

        if ($refusal = $this->guard()->refusalToManageRole(Auth::user(), $role)) {
            \Flux::toast($refusal, variant: 'danger');
            $this->closeDeleteModal();

            return;
        }

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

        app(AuditService::class)->event('ROLE_DELETED', AuditService::ROLES, $role,
            old: ['name' => $role->name, 'permissions' => $role->permissionKeys()], module: AuditService::SETTINGS);

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

        $editingRole = $this->editingId ? Role::find($this->editingId) : null;
        $selectedKeys = $permissions->whereIn('id', $this->selectedPermissions)->pluck('key')->all();
        $inheritedScopes = $permissions->where('is_scoped', true)
            ->mapWithKeys(fn (Permission $p) => [$p->id => PermissionScopes::defaultFor($editingRole?->slug, $p->key, $selectedKeys)->label()])
            ->all();

        $viewingRole = $this->viewingRoleId
            ? Role::with('users')->find($this->viewingRoleId)
            : null;

        return view('livewire.settings.role-manager', [
            'roles' => $roles,
            'groupedPermissions' => $groupedPermissions,
            'totalPermissionsCount' => $permissions->count(),
            'viewingRole' => $viewingRole,
            'inheritedScopes' => $inheritedScopes,
            'scopeOptions' => collect(DataScope::cases())->reject(fn (DataScope $s) => $s === DataScope::None),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app', ['title' => 'Roles & Permissions']);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'description', 'selectedPermissions', 'permissionSearch', 'scopes']);
        $this->resetErrorBag();
    }

    /**
     * The sync payload — permission id => pivot (scope, department_ids) — for
     * the selected permissions, or null after recording a field error. A
     * scope is kept only on a permission that reaches employee data; a
     * department list only with "Selected departments".
     *
     * @return array<int, array{scope: ?string, department_ids: ?string}>|null
     */
    private function scopePivot(): ?array
    {
        $admin = app(PermissionAdministration::class);
        $permissions = Permission::whereIn('id', $this->selectedPermissions)->get()->keyBy('id');
        $pivot = [];
        $failed = false;

        foreach ($this->selectedPermissions as $id) {
            $permission = $permissions->get($id);
            $chosen = (string) ($this->scopes[$id]['scope'] ?? '');
            $scope = $chosen !== '' && $permission?->is_scoped ? DataScope::from($chosen) : null;
            $departments = $scope === DataScope::SelectedDepartments
                ? array_values(array_unique(array_map('intval', $this->scopes[$id]['departments'] ?? [])))
                : [];

            if ($permission && ($refusal = $admin->refusalForScope(Auth::user(), $permission, $scope, $departments))) {
                $this->addError($scope === DataScope::SelectedDepartments && $departments === [] ? "scopes.{$id}.departments" : "scopes.{$id}.scope", $refusal);
                $failed = true;

                continue;
            }

            $pivot[$id] = [
                'scope' => $scope?->value,
                'department_ids' => $departments === [] ? null : json_encode($departments),
            ];
        }

        if ($failed) {
            \Flux::toast('Some data scopes need attention — see the highlighted permissions.', variant: 'danger');

            return null;
        }

        return $pivot;
    }

    /** @return array<string, array{scope: ?string, department_ids: array<int, int>}> */
    private function scopeSnapshot(Role $role): array
    {
        return $role->permissions()->get()
            ->filter(fn (Permission $p) => $p->pivot->scope !== null)
            ->mapWithKeys(fn (Permission $p) => [$p->key => [
                'scope' => $p->pivot->scope,
                'department_ids' => array_map('intval', json_decode((string) $p->pivot->department_ids, true) ?: []),
            ]])
            ->sortKeys()
            ->all();
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
