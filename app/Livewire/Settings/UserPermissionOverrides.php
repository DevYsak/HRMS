<?php

namespace App\Livewire\Settings;

use App\Enums\DataScope;
use App\Models\Department;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Audit\AuditService;
use App\Services\Security\PermissionAdministration;
use App\Services\Security\RoleDelegationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Roles & Permissions → User overrides: give one person a permission their
 * role lacks, narrow or widen its reach, or revoke it — without a new role —
 * and see exactly what they end up with and why.
 *
 * Every change goes through PermissionAdministration (delegation ceiling,
 * reach cap, impossible combinations) and is audited.
 */
class UserPermissionOverrides extends Component
{
    public string $userSearch = '';

    #[Locked]
    public ?int $userId = null;

    public string $permissionId = '';

    public string $effect = UserPermissionOverride::GRANT;

    public string $scope = '';

    /** @var array<int, string> */
    public array $departmentIds = [];

    public string $reason = '';

    public function mount(): void
    {
        $this->authorizeManagement();
    }

    private function authorizeManagement(): void
    {
        $this->authorize('manage-settings');
        abort_unless(Auth::user()->hasPermission('manage_roles'), 403);
    }

    public function selectUser(int $id): void
    {
        $this->authorizeManagement();
        $this->userId = User::findOrFail($id)->id;
        $this->resetForm();
    }

    public function clearUser(): void
    {
        $this->userId = null;
        $this->resetForm();
    }

    public function updatedEffect(): void
    {
        $this->resetErrorBag();

        if ($this->effect === UserPermissionOverride::REVOKE) {
            $this->scope = '';
            $this->departmentIds = [];
        }
    }

    public function updatedScope(): void
    {
        $this->resetErrorBag(['scope', 'departmentIds']);

        if ($this->scope !== DataScope::SelectedDepartments->value) {
            $this->departmentIds = [];
        }
    }

    public function save(): void
    {
        $this->authorizeManagement();
        $target = User::findOrFail($this->userId);

        $this->validate([
            'permissionId' => ['required', Rule::exists('permissions', 'id')],
            'effect' => ['required', Rule::in([UserPermissionOverride::GRANT, UserPermissionOverride::REVOKE])],
            'scope' => ['nullable', Rule::in(DataScope::values())],
            'departmentIds' => ['array'],
            'departmentIds.*' => ['integer', Rule::exists('departments', 'id')],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [], [
            'permissionId' => 'permission',
            'departmentIds' => 'departments',
            'departmentIds.*' => 'department',
        ]);

        $permission = Permission::findOrFail((int) $this->permissionId);
        $scope = $this->scope !== '' ? DataScope::from($this->scope) : null;
        $departments = array_values(array_unique(array_map('intval', $this->departmentIds)));

        if ($refusal = app(PermissionAdministration::class)->refusalForOverride(Auth::user(), $target, $permission, $this->effect, $scope, $departments)) {
            $this->addError($this->errorFieldFor($refusal), $refusal);

            return;
        }

        $existing = UserPermissionOverride::where('user_id', $target->id)->where('permission_id', $permission->id)->first();
        $old = $existing ? $this->describe($existing) : null;

        $override = UserPermissionOverride::updateOrCreate(
            ['user_id' => $target->id, 'permission_id' => $permission->id],
            [
                'effect' => $this->effect,
                'scope' => $scope,
                'department_ids' => $scope === DataScope::SelectedDepartments ? $departments : null,
                'reason' => trim($this->reason),
                'created_by' => Auth::id(),
            ],
        );

        app(AuditService::class)->event('USER_PERMISSION_OVERRIDE_SET', AuditService::PERMISSIONS, $target,
            old: $old, new: $this->describe($override->fresh()), reason: trim($this->reason), module: AuditService::SETTINGS);

        $this->resetForm();
        $verb = $override->effect === UserPermissionOverride::GRANT ? 'granted to' : 'revoked for';
        \Flux::toast("{$permission->label} {$verb} {$target->name}.", variant: 'success');
    }

    public function remove(int $overrideId): void
    {
        $this->authorizeManagement();
        $override = UserPermissionOverride::with(['user', 'permission'])->where('user_id', $this->userId)->findOrFail($overrideId);
        $guard = app(RoleDelegationGuard::class);
        $actor = Auth::user();

        // Removing is a change too: same reach rules as adding.
        if ($override->user->is($actor)) {
            \Flux::toast('You cannot change your own permissions.', variant: 'danger');

            return;
        }

        if (! $guard->isSuperAdmin($actor) && $guard->beyondCeiling($actor, [$override->permission->key]) !== []) {
            \Flux::toast("{$override->permission->label} is outside what you can delegate.", variant: 'danger');

            return;
        }

        $old = $this->describe($override);
        $override->delete();

        app(AuditService::class)->event('USER_PERMISSION_OVERRIDE_REMOVED', AuditService::PERMISSIONS, $override->user,
            old: $old, module: AuditService::SETTINGS);

        \Flux::toast("Override removed — {$override->user->name} now has what their role gives.", variant: 'success');
    }

    public function render()
    {
        $target = $this->userId ? User::with(['assignedRole', 'employee.department'])->find($this->userId) : null;
        $search = trim($this->userSearch);

        return view('livewire.settings.user-permission-overrides', [
            'target' => $target,
            'candidates' => $target === null && mb_strlen($search) >= 2
                ? User::with('assignedRole')
                    ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                    ->orderBy('name')->limit(8)->get()
                : collect(),
            'overrides' => $target
                ? UserPermissionOverride::with(['permission', 'creator'])->where('user_id', $target->id)->get()->sortBy('permission.label')
                : collect(),
            'effective' => $target ? app(PermissionAdministration::class)->effectivePermissions($target) : [],
            'permissions' => Permission::orderBy('module')->orderBy('label')->get()->groupBy('module'),
            'selectedPermission' => $this->permissionId !== '' ? Permission::find((int) $this->permissionId) : null,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'scopes' => collect(DataScope::cases())->reject(fn (DataScope $s) => $s === DataScope::None),
        ]);
    }

    /** @return array<string, mixed> */
    private function describe(UserPermissionOverride $override): array
    {
        return [
            'permission' => $override->permission?->key,
            'effect' => $override->effect,
            'scope' => $override->scope?->value,
            'department_ids' => $override->department_ids ?? [],
        ];
    }

    /** The form field a PermissionAdministration refusal belongs under. */
    private function errorFieldFor(string $refusal): string
    {
        $about = fn (array $phrases) => collect($phrases)->contains(fn (string $p) => str_contains($refusal, $p));

        return match (true) {
            // About the permission or the person, whatever scope was chosen.
            $about(['already grants', 'Nothing to revoke', 'outside what you can delegate', 'your own permissions',
                'Super Admin', 'delegation level', 'Choose grant or revoke']) => 'permissionId',
            $about(['at least one department', 'chosen departments', 'departments inside']) => 'departmentIds',
            default => 'scope',
        };
    }

    private function resetForm(): void
    {
        $this->reset(['permissionId', 'scope', 'departmentIds', 'reason']);
        $this->effect = UserPermissionOverride::GRANT;
        $this->resetErrorBag();
    }
}
