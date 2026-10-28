<flux:main class="min-h-screen space-y-5 bg-[#F7F8FA] p-4 font-sans md:p-6 dark:bg-[#0B1220]">
    @php
        $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        $signed = fn ($v) => ($v > 0 ? '+' : '').$fmt($v);
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('time-off.leave-management') }}" wire:navigate class="text-xs font-semibold text-orange-600 hover:underline">← Leave Management</a>
            <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-[#101828] dark:text-white">{{ $employee->user?->name }}</h1>
            <p class="mt-1 text-sm text-[#667085] dark:text-zinc-400">
                {{ $employee->employee_id }} · {{ $employee->department?->name ?? 'No department' }} · {{ $employee->status?->label() }}
                · Policy: <span class="font-semibold">{{ $this->currentPolicy['policy']?->name ?? 'none (company default)' }}</span>
                <span class="text-xs">({{ $this->currentPolicy['source_label'] }})</span>
            </p>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <div class="w-40">
                <x-clean-select model="leaveYearId" label="Leave year" :options="$leaveYears->map(fn ($y) => ['value' => $y->id, 'label' => $y->label])->all()" />
            </div>
            @can('manage_leave_carry_forward')
                <flux:button icon="arrow-right-circle" wire:click="openAction('carry_forward')">Carry Forward</flux:button>
            @endcan
            @can('apply_leave_on_behalf')
                <flux:button icon="calendar-days" wire:click="openAction('apply')">Apply on behalf</flux:button>
            @endcan
            @can('override_leave_policy')
                <flux:button icon="user-circle" wire:click="openAction('override')">Add override</flux:button>
            @endcan
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900/40 dark:bg-emerald-900/20 dark:text-emerald-300">{{ session('success') }}</div>
    @endif
    @error('formTypeId')
        @if(! $action)
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-300">{{ $message }}</div>
        @endif
    @enderror
    @if(session('error'))
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900/40 dark:bg-rose-900/20 dark:text-rose-300">{{ session('error') }}</div>
    @endif
    @if($this->year->isClosed())
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-300">{{ $this->year->label }} is closed. Its balances can only change through an authorised historical correction.</div>
    @endif

    <div class="flex gap-1 border-b border-[#EAECF0] dark:border-white/10">
        @foreach(['balances' => 'Balances', 'history' => 'History', 'statement' => 'Month-wise Statement', 'carry_forward' => 'Carry Forward', 'requests' => 'Requests', 'encashments' => 'Encashments', 'overrides' => 'Overrides'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-semibold {{ $tab === $key ? 'border-orange-500 text-orange-600' : 'border-transparent text-[#667085] hover:text-[#101828]' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if($tab === 'balances')
        {{-- Leave V2, from the canonical balance service: Earned Leave is the one paid pool; history is never added to it. --}}
        @if($v2 = $this->categorySummary)
            @php
                $earned = $v2['earned'];
                $combined = $this->combinedStats;
                $figure = fn (string $label, $value, string $extra = '') => '<div><dt class="text-[11px] text-zinc-500 dark:text-zinc-400">'.e($label).'</dt><dd class="text-sm font-semibold tabular-nums text-zinc-900 dark:text-white '.$extra.'">'.e($value).'</dd></div>';
            @endphp
            <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3" data-v2-summary>
                <div class="ui-surface p-4 lg:col-span-2" data-v2-earned>
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">Earned Leave <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">shared paid pool · {{ $this->year->label }}{{ $v2['policy'] ? ' · '.$v2['policy'] : '' }}</span></p>
                    <dl class="mt-3 grid grid-cols-3 gap-3 sm:grid-cols-6">
                        {!! $figure('Opening', $fmt($earned['opening'])) !!}
                        {!! $figure('Carry forward', $fmt($earned['carry_forward'])) !!}
                        {!! $figure('Accrued', $fmt($earned['accrued'])) !!}
                        {!! $figure('Used', $fmt($earned['used'])) !!}
                        {!! $figure('Pending', $fmt($earned['pending'])) !!}
                        {!! $figure('Available', $fmt($earned['approved_available']), $earned['approved_available'] < 0 ? 'text-rose-600' : '') !!}
                    </dl>
                    @if(abs($earned['adjustment']) > 0.001)
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Includes HR adjustments of {{ $signed($earned['adjustment']) }} day(s).</p>
                    @endif
                    @if(! empty($earned['usage_by_reason']))
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Used by reason: {{ collect($earned['usage_by_reason'])->map(fn ($r) => $r['name'].' '.$fmt($r['used']))->join(' · ') }}</p>
                    @endif
                    @php
                        $plan = $this->accrualPlan;
                        $suggested = $plan['suggested_start'] ? \Illuminate\Support\Carbon::parse($plan['suggested_start'])->format('F Y') : null;
                        $planColor = ['READY' => 'emerald', 'NO CHANGE' => 'zinc', 'NEEDS REVIEW' => 'amber', 'BLOCKED' => 'rose'][$plan['status']] ?? 'zinc';
                    @endphp
                    <div class="mt-3 border-t border-zinc-100 pt-3 dark:border-white/5" data-accrual-start>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm text-zinc-700 dark:text-zinc-200">
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">Monthly Accrual Starts From</span>
                                <span class="ml-1 font-semibold text-zinc-900 dark:text-white" data-accrual-start-saved>{{ $this->accrualStartsOn?->format('F Y') ?? 'Not set' }}</span>
                                @if(! $this->accrualStartsOn && $suggested)
                                    <span class="ml-2 text-xs text-amber-700 dark:text-amber-300" data-accrual-start-suggested>Suggested: {{ $suggested }} — not saved until you confirm it</span>
                                @endif
                            </p>
                            @if($this->canChangeAccrualStart && ! $editingAccrualStart)
                                <flux:button size="xs" wire:click="openAccrualStart">{{ $this->accrualStartsOn ? 'Change' : 'Set' }}</flux:button>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Existing opening balances remain unchanged. Automatic Earned Leave accrual begins only after completed months from this date.</p>
                        @if(! $this->employee->joining_date)
                            <p class="mt-2 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-800 dark:bg-rose-500/10 dark:text-rose-200" data-accrual-joining-missing>No joining date is recorded. Accrual stays blocked until HR records the joining date on the employee profile.</p>
                        @endif
                        <p class="mt-2 flex flex-wrap items-center gap-2 text-xs text-zinc-600 dark:text-zinc-300" data-accrual-status="{{ $plan['status'] }}">
                            <x-pulse.badge :color="$planColor">{{ $plan['status'] }}</x-pulse.badge>
                            <span>{{ $plan['reason'] }}</span>
                        </p>
                        @if($editingAccrualStart)
                            <form wire:submit="saveAccrualStart" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3" data-accrual-start-form>
                                <flux:select wire:model="accrualStartMonth" label="Starts from (month)">
                                    <flux:select.option value="">Choose a month…</flux:select.option>
                                    @foreach($this->accrualStartOptions as $value => $label)
                                        <flux:select.option value="{{ $value }}">{{ $label }}{{ $plan['suggested_start'] && str_starts_with($plan['suggested_start'], $value) ? ' (suggested)' : '' }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <div class="sm:col-span-2"><flux:input wire:model="accrualStartReason" label="Reason" placeholder="Why accrual starts from this month" /></div>
                                <div class="flex justify-end gap-2 sm:col-span-3">
                                    <flux:button type="button" variant="ghost" size="sm" wire:click="$set('editingAccrualStart', false)">Cancel</flux:button>
                                    <flux:button type="submit" variant="primary" size="sm">Save</flux:button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
                <div class="ui-surface p-4" data-v2-unpaid>
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">Unpaid Leave <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">no balance</span></p>
                    <dl class="mt-3 grid grid-cols-2 gap-3">
                        {!! $figure('Taken', $fmt($v2['unpaid']['used'])) !!}
                        {!! $figure('Pending', $fmt($v2['unpaid']['pending'])) !!}
                    </dl>
                </div>
                @foreach(['comp_off' => 'Comp Off', 'special' => 'Special Leave'] as $key => $label)
                    @foreach($v2[$key]['subtypes'] as $type)
                        <div class="ui-surface p-4" data-v2-{{ $key }}="{{ $type['id'] }}">
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $type['name'] }} <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ $label }} · own balance</span></p>
                            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                @if($key === 'special')
                                    {!! $figure('Entitlement', $type['annual_entitlement'] === null ? '—' : $fmt($type['annual_entitlement'])) !!}
                                @else
                                    {!! $figure('Opening', $fmt($type['opening'])) !!}
                                    {!! $figure('Credited', $fmt($type['accrued'] + max(0, $type['adjustment']))) !!}
                                @endif
                                {!! $figure('Used', $fmt($type['used'])) !!}
                                {!! $figure('Pending', $fmt($type['pending'])) !!}
                                {!! $figure('Available', $type['unlimited'] ? 'Unlimited' : $fmt($type['approved_available'])) !!}
                            </dl>
                        </div>
                    @endforeach
                @endforeach
                <div class="ui-surface p-4" data-v2-combined>
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">Combined Leave <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ $combined['requests'] }} {{ Str::plural('request', $combined['requests']) }}</span></p>
                    <dl class="mt-3 grid grid-cols-3 gap-3">
                        {!! $figure('Paid units', $fmt($combined['paid_units'])) !!}
                        {!! $figure('Unpaid units', $fmt($combined['unpaid_units'])) !!}
                        {!! $figure('Half-day units', $fmt($combined['half_day_units'])) !!}
                    </dl>
                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Approved allocations only.@if($combined['by_status']) {{ collect($combined['by_status'])->map(fn ($n, $s) => \App\Services\Leave\LeaveRequestPresenter::status($s)[0].' '.$n)->join(' · ') }}@endif</p>
                </div>
                @if(! empty($v2['historical']))
                    <div class="ui-surface p-4 lg:col-span-3" data-v2-historical>
                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">Historical balances <x-pulse.badge>Historical</x-pulse.badge></p>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Kept for the record; never added to Earned Leave or any total above.</p>
                        <ul class="mt-2 divide-y divide-zinc-100 text-sm dark:divide-white/5">
                            @foreach($v2['historical'] as $type)
                                <li class="flex flex-wrap justify-between gap-2 py-1.5"><span>{{ $type['name'] }}</span><span class="tabular-nums text-zinc-600 dark:text-zinc-300">credited {{ $fmt($type['credits']) }} · used {{ $fmt($type['used']) }} · left {{ $fmt($type['approved_available']) }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
            <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Every balance row (ledger figures)</p>
        @endif
        <div class="ui-surface overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-[#F9FAFB] text-left text-[10px] font-bold uppercase tracking-wider text-[#667085] dark:bg-white/5">
                    <tr>
                        <th class="px-3 py-2">Type</th>
                        <th class="px-3 py-2 text-right">Base</th><th class="px-3 py-2 text-right">Carry</th><th class="px-3 py-2 text-right">Add-On</th>
                        <th class="px-3 py-2 text-right">Accrued</th><th class="px-3 py-2 text-right">Adjustment</th><th class="px-3 py-2 text-right">Used</th>
                        <th class="px-3 py-2 text-right">Pending</th><th class="px-3 py-2 text-right">Expired</th><th class="px-3 py-2 text-right">Available</th>
                        <th class="px-3 py-2 text-right">To request</th><th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#EAECF0] dark:divide-white/5">
                    @forelse($this->balances as $b)
                        @php $s = $b['summary']; @endphp
                        <tr wire:key="bal-{{ $b['balance']->id }}">
                            <td class="px-3 py-2 font-semibold text-[#101828] dark:text-white">
                                {{ $b['leave_type']?->name }}
                                @if($s['ledger_status'] === 'needs_hr_review')<flux:badge size="sm" color="amber">Opening {{ $fmt($s['opening']) }} — review</flux:badge>@endif
                                @if(! $s['ledger_backed'])<flux:badge size="sm" color="zinc">Legacy</flux:badge>@endif
                            </td>
                            <td class="px-3 py-2 text-right">{{ $fmt($s['base']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($s['carry_forward']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($s['add_on']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($s['accrued']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $signed(round($s['adjustment_credit'] - $s['adjustment_debit'], 2)) }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($s['used']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($s['pending']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($s['expired']) }}</td>
                            <td class="px-3 py-2 text-right font-bold {{ $s['approved_available'] < 0 ? 'text-rose-600' : '' }}">{{ $fmt($s['approved_available']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($s['available_to_request']) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right">
                                {{-- Only real balances: a V2 reason (Casual, Sick…) or a historical type is never adjusted. --}}
                                @if($balanceTypes->contains('id', $b['leave_type']?->id))
                                    @can('add_leave_balance')<flux:button size="xs" wire:click="openAction('add', {{ $b['leave_type']?->id }})">Add</flux:button>@endcan
                                    @can('deduct_leave_balance')<flux:button size="xs" wire:click="openAction('deduct', {{ $b['leave_type']?->id }})">Deduct</flux:button>@endcan
                                    @can('correct_leave_balance')<flux:button size="xs" wire:click="openAction('correct', {{ $b['leave_type']?->id }})">Correct</flux:button>@endcan
                                @endif
                                @can('manage_leave_carry_forward')<flux:button size="xs" wire:click="openAction('carry_forward', {{ $b['leave_type']?->id }})">Carry Fwd</flux:button>@endcan
                                <flux:button size="xs" variant="ghost" wire:click="showTypeHistory({{ $b['leave_type']?->id }})">History</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="12" class="px-3 py-10 text-center text-sm text-[#98A2B3]">No leave balances in {{ $this->year->label }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if($tab === 'history')
        <div class="ui-surface p-4">
            <div class="mb-3 grid grid-cols-1 gap-3 md:grid-cols-5">
                <x-clean-select model="historyTypeId" label="Leave type"
                    :options="array_merge([['value' => '', 'label' => 'All types']], $leaveTypes->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->all())" />
                <flux:input type="month" wire:model.live="historyMonth" label="Month" />
                <x-clean-select model="historyEntryType" label="Entry type"
                    :options="array_merge([['value' => '', 'label' => 'All entries']], collect($entryTypes)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values()->all())" />
                <flux:input type="date" wire:model.live="historyFrom" label="From" />
                <flux:input type="date" wire:model.live="historyTo" label="To" />
            </div>
            <ol class="divide-y divide-[#EAECF0] dark:divide-white/5">
                @forelse($this->history as $h)
                    <li class="flex items-start justify-between gap-3 py-2 text-sm">
                        <div class="flex gap-3">
                            <span class="w-20 shrink-0 text-xs text-[#98A2B3]">{{ $h['date']->toImmutable()->userDate() }}</span>
                            <div>
                                <div class="font-semibold text-[#101828] dark:text-white">{{ $h['label'] }} <span class="font-normal text-[#98A2B3]">· {{ $h['leave_type'] }}</span></div>
                                @if($h['reason'])<div class="text-xs text-[#667085]">{{ $h['reason'] }}</div>@endif
                                @if($h['expires_on'])<div class="text-[11px] text-amber-600">Expires {{ $h['expires_on']->toImmutable()->userDate() }}</div>@endif
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="font-bold {{ $h['days'] < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $signed($h['days']) }}</div>
                            <div class="text-[11px] text-[#98A2B3]">bal. {{ $fmt($h['running']) }}</div>
                            @can('correct_leave_balance')
                                @if(in_array($h['entry_type'], $reversibleTypes, true) && ! $h['reversal'] && ! in_array($h['id'], $this->reversedEntryIds, true))
                                    <button type="button" wire:click="openReverseEntry({{ $h['id'] }})" class="mt-1 text-[11px] font-semibold text-rose-600 hover:underline">Reverse</button>
                                @endif
                            @endcan
                        </div>
                    </li>
                @empty
                    <li class="py-8 text-center text-sm text-[#98A2B3]">No ledger movements match.</li>
                @endforelse
            </ol>
        </div>
    @endif

    @if($tab === 'statement')
        <div class="ui-surface p-4">
            <div class="mb-3 w-64">
                <x-clean-select model="statementTypeId" label="Leave type"
                    :options="$this->balances->map(fn ($b) => ['value' => $b['leave_type']?->id, 'label' => $b['leave_type']?->name])->values()->all()" />
            </div>
            @include('livewire.time-off.partials.monthly-statement', ['months' => $this->statement, 'fmt' => $fmt])
        </div>
    @endif

    @if($tab === 'carry_forward')
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <p class="text-sm text-[#667085]">Carry forward is recorded as its own transaction (from year → to year), posted to the new year as a separate Carry Forward bucket with the policy expiry — never as a manual adjustment.</p>
                @can('manage_leave_carry_forward')
                    <flux:button size="sm" variant="primary" icon="arrow-right-circle" wire:click="openAction('carry_forward')">Enter carry forward</flux:button>
                @endcan
            </div>
            <div class="ui-surface overflow-x-auto">
                <table class="min-w-full text-xs">
                    <thead class="bg-[#F9FAFB] text-left text-[10px] font-bold uppercase tracking-wider text-[#667085] dark:bg-white/5">
                        <tr>
                            <th class="px-3 py-2">From → To</th><th class="px-3 py-2">Leave type</th>
                            <th class="px-3 py-2 text-right">Eligible</th><th class="px-3 py-2 text-right">Carried</th><th class="px-3 py-2 text-right">Reversed</th>
                            <th class="px-3 py-2">Status</th><th class="px-3 py-2">Reason</th><th class="px-3 py-2">By</th><th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#EAECF0] dark:divide-white/5">
                        @forelse($this->carryHistory as $tx)
                            <tr wire:key="cf-{{ $tx->id }}">
                                <td class="px-3 py-2 font-semibold">{{ $tx->previousLeaveYear?->label }} → {{ $tx->currentLeaveYear?->label }}</td>
                                <td class="px-3 py-2">{{ $tx->leaveType?->name }}</td>
                                <td class="px-3 py-2 text-right">{{ $tx->eligible_days === null ? 'HR stated' : $fmt($tx->eligible_days) }}</td>
                                <td class="px-3 py-2 text-right font-bold">{{ $fmt($tx->applied_days) }}</td>
                                <td class="px-3 py-2 text-right">{{ $tx->reversed_days > 0 ? $fmt($tx->reversed_days) : '—' }}</td>
                                <td class="px-3 py-2"><flux:badge size="sm">{{ str_replace('_', ' ', $tx->status) }}</flux:badge></td>
                                <td class="max-w-xs px-3 py-2 text-[#667085]">{{ $tx->reason ?? '—' }}@if($tx->reversal_reason)<div class="text-rose-600">Reversed: {{ $tx->reversal_reason }}</div>@endif</td>
                                <td class="px-3 py-2">{{ $tx->appliedBy?->name ?? '—' }}<div class="text-[11px] text-[#98A2B3]">{{ $tx->applied_at?->toImmutable()->userDateTime() }}</div></td>
                                <td class="px-3 py-2 text-right">
                                    @if($tx->isApplied())
                                        @can('manage_leave_carry_forward')<flux:button size="xs" wire:click="startReverseCarryForward({{ $tx->id }})">Reverse</flux:button>@endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-3 py-8 text-center text-sm text-[#98A2B3]">No carry forward recorded for this employee.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="ui-surface p-4 text-xs">
                <div class="mb-2 font-bold text-[#101828] dark:text-white">Audit history</div>
                @forelse($this->carryAudit as $log)
                    <div class="flex flex-wrap gap-3 border-t border-[#EAECF0] py-1.5 dark:border-white/5">
                        <span class="w-32 text-[#98A2B3]">{{ $log->created_at->toImmutable()->userDateTime() }}</span>
                        <span class="font-semibold">{{ str_replace(['leave.', '_'], ['', ' '], $log->action) }}</span>
                        <span>{{ $log->user?->name ?? 'System' }}</span>
                        <span class="text-[#667085]">
                            {{ $fmt($log->old_values['carried_forward_days'] ?? 0) }} → {{ $fmt($log->new_values['carried_forward_days'] ?? 0) }} day(s)
                            · {{ $log->new_values['previous_leave_year'] ?? '' }} → {{ $log->new_values['current_leave_year'] ?? '' }}
                        </span>
                        @if($log->reason)<span class="text-[#667085]">“{{ $log->reason }}”</span>@endif
                    </div>
                @empty
                    <div class="text-[#98A2B3]">No carry-forward audit entries.</div>
                @endforelse
            </div>
        </div>
    @endif

    @if($tab === 'requests')
        <div class="ui-surface overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-[#F9FAFB] text-left text-[10px] font-bold uppercase tracking-wider text-[#667085] dark:bg-white/5">
                    <tr><th class="px-3 py-2">Type</th><th class="px-3 py-2">Dates</th><th class="px-3 py-2 text-right">Days</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Applied by</th><th class="px-3 py-2">HR note</th><th class="px-3 py-2 text-right">HR actions</th></tr>
                </thead>
                <tbody class="divide-y divide-[#EAECF0] dark:divide-white/5">
                    @forelse($this->requests as $r)
                        <tr>
                            <td class="px-3 py-2">{{ $r->leaveType?->name }}</td>
                            <td class="px-3 py-2">{{ $r->start_date->toImmutable()->userDate() }}@if(! $r->end_date->eq($r->start_date)) – {{ $r->end_date->toImmutable()->userDate() }}@endif</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($r->days) }}</td>
                            <td class="px-3 py-2"><flux:badge size="sm">{{ str_replace('_', ' ', $r->status) }}</flux:badge></td>
                            <td class="px-3 py-2">{{ $r->applied_by_user_id ? 'HR (on behalf)' : 'Employee' }}</td>
                            <td class="px-3 py-2 text-[#667085]">{{ $r->hr_internal_note ?? '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right">
                                @can('manage_approved_leave')
                                    @if($r->status === 'approved')
                                        <flux:button size="xs" wire:click="openRequestAction('correct_leave', {{ $r->id }})">Correct</flux:button>
                                    @endif
                                    @if(in_array($r->status, ['pending', 'pending_hr', 'approved'], true))
                                        <flux:button size="xs" variant="danger" wire:click="openRequestAction('cancel_leave', {{ $r->id }})">Cancel</flux:button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-8 text-center text-sm text-[#98A2B3]">No requests in {{ $this->year->label }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if($tab === 'encashments')
        <div class="ui-surface overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-[#F9FAFB] text-left text-[10px] font-bold uppercase tracking-wider text-[#667085] dark:bg-white/5">
                    <tr><th class="px-3 py-2">Requested</th><th class="px-3 py-2">Type</th><th class="px-3 py-2 text-right">Days</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Approved by</th><th class="px-3 py-2">Finance</th><th class="px-3 py-2">Payout month</th></tr>
                </thead>
                <tbody class="divide-y divide-[#EAECF0] dark:divide-white/5">
                    @forelse($this->encashments as $enc)
                        <tr>
                            <td class="px-3 py-2">{{ $enc->created_at?->toImmutable()->userDate() }}</td>
                            <td class="px-3 py-2">{{ $enc->leaveType?->name }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($enc->requested_days) }}</td>
                            <td class="px-3 py-2"><flux:badge size="sm">{{ str_replace('_', ' ', $enc->status) }}</flux:badge></td>
                            <td class="px-3 py-2">{{ $enc->reviewer?->name ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $enc->financeReviewer?->name ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $enc->payout_month ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-8 text-center text-sm text-[#98A2B3]">No encashment requests.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if($tab === 'overrides')
        <div class="ui-surface overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-[#F9FAFB] text-left text-[10px] font-bold uppercase tracking-wider text-[#667085] dark:bg-white/5">
                    <tr><th class="px-3 py-2">Type</th><th class="px-3 py-2">Override</th><th class="px-3 py-2">Applies to</th><th class="px-3 py-2">Reason</th><th class="px-3 py-2">By</th><th class="px-3 py-2">Status</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-[#EAECF0] dark:divide-white/5">
                    @forelse($this->overrides as $o)
                        <tr>
                            <td class="px-3 py-2">{{ $o->leaveType?->name }}</td>
                            <td class="px-3 py-2">{{ $o->mode === 'set' ? 'Set to '.$fmt($o->days) : $signed((float) $o->days) }} day(s)</td>
                            <td class="px-3 py-2">{{ $o->leaveYear?->label ?? 'From '.($o->effective_from?->toImmutable()->userDate() ?? 'start').' on' }}</td>
                            <td class="px-3 py-2">{{ $o->reason }}</td>
                            <td class="px-3 py-2">{{ $o->creator?->name }} · {{ $o->created_at->toImmutable()->userDate() }}</td>
                            <td class="px-3 py-2">{{ $o->revoked_at ? 'Revoked '.$o->revoked_at->toImmutable()->userDate() : 'Active' }}</td>
                            <td class="px-3 py-2 text-right">
                                @if(! $o->revoked_at)
                                    @can('override_leave_policy')
                                        <flux:button size="xs" wire:click="revokeOverride({{ $o->id }})" wire:confirm="Revoke this override? The entitlement is recalculated.">Revoke</flux:button>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-8 text-center text-sm text-[#98A2B3]">No employee-specific overrides.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- Action dialog --}}
    @if($action)
        <x-leave.overlay max="max-w-lg">
            <form wire:submit="submitAction" class="space-y-4">
                <flux:heading size="lg">
                    {{ ['add' => 'Add Leave', 'deduct' => 'Deduct Leave', 'correct' => 'Correct Balance', 'override' => 'Employee Override', 'apply' => 'Apply Leave on Behalf', 'carry_forward' => 'Carry Forward', 'cancel_leave' => 'Cancel Leave', 'correct_leave' => 'Correct Approved Leave', 'reverse_entry' => 'Reverse Ledger Entry'][$action] }}
                    <span class="font-normal text-[#98A2B3]">· {{ $employee->user?->name }} · {{ $this->year->label }}</span>
                </flux:heading>

                @error('form')<div class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ $message }}</div>@enderror

                @if(! in_array($action, ['cancel_leave', 'reverse_entry'], true))
                    <flux:select wire:model.live="formTypeId" label="Leave type">
                        @foreach(in_array($action, ['add', 'deduct', 'correct'], true) ? $balanceTypes : $leaveTypes as $t)<flux:select.option value="{{ $t->id }}">{{ $t->name }}</flux:select.option>@endforeach
                    </flux:select>
                @endif

                @if($action === 'add')
                    <flux:select wire:model="addOnType" label="Kind">
                        @foreach($addOnTypes as $t)<flux:select.option value="{{ $t }}">{{ ucwords(str_replace('_', ' ', $t)) }}</flux:select.option>@endforeach
                    </flux:select>
                    <flux:input type="number" step="0.5" min="0.5" wire:model.live.debounce.300ms="days" label="Days" />
                    <div class="grid grid-cols-2 gap-3">
                        <flux:input type="date" wire:model="effectiveDate" label="Effective date" />
                        <flux:input type="date" wire:model="expiresOn" label="Expires on (optional)" />
                    </div>
                @elseif($action === 'deduct')
                    <div class="grid grid-cols-2 gap-3">
                        <flux:input type="number" step="0.5" min="0.5" wire:model.live.debounce.300ms="days" label="Days to deduct" />
                        <flux:input type="date" wire:model="effectiveDate" label="Effective date" />
                    </div>
                @elseif($action === 'correct')
                    <div class="grid grid-cols-2 gap-3">
                        <flux:input type="number" step="0.5" min="0" wire:model.live.debounce.300ms="targetBalance" label="Correct available balance" />
                        <flux:input type="date" wire:model="effectiveDate" label="Effective date" />
                    </div>
                @elseif($action === 'cancel_leave' && $targetRequest)
                    <div class="rounded-lg bg-[#F9FAFB] p-3 text-sm dark:bg-white/5">
                        <div class="font-semibold">{{ $targetRequest->leaveType?->name }} · {{ $targetRequest->start_date->toImmutable()->userDate() }}@if(! $targetRequest->end_date->eq($targetRequest->start_date)) – {{ $targetRequest->end_date->toImmutable()->userDate() }}@endif · {{ $fmt($targetRequest->days) }} day(s)</div>
                        <div class="text-xs text-[#667085]">Status: {{ str_replace('_', ' ', $targetRequest->status) }}. Cancelling returns any paid days to the balance they came from; the request and this cancellation both stay in the history.</div>
                    </div>
                @elseif($action === 'correct_leave' && $targetRequest)
                    <div class="text-xs text-[#667085]">Currently {{ $targetRequest->start_date->toImmutable()->userDate() }}@if(! $targetRequest->end_date->eq($targetRequest->start_date)) – {{ $targetRequest->end_date->toImmutable()->userDate() }}@endif ({{ $fmt($targetRequest->days) }} day(s)). The old days are returned and the corrected days deducted, under the same leave rules.</div>
                    <div class="grid grid-cols-2 gap-3">
                        <flux:input type="date" wire:model="startDate" label="From" />
                        <flux:input type="date" wire:model="endDate" label="To" :disabled="$isHalfDay" />
                    </div>
                    <flux:checkbox wire:model.live="isHalfDay" label="Half day" />
                @elseif($action === 'reverse_entry' && $targetEntry)
                    <div class="rounded-lg bg-[#F9FAFB] p-3 text-sm dark:bg-white/5">
                        <div class="font-semibold">{{ $entryTypes[$targetEntry->entry_type] ?? str_replace('_', ' ', $targetEntry->entry_type) }} · {{ $targetEntry->leaveType?->name }} · {{ $signed((float) $targetEntry->days) }} day(s)</div>
                        <div class="text-xs text-[#667085]">{{ $targetEntry->effective_date?->toImmutable()->userDate() }}@if($targetEntry->reason) · {{ $targetEntry->reason }}@endif</div>
                        <div class="mt-1 text-xs text-[#667085]">A reversing entry is added; nothing is deleted. A credit already used becomes a visible shortfall.</div>
                    </div>
                @elseif($action === 'override')
                    <flux:select wire:model="overrideMode" label="Override">
                        <flux:select.option value="add">Add to the policy entitlement (e.g. +5)</flux:select.option>
                        <flux:select.option value="set">Replace the entitlement with a fixed figure</flux:select.option>
                    </flux:select>
                    <flux:input type="number" step="0.5" wire:model="days" label="Days" />
                    <flux:checkbox wire:model="overrideThisYearOnly" label="Only {{ $this->year->label }} (otherwise ongoing from this year)" />
                @elseif($action === 'carry_forward')
                    <div class="grid grid-cols-2 gap-3">
                        <flux:select wire:model.live="cfFromYearId" label="From year">
                            @foreach($leaveYears as $y)<flux:select.option value="{{ $y->id }}">{{ $y->label }}</flux:select.option>@endforeach
                        </flux:select>
                        <flux:select wire:model.live="cfToYearId" label="To year">
                            @foreach($leaveYears as $y)<flux:select.option value="{{ $y->id }}">{{ $y->label }}</flux:select.option>@endforeach
                        </flux:select>
                    </div>
                    @error('cfToYearId')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    @php $cf = $this->carryInfo; @endphp
                    @if($cf)
                        <div class="space-y-1 rounded-lg bg-[#F9FAFB] p-3 text-xs dark:bg-white/5">
                            <div class="grid grid-cols-3 gap-2 text-center">
                                <div><div class="text-[10px] font-bold uppercase text-[#98A2B3]">Closing balance</div><div class="text-base font-bold">{{ $fmt($cf['closing_balance']) }}</div></div>
                                <div><div class="text-[10px] font-bold uppercase text-[#98A2B3]">Eligible</div><div class="text-base font-bold text-emerald-600">{{ $cf['eligible'] === null ? '—' : $fmt($cf['eligible']) }}</div></div>
                                <div><div class="text-[10px] font-bold uppercase text-[#98A2B3]">Max allowed</div><div class="text-base font-bold">{{ $cf['max_allowed'] === null ? 'No cap' : $fmt($cf['max_allowed']) }}</div></div>
                            </div>
                            @if($cf['source_found'])
                                <div class="text-[#667085]">Previous year: allocated {{ $fmt($cf['allocated']) }}, used {{ $cf['used'] === null ? 'unknown' : $fmt($cf['used']) }}, encashed {{ $cf['encashed'] === null ? 'unknown' : $fmt($cf['encashed']) }}@if($cf['cap'] !== null); policy cap {{ $fmt($cf['cap']) }}@endif.</div>
                            @endif
                            @if($cf['already_applied'] > 0)
                                <div class="text-amber-700">Already carried: {{ $fmt($cf['already_applied']) }} day(s). Saving replaces that figure; it does not add to it.</div>
                            @endif
                            @if($cf['message'])<div class="{{ $cf['carryable'] && ! $cf['to_year_closed'] ? 'text-[#667085]' : 'text-rose-600' }}">{{ $cf['message'] }}</div>@endif
                        </div>
                    @endif
                    <flux:input type="number" step="0.5" min="0" wire:model="carryDays" label="Carry forward days" />
                    @error('carryDays')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                @elseif($action === 'apply')
                    <div class="grid grid-cols-2 gap-3">
                        <flux:input type="date" wire:model="startDate" label="From" />
                        <flux:input type="date" wire:model="endDate" label="To" :disabled="$isHalfDay" />
                    </div>
                    <div class="flex flex-wrap items-center gap-4">
                        <flux:checkbox wire:model.live="isHalfDay" label="Half day" />
                        @if($isHalfDay)
                            <flux:select wire:model="halfDayPeriod" class="w-40">
                                <flux:select.option value="first_half">AM (first half)</flux:select.option>
                                <flux:select.option value="second_half">PM (second half)</flux:select.option>
                            </flux:select>
                        @endif
                        <flux:select wire:model="paymentStatus" class="w-32">
                            <flux:select.option value="paid">Paid</flux:select.option>
                            <flux:select.option value="unpaid">Unpaid</flux:select.option>
                        </flux:select>
                    </div>
                    <flux:input type="file" wire:model="attachment" label="Attachment (optional, PDF/JPG/PNG ≤ 5 MB)" />
                    @can('record_approved_leave')
                        <flux:checkbox wire:model="recordApproved" label="Record as already approved (the leave rules and balance check still apply)" />
                    @endcan
                @endif

                @if(in_array($action, ['add', 'deduct', 'correct', 'cancel_leave'], true))
                    <flux:input type="file" wire:model="attachment" label="Supporting document (optional, PDF/JPG/PNG ≤ 5 MB, kept private)" />
                    @error('attachment')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                @endif

                @if(in_array($action, ['add', 'deduct', 'correct'], true) && $this->currentAvailable !== null)
                    @php
                        $current = $this->currentAvailable;
                        $change = match ($action) { 'add' => (float) ($days ?: 0), 'deduct' => -(float) ($days ?: 0), default => round((float) ($targetBalance ?: $current) - $current, 2) };
                    @endphp
                    <div class="grid grid-cols-3 gap-2 rounded-lg bg-[#F9FAFB] p-3 text-center text-sm dark:bg-white/5">
                        <div><div class="text-[10px] font-bold uppercase text-[#98A2B3]">Current</div><div class="font-bold">{{ $fmt($current) }}</div></div>
                        <div><div class="text-[10px] font-bold uppercase text-[#98A2B3]">Change</div><div class="font-bold {{ $change < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $signed($change) }}</div></div>
                        <div><div class="text-[10px] font-bold uppercase text-[#98A2B3]">New</div><div class="font-bold">{{ $fmt(round($current + $change, 2)) }}</div></div>
                    </div>
                @endif

                <flux:textarea wire:model="reason" rows="2" label="Reason (required{{ in_array($action, ['override', 'carry_forward'], true) ? ', recorded in the audit history' : ', shown to the employee' }})" />
                @error('reason')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                @if(! in_array($action, ['override', 'carry_forward'], true))
                    <flux:textarea wire:model="internalNote" rows="2" label="HR internal note (never shown to the employee)" />
                    <flux:checkbox wire:model="notifyEmployee" label="Notify the employee" />
                @endif

                @if(in_array($action, \App\Livewire\TimeOff\EmployeeLeaveDetail::HIGH_IMPACT, true))
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-500/30 dark:bg-amber-500/10">
                        <flux:checkbox wire:model="confirmed" label="I confirm this change to {{ $employee->user?->name }}'s leave. It is recorded in the audit log with my name and reason." />
                        @error('confirmed')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="closeAction">Close</flux:button>
                    <flux:button type="submit" :variant="in_array($action, ['cancel_leave', 'reverse_entry'], true) ? 'danger' : 'primary'" wire:loading.attr="disabled">
                        {{ ['cancel_leave' => 'Cancel leave', 'correct_leave' => 'Save correction', 'reverse_entry' => 'Reverse entry'][$action] ?? 'Save' }}
                    </flux:button>
                </div>
            </form>
        </x-leave.overlay>
    @endif
    @if($reverseTxId)
        <x-leave.overlay max="max-w-md">
            <form wire:submit="reverseCarryForward" class="space-y-4">
                <flux:heading size="lg">Reverse carry forward</flux:heading>
                <flux:text>The carried days are taken back out of the new year. The original entry and this reversal both stay in the history.</flux:text>
                <flux:textarea wire:model="reverseReason" rows="2" label="Reason (required)" />
                @error('reverseReason')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('reverseTxId', null)">Cancel</flux:button>
                    <flux:button type="submit" variant="danger">Reverse</flux:button>
                </div>
            </form>
        </x-leave.overlay>
    @endif
</flux:main>
