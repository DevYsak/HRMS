<div>
@if($visible)
    @php
        $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        $th = 'px-3 py-2.5 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400';
        $canAdjust = auth()->user()->hasPermission('add_leave_balance') || auth()->user()->hasPermission('deduct_leave_balance');
    @endphp
    <section id="employee-balances" class="ui-surface scroll-mt-6 overflow-hidden" data-employee-balances>
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 dark:border-white/5">
            <div>
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Employee Leave Balances</h2>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Current policy and balances. Open an employee for the full ledger or to adjust a balance.</p>
            </div>
            @if($filter !== '')
                <span class="flex items-center gap-2">
                    <x-pulse.badge :color="$filter === 'low' ? 'amber' : 'rose'">{{ $filter === 'low' ? 'Low Earned balance' : 'Earned below zero' }}</x-pulse.badge>
                    <button type="button" wire:click="applyFilter('')" class="text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">Show everyone</button>
                </span>
            @endif
        </div>
        <div class="grid grid-cols-1 gap-3 border-b border-zinc-100 px-5 py-3 sm:grid-cols-3 dark:border-white/5">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Employee, code or department" class="sm:col-span-2" />
            <flux:select wire:model.live="departmentId">
                <flux:select.option value="">All departments</flux:select.option>
                @foreach($departments as $d)<flux:select.option value="{{ $d->id }}">{{ $d->name }}</flux:select.option>@endforeach
            </flux:select>
        </div>

        @if($rows->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">No employees match.</p>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-white/5">
                        <tr><th class="{{ $th }} pl-5">Employee</th><th class="{{ $th }}">Current Policy</th><th class="{{ $th }} text-right">Earned Available</th><th class="{{ $th }} text-right">Earned Used</th><th class="{{ $th }} text-right">Pending</th><th class="{{ $th }} text-right">Comp Off</th><th class="{{ $th }} text-right">Special Leave</th><th class="{{ $th }}">Historical</th><th class="{{ $th }} pr-5 text-right"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach($rows as $row)
                            @php $e = $row['employee']; $url = route('time-off.leave-management.employee', ['employee' => $e]); @endphp
                            <tr wire:key="bal-{{ $e->id }}" data-balance-row="{{ $e->employee_id }}">
                                <td class="px-3 py-2.5 pl-5">
                                    <a href="{{ $url }}" wire:navigate class="block whitespace-nowrap font-medium text-zinc-900 hover:underline dark:text-white">{{ $e->user?->name ?? 'Employee' }}</a>
                                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $e->employee_id }} · {{ $e->department?->name ?? '—' }}</span>
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="block text-zinc-700 dark:text-zinc-200">{{ $row['policy'] ?? 'No policy' }}</span>
                                    @if(! $row['v2'])<x-pulse.badge class="mt-1">Legacy</x-pulse.badge>@endif
                                </td>
                                <td class="px-3 py-2.5 text-right font-semibold tabular-nums {{ ($row['earned_available'] ?? 0) < 0 ? 'text-rose-600 dark:text-rose-400' : '' }}">{{ $fmt($row['earned_available']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $fmt($row['earned_used']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $fmt($row['pending']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $fmt($row['comp_off']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $fmt($row['special']) }}</td>
                                <td class="px-3 py-2.5">@if($row['historical'] > 0)<x-pulse.badge>Historical · {{ $row['historical'] }}</x-pulse.badge>@else<span class="text-zinc-400">—</span>@endif</td>
                                <td class="whitespace-nowrap px-3 py-2.5 pr-5 text-right">
                                    <flux:button size="xs" :href="$url" wire:navigate>{{ $canAdjust ? 'Open / Adjust' : 'Open' }}</flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <ul class="divide-y divide-zinc-100 md:hidden dark:divide-white/5">
                @foreach($rows as $row)
                    @php $e = $row['employee']; @endphp
                    <li wire:key="bal-card-{{ $e->id }}">
                        <a href="{{ route('time-off.leave-management.employee', ['employee' => $e]) }}" wire:navigate class="block space-y-1 px-4 py-3">
                            <span class="flex items-start justify-between gap-2"><span class="font-semibold text-zinc-900 dark:text-white">{{ $e->user?->name ?? 'Employee' }}</span><span class="text-sm font-semibold tabular-nums">{{ $fmt($row['earned_available']) }} <span class="text-xs font-normal text-zinc-500">EL</span></span></span>
                            <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $e->employee_id }} · {{ $row['policy'] ?? 'No policy' }}@if(! $row['v2']) · Legacy @endif</span>
                            <span class="block text-xs text-zinc-600 dark:text-zinc-300">Used {{ $fmt($row['earned_used']) }} · Pending {{ $fmt($row['pending']) }} · Comp Off {{ $fmt($row['comp_off']) }} · Special {{ $fmt($row['special']) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
            @if($paginator->hasPages())
                <div class="border-t border-zinc-100 px-5 py-3 dark:border-white/5">{{ $paginator->links() }}</div>
            @endif
        @endif
    </section>
@endif
</div>
