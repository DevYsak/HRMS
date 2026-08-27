<flux:main class="min-h-screen space-y-5 bg-[#F7F8FA] p-4 font-['Inter'] md:p-6 dark:bg-[#0B1220]">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold tracking-tight text-[#101828] dark:text-white">Leave Carry Forward</h1>
            <p class="mt-1 text-sm text-[#667085] dark:text-zinc-400">
                Bring unused leave from
                <span class="font-semibold text-[#101828] dark:text-zinc-200">{{ $leaveYears->firstWhere('id', $previousYearId)?->label ?? '—' }}</span>
                into
                <span class="font-semibold text-[#101828] dark:text-zinc-200">{{ $leaveYears->firstWhere('id', $currentYearId)?->label ?? '—' }}</span>.
                Nothing is applied until you say so.
            </p>
        </div>

        @can('manage_leave_carry_forward')
            {{-- Branched rather than a conditional attribute: a disabled button must
                 not carry a wire:confirm, or an empty selection still opens a dialog
                 asking to confirm nothing. --}}
            @if(! $hasEligibleRows)
                {{-- Grey, not primary. Flux's disabled state on a primary button is
                     only opacity-75, so it stays orange and still reads as the thing
                     to click. An inert control has to look inert. --}}
                <flux:tooltip content="No eligible leave to carry forward">
                    <flux:button variant="filled" icon="arrow-right-circle" disabled
                        class="cursor-not-allowed! text-[#98A2B3]! opacity-60">
                        Apply carry forward
                    </flux:button>
                </flux:tooltip>
            @elseif(! $hasDerivableRows)
                {{-- Rows exist but none can be calculated. Reporting this as
                     "already carried forward" would claim work was done that
                     nobody has decided yet. --}}
                <flux:tooltip content="Historical used days are not available — enter each approved amount individually">
                    <flux:button variant="filled" icon="pencil-square" disabled
                        class="cursor-not-allowed! text-[#98A2B3]! opacity-60">
                        Awaiting HR decision
                    </flux:button>
                </flux:tooltip>
            @elseif($outstandingDays <= 0)
                <flux:tooltip content="All eligible leave has already been carried forward">
                    <flux:button variant="filled" icon="check" disabled
                        class="cursor-not-allowed! text-[#98A2B3]! opacity-60">
                        All eligible leave carried forward
                    </flux:button>
                </flux:tooltip>
            @else
                <flux:button wire:click="applyAll"
                    wire:confirm="Apply carry forward for all eligible rows?

{{ $totals['employees'] }} employee(s) across {{ $totals['rows'] }} row(s)
{{ $outstandingDays }} day(s) to carry
{{ $leaveYears->firstWhere('id', $previousYearId)?->label ?? '—' }} → {{ $leaveYears->firstWhere('id', $currentYearId)?->label ?? '—' }}

Rows already carried at their full eligible amount are skipped."
                    variant="primary" icon="arrow-right-circle">
                    Apply carry forward
                </flux:button>
            @endif
        @endcan
    </div>

    {{-- Totals first: the question before applying anything is "how much leave
         is this about", and that should not require adding up a table. --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach([
            ['Employees',   $totals['employees'], 'users',            'Eligible for carry forward'],
            ['Eligible',    $totals['eligible'],  'calculator',       'Days the engine calculated'],
            ['Applied',     $totals['applied'],   'check-circle',     'Days already carried'],
            ['Outstanding', $totals['outstanding'], 'exclamation-circle', 'Eligible but not yet carried'],
        ] as [$label, $value, $icon, $hint])
            <div class="rounded-2xl border border-[#EAECF0] bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <div class="flex items-center gap-2">
                    <div class="flex size-8 items-center justify-center rounded-lg bg-orange-50 text-orange-500 dark:bg-orange-500/10">
                        <flux:icon :name="$icon" class="size-4" />
                    </div>
                    <span class="text-[10px] font-bold uppercase tracking-widest text-[#98A2B3]">{{ $label }}</span>
                </div>
                <div class="mt-2 text-2xl font-extrabold text-[#101828] dark:text-white">{{ $value }}</div>
                <div class="text-[11px] text-[#98A2B3]">{{ $hint }}</div>
            </div>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="rounded-2xl border border-[#EAECF0] bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-5">
            <x-clean-select model="previousYearId" label="Previous leave year" :live="true"
                :options="$leaveYears->map(fn ($y) => ['value' => $y->id, 'label' => $y->label])->all()" />
            <x-clean-select model="currentYearId" label="Current leave year" :live="true"
                :options="$leaveYears->map(fn ($y) => ['value' => $y->id, 'label' => $y->label])->all()" />
            <x-clean-select model="departmentId" label="Department" :live="true"
                :options="array_merge([['value' => '', 'label' => 'All departments']], $departments->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->all())" />
            <x-clean-select model="leaveTypeId" label="Leave type" :live="true"
                :options="array_merge([['value' => '', 'label' => 'All leave types']], $leaveTypes->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->all())" />
            <x-clean-select model="statusFilter" label="Status" :live="true"
                :options="[
                    ['value' => '', 'label' => 'All statuses'],
                    ['value' => 'eligible', 'label' => 'Eligible'],
                    ['value' => 'applied', 'label' => 'Applied'],
                    ['value' => 'partially_applied', 'label' => 'Partially applied'],
                    ['value' => 'reversed', 'label' => 'Reversed'],
                ]" />
        </div>
    </div>

    {{-- Preview --}}
    <div class="overflow-hidden rounded-2xl border border-[#EAECF0] bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="w-full min-w-max text-sm">
                <thead>
                    <tr class="border-b border-[#EAECF0] bg-[#F9FAFB] text-left dark:border-white/10 dark:bg-white/[0.02]">
                        @foreach(['Employee', 'Leave type', 'Previous year', 'Allocated', 'Used', 'Encashed', 'Eligible', 'Carried', 'Remaining', 'Status', ''] as $i => $heading)
                            <th class="whitespace-nowrap px-4 py-3 text-[10px] font-bold uppercase tracking-widest text-[#98A2B3] {{ in_array($i, [3,4,5,6,7,8]) ? 'text-right' : '' }}">
                                {{ $heading }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#F2F4F7] dark:divide-white/5">
                    @forelse($rows as $row)
                        @php
                            $key = $row['employee_id'] * 1000000 + $row['leave_type_id'];
                            [$badge, $badgeTone] = match($row['status']) {
                                'applied'           => ['Applied', 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'],
                                'partially_applied' => ['Partially applied', 'bg-amber-50 text-amber-700 ring-amber-600/20'],
                                'reversed'          => ['Reversed', 'bg-rose-50 text-rose-700 ring-rose-600/20'],
                                'rejected'          => ['Rejected', 'bg-rose-50 text-rose-700 ring-rose-600/20'],
                                'not_eligible'      => ['Not eligible', 'bg-zinc-100 text-zinc-600 ring-zinc-500/20'],
                                default             => ['Eligible', 'bg-blue-50 text-blue-700 ring-blue-600/20'],
                            };
                        @endphp
                        <tr class="align-top">
                            <td class="px-4 py-3 font-semibold text-[#101828] dark:text-zinc-100">{{ $row['employee'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-[#667085] dark:text-zinc-400">{{ $row['leave_type'] }}</td>
                            <td class="px-4 py-3 text-[#667085] dark:text-zinc-400">{{ $leaveYears->firstWhere('id', $previousYearId)?->label ?? '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-[#667085] dark:text-zinc-400">{{ $row['allocated'] }}</td>
                            {{-- "Not available" rather than 0. A closed year we hold no usage
                                 figure for has not been measured, and printing zero asserts
                                 that it was. --}}
                            <td class="px-4 py-3 text-right tabular-nums {{ $row['figures_known'] ? 'text-[#667085] dark:text-zinc-400' : 'text-amber-600' }}">
                                {{ $row['figures_known'] ? $row['used'] : 'Not available' }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums {{ $row['figures_known'] ? 'text-[#667085] dark:text-zinc-400' : 'text-amber-600' }}">
                                {{ $row['figures_known'] ? $row['encashed'] : 'Not available' }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums font-bold text-[#101828] dark:text-white">
                                @if(! $row['figures_known'])
                                    <span class="text-xs font-semibold text-amber-600">HR to decide</span>
                                    <div class="text-[10px] font-normal text-[#98A2B3]">closing {{ $row['closing_balance'] }}</div>
                                @else
                                {{ $row['eligible'] }}
                                @if($row['limit'] !== null && $row['eligible'] > $row['carry'])
                                    {{-- The policy cap is why these two numbers differ; saying so
                                         here saves the question being asked. --}}
                                    <flux:tooltip content="Policy caps carry forward at {{ $row['limit'] }} days">
                                        <span class="ml-1 cursor-help text-[10px] font-medium text-amber-600">capped {{ $row['carry'] }}</span>
                                    </flux:tooltip>
                                @endif
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums font-semibold text-emerald-600">{{ $row['applied'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-[#667085] dark:text-zinc-400">{{ $row['remaining_eligible'] }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $badgeTone }}">{{ $badge }}</span>
                                @if($row['applied_by'])
                                    <div class="mt-1 text-[10px] text-[#98A2B3]">by {{ $row['applied_by'] }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @can('manage_leave_carry_forward')
                                    @if($partialFor === $key)
                                        <div class="flex items-center justify-end gap-2">
                                            <flux:input wire:model="partialDays" type="number" step="0.5" min="0" :max="$row['carry']" class="w-24" />
                                            <flux:input wire:model="partialReason" placeholder="Reason" class="w-40" />
                                            <flux:button wire:click="confirmPartial({{ $row['employee_id'] }}, {{ $row['leave_type_id'] }})" variant="primary" size="sm">Apply</flux:button>
                                            <flux:button wire:click="$set('partialFor', null)" variant="ghost" size="sm">Cancel</flux:button>
                                        </div>
                                    @else
                                        <div class="flex items-center justify-end gap-1">
                                            @if($row['remaining_eligible'] > 0)
                                                <flux:tooltip content="Carry the full {{ $row['carry'] }} days">
                                                    <flux:button wire:click="applyRow({{ $row['employee_id'] }}, {{ $row['leave_type_id'] }})" variant="ghost" size="sm" icon="check" />
                                                </flux:tooltip>
                                                <flux:tooltip content="Carry part of it">
                                                    <flux:button wire:click="startPartial({{ $row['employee_id'] }}, {{ $row['leave_type_id'] }}, {{ $row['carry'] }})" variant="ghost" size="sm" icon="adjustments-horizontal" />
                                                </flux:tooltip>
                                            @endif
                                            @if($row['transaction_id'] && $row['applied'] > 0)
                                                <flux:tooltip content="Reverse this carry forward">
                                                    <flux:button wire:click="startReverse({{ $row['transaction_id'] }})" variant="ghost" size="sm" icon="arrow-uturn-left" class="text-rose-500" />
                                                </flux:tooltip>
                                            @endif
                                        </div>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="px-4 py-12">
                                {{-- Zero here means "no source data", not "operation complete".
                                     Neutral rather than red: nothing has gone wrong. --}}
                                <div class="mx-auto flex max-w-lg flex-col items-center gap-3 text-center">
                                    <div class="flex size-11 items-center justify-center rounded-full bg-[#F2F4F7] text-[#98A2B3] dark:bg-white/5">
                                        <flux:icon.information-circle class="size-5" />
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-[#101828] dark:text-zinc-100">No carry-forwardable leave</p>
                                        <p class="mt-1 text-sm text-[#667085] dark:text-zinc-400">
                                            No eligible {{ $leaveYears->firstWhere('id', $previousYearId)?->label ?? 'previous year' }}
                                            leave balance was found for the selected filters.
                                        </p>
                                        <p class="mt-2 text-xs text-[#98A2B3]">
                                            Carry forward is calculated from previous allocated − used − encashed,
                                            subject to the leave type's policy limit. Historical balances for this
                                            leave year may not be configured yet.
                                        </p>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Reversal needs a reason, so it gets a prompt rather than a confirm. --}}
    @if($reverseId)
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-500/20 dark:bg-rose-500/5">
            <h3 class="text-sm font-bold text-rose-800 dark:text-rose-300">Reverse this carry forward</h3>
            <p class="mt-1 text-xs text-rose-700/80 dark:text-rose-300/80">
                The record is kept and the reversal is recorded against it. The employee's balance returns to their fresh entitlement.
            </p>
            <div class="mt-3 flex flex-wrap items-end gap-2">
                <flux:input wire:model="reverseReason" label="Reason" placeholder="Why is this being reversed?" class="min-w-72 flex-1" />
                <flux:button wire:click="confirmReverse" variant="danger">Reverse</flux:button>
                <flux:button wire:click="$set('reverseId', null)" variant="ghost">Cancel</flux:button>
            </div>
        </div>
    @endif

    {{-- The distinction that keeps this screen honest. --}}
    <div class="flex items-start gap-3 rounded-xl border border-orange-100 bg-orange-50/60 px-4 py-3.5 text-sm text-[#7C4A17] dark:border-orange-500/20 dark:bg-orange-500/5 dark:text-orange-300">
        <flux:icon.information-circle class="mt-0.5 size-4 shrink-0" />
        <span>
            Eligible days are calculated by the leave engine as
            <strong>previous allocated − used − encashed</strong>, capped by each leave type's policy limit.
            Use this screen for previous-year entitlement; use <strong>Manual Balance Adjustment</strong> on the employee record only for HR corrections.
        </span>
    </div>
</flux:main>
