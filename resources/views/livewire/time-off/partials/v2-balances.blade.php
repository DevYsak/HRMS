{{-- Leave V2 balances: ONE Earned Leave balance, Comp Off and Unpaid Leave
     side by side; Earned Leave usage by reason (statistics, not balances)
     beside Special Leave with its own entitlement; then historical balances
     from earlier policies, kept for the record and never usable. Every figure
     is LeaveBalanceCalculator's for the balance row itself — display only. --}}
@if($this->v2Balances === null)
    <p class="rounded-xl border border-dashed border-zinc-200 p-6 text-center text-sm text-zinc-500 dark:border-white/10">You do not have an employee profile yet. Contact HR.</p>
@else
@php
    $b = $this->v2Balances;
    $earned = $b['earned'];
    $compOff = $b['comp_off'];
    $compOffType = $compOff['subtypes'][0] ?? null;
    $unpaidType = $b['unpaid']['types'][0] ?? null;
    $days = fn ($v) => $fmt($v).' '.\Illuminate\Support\Str::plural('day', (float) $v);
    $card = 'ui-surface flex flex-col p-5 transition hover:shadow-md';
    $icon = 'flex size-9 shrink-0 items-center justify-center rounded-xl';
    $stat = fn (string $k, $v) => '<div><dt class="text-xs text-zinc-500 dark:text-zinc-400">'.e($k).'</dt><dd class="mt-0.5 text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">'.e($v).'</dd></div>';
    // Usage by reason: every allowed reason, with what was used (statistics only).
    $usage = collect($earned['usage_by_reason'] ?? [])->keyBy('id');
    $reasonRows = collect($b['reasons'])->map(fn ($r) => $r + ['used' => (float) ($usage[$r['id']]['used'] ?? 0), 'unpaid' => (float) ($usage[$r['id']]['unpaid'] ?? 0), 'pending' => (float) ($usage[$r['id']]['pending'] ?? 0)])
        // Reasons used this year that the policy no longer lists still show.
        ->merge($usage->reject(fn ($u) => collect($b['reasons'])->contains('id', $u['id']))->values())
        ->values();
    $anyUsage = $reasonRows->contains(fn ($r) => $r['used'] + ($r['unpaid'] ?? 0) + $r['pending'] > 0);
    $dots = ['#f59e0b', '#f43f5e', '#14b8a6', '#84cc16', '#6366f1', '#0ea5e9', '#a855f7'];
    $hasSpecial = $b['special']['subtypes'] !== [];
    $hasHistorical = ($b['historical'] ?? []) !== [];
    // The canonical Earned Leave breakdown (EarnedLeaveBreakdown): the same lines HR sees.
    $bd = $earned['breakdown'] ?? null;
    $longDate = fn (?string $d) => $d ? \Carbon\CarbonImmutable::parse($d)->userDate() : '—';
    $nextCredit = $bd && $bd['next_credit'] ? $longDate($bd['next_credit']['date']).' (+'.$fmt($bd['next_credit']['days']).' for '.\Illuminate\Support\Carbon::parse($bd['next_credit']['month'].'-01')->format('F').')' : null;
@endphp

<div class="space-y-6" data-v2-balances>
    @unless($embedded)
        {{-- Standalone only: inside My Time Off the policy line sits in the page header. --}}
        <p class="text-xs text-zinc-500">
            Policy: <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $b['policy'] ?? 'No policy assigned' }}</span>
            <span class="text-zinc-400">· {{ $b['policy_source'] }}</span>
        </p>
    @endunless

    {{-- ── Current balances: Earned Leave · Comp Off · Unpaid Leave ── --}}
    <div x-data="{ earnedDetail: false }" class="space-y-4">
        <div class="grid grid-cols-1 items-stretch gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {{-- Earned Leave --}}
            <div class="{{ $card }}" data-category-card="earned">
                <div class="flex items-center justify-between gap-2">
                    <span class="flex items-center gap-2.5">
                        <span class="{{ $icon }} bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400"><flux:icon.sparkles class="size-4" /></span>
                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">Earned Leave</span>
                    </span>
                    <x-pulse.badge color="emerald">Current policy</x-pulse.badge>
                </div>
                @if($earned === null)
                    <p class="mt-4 flex-1 text-sm text-zinc-500">Earned Leave is not set up for your policy yet. Contact HR.</p>
                @else
                    <p class="mt-4 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white" data-earned-total>{{ $earned['unlimited'] ? 'As per policy' : $days($bd['total_available'] ?? $earned['approved_available']) }}</p>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Total available @if(($bd['pending'] ?? 0) > 0) · <span>Available to request</span> {{ $fmt($bd['available_to_request']) }}@endif</p>
                    <dl class="mt-4 grid flex-1 grid-cols-2 gap-2" data-earned-lines>
                        {!! $stat('Carry Forward', $fmt($bd['carry_forward'] ?? 0)) !!}
                        {!! $stat('Current Year Accrued', $fmt($bd['current_year_accrued'] ?? 0)) !!}
                        @if($bd && abs($bd['opening']) > 0.001)
                            {!! $stat('Opening balance', $fmt($bd['opening'])) !!}
                        @endif
                        @if($bd && abs($bd['hr_adjustment']) > 0.001)
                            {!! $stat('HR Adjustment', ($bd['hr_adjustment'] > 0 ? '+' : '').$fmt($bd['hr_adjustment'])) !!}
                        @endif
                        {!! $stat('Used', $fmt($bd['used'] ?? $earned['used'])) !!}
                        {!! $stat('Pending', $fmt($bd['pending'] ?? $earned['pending'])) !!}
                    </dl>
                    @if($bd)
                        <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400" data-earned-rule>{{ $fmt($bd['monthly_days']) }} day per completed month · leave year {{ $longDate($bd['year']['starts_on']) }} – {{ $longDate($bd['year']['ends_on']) }}@if($nextCredit) · next credit {{ $nextCredit }}@endif</p>
                    @endif
                    <div class="mt-4 flex flex-wrap gap-2">
                        <flux:button size="sm" variant="primary" x-on:click="Livewire.dispatch('open-request-leave', { leaveTypeId: {{ $earned['id'] }} })">Request Leave</flux:button>
                        <flux:button size="sm" x-on:click="earnedDetail = ! earnedDetail" x-bind:aria-expanded="earnedDetail" data-earned-toggle>View Details</flux:button>
                    </div>
                @endif
            </div>

            {{-- Comp Off --}}
            <div class="{{ $card }}" data-category-card="comp_off">
                <div class="flex items-center justify-between gap-2">
                    <span class="flex items-center gap-2.5">
                        <span class="{{ $icon }} bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400"><flux:icon.arrow-path-rounded-square class="size-4" /></span>
                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">Comp Off</span>
                    </span>
                    <x-pulse.badge>Separate</x-pulse.badge>
                </div>
                <p class="mt-4 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $days($compOff['available']) }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $compOff['credits'] > 0 ? 'Available to request' : 'No Comp Off credits yet' }}</p>
                <dl class="mt-4 grid flex-1 grid-cols-3 gap-2">
                    {!! $stat('Earned', $fmt($compOff['credits'])) !!}
                    {!! $stat('Used', $fmt($compOff['used'])) !!}
                    {!! $stat('Pending', $fmt($compOff['pending'])) !!}
                </dl>
                @if($compOffType)
                    <div class="mt-4 flex flex-wrap gap-2">
                        @if($compOff['available'] > 0)
                            <flux:button size="sm" variant="primary" x-on:click="Livewire.dispatch('open-request-leave', { leaveTypeId: {{ $compOffType['id'] }} })">Request</flux:button>
                        @endif
                        @if($compOffType['has_balance'])
                            <flux:button size="sm" wire:click="showHistory({{ $compOffType['id'] }})">View History</flux:button>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Unpaid Leave --}}
            <div class="{{ $card }}" data-category-card="unpaid">
                <div class="flex items-center justify-between gap-2">
                    <span class="flex items-center gap-2.5">
                        <span class="{{ $icon }} bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400"><flux:icon.banknotes class="size-4" /></span>
                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">Unpaid Leave</span>
                    </span>
                </div>
                <p class="mt-4 text-2xl font-bold text-zinc-900 dark:text-white">No balance required</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Affects payroll</p>
                <dl class="mt-4 grid flex-1 grid-cols-2 gap-2">
                    {!! $stat('Used this year', $fmt($b['unpaid']['used'])) !!}
                    {!! $stat('Pending', $fmt($b['unpaid']['pending'])) !!}
                </dl>
                @if($unpaidType)
                    <div class="mt-4"><flux:button size="sm" variant="primary" x-on:click="Livewire.dispatch('open-request-leave', { leaveTypeId: {{ $unpaidType['id'] }} })">Request Leave</flux:button></div>
                @endif
            </div>
        </div>

        {{-- Earned Leave in detail: opened from "View Details", never repeated on the page. --}}
        @if($earned !== null)
            <div x-show="earnedDetail" x-cloak x-transition class="ui-surface p-5" data-earned-detail>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Earned Leave — details</h3>
                    @if($earned['has_balance'])
                        <flux:button size="sm" variant="ghost" wire:click="showStatement({{ $earned['id'] }})">Month-wise statement</flux:button>
                    @endif
                </div>
                @if($bd)
                    <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4" data-earned-detail-lines>
                        @foreach(array_filter([
                            'Carry Forward' => $bd['carry_forward'],
                            'Current Year Accrued' => $bd['current_year_accrued'],
                            'Opening balance (previous policy)' => abs($bd['opening']) > 0.001 ? $bd['opening'] : null,
                            'HR Adjustments' => $bd['hr_adjustment'],
                            'Used' => $bd['used'],
                            'Pending' => $bd['pending'],
                            'Expired' => $bd['expired'],
                            'Total Available' => $bd['total_available'],
                        ], fn ($v) => $v !== null) as $k => $v)
                            <div class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-white/5">
                                <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ $k }}</dt>
                                <dd class="text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $k === 'HR Adjustments' && $v > 0 ? '+' : '' }}{{ $fmt($v) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <dl class="mt-3 grid grid-cols-1 gap-1 text-xs text-zinc-600 sm:grid-cols-2 dark:text-zinc-300">
                        <div><dt class="inline text-zinc-500 dark:text-zinc-400">Monthly accrual:</dt> <dd class="inline font-semibold">{{ $fmt($bd['monthly_days']) }} day after each completed month, up to {{ $fmt($bd['annual_entitlement']) }} new days a year ({{ $fmt($bd['generated_this_year']) }} so far)</dd></div>
                        <div><dt class="inline text-zinc-500 dark:text-zinc-400">Leave year:</dt> <dd class="inline font-semibold">{{ $longDate($bd['year']['starts_on']) }} – {{ $longDate($bd['year']['ends_on']) }}</dd></div>
                        @if($bd['starts_from'])
                            <div><dt class="inline text-zinc-500 dark:text-zinc-400">Monthly accrual starts from:</dt> <dd class="inline font-semibold">{{ \Illuminate\Support\Carbon::parse($bd['starts_from'])->format('F Y') }}</dd></div>
                        @endif
                        <div><dt class="inline text-zinc-500 dark:text-zinc-400">Next credit:</dt> <dd class="inline font-semibold">{{ $nextCredit ?? 'None scheduled' }}</dd></div>
                    </dl>
                    @if(($bd['historical_used'] ?? 0) > 0.001)
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400" data-earned-historical-used>Used: {{ $fmt($bd['historical_used']) }} before Leave V2 (HR register) + {{ $fmt($bd['portal_used']) }} in the portal.</p>
                    @endif
                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Carry forward is unused leave from earlier years; it is part of your total but never counts as this year's accrual.</p>
                @endif
            </div>
        @endif
    </div>

    {{-- ── Earned Leave usage (by reason) · Special Leave and historical balances ── --}}
    @if($earned !== null || $hasSpecial || $hasHistorical)
        <div class="grid grid-cols-1 items-stretch gap-4 lg:grid-cols-2">
            @if($earned !== null)
                {{-- Usage figures only: reasons never hold a balance. --}}
                <section class="ui-surface flex flex-col p-5 {{ $hasSpecial || $hasHistorical ? '' : 'lg:col-span-2' }}" data-usage-by-reason>
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Earned Leave usage</h3>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">(by reason)</span>
                    </div>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Usage figures for this leave year (not balances).</p>
                    <div class="mt-4 grid flex-1 grid-cols-1 items-stretch gap-4 sm:grid-cols-2">
                        <ul class="divide-y divide-zinc-100 text-sm dark:divide-white/5">
                            @forelse($reasonRows as $i => $reason)
                                <li wire:key="v2-reason-{{ $reason['id'] }}" class="flex items-center justify-between gap-3 py-2" data-reason-usage="{{ $reason['code'] }}">
                                    <span class="flex min-w-0 items-center gap-2.5">
                                        <span class="size-2.5 shrink-0 rounded-full" style="background: {{ $dots[$i % count($dots)] }}"></span>
                                        <span class="truncate text-zinc-700 dark:text-zinc-200">{{ $reason['name'] }}</span>
                                    </span>
                                    <span class="shrink-0 text-right tabular-nums">
                                        <span class="text-zinc-900 dark:text-white">{{ $days($reason['used']) }}</span>
                                        @if(($reason['unpaid'] ?? 0) > 0 || $reason['pending'] > 0)
                                            <span class="block text-xs text-zinc-500 dark:text-zinc-400">@if(($reason['unpaid'] ?? 0) > 0){{ $fmt($reason['unpaid']) }} unpaid @endif @if($reason['pending'] > 0){{ $fmt($reason['pending']) }} pending @endif</span>
                                        @endif
                                    </span>
                                </li>
                            @empty
                                <li class="py-2 text-zinc-500 dark:text-zinc-400">Your policy lists no reasons.</li>
                            @endforelse
                        </ul>
                        @if(! $anyUsage)
                            <div class="flex flex-col items-center justify-center rounded-xl bg-zinc-50 p-5 text-center dark:bg-white/5" data-usage-empty>
                                <span class="flex size-12 items-center justify-center rounded-full bg-white text-zinc-400 shadow-sm dark:bg-white/10 dark:text-zinc-300"><flux:icon.document-text class="size-6" /></span>
                                <p class="mt-3 text-sm font-semibold text-zinc-900 dark:text-white">No Earned Leave has been used</p>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">You have not taken any Earned Leave this leave year.</p>
                            </div>
                        @else
                            <dl class="grid grid-cols-1 content-center gap-3 rounded-xl bg-zinc-50 p-5 dark:bg-white/5">
                                {!! $stat('Used this leave year', $days($reasonRows->sum('used'))) !!}
                                {!! $stat('Pending approval', $days($reasonRows->sum('pending'))) !!}
                                {!! $stat('Taken as unpaid', $days($reasonRows->sum(fn ($r) => $r['unpaid'] ?? 0))) !!}
                            </dl>
                        @endif
                    </div>
                </section>
            @endif

            @if($hasSpecial || $hasHistorical)
                <div class="flex flex-col gap-4 {{ $earned === null ? 'lg:col-span-2' : '' }}">
                    {{-- Special Leave: its own entitlement, only what the employee is eligible for. --}}
                    @if($hasSpecial)
                        <section class="ui-surface flex-1 p-5" data-special-leave>
                            <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Special Leave</h3>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Statutory and special leave with independent entitlement. Not part of your Earned Leave.</p>
                            <div class="mt-4 grid grid-cols-1 items-stretch gap-3 sm:grid-cols-2">
                                @foreach($b['special']['subtypes'] as $sub)
                                    @php $differs = $sub['annual_entitlement'] !== null && $sub['credits'] > 0 && abs($sub['annual_entitlement'] - $sub['credits']) > 0.001; @endphp
                                    <div wire:key="v2-special-{{ $sub['id'] }}" class="flex flex-col rounded-xl border border-zinc-200 p-4 dark:border-white/10" data-special-card="{{ $sub['code'] }}">
                                        <div class="flex items-start gap-2.5">
                                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-500 dark:bg-rose-500/10 dark:text-rose-400"><flux:icon.heart class="size-4" /></span>
                                            <span class="min-w-0">
                                                <span class="block break-words text-sm font-semibold text-zinc-900 dark:text-white">{{ $sub['name'] }}</span>
                                                @if($differs)<x-pulse.badge color="blue" class="mt-1" title="Granted before the current policy; the balance is kept as granted.">Existing grant</x-pulse.badge>@endif
                                            </span>
                                        </div>
                                        <p class="mt-3 text-xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $fmt($sub['available']) }}
                                            @if($sub['credits'] > 0)<span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">/ {{ $fmt($sub['credits']) }} days granted</span>@endif
                                        </p>
                                        @if($sub['annual_entitlement'] !== null)
                                            <p class="mt-0.5 flex-1 text-xs text-zinc-500 dark:text-zinc-400" @if($differs) data-policy-entitlement @endif>{{ $differs ? 'Policy entitlement' : 'Entitlement' }}: {{ $fmt($sub['annual_entitlement']) }} days a year</p>
                                        @else
                                            <p class="flex-1"></p>
                                        @endif
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @if($sub['requestable'] ?? true)
                                                <flux:button size="sm" variant="primary" x-on:click="Livewire.dispatch('open-request-leave', { leaveTypeId: {{ $sub['id'] }} })">Request</flux:button>
                                            @endif
                                            @if($sub['has_balance'])<flux:button size="sm" wire:click="showStatement({{ $sub['id'] }})">View Details</flux:button>@endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    {{-- Historical balances: earlier policies, kept for the record, never usable. --}}
                    @if($hasHistorical)
                        <details class="ui-surface group p-5" data-historical-balances>
                            <summary class="flex cursor-pointer list-none items-start justify-between gap-3">
                                <span class="flex min-w-0 gap-3">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400"><flux:icon.clock class="size-4" /></span>
                                    <span class="min-w-0">
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span class="text-sm font-semibold text-zinc-900 dark:text-white">Historical Leave Balances</span>
                                            <x-pulse.badge>{{ count($b['historical']) }} {{ \Illuminate\Support\Str::plural('balance', count($b['historical'])) }}</x-pulse.badge>
                                        </span>
                                        <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400" data-historical-note>Retained for record · not usable for new requests · not included in your current Earned Leave total.</span>
                                    </span>
                                </span>
                                <flux:icon.chevron-down class="mt-1 size-4 shrink-0 text-zinc-400 transition group-open:rotate-180" />
                            </summary>
                            <div class="mt-3 divide-y divide-zinc-100 dark:divide-white/5">
                                @foreach($b['historical'] as $old)
                                    <div wire:key="v2-hist-{{ $old['id'] }}" class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm" data-historical-row="{{ $old['code'] }}">
                                        <span class="flex min-w-0 items-center gap-2">
                                            <span class="truncate text-zinc-700 dark:text-zinc-200">{{ $old['name'] }}</span>
                                            <x-pulse.badge>Historical</x-pulse.badge>
                                        </span>
                                        <span class="flex min-w-0 flex-wrap items-center justify-end gap-x-3 gap-y-1">
                                            <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ $days($old['approved_available']) }} historical balance</span>
                                            <flux:button size="xs" variant="ghost" wire:click="showStatement({{ $old['id'] }})">Statement</flux:button>
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </div>
            @endif
        </div>
    @endif

    {{-- ── Paid types still active but not in a V2 category ── --}}
    @if($b['other_paid'] !== [])
        <section class="ui-surface p-5" data-other-paid>
            <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Other paid leave</h3>
            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Other paid leave types you hold. They are not part of your Earned Leave.</p>
            <div class="mt-3 divide-y divide-zinc-100 dark:divide-white/5">
                @foreach($b['other_paid'] as $other)
                    <div wire:key="v2-other-{{ $other['id'] }}" class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                        <span class="min-w-0 truncate text-zinc-700 dark:text-zinc-200">{{ $other['name'] }}@if($other['code']) <span class="text-zinc-400">({{ $other['code'] }})</span>@endif</span>
                        <span class="flex min-w-0 flex-wrap items-center justify-end gap-x-3 gap-y-1">
                            <span class="tabular-nums font-semibold text-zinc-900 dark:text-white">{{ $days($other['available']) }}</span>
                            <flux:button size="xs" variant="ghost" wire:click="showStatement({{ $other['id'] }})">View details</flux:button>
                        </span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
@endif
