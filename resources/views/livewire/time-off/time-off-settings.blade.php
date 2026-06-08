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
                <span
                    class="px-2.5 py-0.5 bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 text-xs font-semibold rounded-full">
                    {{ $leaveTypes->count() }} types configured
                </span>
            </div>

            <div class="space-y-2">
                @foreach($leaveTypes as $type)
                    @php
                        $iconMap = [
                            'annual' => 'calendar-days',
                            'sick' => 'heart',
                            'other' => 'face-smile',
                            'mdl' => 'building-office',
                            'comp_off' => 'banknotes',
                            'encashment' => 'currency-dollar',
                            'unpaid' => 'document-text',
                            'maternity' => 'user',
                        ];
                        $icon = $iconMap[$type->category ?? 'other'] ?? 'tag';
                    @endphp
                    <div class="flex items-center gap-4 p-4 rounded-xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 group hover:border-zinc-200 dark:hover:border-zinc-700 hover:shadow-sm transition-all cursor-pointer"
                        wire:click="openModal({{ $type->id }})">
                        {{-- Colored icon --}}
                        <div class="size-11 rounded-xl flex items-center justify-center shrink-0"
                            style="background-color: {{ $type->color }}22;">
                            <flux:icon :name="$icon" class="size-5" style="color: {{ $type->color }}" />
                        </div>

                        {{-- Name + meta --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap mb-0.5">
                                <span class="font-semibold text-sm text-zinc-900 dark:text-white">{{ $type->name }}</span>
                                @if($type->code)
                                    <span
                                        class="px-1.5 py-0.5 text-[10px] font-mono font-bold bg-zinc-100 dark:bg-zinc-800 text-zinc-500 rounded">{{ $type->code }}</span>
                                @endif
                                <span class="px-2 py-0.5 text-[10px] font-bold rounded tracking-wide uppercase"
                                    style="background-color: {{ $type->color }}22; color: {{ $type->color }}">
                                    {{ $type->is_paid ? 'PAID' : 'UNPAID' }} &bull;
                                    {{ strtoupper(str_replace('_', ' ', $type->category ?? 'other')) }}
                                </span>
                                @if($type->is_monthly_accrual)
                                    <span
                                        class="px-1.5 py-0.5 text-[10px] font-bold bg-indigo-50 text-indigo-600 dark:bg-indigo-900/20 dark:text-indigo-400 rounded">Accrual</span>
                                @endif
                                @if($type->gender_restriction !== 'none')
                                    <span
                                        class="px-1.5 py-0.5 text-[10px] font-bold bg-pink-50 text-pink-600 dark:bg-pink-900/20 dark:text-pink-400 rounded">{{ ucfirst($type->gender_restriction) }}
                                        only</span>
                                @endif
                            </div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400 flex flex-wrap gap-x-3 gap-y-0.5">
                                <span>CF:
                                    {{ $type->allow_carry_forward ? ($type->carry_forward_limit > 0 ? 'Yes (' . $type->carry_forward_limit . 'd)' : 'Yes') : 'No' }}</span>
                                <span>Encash: {{ $type->allow_encashment ? 'Yes' : 'No' }}</span>
                                <span>Half Day: {{ $type->allow_half_day ? 'Yes' : 'No' }}</span>
                                <span>Sandwich: {{ $type->is_sandwich_applicable ? 'Yes' : 'No' }}</span>
                                @if($type->attachment_required) <span class="text-amber-600 dark:text-amber-400">Attachment
                                req.</span> @endif
                                @if($type->probation_restricted) <span class="text-red-500">No probation</span> @endif
                            </div>
                        </div>

                        {{-- Active badge --}}
                        <span
                            class="shrink-0 px-2.5 py-1 text-[10px] font-bold rounded-full border bg-emerald-50 text-emerald-600 border-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-400 dark:border-emerald-800/40">
                            Active
                        </span>

                        {{-- Delete (hover) --}}
                        <div class="opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
                            <flux:button wire:click.stop="delete({{ $type->id }})"
                                wire:confirm="Are you sure? This may affect existing requests." variant="ghost" size="sm"
                                icon="trash" class="text-red-400 hover:text-red-600" />
                        </div>

                        {{-- Chevron --}}
                        <flux:icon.chevron-right
                            class="size-4 shrink-0 text-zinc-300 dark:text-zinc-600 group-hover:text-zinc-400 transition-colors" />
                    </div>
                @endforeach
            </div>

            {{-- Bottom notice --}}
            <div
                class="mt-5 pt-4 border-t border-zinc-100 dark:border-zinc-800 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                <flux:icon.shield-check class="size-4 shrink-0 text-zinc-400" />
                <span>Changes to leave types will affect future requests. Existing approved leaves won't be
                    impacted.</span>
            </div>
        </div>

        {{-- About Leave Types --}}
        <div class="pulse-card overflow-hidden">
            {{-- Illustration --}}
            <div
                class="-mx-6 -mt-6 mb-5 h-40 bg-gradient-to-br from-violet-100 to-indigo-100 dark:from-violet-950/40 dark:to-indigo-950/40 relative flex items-center justify-center overflow-hidden">
                <div
                    class="absolute top-5 right-10 size-16 rounded-2xl bg-violet-300/50 dark:bg-violet-700/30 rotate-12">
                </div>
                <div
                    class="absolute bottom-3 right-5 size-10 rounded-xl bg-indigo-300/40 dark:bg-indigo-700/30 -rotate-6">
                </div>
                <div class="absolute top-3 left-10 size-8 rounded-full bg-violet-200/60 dark:bg-violet-800/30"></div>
                <div class="absolute bottom-6 left-6 size-5 rounded-full bg-indigo-200/50 dark:bg-indigo-800/30"></div>
                <div
                    class="relative z-10 size-14 rounded-2xl bg-white dark:bg-zinc-800 shadow-lg flex items-center justify-center">
                    <flux:icon.clipboard-document-check class="size-7 text-violet-500" />
                </div>
                <div
                    class="absolute top-4 right-28 size-7 rounded-full bg-amber-300/80 dark:bg-amber-700/40 flex items-center justify-center">
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
                    <div
                        class="size-8 rounded-lg bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center shrink-0">
                        <flux:icon.users class="size-4 text-violet-600 dark:text-violet-400" />
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Company Wide</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5 leading-relaxed">These leave types
                            are available across the organization.</div>
                    </div>
                </div>
                <div class="flex items-start gap-3 p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl">
                    <div
                        class="size-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center shrink-0">
                        <flux:icon.adjustments-horizontal class="size-4 text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Policy Driven</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5 leading-relaxed">Each type follows
                            company policy and configuration.</div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    {{-- Add / Edit Modal --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data
            x-on:keydown.escape.window="$wire.set('showModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showModal', false)"></div>
            <div
                class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                <div class="space-y-5">
                    <flux:heading size="lg">{{ $editingId ? 'Edit' : 'Add' }} Leave Type</flux:heading>

                    <form wire:submit="save" class="space-y-5">

                        {{-- ── Basic Info ────────────────────────────────── --}}
                        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
                            <p class="text-xs font-bold text-zinc-400 uppercase tracking-wide">Basic Info</p>
                            <div class="grid grid-cols-2 gap-3">
                                <flux:input wire:model="name" label="Name" placeholder="e.g. Earned Leave" required />
                                <flux:input wire:model="code" label="Short Code" placeholder="e.g. EL" />
                            </div>

                            <flux:select wire:model="category" label="Category">
                                <option value="annual">Annual</option>
                                <option value="sick">Sick</option>
                                <option value="mdl">Maternity / MDL</option>
                                <option value="paternity">Paternity</option>
                                <option value="bereavement">Bereavement</option>
                                <option value="comp_off">Comp Off</option>
                                <option value="lwp">Leave Without Pay</option>
                                <option value="wfh">Work From Home</option>
                                <option value="unauthorized">Unauthorized</option>
                                <option value="encashment">Encashment</option>
                                <option value="custom">Custom</option>
                                <option value="unpaid">Unpaid</option>
                                <option value="other">Other</option>
                            </flux:select>

                            <div class="space-y-1.5">
                                <flux:label>Color</flux:label>
                                <div class="flex flex-wrap gap-2">
                                    @foreach(['#1DB77A', '#3B82F6', '#EF4444', '#F59E0B', '#8B5CF6', '#EC4899', '#06B6D4', '#64748B', '#DC2626', '#10B981', '#6366F1', '#F97316'] as $c)
                                        <button type="button" wire:click="$set('color', '{{ $c }}')"
                                            class="size-6 rounded-full border-2 transition-transform hover:scale-110 {{ $color === $c ? 'border-zinc-900 dark:border-white scale-110' : 'border-transparent' }}"
                                            style="background-color: {{ $c }}"></button>
                                    @endforeach
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <flux:checkbox wire:model="is_paid" id="is_paid_chk" />
                                <label for="is_paid_chk"
                                    class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 cursor-pointer">Default
                                    Paid Leave</label>
                            </div>
                        </div>

                        {{-- ── Request Behaviour ─────────────────────────── --}}
                        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 space-y-3">
                            <p class="text-xs font-bold text-zinc-400 uppercase tracking-wide">Request Behaviour</p>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="flex items-center gap-2 cursor-pointer text-sm">
                                    <flux:checkbox wire:model="allow_paid_request" />
                                    <span class="text-zinc-700 dark:text-zinc-300">Employee can request Paid</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer text-sm">
                                    <flux:checkbox wire:model="allow_unpaid_request" />
                                    <span class="text-zinc-700 dark:text-zinc-300">Employee can request Unpaid</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer text-sm">
                                    <flux:checkbox wire:model="allow_hr_override" />
                                    <span class="text-zinc-700 dark:text-zinc-300">HR can override Paid/Unpaid</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer text-sm">
                                    <flux:checkbox wire:model="hr_remark_required" />
                                    <span class="text-zinc-700 dark:text-zinc-300">HR remark mandatory on override</span>
                                </label>
                            </div>
                        </div>

                        {{-- ── Leave Rules ────────────────────────────────── --}}
                        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 space-y-3">
                            <p class="text-xs font-bold text-zinc-400 uppercase tracking-wide">Leave Rules</p>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="flex items-center gap-2 cursor-pointer text-sm">
                                    <flux:checkbox wire:model="allow_half_day" />
                                    <span class="text-zinc-700 dark:text-zinc-300">Half Day Allowed</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer text-sm">
                                    <flux:checkbox wire:model="is_sandwich_applicable" />
                                    <span class="text-zinc-700 dark:text-zinc-300">Sandwich Policy</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer text-sm">
                                    <flux:checkbox wire:model="allow_carry_forward" />
                                    <span class="text-zinc-700 dark:text-zinc-300">Carry Forward</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer text-sm">
                                    <flux:checkbox wire:model.live="allow_encashment" />
                                    <span class="text-zinc-700 dark:text-zinc-300">Encashable</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer text-sm">
                                    <flux:checkbox wire:model="attachment_required" />
                                    <span class="text-zinc-700 dark:text-zinc-300">Attachment Required</span>
                                </label>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <flux:input wire:model="carry_forward_limit" type="number" min="0"
                                    label="CF Limit (days, 0 = unlimited)" />
                                <flux:input wire:model="max_consecutive_days" type="number" min="1"
                                    label="Max Consecutive Days" placeholder="blank = no limit" />
                            </div>

                            {{-- Encashment config (visible only when encashment is enabled) --}}
                            @if($allow_encashment)
                                <div
                                    class="rounded-xl border border-blue-100 dark:border-blue-900/40 bg-blue-50/50 dark:bg-blue-900/10 p-4 space-y-3">
                                    <p class="text-xs font-bold text-blue-500 uppercase tracking-wide">Encashment Rules</p>
                                    <div class="grid grid-cols-2 gap-3">
                                        <flux:input wire:model="max_encashable_days" type="number" min="1" max="365"
                                            label="Max Encashable Days / Year" placeholder="blank = no cap" />
                                        <flux:input wire:model="encashment_rate_multiplier" type="number" step="0.05" min="0.1"
                                            max="10" label="Rate Multiplier (e.g. 1.5×)" />
                                    </div>
                                    <label class="flex items-center gap-2 cursor-pointer text-sm">
                                        <flux:checkbox wire:model="allow_current_year_encashment" />
                                        <span class="text-zinc-700 dark:text-zinc-300">Allow current-year leave encashment (in
                                            addition to carry-forward)</span>
                                    </label>
                                </div>
                            @endif
                        </div>

                        {{-- ── Monthly Accrual ────────────────────────────── --}}
                        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 space-y-3">
                            <p class="text-xs font-bold text-zinc-400 uppercase tracking-wide">Monthly Accrual</p>
                            <label class="flex items-center gap-2 cursor-pointer text-sm">
                                <flux:checkbox wire:model.live="is_monthly_accrual" />
                                <span class="text-zinc-700 dark:text-zinc-300">Enable Monthly Accrual</span>
                            </label>
                            @if($is_monthly_accrual)
                                <flux:input wire:model="accrual_days_per_month" type="number" step="0.25" min="0" max="31"
                                    label="Days to Credit Per Month" placeholder="e.g. 1.75" />
                            @endif
                        </div>

                        {{-- ── Restrictions ───────────────────────────────── --}}
                        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 space-y-3">
                            <p class="text-xs font-bold text-zinc-400 uppercase tracking-wide">Restrictions</p>
                            <flux:select wire:model="gender_restriction" label="Gender Restriction">
                                <option value="none">No Restriction</option>
                                <option value="male">Male Only</option>
                                <option value="female">Female Only</option>
                            </flux:select>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="flex items-center gap-2 cursor-pointer text-sm">
                                    <flux:checkbox wire:model="probation_restricted" />
                                    <span class="text-zinc-700 dark:text-zinc-300">Not available on Probation</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer text-sm">
                                    <flux:checkbox wire:model="notice_period_restricted" />
                                    <span class="text-zinc-700 dark:text-zinc-300">Not available on Notice Period</span>
                                </label>
                            </div>
                        </div>

                        {{-- ── Allocation & Control ──────────────────────────── --}}
                        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 space-y-3">
                            <p class="text-xs font-bold text-zinc-400 uppercase tracking-wide">Allocation & Control</p>

                            <flux:input wire:model="annual_allocation_days" type="number" step="0.5" min="0" max="365"
                                label="Annual Allocation (days)"
                                description="Set to auto-initialize every employee's balance for this year. Leave blank for manual/unlimited types." />

                            <label class="flex items-center gap-2 cursor-pointer text-sm">
                                <flux:checkbox wire:model="is_system_controlled" />
                                <div>
                                    <span class="text-zinc-700 dark:text-zinc-300 font-semibold">System Controlled</span>
                                    <p class="text-xs text-zinc-400 mt-0.5">HR cannot manually adjust this leave type. Used
                                        for Unauthorized Leave, LWP, and WFH.</p>
                                </div>
                            </label>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="$wire.set('showModal', false)"
                                class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">Cancel</button>
                            <flux:button type="submit" variant="primary">Save Leave Type</flux:button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    @endif
</flux:main>