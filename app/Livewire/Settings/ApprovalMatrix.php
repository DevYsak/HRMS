<?php

namespace App\Livewire\Settings;

use App\Enums\DataScope;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Approvals\ApprovalQueueService;
use App\Services\Security\PermissionScopes;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Who approves what — a read-only map of the CURRENT approval policy, so HR
 * can see the workflow without reading code. The decider of each type is the
 * permission ApprovalQueueService checks; the roles below are the live role
 * grants of that permission with their data scope. No stage is invented here.
 */
class ApprovalMatrix extends Component
{
    /** @var array<string, array{steps: list<string>, note: string}> */
    public const POLICY = [
        'leave' => [
            'steps' => ['Employee submits', 'Reporting manager / team lead / department head in scope (approve_leave)', 'Final on approval'],
            'note' => 'One decision (D2). Not decided in 24 hours → escalated to HR, who can also decide. HR corrects decided leave.',
        ],
        'regularisation' => [
            'steps' => ['Employee submits', 'HR review (Approve Regularisations (HR))', 'Final on approval — hours recalculated'],
            'note' => 'Managers see their team\'s requests read-only; HR decides.',
        ],
        'overtime' => [
            'steps' => ['Employee submits', 'Manager / department head in scope (approve_overtime)', 'Approved request', 'Payable OT worked out from attendance'],
            'note' => 'An approved request is not the pay: payable hours come from actual attendance beyond the shift. Escalated to HR after 24 hours.',
        ],
        'wfh' => [
            'steps' => ['Employee submits', 'Approver in scope (approve_wfh)', 'Final on approval'],
            'note' => '',
        ],
        'holiday' => [
            'steps' => ['Employee submits', 'Leave approver in scope (approve_leave)', 'Final on approval — holiday-worked pay recorded'],
            'note' => 'Holiday work has no permission of its own; leave approvers decide it.',
        ],
        'encashment' => [
            'steps' => ['Employee submits', 'Director or HR Admin', 'Payroll settlement'],
            'note' => 'D3. Legacy requests at the Finance stage are finished by Finance approvers.',
        ],
    ];

    public function mount(): void
    {
        abort_unless(Auth::user()->canManageSettings() || Auth::user()->hasPermission('manage_roles'), 403);
    }

    public function render()
    {
        $permissions = Permission::whereIn('key', collect(ApprovalQueueService::TYPES)->pluck('permission')->unique()->all())->get()->keyBy('key');
        $roles = Role::where('is_active', true)->with('permissions')->orderBy('name')->get();

        $rows = collect(self::POLICY)->map(function (array $policy, string $type) use ($permissions, $roles) {
            $permissionKey = ApprovalQueueService::TYPES[$type]['permission'] ?? null;

            $deciders = $permissionKey === null
                ? collect([['role' => 'Director', 'scope' => 'Company'], ['role' => 'HR Admin', 'scope' => 'Company']])
                : $roles->filter(fn (Role $role) => $role->slug === 'super_admin' || $role->permissions->contains('key', $permissionKey))
                    ->map(function (Role $role) use ($permissionKey) {
                        $pivot = $role->permissions->firstWhere('key', $permissionKey)?->pivot;
                        $scope = $role->slug === 'super_admin'
                            ? DataScope::All
                            : ($pivot?->scope ? DataScope::tryFrom($pivot->scope) : PermissionScopes::defaultFor($role->slug, $permissionKey, $role->permissionKeys()));

                        return ['role' => $role->name, 'scope' => $scope?->label() ?? 'Default'];
                    })->values();

            return [
                'type' => $type,
                'label' => ApprovalQueueService::TYPES[$type]['label'] ?? 'Leave Encashment',
                'permission' => $permissionKey ? ($permissions->get($permissionKey)?->label ?? $permissionKey) : 'Director / HR Admin role',
                'steps' => $policy['steps'],
                'note' => $policy['note'],
                'deciders' => $deciders,
            ];
        })->values();

        return view('livewire.settings.approval-matrix', ['rows' => $rows])
            ->layout('layouts.app', ['title' => 'Approval Matrix']);
    }
}
