@php
    use App\Services\Leave\LeaveCategory;
    $fmt = fn ($v) => $v === null || $v === '' ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $cap = $this->capSummary;
    $card = 'rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900';
    $cell = 'w-full rounded-md border border-zinc-200 bg-white px-2 py-1 text-xs dark:border-white/10 dark:bg-zinc-900';
@endphp

<flux:main>
    <div class="mx-auto w-full max-w-[1400px] space-y-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="pulse-page-title">Leave Policies</h1>
                <p class="text-sm text-zinc-500">One Earned Leave balance, the reasons that draw on it, other leave types and where each policy applies.</p>
            </div>
            <flux:button wire:click="newPolicy" icon="plus" variant="primary">New policy</flux:button>
        </div>

        @unless(\App\Services\Leave\LeavePolicyEngine::enabled())
            <div class="flex gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" role="status" data-engine-off>
                <flux:icon.information-circle class="mt-0.5 size-4 shrink-0" />
                <span>The Leave V2 policy engine is off. You can prepare policies and assignments here; employees keep the current leave rules until it is switched on.</span>
            </div>
        @endunless

        <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[16rem_minmax(0,1fr)]">
            {{-- Policy list --}}
            <nav class="{{ $card }} !p-2" aria-label="Leave policies">
                @forelse($this->policies as $p)
                    <button type="button" wire:click="selectPolicy({{ $p->id }})" wire:key="pol-{{ $p->id }}"
                        @class(['flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm',
                            'bg-orange-50 font-semibold text-orange-700 dark:bg-orange-500/10 dark:text-orange-300' => $policyId === $p->id,
                            'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-white/5' => $policyId !== $p->id])>
                        <span class="truncate">{{ $p->name }}</span>
                        @unless($p->is_active)<span class="text-[10px] font-semibold uppercase text-zinc-400">Inactive</span>@endunless
                    </button>
                @empty
                    <p class="p-3 text-sm text-zinc-500">No policies yet.</p>
                @endforelse
            </nav>

            <div class="min-w-0 space-y-5">
                {{-- Policy --}}
                <section class="{{ $card }}" data-policy-form>
                    <h2 class="mb-4 text-base font-semibold text-zinc-900 dark:text-white">{{ $policyId ? 'Policy' : 'New policy' }}</h2>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <flux:input wire:model="form.name" label="Policy name" placeholder="NEXCORE Standard Leave Policy" />
                        <flux:input type="number" step="0.5" min="0" wire:model.live.debounce.400ms="form.annual_paid_leave_cap" label="Annual paid leave cap (days)" placeholder="12" />
                        <flux:select wire:model.live="form.policy_year_type" label="Policy year">
                            <option value="jul_jun">Jul – Jun</option>
                            <option value="jan_dec">Jan – Dec</option>
                            <option value="apr_mar">Apr – Mar</option>
                            <option value="anniversary">Employee anniversary</option>
                            <option value="custom">Custom start month</option>
                        </flux:select>
                        @if(($form['policy_year_type'] ?? '') === 'custom')
                            <flux:input type="number" min="1" max="12" wire:model="form.policy_year_start_month" label="Start month (1–12)" />
                        @endif
                        <flux:input type="date" wire:model="form.effective_from" label="Effective from" />
                        <flux:input type="date" wire:model="form.effective_to" label="Effective to (optional)" />
                        <flux:select wire:model="form.earned_leave_type_id" label="Earned Leave balance" data-earned-type-select>
                            <option value="">Not set</option>
                            @foreach($this->earnedTypes as $t)
                                <option value="{{ $t->id }}">{{ $t->name }}{{ $t->code ? ' ('.$t->code.')' : '' }}</option>
                            @endforeach
                        </flux:select>
                    </div>
                    <p class="mt-2 text-xs text-zinc-500">The leave type that holds the one shared Earned Leave balance. Every other Earned Leave type becomes a reason that draws on it.</p>
                    @if(($form['policy_year_type'] ?? 'jul_jun') !== 'jul_jun')
                        <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                            Balances are kept per company leave year (1 Jul – 30 Jun). Other policy years are stored but do not change balance periods yet.
                        </p>
                    @endif
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <flux:checkbox wire:model="form.is_active" label="Active" />
                        <flux:button wire:click="savePolicy" variant="primary">Save policy</flux:button>
                    </div>
                    @error('form.annual_paid_leave_cap')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                    @error('form.name')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                    @error('form.earned_leave_type_id')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                </section>

                @if($policyId)
                    @error('rules')<p class="text-sm text-rose-600" role="alert">{{ $message }}</p>@enderror

                    {{-- Earned Leave: the one shared balance --}}
                    <section class="{{ $card }}" data-earned-policy>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Earned Leave</h2>
                                <p class="text-xs text-zinc-500">One paid leave balance. Every reason below draws on it.</p>
                            </div>
                            <flux:button wire:click="saveRules" variant="primary" size="sm" :disabled="$cap['over'] ?? false">Save rules</flux:button>
                        </div>

                        @if($this->poolType === null)
                            <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">Choose the Earned Leave balance in the policy above and save the policy.</p>
                        @else
                            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                <flux:input type="number" step="0.5" min="0" wire:model.live.debounce.400ms="earned.fixed_days" label="Annual entitlement (days)" placeholder="12" />
                                <flux:select wire:model="earned.accrual_method" label="Accrual">
                                    @foreach(['monthly' => 'Monthly', 'annual_upfront' => 'Annual upfront', 'quarterly' => 'Quarterly', 'half_yearly' => 'Half-yearly', 'one_time' => 'One-time', 'manual' => 'Manual (HR)', 'unlimited' => 'Unlimited'] as $v => $l)
                                        <option value="{{ $v }}">{{ $l }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:input type="number" step="0.25" min="0" wire:model="earned.accrual_amount" label="Days per period" placeholder="1" />
                                <div class="flex items-end pb-2"><flux:checkbox wire:model="earned.carry_forward_enabled" label="Carry forward" /></div>
                                <flux:input type="number" step="0.5" min="0" wire:model="earned.carry_forward_max_days" label="Maximum carry forward" placeholder="—" />
                                <flux:select wire:model="earned.excess_handling" label="If the balance is short">
                                    <option value="block">Block the request</option>
                                    <option value="convert_to_lwp">Convert the excess to LWP</option>
                                </flux:select>
                            </div>
                            @if($cap)
                                <div class="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-zinc-50 px-4 py-3 text-sm dark:bg-white/5" data-cap-summary>
                                    <span class="text-zinc-600 dark:text-zinc-300">{{ $this->poolType->name }}</span>
                                    <span @class(['font-semibold tabular-nums', 'text-rose-600' => $cap['over'], 'text-emerald-600' => ! $cap['over'] && $cap['remaining'] === 0.0])>
                                        {{ $fmt($cap['allocated']) }} / {{ $cap['cap'] !== null ? $fmt($cap['cap']) : 'no cap' }}
                                    </span>
                                </div>
                                @if($cap['over'])
                                    <p class="mt-2 text-xs font-medium text-rose-600" role="alert">Allocations exceed the cap by {{ $fmt($cap['allocated'] - $cap['cap']) }} days. Saving is blocked until you reduce them or raise the cap.</p>
                                @endif
                            @endif
                        @endif
                    </section>

                    {{-- Allowed paid leave reasons: labels with validation, never balances --}}
                    @if($this->poolType !== null)
                        <section class="{{ $card }} !px-0" data-policy-reasons>
                            <div class="px-5">
                                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Allowed paid leave reasons</h2>
                                <p class="text-xs text-zinc-500">Casual, Sick, Privilege, Personal… Each draws on Earned Leave. Rules are optional; there is no allocation per reason.</p>
                            </div>
                            <div class="mt-3 overflow-x-auto">
                                <table class="w-full min-w-[760px] text-left text-xs">
                                    <thead class="border-y border-zinc-100 bg-zinc-50 text-[11px] uppercase tracking-wide text-zinc-500 dark:border-white/5 dark:bg-white/5">
                                        <tr>
                                            <th class="px-3 py-2 pl-5">Reason</th><th class="px-2 py-2">Allowed</th><th class="px-2 py-2">Attachment from (days)</th>
                                            <th class="px-2 py-2">Max consecutive</th><th class="px-2 py-2">Notice (days)</th><th class="px-2 py-2">Half day</th><th class="px-2 py-2 pr-5">HR after (days)</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                                        @forelse($this->reasonTypes as $type)
                                            @php $k = $type->id; @endphp
                                            <tr wire:key="reason-{{ $k }}" data-reason-row="{{ $type->code }}">
                                                <td class="px-3 py-2 pl-5 font-medium text-zinc-900 dark:text-white">{{ $type->name }}</td>
                                                <td class="px-2 py-2"><input type="checkbox" wire:model="reasons.{{ $k }}.include" class="accent-orange-500" aria-label="Allow {{ $type->name }}"></td>
                                                <td class="px-2 py-2"><input type="number" step="0.5" min="0.5" wire:model="reasons.{{ $k }}.attachment_after_days" class="{{ $cell }} w-16" placeholder="—" aria-label="{{ $type->name }} attachment from"></td>
                                                <td class="px-2 py-2"><input type="number" min="1" wire:model="reasons.{{ $k }}.max_consecutive_days" class="{{ $cell }} w-16" placeholder="—" aria-label="{{ $type->name }} max consecutive days"></td>
                                                <td class="px-2 py-2"><input type="number" min="0" wire:model="reasons.{{ $k }}.notice_days" class="{{ $cell }} w-16" placeholder="—" aria-label="{{ $type->name }} notice"></td>
                                                <td class="px-2 py-2"><input type="checkbox" wire:model="reasons.{{ $k }}.allow_half_day" class="accent-orange-500" aria-label="{{ $type->name }} half day"></td>
                                                <td class="px-2 py-2 pr-5"><input type="number" step="0.5" min="0.5" wire:model="reasons.{{ $k }}.hr_after_days" class="{{ $cell }} w-16" placeholder="Never" aria-label="{{ $type->name }} HR after"></td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="7" class="px-5 py-6 text-center text-sm text-zinc-500">No reasons yet. Add leave types in the Earned Leave category in Leave Settings (e.g. Casual, Sick, Personal).</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <p class="mt-3 px-5 text-[11px] text-zinc-500">Reporting manager is always the first approver. "HR after" adds HR as a second approver for requests of that many days or more.</p>
                        </section>
                    @endif

                    {{-- Combined Leave: one request, each date full/half, paid/unpaid --}}
                    <section class="{{ $card }}" data-combined-rules>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Combined Leave rules</h2>
                                <p class="text-xs text-zinc-500">One request over a date range; each working date Full or Half Day, Paid or Unpaid. Paid days use the reason's balance (Earned Leave for a reason).</p>
                            </div>
                            <flux:button wire:click="saveCombinedRules" variant="primary" size="sm">Save Combined Leave rules</flux:button>
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach([
                                'enabled' => 'Offer Combined Leave',
                                'allow_mixed' => 'Allow paid and unpaid in one request',
                                'allow_per_date_allocation' => 'Allow a different allocation per date',
                                'allow_half_days' => 'Allow half days',
                                'allow_half_paid' => 'Allow Half Day – Paid',
                                'allow_half_unpaid' => 'Allow Half Day – Unpaid',
                                'allow_sessions' => 'Ask for first / second half',
                                'count_weekly_offs' => 'Charge weekly offs (otherwise excluded)',
                                'count_holidays' => 'Charge holidays (otherwise excluded)',
                                'allow_per_date_reason' => 'Different reason per date (not yet used)',
                            ] as $key => $text)
                                <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                                    <input type="checkbox" wire:model="combined.{{ $key }}" class="rounded accent-orange-500" data-combined-flag="{{ $key }}"> {{ $text }}
                                </label>
                            @endforeach
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <flux:select wire:model="combined.excess_handling" label="If paid days exceed the balance">
                                <option value="block">Block — the employee changes days to unpaid</option>
                                <option value="convert_to_lwp">Propose converting the excess to unpaid</option>
                            </flux:select>
                            <flux:input type="number" min="1" wire:model="combined.max_range_days" label="Maximum date range (days)" placeholder="31" />
                            <flux:input type="number" min="1" wire:model="combined.max_consecutive_working_days" label="Maximum working days" placeholder="No limit" />
                        </div>
                        <div class="mt-4">
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Reasons allowed <span class="font-normal text-zinc-500">(none ticked = every reason the policy allows)</span></p>
                            <div class="mt-2 flex flex-wrap gap-x-5 gap-y-2">
                                @forelse($this->combinedReasonTypes as $type)
                                    <label wire:key="creason-{{ $type->id }}" class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                                        <input type="checkbox" value="{{ $type->id }}" wire:model="combined.reason_type_ids" class="rounded accent-orange-500"> {{ $type->name }}
                                    </label>
                                @empty
                                    <p class="text-xs text-zinc-500">No reasons yet — allow Earned Leave reasons above.</p>
                                @endforelse
                            </div>
                        </div>
                        @error('combined.max_range_days')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                        @error('combined.max_consecutive_working_days')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </section>

                    {{-- Other leave types with their own balance --}}
                    <section class="{{ $card }} !px-0" data-policy-rules>
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5">
                            <div>
                                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Other leave types</h2>
                                <p class="text-xs text-zinc-500">Special leave, Comp Off and unpaid leave keep their own rules and, where set, their own entitlement — never the Earned Leave pool.</p>
                            </div>
                            <flux:button wire:click="saveRules" variant="primary" size="sm" :disabled="$cap['over'] ?? false">Save rules</flux:button>
                        </div>
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full min-w-[1100px] text-left text-xs">
                                <thead class="border-y border-zinc-100 bg-zinc-50 text-[11px] uppercase tracking-wide text-zinc-500 dark:border-white/5 dark:bg-white/5">
                                    <tr>
                                        <th class="px-3 py-2 pl-5">Leave type</th><th class="px-2 py-2">In policy</th><th class="px-2 py-2">Annual days</th>
                                        <th class="px-2 py-2">Accrual</th><th class="px-2 py-2">Per period</th><th class="px-2 py-2">Carry fwd (max)</th>
                                        <th class="px-2 py-2">Max balance</th><th class="px-2 py-2">Excess</th><th class="px-2 py-2">Attach from</th>
                                        <th class="px-2 py-2">Notice</th><th class="px-2 py-2">Half day</th><th class="px-2 py-2 pr-5">HR after (days)</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                                    @forelse($this->otherTypes as $type)
                                        @php $k = $type->id; $cat = LeaveCategory::of($type); @endphp
                                        <tr wire:key="rule-{{ $k }}" data-rule-row="{{ $type->code }}">
                                            <td class="px-3 py-2 pl-5">
                                                <p class="font-medium text-zinc-900 dark:text-white">{{ $type->name }}</p>
                                                <p class="text-[11px] text-zinc-500">{{ LeaveCategory::label($cat) }}@if($type->counts_toward_annual_cap) · counts toward cap @endif</p>
                                            </td>
                                            <td class="px-2 py-2"><input type="checkbox" wire:model.live="rules.{{ $k }}.include" class="accent-orange-500" aria-label="Include {{ $type->name }}"></td>
                                            <td class="px-2 py-2"><input type="number" step="0.5" min="0" wire:model.live.debounce.400ms="rules.{{ $k }}.fixed_days" class="{{ $cell }} w-16" aria-label="{{ $type->name }} annual days"></td>
                                            <td class="px-2 py-2">
                                                <select wire:model="rules.{{ $k }}.accrual_method" class="{{ $cell }}" aria-label="{{ $type->name }} accrual">
                                                    @foreach(['annual_upfront' => 'Annual upfront', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Half-yearly', 'one_time' => 'One-time', 'manual' => 'Manual (HR)', 'unlimited' => 'Unlimited'] as $v => $l)
                                                        <option value="{{ $v }}">{{ $l }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td class="px-2 py-2"><input type="number" step="0.25" min="0" wire:model="rules.{{ $k }}.accrual_amount" class="{{ $cell }} w-16" aria-label="{{ $type->name }} accrual amount"></td>
                                            <td class="px-2 py-2">
                                                <div class="flex items-center gap-1">
                                                    <input type="checkbox" wire:model="rules.{{ $k }}.carry_forward_enabled" class="accent-orange-500" aria-label="{{ $type->name }} carry forward">
                                                    <input type="number" step="0.5" min="0" wire:model="rules.{{ $k }}.carry_forward_max_days" class="{{ $cell }} w-14" aria-label="{{ $type->name }} carry forward max">
                                                </div>
                                            </td>
                                            <td class="px-2 py-2"><input type="number" step="0.5" min="0" wire:model="rules.{{ $k }}.max_balance" class="{{ $cell }} w-16"></td>
                                            <td class="px-2 py-2">
                                                <select wire:model="rules.{{ $k }}.excess_handling" class="{{ $cell }}" aria-label="{{ $type->name }} excess">
                                                    <option value="block">Block</option>
                                                    <option value="convert_to_lwp">Convert to LWP</option>
                                                </select>
                                            </td>
                                            <td class="px-2 py-2"><input type="number" step="0.5" min="0.5" wire:model="rules.{{ $k }}.attachment_after_days" class="{{ $cell }} w-14" placeholder="—"></td>
                                            <td class="px-2 py-2"><input type="number" min="0" wire:model="rules.{{ $k }}.notice_days" class="{{ $cell }} w-14" placeholder="—"></td>
                                            <td class="px-2 py-2"><input type="checkbox" wire:model="rules.{{ $k }}.allow_half_day" class="accent-orange-500"></td>
                                            <td class="px-2 py-2 pr-5"><input type="number" step="0.5" min="0.5" wire:model="rules.{{ $k }}.hr_after_days" class="{{ $cell }} w-16" placeholder="Never"></td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="12" class="px-5 py-6 text-center text-sm text-zinc-500">No other leave types are categorised yet.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>

                    {{-- Assignments --}}
                    <section class="{{ $card }}" data-policy-assignments>
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Where this policy applies</h2>
                        <p class="mt-1 text-xs text-zinc-500">Employee override takes precedence over a department policy, which takes precedence over the company policy.</p>

                        <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
                            <flux:select wire:model.live="assign.scope" label="Applies to">
                                <option value="company">Whole company</option>
                                <option value="department">Department</option>
                                <option value="employee">One employee (override)</option>
                            </flux:select>
                            @if($assign['scope'] === 'department')
                                <flux:select wire:model="assign.scope_id" label="Department">
                                    <option value="">Choose…</option>
                                    @foreach($this->departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                                </flux:select>
                            @elseif($assign['scope'] === 'employee')
                                <flux:select wire:model="assign.scope_id" label="Employee">
                                    <option value="">Choose…</option>
                                    @foreach($this->employees as $e)<option value="{{ $e->id }}">{{ $e->user?->name }} ({{ $e->employee_id }})</option>@endforeach
                                </flux:select>
                            @endif
                            <flux:input type="date" wire:model="assign.effective_from" label="From" />
                            <flux:input type="date" wire:model="assign.effective_to" label="To (optional)" />
                            <div class="flex items-end"><flux:button wire:click="assignPolicy" variant="primary" class="w-full">Assign</flux:button></div>
                        </div>
                        @error('assign')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                        @error('assign.effective_from')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror

                        <div class="mt-4 overflow-x-auto">
                            <table class="w-full min-w-[560px] text-left text-sm">
                                <thead class="border-y border-zinc-100 bg-zinc-50 text-[11px] uppercase tracking-wide text-zinc-500 dark:border-white/5 dark:bg-white/5">
                                    <tr><th class="px-3 py-2">Scope</th><th class="px-3 py-2">From</th><th class="px-3 py-2">To</th><th class="px-3 py-2">Status</th><th class="px-3 py-2"></th></tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                                    @forelse($this->assignments as $a)
                                        <tr wire:key="asg-{{ $a->id }}">
                                            <td class="px-3 py-2">{{ $this->assignmentLabel($a) }}</td>
                                            <td class="px-3 py-2 tabular-nums">{{ $a->effective_from?->toImmutable()->userDate() }}</td>
                                            <td class="px-3 py-2 tabular-nums">{{ $a->effective_to?->toImmutable()->userDate() ?? 'Open' }}</td>
                                            <td class="px-3 py-2">{{ $a->revoked_at ? 'Revoked' : 'Active' }}</td>
                                            <td class="px-3 py-2 text-right">
                                                @unless($a->revoked_at)
                                                    <flux:button size="xs" variant="ghost" wire:click="revoke({{ $a->id }})" wire:confirm="Revoke this assignment? Employees fall back to the next policy in precedence.">Revoke</flux:button>
                                                @endunless
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="px-3 py-6 text-center text-sm text-zinc-500">Not assigned yet.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif
            </div>
        </div>
    </div>
</flux:main>
