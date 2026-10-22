<flux:main class="min-h-screen space-y-5 bg-[#F7F8FA] p-4 font-sans md:p-6 dark:bg-[#0B1220]">
    @php
        $P = \App\Services\Leave\LeaveRequestPresenter::class;

        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        $filtered = $search !== '' || $departmentId || $leaveTypeId || $mode !== '' || $leaveYearId || $from !== '' || $to !== '' || $unpaidOnly;
        $th = 'px-3 py-2.5 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400';
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold tracking-tight text-[#101828] dark:text-white">Leave Requests</h1>
            <p class="mt-1 text-sm text-[#667085] dark:text-zinc-400">Every leave application in your scope. Open one to review, decide or correct it.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('view_leave_management')
                <flux:button size="sm" icon="users" :href="route('time-off.leave-management')" wire:navigate>Employee Balances</flux:button>
            @endcan
            @if($canFileForEmployee)
                <flux:button size="sm" variant="primary" icon="plus" x-on:click="Livewire.dispatch('open-request-leave-for')" data-request-for-employee>Request Leave for Employee</flux:button>
            @endif
        </div>
    </div>

    {{-- Status tabs --}}
    <nav class="flex max-w-full gap-1 overflow-x-auto border-b border-zinc-200 dark:border-white/10" aria-label="Status">
        @foreach(['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'] as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" data-status-tab="{{ $key }}"
                @class(['-mb-px shrink-0 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-semibold transition',
                    'border-orange-500 text-orange-600 dark:text-orange-400' => $tab === $key,
                    'border-transparent text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' => $tab !== $key])>
                {{ $label }} <span class="ml-1 rounded-full bg-zinc-100 px-1.5 text-xs tabular-nums text-zinc-600 dark:bg-white/10 dark:text-zinc-300">{{ $this->tabCounts[$key] }}</span>
            </button>
        @endforeach
    </nav>

    {{-- Filters --}}
    <div class="ui-surface grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4" data-request-filters>
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Employee, code, request ID or reason" class="sm:col-span-2" />
        <flux:select wire:model.live="departmentId" placeholder="All departments">
            <flux:select.option value="">All departments</flux:select.option>
            @foreach($departments as $d)<flux:select.option value="{{ $d->id }}">{{ $d->name }}</flux:select.option>@endforeach
        </flux:select>
        <flux:select wire:model.live="leaveTypeId" placeholder="All leave types">
            <flux:select.option value="">All leave types</flux:select.option>
            @foreach($leaveTypes as $t)<flux:select.option value="{{ $t->id }}">{{ $t->name }}{{ ($t->trashed() || $t->availability !== 'active') ? ' (historical)' : '' }}</flux:select.option>@endforeach
        </flux:select>
        <flux:select wire:model.live="mode">
            <flux:select.option value="">Any request mode</flux:select.option>
            <flux:select.option value="standard">Standard</flux:select.option>
            <flux:select.option value="combined">Combined Leave</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="leaveYearId">
            <flux:select.option value="">Any leave year</flux:select.option>
            @foreach($leaveYears as $y)<flux:select.option value="{{ $y->id }}">{{ $y->label }}</flux:select.option>@endforeach
        </flux:select>
        <div class="grid grid-cols-2 gap-2">
            <flux:input type="date" wire:model.live="from" aria-label="From" />
            <flux:input type="date" wire:model.live="to" aria-label="To" />
        </div>
        <div class="flex items-center justify-between gap-3">
            <flux:checkbox wire:model.live="unpaidOnly" label="Unpaid days only" />
            @if($filtered)
                <button type="button" wire:click="clearFilters" class="text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">Clear filters</button>
            @endif
        </div>
    </div>

    <div class="ui-surface overflow-hidden" data-leave-requests>
        @if($this->requests->isEmpty())
            <div class="flex flex-col items-center px-5 py-14 text-center">
                <flux:icon.document-magnifying-glass class="size-8 text-zinc-300 dark:text-zinc-600" />
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $filtered || $tab !== 'all' ? 'No leave requests match these filters.' : 'No leave requests in your scope yet.' }}</p>
            </div>
        @else
            {{-- Desktop / tablet --}}
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-white/5">
                        <tr>
                            <th class="{{ $th }} pl-5">Request ID</th><th class="{{ $th }}">Employee</th><th class="{{ $th }}">Leave Type / Category</th><th class="{{ $th }}">Reason</th>
                            <th class="{{ $th }}">Dates</th><th class="{{ $th }} text-right">Paid</th><th class="{{ $th }} text-right">Unpaid</th><th class="{{ $th }} text-right">Days</th>
                            <th class="{{ $th }}">Status</th><th class="{{ $th }}">Approver</th><th class="{{ $th }}">Applied On</th><th class="{{ $th }} pr-5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach($this->requests as $r)
                            @php [$statusLabel, $statusColor] = $P::status($r->status); $url = route('time-off.leave-requests.show', $r); @endphp
                            <tr wire:key="lr-{{ $r->id }}" class="hover:bg-zinc-50/60 dark:hover:bg-white/5" data-request-row="{{ $r->id }}">
                                <td class="whitespace-nowrap px-3 py-3 pl-5"><a href="{{ $url }}" wire:navigate class="text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">{{ $P::reference($r) }}</a></td>
                                <td class="px-3 py-3">
                                    <a href="{{ $url }}" wire:navigate class="block whitespace-nowrap font-medium text-zinc-900 hover:underline dark:text-white">{{ $r->employee?->user?->name ?? 'Employee' }}</a>
                                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $r->employee?->employee_id }} · {{ $r->employee?->department?->name ?? '—' }}</span>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="block whitespace-nowrap text-zinc-800 dark:text-zinc-100">{{ $P::category($r) }}</span>
                                    @if($r->isCombined())<x-pulse.badge color="violet" class="mt-1">Combined</x-pulse.badge>@elseif($P::isLegacy($r))<x-pulse.badge class="mt-1">Legacy</x-pulse.badge>@endif
                                </td>
                                <td class="px-3 py-3 text-zinc-600 dark:text-zinc-300">
                                    <span class="block">{{ $P::reason($r) ?? '—' }}</span>
                                    @if($r->reason)<span class="block truncate text-xs text-zinc-500 dark:text-zinc-400" style="max-width: 12rem" title="{{ $r->reason }}">{{ $r->reason }}</span>@endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-zinc-700 dark:text-zinc-200">{{ $P::dates($r) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $fmt($P::paid($r)) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums {{ $P::unpaid($r) > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-400' }}">{{ $fmt($P::unpaid($r)) }}</td>
                                <td class="px-3 py-3 text-right font-semibold tabular-nums">{{ $fmt($r->days) }}</td>
                                <td class="px-3 py-3"><x-pulse.badge :color="$statusColor">{{ $statusLabel }}</x-pulse.badge></td>
                                <td class="px-3 py-3 text-xs text-zinc-600 dark:text-zinc-300" style="max-width: 11rem">{{ $approverOf($r) ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-zinc-500 dark:text-zinc-400">{{ $r->created_at->format('d M Y') }}</td>
                                <td class="whitespace-nowrap px-3 py-3 pr-5 text-right">
                                    <flux:button size="xs" :href="$url" wire:navigate>{{ $P::isPending($r) ? 'Manage' : 'View' }}</flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Phones: one card per request --}}
            <ul class="divide-y divide-zinc-100 md:hidden dark:divide-white/5">
                @foreach($this->requests as $r)
                    @php [$statusLabel, $statusColor] = $P::status($r->status); @endphp
                    <li wire:key="lr-card-{{ $r->id }}">
                        <a href="{{ route('time-off.leave-requests.show', $r) }}" wire:navigate class="block space-y-1 px-4 py-3">
                            <span class="flex items-start justify-between gap-2">
                                <span class="min-w-0 font-semibold text-zinc-900 dark:text-white">{{ $r->employee?->user?->name ?? 'Employee' }}</span>
                                <x-pulse.badge :color="$statusColor">{{ $statusLabel }}</x-pulse.badge>
                            </span>
                            <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $P::reference($r) }} · {{ $P::category($r) }}@if($P::reason($r)) · {{ $P::reason($r) }}@endif</span>
                            <span class="block text-xs text-zinc-600 dark:text-zinc-300">{{ $P::dates($r) }} · {{ $fmt($r->days) }} days · Paid {{ $fmt($P::paid($r)) }} · Unpaid {{ $fmt($P::unpaid($r)) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>

            @if($this->requests->hasPages())
                <div class="border-t border-zinc-100 px-5 py-3 dark:border-white/5">{{ $this->requests->links() }}</div>
            @endif
        @endif
    </div>

    @if($canFileForEmployee)
        <livewire:time-off.request-leave :hr-mode="true" />
    @endif
</flux:main>
