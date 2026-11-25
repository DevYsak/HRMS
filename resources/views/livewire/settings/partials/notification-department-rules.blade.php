{{--
    Settings → Notifications & Email → Department rules. For one department:
    each notification's on/off for that department's people, and who else
    receives it, who never does, or only the people listed. Applied to
    notifications about an employee in that department (NotificationRecipientPolicy).
--}}
@php
    $department = $ruleDepartments->firstWhere('id', $ruleDepartmentId);
    $roleNames = $rbacRoles->pluck('name', 'slug');
    $peopleNames = $recipientList->pluck('name', 'id');
@endphp
<div class="space-y-4" data-department-rules>
    <div class="ui-surface p-5">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="max-w-2xl">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Department-wise notifications</h3>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Choose a department, then for each notification: switch it off for that department's people, add who else receives it,
                    exclude people, or send it only to the people you list. A rule applies to notifications about an employee in that department;
                    with no rule the company-wide settings on <em>Modules &amp; general</em> apply.
                </p>
            </div>
            @if($ruleDepartments->isEmpty())
                <p class="text-xs text-zinc-400">No departments within your reach.</p>
            @else
                <flux:select wire:model.live="ruleDepartmentId" class="max-w-xs" data-rule-department>
                    @foreach($ruleDepartments as $option)
                        <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </div>
    </div>

    @if($department)
        @foreach($settings as $group => $rows)
            <div class="ui-surface overflow-hidden">
                <div class="border-b border-zinc-100 bg-zinc-50/70 px-5 py-3 dark:border-white/5 dark:bg-zinc-800/40">
                    <h3 class="text-xs font-bold uppercase tracking-widest text-zinc-500">{{ $group }}</h3>
                </div>
                <div class="overflow-x-auto" data-table-scroll><table class="w-full min-w-[680px] text-sm">
                    <thead>
                        <tr class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                            <th class="px-5 py-2 text-left">Notification</th>
                            <th class="px-3 py-2 text-center">For {{ $department->name }}</th>
                            <th class="px-3 py-2 text-left">Recipients in this department</th>
                            <th class="px-5 py-2 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach($rows as $s)
                            @php
                                $rule = $departmentRules->get($s->key);
                                $on = $rule?->is_enabled ?? true;
                            @endphp
                            <tr wire:key="dept-rule-{{ $ruleDepartmentId }}-{{ $s->key }}" data-rule-row="{{ $s->key }}">
                                <td @class(['py-3 pr-5 font-semibold text-zinc-900 dark:text-white', 'pl-5' => ! str_contains($s->key, '@'), 'pl-10' => str_contains($s->key, '@')])>
                                    @if(str_contains($s->key, '@'))<span class="mr-1 rounded bg-sky-50 px-1.5 text-[9px] font-bold uppercase text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">Stage</span>@endif
                                    {{ $s->label }}
                                </td>
                                <td class="px-3 py-3 text-center">
                                    <button type="button" wire:click="toggleRuleEnabled(@js($s->key))" aria-label="Turn {{ $s->label }} {{ $on ? 'off' : 'on' }} for {{ $department->name }}"
                                        @class(['relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition', 'bg-orange-500' => $on, 'bg-zinc-200 dark:bg-zinc-700' => ! $on])
                                        data-rule-enabled="{{ $on ? 'on' : 'off' }}">
                                        <span @class(['inline-block size-4 transform rounded-full bg-white shadow transition', 'translate-x-4' => $on, 'translate-x-0.5' => ! $on])></span>
                                    </button>
                                </td>
                                <td class="px-3 py-3 text-xs">
                                    @if(! $on)
                                        <span class="font-semibold text-rose-600">Off for this department</span>
                                    @elseif($rule === null || $rule->isEmpty())
                                        <span class="text-zinc-400">Company-wide settings</span>
                                    @else
                                        <div class="flex flex-wrap gap-1">
                                            @if($rule->replace_defaults)<span class="rounded bg-orange-50 px-1.5 py-0.5 font-bold text-orange-700 dark:bg-orange-500/10 dark:text-orange-300">Only the listed people</span>@endif
                                            @foreach((array) $rule->include_roles as $slug)<span class="rounded bg-emerald-50 px-1.5 py-0.5 font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">+ {{ $roleNames[$slug] ?? $slug }}</span>@endforeach
                                            @foreach((array) $rule->include_user_ids as $id)<span class="rounded bg-emerald-50 px-1.5 py-0.5 font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">+ {{ $peopleNames[$id] ?? 'User #'.$id }}</span>@endforeach
                                            @foreach((array) $rule->exclude_roles as $slug)<span class="rounded bg-zinc-100 px-1.5 py-0.5 font-semibold text-zinc-500 dark:bg-white/5">− {{ $roleNames[$slug] ?? $slug }}</span>@endforeach
                                            @foreach((array) $rule->exclude_user_ids as $id)<span class="rounded bg-zinc-100 px-1.5 py-0.5 font-semibold text-zinc-500 dark:bg-white/5">− {{ $peopleNames[$id] ?? 'User #'.$id }}</span>@endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right">
                                    <flux:button wire:click="openRule(@js($s->key))" size="xs" variant="ghost" icon="adjustments-horizontal">Configure</flux:button>
                                    @if($rule)
                                        <flux:button wire:click="resetRule(@js($s->key))" wire:confirm="Use the company-wide settings for {{ $s->label }} in {{ $department->name }}?" size="xs" variant="ghost" icon="arrow-uturn-left">Reset</flux:button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            </div>
        @endforeach
    @endif

    {{-- One department's rule for one notification --}}
    <flux:modal wire:model.self="showRuleModal" class="w-full max-w-2xl">
        <form wire:submit="saveRule" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ $ruleSetting?->label }} · {{ $department?->name }}</flux:heading>
                <flux:text class="mt-1 text-xs">For notifications about an employee in {{ $department?->name }}. Excluded people and roles never receive it; personal mutes still apply.</flux:text>
            </div>

            <div class="space-y-2 rounded-xl bg-zinc-50 p-3 dark:bg-white/5">
                <flux:switch wire:model.live="ruleEnabled" label="Send this notification for {{ $department?->name }} employees" />
                <flux:checkbox wire:model.live="ruleReplace" label="Send it only to the roles and people listed below (instead of the usual recipients)" :disabled="! $ruleEnabled" />
            </div>

            @if($ruleEnabled)
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <p class="mb-1.5 text-xs font-bold uppercase tracking-wider text-emerald-700">{{ $ruleReplace ? 'Send to these roles' : 'Also send to these roles' }}</p>
                        <div class="space-y-1">
                            @foreach($rbacRoles as $role)
                                <flux:checkbox wire:model="ruleIncludeRoles" value="{{ $role->slug }}" label="{{ $role->name }}" />
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <p class="mb-1.5 text-xs font-bold uppercase tracking-wider text-zinc-500">Never send to these roles</p>
                        <div class="space-y-1">
                            @foreach($rbacRoles as $role)
                                <flux:checkbox wire:model="ruleExcludeRoles" value="{{ $role->slug }}" label="{{ $role->name }}" />
                            @endforeach
                        </div>
                        @error('ruleExcludeRoles')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-notifications.people-picker model="ruleIncludeUserIds" :people="$recipientList" :label="$ruleReplace ? 'Send to these people' : 'Also send to these people'" tone="text-emerald-700" />
                        @error('ruleIncludeUserIds')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <x-notifications.people-picker model="ruleExcludeUserIds" :people="$recipientList" label="Never send to these people" />
                </div>
            @endif

            <div class="flex flex-wrap justify-end gap-2">
                @if($ruleKey && $departmentRules->has($ruleKey))
                    <flux:button wire:click="resetRule(@js($ruleKey))" variant="ghost" icon="arrow-uturn-left">Use company-wide settings</flux:button>
                @endif
                <flux:button wire:click="$set('showRuleModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Save rule</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
