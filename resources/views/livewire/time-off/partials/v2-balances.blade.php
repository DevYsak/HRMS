{{-- Leave V2 balances: ONE Earned Leave balance, Comp Off and Unpaid Leave
     side by side; Earned Leave usage by reason (statistics, not balances);
     Special Leave with its own entitlement; then historical balances from
     earlier policies, kept for the record and never usable. Every figure is
     LeaveBalanceCalculator's for the balance row itself — display only. --}}
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
    $card = 'ui-surface flex flex-col p-5';
    $label = 'text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400';
    $stat = fn (string $k, $v) => '<div><dt class="text-[11px] text-zinc-500 dark:text-zinc-400">'.e($k).'</dt><dd class="text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">'.e($v).'</dd></div>';
    // Usage by reason: every allowed reason, with what was used (statistics only).
    $usage = collect($earned['usage_by_reason'] ?? [])->keyBy('id');
    $reasonRows = collect($b['reasons'])->map(fn ($r) => $r + ['used' => (float) ($usage[$r['id']]['used'] ?? 0), 'unpaid' => (float) ($usage[$r['id']]['unpaid'] ?? 0), 'pending' => (float) ($usage[$r['id']]['pending'] ?? 0)])
        // Reasons used this year that the policy no longer lists still show.
        ->merge($usage->reject(fn ($u) => collect($b['reasons'])->contains('id', $u['id']))->values())
        ->values();
    $anyUsage = $reasonRows->contains(fn ($r) => $r['used'] + ($r['unpaid'] ?? 0) + $r['pending'] > 0);
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
        <div class="grid grid-cols-1 items-stretch gap-4 md:grid-cols-3">
            {{-- Earned Leave --}}
            <div class="{{ $card }}" data-category-card="earned">
                <div class="flex items-center justify-between gap-2">
                    <span class="flex items-center gap-2">
                        <span class="flex size-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400"><flux:icon.sparkles class="size-4" /></span>
                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">Earned Leave</span>
                    </span>
                    <x-pulse.badge color="emerald">Current policy</x-pulse.badge>
                </div>
                @if($earned === null)
                    <p class="mt-4 flex-1 text-sm text-zinc-500">Earned Leave is not set up for your policy yet. Contact HR.</p>
                @else
                    <p class="mt-4 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $earned['unlimited'] ? 'As per policy' : $days($earned['available']) }}</p>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Available to request</p>
                    <dl class="mt-4 grid flex-1 grid-cols-4 gap-2">
                        {!! $stat('Entitlement', $earned['annual_entitlement'] === null ? '—' : $fmt($earned['annual_entitlement'])) !!}
                        {!! $stat('Accrued', $fmt($earned['credits'])) !!}
                        {!! $stat('Used', $fmt($earned['used'])) !!}
                        {!! $stat('Pending', $fmt($earned['pending'])) !!}
                    </dl>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <flux:button size="xs" variant="primary" wire:click="apply({{ $earned['id'] }})">Request</flux:button>
                        <flux:button size="xs" x-on:click="earnedDetail = ! earnedDetail" x-bind:aria-expanded="earnedDetail" data-earned-toggle>View Details</flux:button>
                    </div>
                @endif
            </div>

            {{-- Comp Off --}}
            <div class="{{ $card }}" data-category-card="comp_off">
                <div class="flex items-center justify-between gap-2">
                    <span class="flex items-center gap-2">
                        <span class="flex size-8 items-center justify-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400"><flux:icon.arrow-path-rounded-square class="size-4" /></span>
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
                            <flux:button size="xs" variant="primary" wire:click="apply({{ $compOffType['id'] }})">Request</flux:button>
                        @endif
                        @if($compOffType['has_balance'])
                            <flux:button size="xs" wire:click="showHistory({{ $compOffType['id'] }})">View History</flux:button>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Unpaid Leave --}}
            <div class="{{ $card }}" data-category-card="unpaid">
                <div class="flex items-center justify-between gap-2">
                    <span class="flex items-center gap-2">
                        <span class="flex size-8 items-center justify-center rounded-lg bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400"><flux:icon.banknotes class="size-4" /></span>
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
                    <div class="mt-4"><flux:button size="xs" variant="primary" wire:click="apply({{ $unpaidType['id'] }})">Request</flux:button></div>
                @endif
            </div>
        </div>

        {{-- Earned Leave in detail: opened from "View Details", never repeated on the page. --}}
        @if($earned !== null)
            <div x-show="earnedDetail" x-cloak x-transition class="ui-surface p-5" data-earned-detail>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Earned Leave — details</h3>
                    @if($earned['has_balance'])
                        <flux:button size="xs" variant="ghost" wire:click="showStatement({{ $earned['id'] }})">Month-wise statement</flux:button>
                    @endif
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                    @foreach([
                        'Annual entitlement' => $earned['annual_entitlement'],
                        'Opening' => $earned['opening'],
                        'Accrued' => $earned['accrued'],
                        'Carry forward' => $earned['carry_forward'],
                        'Adjustments' => $earned['adjustment'],
                        'Used' => $earned['used'],
                        'Pending' => $earned['pending'],
                        'Available' => $earned['available'],
                    ] as $k => $v)
                        <div class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-white/5">
                            <dt class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $k }}</dt>
                            <dd class="text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $v === null ? '—' : $fmt($v) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif
    </div>

    {{-- ── Earned Leave usage by reason: statistics, not balances ── --}}
    @if($earned !== null)
        <section class="ui-surface p-5" data-usage-by-reason>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Earned Leave usage</h3>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">By reason · usage figures, not balances</p>
            </div>
            @if(! $anyUsage)
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">No Earned Leave has been used this leave year.</p>
            @else
                <table class="mt-3 w-full text-left text-sm">
                    <thead class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        <tr><th class="py-1.5">Reason</th><th class="py-1.5 text-right">Used</th><th class="py-1.5 text-right">Unpaid</th><th class="py-1.5 text-right">Pending</th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach($reasonRows as $reason)
                            <tr wire:key="v2-reason-{{ $reason['id'] }}" data-reason-usage="{{ $reason['code'] }}">
                                <td class="py-1.5 text-zinc-700 dark:text-zinc-200">{{ $reason['name'] }}</td>
                                <td class="py-1.5 text-right tabular-nums text-zinc-900 dark:text-white">{{ $fmt($reason['used']) }}</td>
                                <td class="py-1.5 text-right tabular-nums text-zinc-500 dark:text-zinc-400">{{ $fmt($reason['unpaid'] ?? 0) }}</td>
                                <td class="py-1.5 text-right tabular-nums text-zinc-500 dark:text-zinc-400">{{ $fmt($reason['pending']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endif

    {{-- ── Special Leave: its own entitlement, only what the employee is eligible for ── --}}
    @if($b['special']['subtypes'] !== [])
        <section data-special-leave>
            <div class="mb-3">
                <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Special Leave</h3>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Statutory and special leave with independent entitlement. Not part of your Earned Leave.</p>
            </div>
            <div class="grid grid-cols-1 items-stretch gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($b['special']['subtypes'] as $sub)
                    @php $differs = $sub['annual_entitlement'] !== null && $sub['credits'] > 0 && abs($sub['annual_entitlement'] - $sub['credits']) > 0.001; @endphp
                    <div wire:key="v2-special-{{ $sub['id'] }}" class="ui-surface flex flex-col p-4" data-special-card="{{ $sub['code'] }}">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $sub['name'] }}</p>
                            @if($differs)<x-pulse.badge color="blue" title="Granted before the current policy; the balance is kept as granted.">Existing grant</x-pulse.badge>@endif
                        </div>
                        <p class="mt-2 text-xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $fmt($sub['available']) }}
                            @if($sub['credits'] > 0)<span class="text-xs font-medium text-zinc-400">/ {{ $fmt($sub['credits']) }} days granted</span>@endif
                        </p>
                        @if($sub['annual_entitlement'] !== null)
                            <p class="mt-0.5 flex-1 text-[11px] text-zinc-500 dark:text-zinc-400" @if($differs) data-policy-entitlement @endif>{{ $differs ? 'Policy entitlement' : 'Entitlement' }}: {{ $fmt($sub['annual_entitlement']) }} days a year</p>
                        @else
                            <p class="flex-1"></p>
                        @endif
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if($sub['requestable'] ?? true)
                                <flux:button size="xs" variant="primary" wire:click="apply({{ $sub['id'] }})">Request</flux:button>
                            @endif
                            @if($sub['has_balance'])<flux:button size="xs" wire:click="showStatement({{ $sub['id'] }})">View Details</flux:button>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ── Paid types still active but not in a V2 category ── --}}
    @if($b['other_paid'] !== [])
        <section class="ui-surface p-5" data-other-paid>
            <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Other paid leave</h3>
            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Other paid leave types you hold. They are not part of your Earned Leave.</p>
            <div class="mt-3 divide-y divide-zinc-100 dark:divide-white/5">
                @foreach($b['other_paid'] as $other)
                    <div wire:key="v2-other-{{ $other['id'] }}" class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span class="min-w-0 truncate text-zinc-700 dark:text-zinc-200">{{ $other['name'] }}@if($other['code']) <span class="text-zinc-400">({{ $other['code'] }})</span>@endif</span>
                        <span class="flex shrink-0 items-center gap-3">
                            <span class="tabular-nums font-semibold text-zinc-900 dark:text-white">{{ $days($other['available']) }}</span>
                            <flux:button size="xs" variant="ghost" wire:click="showStatement({{ $other['id'] }})">View details</flux:button>
                        </span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ── Historical balances: earlier policies, kept for the record, never usable ── --}}
    @if(($b['historical'] ?? []) !== [])
        <details class="ui-surface group p-5" data-historical-balances>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3">
                <span>
                    <span class="flex items-center gap-2">
                        <span class="text-base font-semibold text-zinc-900 dark:text-white">Historical Leave Balances</span>
                        <x-pulse.badge>{{ count($b['historical']) }} {{ \Illuminate\Support\Str::plural('balance', count($b['historical'])) }}</x-pulse.badge>
                    </span>
                    <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">These balances belong to previous leave policies and are retained for leave history. They cannot be used for new requests.</span>
                </span>
                <flux:icon.chevron-down class="size-4 shrink-0 text-zinc-400 transition group-open:rotate-180" />
            </summary>
            <div class="mt-3 divide-y divide-zinc-100 dark:divide-white/5">
                @foreach($b['historical'] as $old)
                    <div wire:key="v2-hist-{{ $old['id'] }}" class="flex items-center justify-between gap-3 py-2 text-sm" data-historical-row="{{ $old['code'] }}">
                        <span class="flex min-w-0 items-center gap-2">
                            <span class="truncate text-zinc-700 dark:text-zinc-200">{{ $old['name'] }}</span>
                            <x-pulse.badge>Historical</x-pulse.badge>
                        </span>
                        <span class="flex shrink-0 items-center gap-3">
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
