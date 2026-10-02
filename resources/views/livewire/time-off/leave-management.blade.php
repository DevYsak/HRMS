<flux:main class="min-h-screen space-y-5 bg-[#F7F8FA] p-4 font-['Inter'] md:p-6 dark:bg-[#0B1220]">

    @php
        $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold tracking-tight text-[#101828] dark:text-white">Leave Management</h1>
            <p class="mt-1 text-sm text-[#667085] dark:text-zinc-400">
                Every employee's leave for <span class="font-semibold text-[#101828] dark:text-zinc-200">{{ $this->year->label }}</span>
                ({{ $this->year->starts_on->format('d M Y') }} – {{ $this->year->ends_on->format('d M Y') }}).
                Provisioning, accrual and rollover run automatically; manage the exceptions here.
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('run_leave_rollover')
                <flux:button size="sm" icon="arrow-path" :href="route('time-off.year-rollover')" wire:navigate>Year Rollover</flux:button>
            @endcan
            @can('reconcile_leave')
                <flux:button size="sm" icon="scale" :href="route('time-off.reconciliation')" wire:navigate>Reconciliation</flux:button>
            @endcan
            @can('bulk_allocate_leave')
                <flux:button size="sm" icon="user-plus" wire:click="previewBulkProvision">Bulk Provision Missing</flux:button>
                @can('add_leave_balance')
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="$set('bulkMode', 'add_on_form')">Bulk Add-On</flux:button>
                @endcan
            @endcan
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900/40 dark:bg-emerald-900/20 dark:text-emerald-300">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900/40 dark:bg-rose-900/20 dark:text-rose-300">{{ session('error') }}</div>
    @endif

    {{-- Summary cards: each opens the matching filtered list. --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
        @foreach([
            ['Total Employees', 'total_employees', null, 'users', null],
            ['Missing Balances', 'missing_balances', 'missing', 'exclamation-triangle', null],
            ['Pending Manager', 'pending_manager', 'pending', 'clock', null],
            ['Pending HR', 'pending_hr', 'pending', 'inbox-stack', null],
            ['Needs Information', 'needs_info', 'pending', 'question-mark-circle', null],
            ['Escalated', 'escalated', 'pending', 'arrow-trending-up', null],
            ['On Leave Today', 'on_leave_today', 'on_leave', 'sun', null],
            ['Upcoming (30 days)', 'upcoming_leave', 'upcoming', 'calendar-days', null],
            ['Negative Balances', 'negative_balances', 'negative', 'minus-circle', null],
            ['Carry Forward Review', 'carry_forward_pending', null, 'arrow-right-circle', route('time-off.year-rollover')],
            ['Expiring (30 days)', 'expiring_leave', 'expiring', 'bell-alert', null],
            ['No Leave Policy', 'no_policy', 'no_policy', 'document-minus', null],
            ['Encashment Pending', 'encashment_pending', null, 'banknotes', null],
            ['Reconciliation Issues', 'reconciliation_issues', 'review', 'scale', route('time-off.reconciliation')],
        ] as [$label, $key, $cardFlag, $icon, $href])
            @php $active = $cardFlag !== null && $flag === $cardFlag; @endphp
            <button type="button"
                @if($href) onclick="window.location='{{ $href }}'" @elseif($cardFlag) wire:click="setFlag('{{ $cardFlag }}')" @endif
                class="rounded-2xl border bg-white p-3 text-left shadow-sm transition hover:border-orange-300 dark:bg-zinc-900 {{ $active ? 'border-orange-400 ring-2 ring-orange-200 dark:ring-orange-900/40' : 'border-[#EAECF0] dark:border-white/10' }}">
                <div class="flex items-center gap-2">
                    <div class="flex size-7 items-center justify-center rounded-lg bg-orange-50 text-orange-500 dark:bg-orange-500/10">
                        <flux:icon :name="$icon" class="size-4" />
                    </div>
                    <span class="text-[10px] font-bold uppercase tracking-widest text-[#98A2B3]">{{ $label }}</span>
                </div>
                <div class="mt-2 text-xl font-extrabold text-[#101828] dark:text-white">{{ $this->cards[$key] ?? 0 }}</div>
            </button>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="rounded-2xl border border-[#EAECF0] bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-3 lg:grid-cols-5">
            <flux:input wire:model.live.debounce.400ms="search" label="Employee" placeholder="Name or code" icon="magnifying-glass" />
            <x-clean-select model="leaveYearId" label="Leave year"
                :options="$leaveYears->map(fn ($y) => ['value' => $y->id, 'label' => $y->label])->all()" />
            <x-clean-select model="leaveTypeId" label="Leave type"
                :options="$leaveTypes->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->all()" />
            <x-clean-select model="departmentId" label="Department"
                :options="array_merge([['value' => '', 'label' => 'All departments']], $departments->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->all())" />
            <x-clean-select model="managerId" label="Manager"
                :options="array_merge([['value' => '', 'label' => 'All managers']], $managers->map(fn ($m) => ['value' => $m->id, 'label' => $m->name])->all())" />
            <x-clean-select model="officeId" label="Office"
                :options="array_merge([['value' => '', 'label' => 'All offices']], $offices->map(fn ($o) => ['value' => $o->id, 'label' => $o->name])->all())" />
            <x-clean-select model="employmentTypeId" label="Employment type"
                :options="array_merge([['value' => '', 'label' => 'All types']], $employmentTypes->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->all())" />
            <x-clean-select model="status" label="Status"
                :options="array_merge([['value' => '', 'label' => 'All holding leave']], collect(\App\Enums\EmployeeStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])->all())" />
            <x-clean-select model="policyId" label="Leave policy"
                :options="array_merge([['value' => '', 'label' => 'All policies']], $policies->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->all())" />
            <x-clean-select model="flag" label="Show"
                :options="[
                    ['value' => '', 'label' => 'Everyone'],
                    ['value' => 'missing', 'label' => 'Missing balance'],
                    ['value' => 'negative', 'label' => 'Negative balance'],
                    ['value' => 'pending', 'label' => 'Has pending requests'],
                    ['value' => 'on_leave', 'label' => 'On leave today'],
                    ['value' => 'upcoming', 'label' => 'Upcoming leave'],
                    ['value' => 'expiring', 'label' => 'Leave expiring soon'],
                    ['value' => 'no_policy', 'label' => 'No leave policy'],
                    ['value' => 'review', 'label' => 'Needs HR review'],
                ]" />
        </div>
    </div>

    {{-- Employee table --}}
    <div class="overflow-hidden rounded-2xl border border-[#EAECF0] bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <div class="flex items-center justify-between border-b border-[#EAECF0] px-4 py-3 text-sm dark:border-white/10">
            <span class="font-semibold text-[#101828] dark:text-white">{{ $paged->total() }} employee(s)</span>
            @if(count($selected) > 0)
                <span class="text-xs text-orange-700 dark:text-orange-300">{{ count($selected) }} selected — bulk actions apply to the selection</span>
            @else
                <span class="text-xs text-[#98A2B3]">Nothing selected — bulk actions apply to everyone in this filter</span>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-[#F9FAFB] text-left text-[10px] font-bold uppercase tracking-wider text-[#667085] dark:bg-white/5">
                    <tr>
                        <th class="px-3 py-2"><input type="checkbox" wire:click="toggleSelectPage" class="rounded"></th>
                        <th class="px-3 py-2">Employee</th>
                        <th class="px-3 py-2">Department / Manager</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">Policy</th>
                        <th class="px-3 py-2 text-right">Base</th>
                        <th class="px-3 py-2 text-right">Carry Fwd</th>
                        <th class="px-3 py-2 text-right">Add-On</th>
                        <th class="px-3 py-2 text-right">Accrual</th>
                        <th class="px-3 py-2 text-right">Adj.</th>
                        <th class="px-3 py-2 text-right">Used</th>
                        <th class="px-3 py-2 text-right">Pending</th>
                        <th class="px-3 py-2 text-right">Expired</th>
                        <th class="px-3 py-2 text-right">Available</th>
                        <th class="px-3 py-2">Next Accrual</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#EAECF0] dark:divide-white/5">
                    @forelse($paged as $row)
                        <tr wire:key="lm-{{ $row['employee_id'] }}" class="hover:bg-orange-50/40 dark:hover:bg-white/5">
                            <td class="px-3 py-2"><input type="checkbox" wire:model.live="selected" value="{{ $row['employee_id'] }}" class="rounded"></td>
                            <td class="px-3 py-2">
                                <div class="font-semibold text-[#101828] dark:text-white">{{ $row['name'] }}</div>
                                <div class="text-[11px] text-[#98A2B3]">{{ $row['code'] }}</div>
                            </td>
                            <td class="px-3 py-2">
                                <div>{{ $row['department'] ?? '—' }}</div>
                                <div class="text-[11px] text-[#98A2B3]">{{ $row['manager'] ?? '—' }}</div>
                            </td>
                            <td class="px-3 py-2">{{ $row['status'] }}</td>
                            <td class="px-3 py-2">
                                @if($row['policy']) {{ $row['policy'] }} @else <flux:badge size="sm" color="amber">No policy</flux:badge> @endif
                            </td>
                            @if(! $row['has_balance'])
                                <td colspan="9" class="px-3 py-2 text-center"><flux:badge size="sm" color="red">Missing balance</flux:badge></td>
                            @else
                                <td class="px-3 py-2 text-right">{{ $fmt($row['base']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $fmt($row['carry_forward']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $fmt($row['add_on']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $fmt($row['accrued']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $fmt($row['adjustment']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $fmt($row['used']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $fmt($row['pending']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $fmt($row['expired']) }}</td>
                                <td class="px-3 py-2 text-right font-bold {{ ($row['available'] ?? 0) < 0 ? 'text-rose-600' : 'text-[#101828] dark:text-white' }}">
                                    {{ $fmt($row['available']) }}
                                    @if($row['flags']['review'])<flux:tooltip content="Needs HR review: history not fully decomposed"><flux:icon.exclamation-triangle class="inline size-3 text-amber-500" /></flux:tooltip>@endif
                                </td>
                            @endif
                            <td class="px-3 py-2 text-[11px] text-[#667085]">{{ $row['next_accrual'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-right">
                                <flux:button size="xs" icon="arrow-top-right-on-square"
                                    :href="route('time-off.leave-management.employee', ['employee' => $row['employee_id'], 'year' => $leaveYearId])" wire:navigate>Open</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="16" class="px-3 py-10 text-center text-sm text-[#98A2B3]">No employees match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-[#EAECF0] px-4 py-3 dark:border-white/10">{{ $paged->links() }}</div>
    </div>

    {{-- Bulk add-on: the inputs, then a preview, then confirm. --}}
    @if($bulkMode === 'add_on_form')
    <x-leave.overlay max="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">Bulk Add-On Leave</flux:heading>
            <flux:text>Grants the same add-on to {{ count($selected) ?: $paged->total() }} employee(s) for the selected leave type and year. You will see a preview before anything is written.</flux:text>
            <flux:input wire:model="bulkDays" type="number" step="0.5" min="0.5" label="Days" />
            <flux:select wire:model="bulkAddOnType" label="Kind">
                @foreach($addOnTypes as $t)
                    <flux:select.option value="{{ $t }}">{{ ucwords(str_replace('_', ' ', $t)) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="bulkExpiresOn" type="date" label="Expires on (optional)" />
            <flux:textarea wire:model="bulkReason" label="Reason (shown to employees)" rows="2" />
            <div class="flex justify-end gap-2">
                <flux:button wire:click="closeBulk">Cancel</flux:button>
                <flux:button variant="primary" wire:click="previewBulkAddOn">Preview</flux:button>
            </div>
        </div>
    </x-leave.overlay>
    @endif

    @if(in_array($bulkMode, ['provision', 'add_on'], true) && $bulkPreview)
    <x-leave.overlay max="max-w-4xl">
            <div class="space-y-4">
                <flux:heading size="lg">{{ $bulkMode === 'provision' ? 'Bulk Provision Missing — preview' : 'Bulk Add-On — preview' }}</flux:heading>
                <div class="grid grid-cols-2 gap-2 text-xs md:grid-cols-4">
                    @foreach($bulkPreview['summary'] as $k => $v)
                        <div class="rounded-lg bg-[#F9FAFB] px-3 py-2 dark:bg-white/5">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-[#98A2B3]">{{ str_replace('_', ' ', $k) }}</div>
                            <div class="text-lg font-bold text-[#101828] dark:text-white">{{ $v }}</div>
                        </div>
                    @endforeach
                </div>
                <div class="max-h-80 overflow-y-auto rounded-lg border border-[#EAECF0] dark:border-white/10">
                    <table class="min-w-full text-xs">
                        <tbody class="divide-y divide-[#EAECF0] dark:divide-white/5">
                            @foreach($bulkPreview['rows'] as $r)
                                <tr>
                                    <td class="px-3 py-1.5">{{ $r['employee'] }} <span class="text-[#98A2B3]">{{ $r['employee_code'] }}</span></td>
                                    <td class="px-3 py-1.5">{{ $r['leave_type'] }}</td>
                                    @if($bulkMode === 'provision')
                                        <td class="px-3 py-1.5 text-right">{{ $fmt($r['current_base']) }} → {{ $fmt($r['expected']) }}</td>
                                    @else
                                        <td class="px-3 py-1.5 text-right">{{ $fmt($r['current']) }} → {{ $fmt($r['new']) }}</td>
                                    @endif
                                    <td class="px-3 py-1.5"><flux:badge size="sm">{{ str_replace('_', ' ', $r['status']) }}</flux:badge></td>
                                    <td class="px-3 py-1.5 text-[#667085]">{{ $r['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:button wire:click="closeBulk">Cancel</flux:button>
                    @if($bulkMode === 'provision')
                        <flux:button variant="primary" wire:click="confirmBulkProvision" wire:loading.attr="disabled">Confirm — provision {{ $bulkPreview['summary']['valid'] }}</flux:button>
                    @else
                        <flux:button variant="primary" wire:click="confirmBulkAddOn" wire:loading.attr="disabled">Confirm — grant to {{ $bulkPreview['summary']['valid'] }}</flux:button>
                    @endif
                </div>
            </div>
    </x-leave.overlay>
    @endif
</flux:main>
