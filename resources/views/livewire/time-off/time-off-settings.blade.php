<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Leave Settings</h1>
            <p class="pulse-page-subtitle">Configure leave types and company policies</p>
        </div>
        <flux:button wire:click="openModal()" variant="primary" icon="plus">
            Add Leave Type
        </flux:button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Leave Types List --}}
        <div class="lg:col-span-2 pulse-card">
            <div class="flex items-center gap-3 mb-5">
                <flux:icon.clipboard-document-list class="size-5 text-zinc-400" />
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Leave Types</h3>
                <span class="px-2.5 py-0.5 bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 text-xs font-semibold rounded-full">
                    {{ $leaveTypes->count() }} types configured
                </span>
            </div>

            <div class="space-y-2">
                @foreach($leaveTypes as $type)
                    @php
                        $iconMap = [
                            'annual'     => 'calendar-days',
                            'sick'       => 'heart',
                            'other'      => 'face-smile',
                            'mdl'        => 'building-office',
                            'comp_off'   => 'banknotes',
                            'encashment' => 'currency-dollar',
                            'unpaid'     => 'document-text',
                            'maternity'  => 'user',
                        ];
                        $icon = $iconMap[$type->category ?? 'other'] ?? 'tag';
                    @endphp
                    <div
                        class="flex items-center gap-4 p-4 rounded-xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 group hover:border-zinc-200 dark:hover:border-zinc-700 hover:shadow-sm transition-all cursor-pointer"
                        wire:click="openModal({{ $type->id }})"
                    >
                        {{-- Colored icon --}}
                        <div class="size-11 rounded-xl flex items-center justify-center shrink-0"
                             style="background-color: {{ $type->color }}22;">
                            <flux:icon :name="$icon" class="size-5" style="color: {{ $type->color }}" />
                        </div>

                        {{-- Name + meta --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap mb-0.5">
                                <span class="font-semibold text-sm text-zinc-900 dark:text-white">{{ $type->name }}</span>
                                <span class="px-2 py-0.5 text-[10px] font-bold rounded tracking-wide uppercase"
                                      style="background-color: {{ $type->color }}22; color: {{ $type->color }}">
                                    {{ $type->is_paid ? 'PAID' : 'UNPAID' }} &bull; {{ strtoupper(str_replace('_', ' ', $type->category ?? 'other')) }}
                                </span>
                            </div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                Carry Forward: {{ $type->allow_carry_forward ? ($type->carry_forward_limit > 0 ? 'Yes · Limit '.$type->carry_forward_limit.' days' : 'Yes · No Limit') : 'No' }}
                                &nbsp;&middot;&nbsp;
                                Encashment: {{ $type->allow_encashment ? 'Enabled' : 'Disabled' }}
                            </div>
                        </div>

                        {{-- Active badge --}}
                        <span class="shrink-0 px-2.5 py-1 text-[10px] font-bold rounded-full border bg-emerald-50 text-emerald-600 border-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-400 dark:border-emerald-800/40">
                            Active
                        </span>

                        {{-- Delete (hover) --}}
                        <div class="opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
                            <flux:button
                                wire:click.stop="delete({{ $type->id }})"
                                wire:confirm="Are you sure? This may affect existing requests."
                                variant="ghost" size="sm" icon="trash"
                                class="text-red-400 hover:text-red-600"
                            />
                        </div>

                        {{-- Chevron --}}
                        <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-300 dark:text-zinc-600 group-hover:text-zinc-400 transition-colors" />
                    </div>
                @endforeach
            </div>

            {{-- Bottom notice --}}
            <div class="mt-5 pt-4 border-t border-zinc-100 dark:border-zinc-800 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                <flux:icon.shield-check class="size-4 shrink-0 text-zinc-400" />
                <span>Changes to leave types will affect future requests. Existing approved leaves won't be impacted.</span>
            </div>
        </div>

        {{-- About Leave Types --}}
        <div class="pulse-card overflow-hidden">
            {{-- Illustration --}}
            <div class="-mx-6 -mt-6 mb-5 h-40 bg-gradient-to-br from-violet-100 to-indigo-100 dark:from-violet-950/40 dark:to-indigo-950/40 relative flex items-center justify-center overflow-hidden">
                <div class="absolute top-5 right-10 size-16 rounded-2xl bg-violet-300/50 dark:bg-violet-700/30 rotate-12"></div>
                <div class="absolute bottom-3 right-5 size-10 rounded-xl bg-indigo-300/40 dark:bg-indigo-700/30 -rotate-6"></div>
                <div class="absolute top-3 left-10 size-8 rounded-full bg-violet-200/60 dark:bg-violet-800/30"></div>
                <div class="absolute bottom-6 left-6 size-5 rounded-full bg-indigo-200/50 dark:bg-indigo-800/30"></div>
                <div class="relative z-10 size-14 rounded-2xl bg-white dark:bg-zinc-800 shadow-lg flex items-center justify-center">
                    <flux:icon.clipboard-document-check class="size-7 text-violet-500" />
                </div>
                <div class="absolute top-4 right-28 size-7 rounded-full bg-amber-300/80 dark:bg-amber-700/40 flex items-center justify-center">
                    <flux:icon.information-circle class="size-4 text-amber-600 dark:text-amber-400" />
                </div>
            </div>

            <h4 class="font-bold text-zinc-900 dark:text-white mb-2">About Leave Types</h4>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 leading-relaxed mb-5">
                Leave types defined here are available for all employees. Balances must be initialized for
                new leave types in the employee profile settings.
            </p>

            <div class="space-y-3">
                <div class="flex items-start gap-3 p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl">
                    <div class="size-8 rounded-lg bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center shrink-0">
                        <flux:icon.users class="size-4 text-violet-600 dark:text-violet-400" />
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Company Wide</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5 leading-relaxed">These leave types are available across the organization.</div>
                    </div>
                </div>
                <div class="flex items-start gap-3 p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl">
                    <div class="size-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center shrink-0">
                        <flux:icon.adjustments-horizontal class="size-4 text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Policy Driven</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5 leading-relaxed">Each type follows company policy and configuration.</div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    {{-- Add / Edit Modal --}}
    <flux:modal wire:model.self="showModal" class="w-full max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Edit' : 'Add' }} Leave Type</flux:heading>
            </div>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model="name" label="Type Name" placeholder="e.g. Annual Leave" required />

                <div class="flex items-center gap-4 py-2">
                    <flux:checkbox wire:model="is_paid" label="Paid Leave" />
                </div>

                <flux:select wire:model="category" label="Category">
                    <option value="annual">Annual</option>
                    <option value="sick">Sick</option>
                    <option value="mdl">MDL</option>
                    <option value="comp_off">Comp Off</option>
                    <option value="encashment">Encashment</option>
                    <option value="unpaid">Unpaid</option>
                    <option value="other">Other</option>
                </flux:select>

                <div class="grid grid-cols-1 gap-4">
                    <flux:switch wire:model="allow_carry_forward" label="Allow Carry Forward" />
                    <flux:input wire:model="carry_forward_limit" type="number" min="0" label="Carry Forward Limit" suffix="days" />
                    <flux:switch wire:model="allow_encashment" label="Allow Encashment" />
                </div>

                <div class="space-y-2">
                    <flux:label>UI Color Marker</flux:label>
                    <div class="flex flex-wrap gap-2">
                        @foreach(['#1DB77A', '#3B82F6', '#EF4444', '#F59E0B', '#8B5CF6', '#EC4899', '#6B7280', '#06B6D4'] as $c)
                            <button type="button" wire:click="$set('color', '{{ $c }}')"
                                class="size-7 rounded-full border-2 transition-transform hover:scale-110 {{ $color === $c ? 'border-zinc-900 scale-110 dark:border-white' : 'border-transparent' }}"
                                style="background-color: {{ $c }}">
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-4">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">Save Type</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</flux:main>
