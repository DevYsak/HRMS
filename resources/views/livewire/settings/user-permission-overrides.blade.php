<div class="space-y-4">
    @if(! $target)
        <div class="pulse-card space-y-3 p-5">
            <div>
                <flux:heading size="sm">Find a person</flux:heading>
                <flux:text class="text-sm">Give one person an exception to their role — a permission their role lacks, a different reach, or a revoke.</flux:text>
            </div>
            <flux:input wire:model.live.debounce.300ms="userSearch" icon="magnifying-glass" placeholder="Name or email (2+ letters)" />
            @if($candidates->isNotEmpty())
                <div class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                    @foreach($candidates as $candidate)
                        <button type="button" wire:click="selectUser({{ $candidate->id }})"
                            class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <span class="min-w-0">
                                <span class="block truncate font-medium text-zinc-900 dark:text-white">{{ $candidate->name }}</span>
                                <span class="block truncate text-xs text-zinc-500">{{ $candidate->email }}</span>
                            </span>
                            <flux:badge size="sm">{{ $candidate->displayRoleName() }}</flux:badge>
                        </button>
                    @endforeach
                </div>
            @elseif(mb_strlen(trim($userSearch)) >= 2)
                <p class="text-sm text-zinc-500">No one matches “{{ $userSearch }}”.</p>
            @endif
        </div>
    @else
        <div class="pulse-card flex flex-wrap items-center justify-between gap-3 p-4">
            <div class="min-w-0">
                <div class="font-semibold text-zinc-900 dark:text-white">{{ $target->name }}</div>
                <div class="text-xs text-zinc-500">
                    {{ $target->displayRoleName() }}
                    @if($target->employee?->department) · {{ $target->employee->department->name }} @endif
                    @if($target->assignedRole && ! $target->assignedRole->is_active) · <span class="text-amber-600">role inactive — Employee self-service applies</span> @endif
                </div>
            </div>
            <flux:button wire:click="clearUser" size="sm" variant="ghost" icon="arrow-left">Choose someone else</flux:button>
        </div>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-5">
            {{-- Add / change an override --}}
            <div class="pulse-card space-y-4 p-5 xl:col-span-2">
                <flux:heading size="sm">Add or change an override</flux:heading>

                <flux:select wire:model.live="permissionId" label="Permission">
                    <flux:select.option value="">Choose a permission…</flux:select.option>
                    @foreach($permissions as $module => $group)
                        <optgroup label="{{ $module }}">
                            @foreach($group as $permission)
                                <option value="{{ $permission->id }}">{{ $permission->label }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </flux:select>

                <flux:radio.group wire:model.live="effect" label="Effect" variant="segmented">
                    <flux:radio value="grant" label="Grant" />
                    <flux:radio value="revoke" label="Revoke" />
                </flux:radio.group>

                @if($effect === 'grant' && $selectedPermission?->is_scoped)
                    <flux:select wire:model.live="scope" label="Data scope" description="Whose records this permission reaches for this person.">
                        <flux:select.option value="">Inherit from role</flux:select.option>
                        @foreach($scopes as $s)
                            <flux:select.option value="{{ $s->value }}">{{ $s->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if($scope === \App\Enums\DataScope::SelectedDepartments->value)
                        <flux:checkbox.group wire:model="departmentIds" label="Departments">
                            <div class="grid max-h-48 grid-cols-1 gap-1 overflow-y-auto sm:grid-cols-2">
                                @foreach($departments as $department)
                                    <flux:checkbox value="{{ $department->id }}" label="{{ $department->name }}" />
                                @endforeach
                            </div>
                        </flux:checkbox.group>
                    @endif
                @elseif($effect === 'grant' && $selectedPermission)
                    <p class="text-xs text-zinc-500">{{ $selectedPermission->label }} doesn't reach employee data, so it has no scope.</p>
                @endif
                {{-- The scope / department inputs render their own errors; when they are
                     not on screen (no scope for this permission, or a revoke) a scope
                     error still has to show. --}}
                @unless($effect === 'grant' && $selectedPermission?->is_scoped)
                    <flux:error name="scope" />
                    <flux:error name="departmentIds" />
                @endunless

                <flux:textarea wire:model="reason" label="Reason" rows="2" placeholder="Why this person needs the exception (kept in the audit log)" />

                <div class="flex justify-end">
                    <flux:button wire:click="save" variant="primary">Save override</flux:button>
                </div>
            </div>

            {{-- Current overrides + effective permissions --}}
            <div class="space-y-4 xl:col-span-3">
                <div class="pulse-card p-5">
                    <flux:heading size="sm" class="mb-3">Overrides for {{ $target->name }}</flux:heading>
                    @forelse($overrides as $override)
                        <div class="flex items-start justify-between gap-3 border-t border-zinc-100 py-2.5 first:border-t-0 dark:border-zinc-800">
                            <div class="min-w-0 text-sm">
                                <div class="font-medium text-zinc-900 dark:text-white">
                                    {{ $override->permission->label }}
                                    <flux:badge size="sm" :color="$override->isRevoke() ? 'red' : 'green'">{{ $override->isRevoke() ? 'Revoked' : 'Granted' }}</flux:badge>
                                    @if($override->scope)
                                        <flux:badge size="sm" color="zinc">{{ $override->scope->label() }}</flux:badge>
                                    @endif
                                </div>
                                <div class="mt-0.5 text-xs text-zinc-500">
                                    {{ $override->reason }} · {{ $override->creator?->name ?? 'System' }}, {{ $override->updated_at->format('d M Y') }}
                                </div>
                            </div>
                            <flux:button wire:click="remove({{ $override->id }})" wire:confirm="Remove this override? {{ $target->name }} goes back to what their role gives." size="sm" variant="ghost" icon="x-mark" />
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">No overrides — {{ $target->name }} has exactly what their role gives.</p>
                    @endforelse
                </div>

                <div class="pulse-card p-5">
                    <flux:heading size="sm">Effective permissions</flux:heading>
                    <flux:text class="mb-3 text-xs">What {{ $target->name }} can actually do, where it comes from, and how far it reaches.</flux:text>
                    <div class="max-h-[28rem] overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-white text-left text-xs text-zinc-500 dark:bg-zinc-900">
                                <tr><th class="py-1.5 font-medium">Permission</th><th class="py-1.5 font-medium">Source</th><th class="py-1.5 font-medium">Reach</th></tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach($effective as $row)
                                    <tr class="{{ $row['held'] ? '' : 'text-zinc-400 line-through' }}">
                                        <td class="py-1.5 pe-2">{{ $row['label'] }} <span class="text-xs text-zinc-400">· {{ $row['module'] }}</span></td>
                                        <td class="py-1.5 pe-2 text-xs">{{ $row['source'] }}</td>
                                        <td class="py-1.5 text-xs">
                                            {{ $row['scope'] ?? ($row['held'] ? 'Not data-scoped' : '—') }}
                                            @if($row['departments']) <span class="text-zinc-500">({{ implode(', ', $row['departments']) }})</span> @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
