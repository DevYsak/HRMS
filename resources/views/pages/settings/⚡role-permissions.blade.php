<?php

use App\Enums\UserRole;
use App\Models\RolePermission;
use App\Services\RolePermissionService;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Role Permissions')] class extends Component {

    /** matrix[role][permission] = bool */
    public array $matrix = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        $this->matrix = RolePermissionService::matrix();
    }

    public function toggle(string $role, string $permission): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
        abort_unless(array_key_exists($role, RolePermissionService::$configurableRoles), 422);
        abort_unless(array_key_exists($permission, RolePermissionService::$definitions), 422);

        $current = $this->matrix[$role][$permission] ?? false;
        $new     = ! $current;

        RolePermission::updateOrCreate(
            ['role' => $role, 'permission' => $permission],
            ['enabled' => $new]
        );

        $this->matrix[$role][$permission] = $new;
        RolePermissionService::flush($role);

        \Flux::toast(
            RolePermissionService::$configurableRoles[$role] . ': '
            . RolePermissionService::$definitions[$permission]['label']
            . ' ' . ($new ? 'enabled' : 'disabled') . '.'
        );
    }

    public function resetToDefaults(string $role): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        RolePermission::where('role', $role)->delete();
        RolePermissionService::flush($role);

        $defaults = RolePermissionService::$defaults[$role] ?? [];
        foreach (array_keys(RolePermissionService::$definitions) as $perm) {
            $this->matrix[$role][$perm] = in_array($perm, $defaults, true);
        }

        \Flux::toast(RolePermissionService::$configurableRoles[$role] . ' permissions reset to defaults.');
    }

};

?>

<x-settings.layout :heading="__('Role Permissions')" :subheading="__('Control what each role can access across the system. Changes apply immediately.')">

@php
    $roles = \App\Services\RolePermissionService::$configurableRoles;

    // Grouped permissions with detailed "unlocks" info
    $groups = [
        'People Management' => [
            'manage-employees' => [
                'icon'    => 'users',
                'label'   => 'Manage Employees',
                'desc'    => 'Full access to employee records',
                'unlocks' => ['Create / edit employee profiles', 'View all employee data', 'Assign departments & managers', 'Onboarding & offboarding'],
            ],
        ],
        'Time & Attendance' => [
            'approve-leave' => [
                'icon'    => 'calendar-days',
                'label'   => 'Approve Leave',
                'desc'    => 'Review and action leave requests',
                'unlocks' => ['View all leave requests', 'Approve or reject applications', 'Access leave calendar', 'Team & all-employee leave view'],
            ],
            'approve-ot' => [
                'icon'    => 'clock',
                'label'   => 'Approve Overtime',
                'desc'    => 'Review and action OT pre-approvals',
                'unlocks' => ['View all OT requests', 'Approve or reject OT', 'View OT records & reports'],
            ],
        ],
        'Payroll & Finance' => [
            'run-payroll' => [
                'icon'    => 'banknotes',
                'label'   => 'Run Payroll',
                'desc'    => 'Process payroll cycles',
                'unlocks' => ['Generate payroll drafts', 'Manage salary components', 'Add incentives & reimbursements', 'Submit to finance'],
            ],
            'approve-finance' => [
                'icon'    => 'check-badge',
                'label'   => 'Finance Sign-off',
                'desc'    => 'Approve payroll for disbursement',
                'unlocks' => ['Review payroll summary', 'Approve or reject payroll runs', 'Mark payroll as finalized'],
            ],
        ],
        'Operations' => [
            'manage-documents' => [
                'icon'    => 'document-text',
                'label'   => 'Manage Documents',
                'desc'    => 'Upload and manage HR documents',
                'unlocks' => ['Upload documents for employees', 'Generate experience letters', 'Set document expiry alerts'],
            ],
            'view-reports' => [
                'icon'    => 'chart-bar',
                'label'   => 'View Reports',
                'desc'    => 'Download system reports',
                'unlocks' => ['Attendance summary CSV', 'OT records CSV', 'Payroll summary PDF'],
            ],
        ],
        'System' => [
            'manage-settings' => [
                'icon'    => 'cog-6-tooth',
                'label'   => 'Manage Settings',
                'desc'    => 'Configure company-wide settings',
                'unlocks' => ['Company profile & logo', 'Leave types & balances', 'Attendance shift settings', 'Public holidays'],
            ],
        ],
    ];

    $roleIcons = ['hr_admin'=>'user-group','director'=>'chart-bar-square','manager'=>'briefcase','finance'=>'banknotes','employee'=>'user'];
@endphp

{{-- Legend --}}
<div class="mb-5 flex flex-wrap items-center gap-5 text-xs text-zinc-500 dark:text-zinc-400">
    <div class="flex items-center gap-2">
        <div class="size-5 rounded-lg bg-emerald-500 flex items-center justify-center shadow-sm">
            <flux:icon.check class="size-3 text-white" />
        </div>
        <span>Enabled</span>
    </div>
    <div class="flex items-center gap-2">
        <div class="size-5 rounded-lg bg-zinc-200 dark:bg-zinc-700 border border-zinc-300 dark:border-zinc-600"></div>
        <span>Disabled</span>
    </div>
    <div class="flex items-center gap-2">
        <div class="size-5 rounded-lg bg-brand-600 flex items-center justify-center shadow-sm">
            <flux:icon.lock-closed class="size-3 text-white" />
        </div>
        <span>Super Admin (always on, locked)</span>
    </div>
</div>

{{-- Full-width permission matrix --}}
<div class="overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm">
    <table class="w-full text-sm" style="table-layout:fixed;">
        <colgroup>
            <col style="width:30%">   {{-- Permission description --}}
            <col style="width:10%">   {{-- Super Admin --}}
            @foreach($roles as $role => $label)
                <col style="width:{{ floor(60 / count($roles)) }}%">
            @endforeach
        </colgroup>
        <thead>
            <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/80">
                <th class="py-4 pl-6 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                    Permission &amp; Access Details
                </th>
                <th class="py-4 px-3 text-center text-[10px] font-bold uppercase tracking-widest text-orange-500">
                    <div class="flex flex-col items-center gap-1.5">
                        <flux:icon.shield-check class="size-4" />
                        <span>Super<br>Admin</span>
                    </div>
                </th>
                @foreach($roles as $role => $label)
                    <th class="py-4 px-3 text-center text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                        <div class="flex flex-col items-center gap-1.5">
                            <flux:icon :name="$roleIcons[$role] ?? 'user'" class="size-4" />
                            <span>{{ $label }}</span>
                        </div>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($groups as $groupName => $perms)
                {{-- Group header row --}}
                <tr class="bg-zinc-50/80 dark:bg-zinc-900/50 border-y border-zinc-100 dark:border-zinc-800">
                    <td colspan="{{ 2 + count($roles) }}" class="py-2 pl-6 pr-4">
                        <span class="text-[10px] font-black uppercase tracking-widest text-zinc-400 dark:text-zinc-500">
                            {{ $groupName }}
                        </span>
                    </td>
                </tr>

                @foreach($perms as $perm => $def)
                    <tr class="border-b border-zinc-50 dark:border-zinc-800/60 hover:bg-orange-50/30 dark:hover:bg-orange-950/10 transition-colors">
                        {{-- Permission info + unlocks --}}
                        <td class="py-4 pl-6 pr-4 align-top">
                            <div class="flex items-start gap-3">
                                <div class="size-9 rounded-xl bg-orange-50 dark:bg-orange-950/30 border border-orange-100 dark:border-orange-900/30 flex items-center justify-center shrink-0 mt-0.5">
                                    <flux:icon :name="$def['icon']" class="size-4 text-orange-600 dark:text-orange-400" />
                                </div>
                                <div class="min-w-0">
                                    <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $def['label'] }}</div>
                                    <div class="text-[11px] text-zinc-500 mt-0.5 mb-2">{{ $def['desc'] }}</div>
                                    <div class="space-y-0.5">
                                        @foreach($def['unlocks'] as $unlock)
                                            <div class="flex items-center gap-1.5 text-[10px] text-zinc-400">
                                                <div class="size-1 rounded-full bg-zinc-300 dark:bg-zinc-600 shrink-0"></div>
                                                {{ $unlock }}
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </td>

                        {{-- Super Admin locked --}}
                        <td class="py-4 px-3 text-center align-middle">
                            <div class="flex justify-center">
                                <div class="size-9 rounded-xl bg-brand-600 flex items-center justify-center cursor-not-allowed shadow-sm" title="Super Admin always has this permission">
                                    <flux:icon.lock-closed class="size-4 text-white" />
                                </div>
                            </div>
                        </td>

                        {{-- Role toggles --}}
                        @foreach($roles as $role => $label)
                            @php $enabled = $matrix[$role][$perm] ?? false; @endphp
                            <td class="py-4 px-3 text-center align-middle">
                                <div class="flex flex-col items-center gap-1.5">
                                    <button
                                        wire:click="toggle('{{ $role }}', '{{ $perm }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="toggle('{{ $role }}', '{{ $perm }}')"
                                        title="{{ $enabled ? 'Disable for '.$label : 'Enable for '.$label }}"
                                        class="size-9 rounded-xl transition-all duration-200 active:scale-90 flex items-center justify-center
                                            {{ $enabled
                                                ? 'bg-emerald-500 hover:bg-emerald-600 shadow-sm'
                                                : 'bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 border border-zinc-200 dark:border-zinc-700' }}">
                                        @if($enabled)
                                            <flux:icon.check class="size-4 text-white" />
                                        @else
                                            <flux:icon.x-mark class="size-4 text-zinc-300 dark:text-zinc-600" />
                                        @endif
                                    </button>
                                    <span class="text-[9px] font-bold {{ $enabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-300 dark:text-zinc-600' }} uppercase tracking-wide">
                                        {{ $enabled ? 'ON' : 'OFF' }}
                                    </span>
                                </div>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</div>

{{-- Reset + Info --}}
<div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900">
        <h3 class="text-sm font-bold text-zinc-800 dark:text-zinc-200 mb-1">Reset Role to Defaults</h3>
        <p class="text-xs text-zinc-500 mb-3">Removes all overrides and restores the original permission set.</p>
        <div class="flex flex-wrap gap-2">
            @foreach($roles as $role => $label)
                <flux:button
                    wire:click="resetToDefaults('{{ $role }}')"
                    wire:confirm="Reset {{ $label }} permissions to system defaults?"
                    variant="ghost" size="sm">
                    Reset {{ $label }}
                </flux:button>
            @endforeach
        </div>
    </div>
    <div class="flex gap-3 p-5 rounded-2xl bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800/50">
        <flux:icon.information-circle class="size-5 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" />
        <div class="text-xs text-amber-700 dark:text-amber-300 leading-relaxed">
            <strong>Note:</strong> Changes take effect immediately (5-min cache).
            Super Admin always retains full access.
            Middleware enforces these on every request.
        </div>
    </div>
</div>

</x-settings.layout>
