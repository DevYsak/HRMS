<div>
@if($visible)
    @php
        $P = \App\Services\Leave\LeaveRequestPresenter::class;
        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        $toBalances = fn (string $filter) => "Livewire.dispatch('leave-balances-filter', { filter: '{$filter}' }); document.getElementById('employee-balances')?.scrollIntoView({ behavior: 'smooth', block: 'start' })";
        $tiles = [
            ['label' => 'On Leave Today', 'value' => $cards['on_leave_today'], 'sub' => 'Approved leave covering today', 'icon' => 'user-minus', 'tone' => 'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400', 'href' => $links['on_leave']],
            ['label' => 'Pending Leave Requests', 'value' => $cards['pending'], 'sub' => 'Awaiting a decision', 'icon' => 'clock', 'tone' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400', 'href' => $links['pending']],
            ['label' => 'Upcoming Approved Leave', 'value' => $cards['upcoming'], 'sub' => 'Starting in the next 30 days', 'icon' => 'calendar-days', 'tone' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'href' => $links['upcoming']],
            ['label' => 'Low Earned Balance', 'value' => $cards['low_earned'], 'sub' => 'At or below '.$fmt(\App\Services\Leave\LeaveDashboardService::LOW_EARNED_DAYS).' days', 'icon' => 'battery-50', 'tone' => 'bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400', 'go' => $canSeeBalances ? $toBalances('low') : null],
            ['label' => 'Unpaid Leave This Period', 'value' => $fmt($cards['unpaid_days']).' days', 'sub' => $cards['unpaid_requests'].' '.Str::plural('request', $cards['unpaid_requests']).' · '.$cards['month'], 'icon' => 'banknotes', 'tone' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400', 'href' => $links['unpaid']],
            ['label' => 'Balance Exceptions', 'value' => $cards['exceptions'], 'sub' => 'Earned Leave below zero', 'icon' => 'exclamation-triangle', 'tone' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400', 'go' => $canSeeBalances ? $toBalances('negative') : null],
        ];
        $tile = 'ui-surface flex items-center gap-3 p-4 text-left transition hover:-translate-y-0.5 hover:shadow-md';
        $th = 'px-3 py-2.5 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400';
    @endphp
    <section class="space-y-4" data-leave-overview>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Leave</h2>
            <a href="{{ route('time-off.leave-requests') }}" wire:navigate class="text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">All leave requests</a>
        </div>

        <div class="grid grid-cols-1 items-stretch gap-4 sm:grid-cols-2 lg:grid-cols-3" data-leave-cards>
            @foreach($tiles as $t)
                @php $body = '<span class="flex size-10 shrink-0 items-center justify-center rounded-xl '.$t['tone'].'">'; @endphp
                @if(isset($t['href']))
                    <a href="{{ $t['href'] }}" wire:navigate class="{{ $tile }}" data-leave-card="{{ Str::slug($t['label']) }}">
                @elseif($t['go'] ?? null)
                    <button type="button" x-on:click="{{ $t['go'] }}" class="{{ $tile }}" data-leave-card="{{ Str::slug($t['label']) }}">
                @else
                    <div class="{{ $tile }}" data-leave-card="{{ Str::slug($t['label']) }}">
                @endif
                    {!! $body !!}<flux:icon :name="$t['icon']" class="size-5" /></span>
                    <span class="min-w-0">
                        <span class="block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $t['label'] }}</span>
                        <span class="block text-xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $t['value'] }}</span>
                        <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $t['sub'] }}</span>
                    </span>
                @if(isset($t['href']))
                    </a>
                @elseif($t['go'] ?? null)
                    </button>
                @else
                    </div>
                @endif
            @endforeach
        </div>

        {{-- Pending leave requests --}}
        <div class="ui-surface overflow-hidden" data-pending-leave>
            <div class="flex items-center justify-between gap-2 border-b border-zinc-100 px-5 py-3 dark:border-white/5">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Pending Leave Requests</h3>
                <a href="{{ $links['pending'] }}" wire:navigate class="text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">View All Leave Requests</a>
            </div>
            @if($pending->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">No leave requests are waiting.</p>
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-white/5">
                            <tr><th class="{{ $th }} pl-5">Employee</th><th class="{{ $th }}">Department</th><th class="{{ $th }}">Reason</th><th class="{{ $th }}">Date Range</th><th class="{{ $th }} text-right">Paid / Unpaid</th><th class="{{ $th }} text-right">Days</th><th class="{{ $th }}">Current Approver</th><th class="{{ $th }}">Status</th><th class="{{ $th }} pr-5 text-right">Action</th></tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                            @foreach($pending as $r)
                                @php [$statusLabel, $statusColor] = $P::status($r->status); @endphp
                                <tr wire:key="dash-pending-{{ $r->id }}">
                                    <td class="whitespace-nowrap px-3 py-2.5 pl-5 font-medium text-zinc-900 dark:text-white">{{ $r->employee?->user?->name ?? 'Employee' }}</td>
                                    <td class="px-3 py-2.5 text-zinc-600 dark:text-zinc-300">{{ $r->employee?->department?->name ?? '—' }}</td>
                                    <td class="px-3 py-2.5 text-zinc-600 dark:text-zinc-300">{{ $P::category($r) }}@if($P::reason($r)) · {{ $P::reason($r) }}@endif</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-zinc-700 dark:text-zinc-200">{{ $P::dates($r) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{{ $fmt($P::paid($r)) }} / {{ $fmt($P::unpaid($r)) }}</td>
                                    <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ $fmt($r->days) }}</td>
                                    <td class="px-3 py-2.5 text-xs text-zinc-600 dark:text-zinc-300" style="max-width: 12rem">{{ $approverOf($r) }}</td>
                                    <td class="px-3 py-2.5"><x-pulse.badge :color="$statusColor">{{ $statusLabel }}</x-pulse.badge></td>
                                    <td class="px-3 py-2.5 pr-5 text-right"><flux:button size="xs" :href="route('time-off.leave-requests.show', $r)" wire:navigate>Manage</flux:button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <ul class="divide-y divide-zinc-100 md:hidden dark:divide-white/5">
                    @foreach($pending as $r)
                        @php [$statusLabel, $statusColor] = $P::status($r->status); @endphp
                        <li wire:key="dash-pending-card-{{ $r->id }}">
                            <a href="{{ route('time-off.leave-requests.show', $r) }}" wire:navigate class="block space-y-1 px-4 py-3">
                                <span class="flex items-start justify-between gap-2"><span class="font-semibold text-zinc-900 dark:text-white">{{ $r->employee?->user?->name ?? 'Employee' }}</span><x-pulse.badge :color="$statusColor">{{ $statusLabel }}</x-pulse.badge></span>
                                <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $P::category($r) }} · {{ $P::dates($r) }} · {{ $fmt($r->days) }} days</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>
@endif
</div>
