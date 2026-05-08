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

    public function render(): \Illuminate\View\View
    {
        return view('pages.settings.role-permissions');
    }
};

?>

<x-settings.layout :heading="__('Role Permissions')" :subheading="__('Control what each role can see and do across the system.')">

    @php
        $definitions = \App\Services\RolePermissionService::$definitions;
        $roles       = \App\Services\RolePermissionService::$configurableRoles;
    @endphp

    {{-- Legend --}}
    <div class="mb-6 flex items-center gap-4 text-xs text-zinc-500 dark:text-zinc-400">
        <div class="flex items-center gap-1.5">
            <div class="size-4 rounded bg-emerald-500 flex items-center justify-center">
                <flux:icon.check class="size-2.5 text-white" />
            </div>
            Enabled
        </div>
        <div class="flex items-center gap-1.5">
            <div class="size-4 rounded bg-zinc-200 dark:bg-zinc-700"></div>
            Disabled
        </div>
        <div class="flex items-center gap-1.5">
            <div class="size-4 rounded bg-indigo-600 flex items-center justify-center">
                <flux:icon.lock-closed class="size-2.5 text-white" />
            </div>
            Super Admin (always on)
        </div>
    </div>

    {{-- Permission Matrix Table --}}
    <div class="overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-950/40">
                    <th class="py-4 pl-6 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400 w-72">
                        Permission
                    </th>
                    {{-- Super Admin (locked) --}}
                    <th class="py-4 px-4 text-center text-[10px] font-bold uppercase tracking-widest text-indigo-500 w-28">
                        <div class="flex flex-col items-center gap-1">
                            <flux:icon.shield-check class="size-4" />
                            Super Admin
                        </div>
                    </th>
                    {{-- Configurable roles --}}
                    @foreach($roles as $role => $label)
                        <th class="py-4 px-4 text-center text-[10px] font-bold uppercase tracking-widest text-zinc-400 w-28">
                            <div class="flex flex-col items-center gap-1">
                                @php $icon = match($role) { 'hr_admin'=>'user-group', 'director'=>'chart-bar-square', 'manager'=>'briefcase', 'finance'=>'banknotes', default=>'user' }; @endphp
                                <flux:icon :name="$icon" class="size-4" />
                                {{ $label }}
                            </div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                @foreach($definitions as $perm => $def)
                    <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20 transition-colors group">
                        {{-- Permission info --}}
                        <td class="py-4 pl-6 pr-4">
                            <div class="flex items-start gap-3">
                                <div class="size-8 rounded-xl bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center shrink-0 mt-0.5">
                                    <flux:icon :name="$def['icon']" class="size-4 text-zinc-500 dark:text-zinc-400" />
                                </div>
                                <div>
                                    <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $def['label'] }}</div>
                                    <div class="text-[11px] text-zinc-400 mt-0.5">{{ $def['desc'] }}</div>
                                </div>
                            </div>
                        </td>

                        {{-- Super Admin — always locked on --}}
                        <td class="py-4 px-4 text-center">
                            <div class="flex justify-center">
                                <div class="size-8 rounded-xl bg-indigo-600 flex items-center justify-center cursor-not-allowed" title="Super Admin always has this permission">
                                    <flux:icon.lock-closed class="size-4 text-white" />
                                </div>
                            </div>
                        </td>

                        {{-- Configurable role toggles --}}
                        @foreach($roles as $role => $label)
                            @php $enabled = $matrix[$role][$perm] ?? false; @endphp
                            <td class="py-4 px-4 text-center">
                                <div class="flex justify-center">
                                    <button
                                        wire:click="toggle('{{ $role }}', '{{ $perm }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="toggle('{{ $role }}', '{{ $perm }}')"
                                        title="{{ $enabled ? 'Click to disable' : 'Click to enable' }}"
                                        class="size-8 rounded-xl transition-all duration-200 active:scale-90 flex items-center justify-center
                                            {{ $enabled
                                                ? 'bg-emerald-500 hover:bg-emerald-600 shadow-sm shadow-emerald-200 dark:shadow-none'
                                                : 'bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 border border-zinc-200 dark:border-zinc-700' }}">
                                        @if($enabled)
                                            <flux:icon.check class="size-4 text-white" />
                                        @else
                                            <flux:icon.minus class="size-4 text-zinc-400" />
                                        @endif
                                    </button>
                                </div>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Reset to Defaults per role --}}
    <div class="mt-6 p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900">
        <h3 class="text-sm font-bold text-zinc-800 dark:text-zinc-200 mb-1">Reset Role to Defaults</h3>
        <p class="text-xs text-zinc-500 mb-4">Removes all custom overrides and restores the original permission set for a role.</p>
        <div class="flex flex-wrap gap-2">
            @foreach($roles as $role => $label)
                <flux:button
                    wire:click="resetToDefaults('{{ $role }}')"
                    wire:confirm="Reset {{ $label }} permissions to defaults? This cannot be undone."
                    variant="ghost"
                    size="sm">
                    Reset {{ $label }}
                </flux:button>
            @endforeach
        </div>
    </div>

    {{-- Info box --}}
    <div class="mt-4 flex gap-3 p-4 rounded-xl bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800/50">
        <flux:icon.information-circle class="size-5 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" />
        <div class="text-xs text-amber-700 dark:text-amber-300 leading-relaxed">
            <strong>Note:</strong> Changes take effect immediately and are cached for 5 minutes.
            Super Admin always retains full access regardless of these settings.
            Route-level middleware enforces these permissions on every request.
        </div>
    </div>

</x-settings.layout>
