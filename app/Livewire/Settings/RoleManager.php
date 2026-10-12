<?php

namespace App\Livewire\Settings;

use App\Enums\DataScope;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\UserPermissionOverride;
use App\Services\Audit\AuditService;
use App\Services\Security\PermissionAdministration;
use App\Services\Security\PermissionScopes;
use App\Services\Security\PermissionSections;
use App\Services\Security\RoleDelegationGuard;
use Illuminate\Support\Collection;
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

    /** A preset being previewed in the editor (role slug), before it is applied. */
    public string $previewingPreset = '';

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

    /** Tick / untick a whole section (Dashboard, People, …) — not saved until Save. */
    public function toggleModule(string $module): void
    {
        $moduleIds = Permission::query()->get()
            ->filter(fn (Permission $p) => PermissionSections::for($p) === $module)
            ->pluck('id')->all();

        $allSelected = empty(array_diff($moduleIds, $this->selectedPermissions));

        if ($allSelected) {
            $this->selectedPermissions = array_values(array_diff($this->selectedPermissions, $moduleIds));
        } else {
            $this->selectedPermissions = array_values(array_unique(array_merge($this->selectedPermissions, $moduleIds)));
        }
    }

    /** Show what a preset would add and remove — nothing changes yet. */
    public function previewPreset(string $slug): void
    {
        $this->previewingPreset = array_key_exists($slug, PermissionSections::PRESETS) ? $slug : '';
    }

    public function cancelPreset(): void
    {
        $this->previewingPreset = '';
    }

    /**
     * Fill the editor with the previewed preset (scopes back to "inherit").
     * Still only the editor: Save applies it through the delegation ceiling,
     * scope checks and the audit trail like any edit. User overrides are
     * never touched.
     */
    public function applyPreset(): void
    {
        $this->authorizeRoleManagement();

        if ($this->previewingPreset === '') {
            return;
        }

        $keys = PermissionSections::presetKeys($this->previewingPreset);
        $allowed = array_values(array_diff($keys, $this->guard()->beyondCeiling(Auth::user(), $keys)));

        $this->selectedPermissions = Permission::whereIn('key', $allowed)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->scopes = [];
        $this->previewingPreset = '';
        \Flux::toast('Preset loaded into the editor — review and Save to apply.');
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
            ->groupBy(fn (Permission $permission) => PermissionSections::for($permission))
            ->sortBy(fn ($group, string $section) => array_search($section, PermissionSections::ORDER, true));

        $editingRole = $this->editingId ? Role::find($this->editingId) : null;
        $selectedKeys = $permissions->whereIn('id', $this->selectedPermissions)->pluck('key')->all();
        $inheritedScopes = $permissions->where('is_scoped', true)
            ->mapWithKeys(fn (Permission $p) => [$p->id => PermissionScopes::defaultFor($editingRole?->slug, $p->key, $selectedKeys)->label()])
            ->all();

        $viewingRole = $this->viewingRoleId
            ? Role::with('users')->find($this->viewingRoleId)
            : null;

        // Users with personal overrides, per role (shown as a badge on the card).
        $overrideCounts = UserPermissionOverride::query()
            ->join('users', 'users.id', '=', 'user_permission_overrides.user_id')
            ->selectRaw('users.role_id, COUNT(DISTINCT user_permission_overrides.user_id) as total')
            ->groupBy('users.role_id')
            ->pluck('total', 'role_id');

        return view('livewire.settings.role-manager', [
            'roles' => $roles,
            'overrideCounts' => $overrideCounts,
            'roleSummary' => $this->showModal ? $this->roleSummary($permissions, $editingRole, $inheritedScopes) : null,
            'presets' => PermissionSections::PRESETS,
            'presetPreview' => $this->presetPreview($permissions),
            'groupedPermissions' => $groupedPermissions,
            'totalPermissionsCount' => $permissions->count(),
            'viewingRole' => $viewingRole,
            'inheritedScopes' => $inheritedScopes,
            'scopeOptions' => collect(DataScope::cases())->reject(fn (DataScope $s) => $s === DataScope::None),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app', ['title' => 'Roles & Permissions']);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * What this role will be able to do, in words, from the editor's current
     * ticks and scopes — read before saving 50+ checkboxes.
     *
     * @param  Collection<int, Permission>  $permissions
     * @param  array<int, string>  $inheritedScopes
     * @return array<string, mixed>
     */
    private function roleSummary($permissions, ?Role $role, array $inheritedScopes): array
    {
        $selected = $permissions->whereIn('id', $this->selectedPermissions)->keyBy('key');
        $has = fn (string $key) => $selected->has($key);
        $scopeOf = function (string $key) use ($selected, $inheritedScopes): ?string {
            $permission = $selected->get($key);
            if (! $permission) {
                return null;
            }
            $chosen = (string) ($this->scopes[$permission->id]['scope'] ?? '');

            return $chosen !== '' ? DataScope::from($chosen)->label() : ($inheritedScopes[$permission->id] ?? 'Default');
        };

        $dashboard = match (true) {
            $has('view_hr_dashboard') && $has('manage_employees') => 'HR Operations',
            $has('view_executive_dashboard') => 'Executive View',
            $has('approve_leave') => 'Team View',
            default => 'My dashboard',
        };

        $approve = array_values(array_filter([
            $has('approve_leave') ? 'Leave ('.$scopeOf('approve_leave').')' : null,
            $has('approve_overtime') ? 'OT ('.$scopeOf('approve_overtime').')' : null,
            $has('hr_approve_regularisation') ? 'Regularisation ('.$scopeOf('hr_approve_regularisation').')' : null,
            $has('approve_wfh') ? 'WFH ('.$scopeOf('approve_wfh').')' : null,
            $has('approve_finance') ? 'Finance sign-off' : null,
        ]));

        $view = array_values(array_filter([
            $has('view_employee') ? 'Employees ('.$scopeOf('view_employee').')' : null,
            $has('view_attendance') ? 'Attendance ('.$scopeOf('view_attendance').')' : null,
            $has('view_performance') || $has('review_performance') ? 'Performance ('.($scopeOf('review_performance') ?? $scopeOf('view_performance')).')' : null,
            $has('view_reports') ? 'Reports ('.$scopeOf('view_reports').')' : null,
            $has('view_payroll') ? 'Payroll ('.$scopeOf('view_payroll').')' : null,
        ]));

        $anyAll = $selected->filter(fn (Permission $p) => $p->is_scoped)->contains(fn (Permission $p) => $scopeOf($p->key) === DataScope::All->label());

        $cannot = array_values(array_filter([
            ! $has('approve_payroll') ? 'Payroll approval' : null,
            ! $has('manage_settings') ? 'System settings' : null,
            ! $has('manage_roles') ? 'Roles & permissions' : null,
            ! $anyAll ? 'Company-wide data' : null,
        ]));

        $default = $role ? (PermissionScopes::ROLE_DEFAULTS[$role->slug] ?? PermissionScopes::defaultFor($role->slug, 'view_employee', $selected->keys()->all())) : DataScope::Team;

        return [
            'name' => $this->name ?: 'New role',
            'dashboard' => $dashboard,
            'default_scope' => $default->label(),
            'approve' => $approve,
            'view' => $view,
            'cannot' => $cannot,
            'privileged' => array_values(array_intersect($selected->keys()->all(), RoleDelegationGuard::PRIVILEGED)),
        ];
    }

    /**
     * What applying the previewed preset would change in the editor.
     *
     * @param  Collection<int, Permission>  $permissions
     * @return array{label: string, add: array<int, string>, remove: array<int, string>, blocked: array<int, string>}|null
     */
    private function presetPreview($permissions): ?array
    {
        if ($this->previewingPreset === '') {
            return null;
        }

        $keys = PermissionSections::presetKeys($this->previewingPreset);
        $blocked = $this->guard()->beyondCeiling(Auth::user(), $keys);
        $target = array_diff($keys, $blocked);
        $current = $permissions->whereIn('id', $this->selectedPermissions)->pluck('key')->all();
        $label = fn (array $ks) => $permissions->whereIn('key', $ks)->pluck('label')->sort()->values()->all();

        return [
            'label' => PermissionSections::PRESETS[$this->previewingPreset],
            'add' => $label(array_diff($target, $current)),
            'remove' => $label(array_diff($current, $target)),
            'blocked' => $label($blocked),
        ];
    }

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
